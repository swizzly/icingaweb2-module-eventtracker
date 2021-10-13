<?php

namespace Icinga\Module\Eventtracker;

use gipfl\ZfDb\Adapter\Adapter as Db;
use Icinga\Module\Eventtracker\Engine\Counters;

class EventReceiver
{
    const CNT_NEW = 'new';
    const CNT_IGNORED = 'ignored';
    const CNT_RECOVERED = 'recovered';
    const CNT_REFRESHED = 'refreshed';

    /** @var Db */
    protected $db;

    protected $counters;

    public function __construct(Db $db)
    {
        $this->db = $db;
        $this->counters = new Counters();
    }

    /**
     * @param Event $event
     * @return Issue|null
     * @throws \gipfl\ZfDb\Adapter\Exception\AdapterException
     * @throws \gipfl\ZfDb\Statement\Exception\StatementException
     */
    public function processEvent(Event $event)
    {
        $issue = Issue::loadIfEventExists($event, $this->db);
        if ($event->hasBeenCleared()) {
            if ($issue) {
                $this->counters->increment(self::CNT_RECOVERED);
                $issue->recover($event, $this->db);
            } else {
                $this->counters->increment(self::CNT_IGNORED);
                return null;
            }
        } elseif ($event->isProblem()) {
            if ($issue) {
                $this->counters->increment(self::CNT_REFRESHED);
                $issue->setPropertiesFromEvent($event);
            } else {
                $issue = Issue::create($event, $this->db);
                $this->counters->increment(self::CNT_NEW);
            }
            $issue->storeToDb($this->db);
        } elseif ($issue) {
            $this->counters->increment(self::CNT_RECOVERED);
            $issue->recover($event, $this->db);

            return null;
        } else {
            $this->counters->increment(self::CNT_IGNORED);
        }

        return $issue;
    }

    public function getCounters()
    {
        return $this->counters;
    }
}
