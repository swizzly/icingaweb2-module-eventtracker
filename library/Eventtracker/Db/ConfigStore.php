<?php

namespace Icinga\Module\Eventtracker\Db;

use gipfl\Json\JsonString;
use gipfl\ZfDb\Adapter\Adapter;
use Icinga\Module\Eventtracker\Engine\Action;
use Icinga\Module\Eventtracker\Engine\Action\ActionRegistry;
use Icinga\Module\Eventtracker\Engine\Channel;
use Icinga\Module\Eventtracker\Engine\Input;
use Icinga\Module\Eventtracker\Engine\Input\InputRegistry;
use Icinga\Module\Eventtracker\Engine\Registry;
use Icinga\Module\Eventtracker\Engine\Task;
use Icinga\Module\Eventtracker\Modifier\Settings;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;

class ConfigStore
{
    protected $db;

    protected $serializedProperties = ['settings', 'rules', 'input_uuids'];

    /** @var LoggerInterface */
    protected $logger;

    public function __construct(Adapter $db, LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function loadInputs($filter = [])
    {
        $inputs = [];
        foreach ($this->fetchObjects('input', $filter) as $row) {
            $row->uuid = Uuid::fromBytes($row->uuid);
            $inputs[$row->uuid->toString()] = $this->initializeTaskFromDbRow($row, new InputRegistry(), Input::class);
        }

        return $inputs;
    }

    protected function initializeTaskFromDbRow($row, Registry $registry, $contract): Task
    {
        $implementation = $registry->getClassName($row->implementation);
        $interfaces = class_implements($implementation);
        if (isset($interfaces[$contract])) {
            return new $implementation(
                Settings::fromSerialization($row->settings),
                $row->uuid,
                $row->label,
                $this->logger
            );
        } else {
            throw new RuntimeException("Task ignored, $implementation is no valid implementation for $contract");
        }
    }

    /**
     * @return Channel[]
     * @throws \gipfl\Json\JsonDecodeException
     */
    public function loadChannels()
    {
        $channels = [];
        foreach ($this->fetchObjects('channel') as $row) {
            $uuid = Uuid::fromBytes($row->uuid);
            $channels[$uuid->toString()] = new Channel(Settings::fromSerialization([
                'rules'          => JsonString::decode($row->rules),
                'implementation' => $row->input_implementation,
                'inputs'         => $row->input_uuids,
            ]), $uuid, $row->label, $this->logger);
        }

        return $channels;
    }

    public function loadActions($filter = []): array
    {
        $actions = [];
        foreach ($this->fetchObjects('action', $filter) as $row) {
            $row->uuid = Uuid::fromBytes($row->uuid);
            /** @var Action $action */
            $action = $this->initializeTaskFromDbRow($row, new ActionRegistry(), Action::class);
            $actions[$row->uuid->toString()] = $action
                ->setActionDescription($row->description)
                ->setEnabled($row->enabled === 'y')
                ->setFilter($row->filter);
        }

        return $actions;
    }

    public function fetchObject($table, UuidInterface $uuid)
    {
        $db = $this->db;
        $row = $db->fetchRow($db->select()->from($table)->where('uuid = ?', $uuid->getBytes()));
        $this->unserializeSerializedProperties($row);

        return $row;
    }

    protected function unserializeSerializedProperties($row)
    {
        foreach ($this->serializedProperties as $property) {
            if (isset($row->$property)) {
                $row->$property = JsonString::decode($row->$property);
            }
        }
    }

    protected function fetchObjects($table, $filter = [])
    {
        $db = $this->db;
        $query = "SELECT * FROM $table";
        if (! empty($filter)) {
            $query .= ' WHERE';
        }
        foreach ($filter as $key => $value) {
            $query .= $db->quoteInto(sprintf(' %s = ?', $db->quoteIdentifier($key)), $value);
        }
        $query .= ' ORDER BY label';
        $rows = $db->fetchAll($query);
        foreach ($rows as $row) {
            $this->unserializeSerializedProperties($row);
        }
        return $rows;
    }

    public function enumObjects($table)
    {
        $db = $this->db;
        $result = [];
        foreach ($db->fetchPairs("SELECT uuid, label FROM $table ORDER BY label") as $uuid => $label) {
            $result[Uuid::fromBytes($uuid)->toString()] = $label;
        }

        return $result;
    }

    public function deleteObject($table, UuidInterface $uuid)
    {
        return $this->db->delete($table, $this->quotedWhere($uuid));
    }

    /**
     * @param $table
     * @param $object
     * @return bool|UuidInterface
     * @throws \gipfl\ZfDb\Adapter\Exception\AdapterException
     * @throws \gipfl\ZfDb\Statement\Exception\StatementException
     */
    public function storeObject($table, $object)
    {
        $this->propertyArrayCleanup($object);
        $isUpdate = isset($object['uuid']);
        if ($isUpdate) {
            $uuid = Uuid::fromString($object['uuid']);
            unset($object['uuid']);
        } else {
            $uuid = Uuid::uuid4();
        }
        if ($isUpdate) {
            return $this->db->update($table, $object, $this->quotedWhere($uuid)) > 0;
        } else {
            $object['uuid'] = $uuid->getBytes();
            $this->db->insert($table, $object);
        }

        return $uuid;
    }

    /**
     * @deprecated
     * @return Adapter
     */
    public function getDb()
    {
        return $this->db;
    }

    protected function quotedWhere(UuidInterface $uuid)
    {
        return $this->db->quoteInto('uuid = ?', $uuid->getBytes());
    }

    protected function propertyArrayCleanup(&$array)
    {
        foreach ($this->serializedProperties as $property) {
            if (isset($array[$property])) {
                $array[$property] = JsonString::encode($array[$property]);
            }
        }
    }
}
