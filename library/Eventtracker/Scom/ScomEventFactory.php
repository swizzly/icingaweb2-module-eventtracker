<?php

namespace Icinga\Module\Eventtracker\Scom;

use Icinga\Module\Eventtracker\Event;
use Icinga\Module\Eventtracker\ObjectClassInventory;

class ScomEventFactory
{
    protected $senderId;

    protected $classInventory;

    public function __construct($senderId, ObjectClassInventory $classInventory)
    {
        $this->senderId = $senderId;
        $this->classInventory = $classInventory;
    }

    public function fromPlainObject($obj)
    {
        $event = new Event();
        $event->setProperties([
            'host_name'       => $obj->entity_name,
            'object_name'     => substr($obj->alert_name, 0, 128),
            'object_class'    => $this->classInventory->requireClass(substr($obj->entity_base_type, 0, 128)),
            'severity'        => $obj->alert_severity,
            'priority'        => $obj->alert_priority,
            'message'         => $obj->description ? $obj->description : '-',
            'sender_event_id' => $obj->alert_id,
            'sender_id'       => $this->senderId
        ]);

        return $event;
    }
}
