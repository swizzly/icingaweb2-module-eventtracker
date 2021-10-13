<?php

namespace Icinga\Module\Eventtracker\Daemon;

use gipfl\ZfDb\Adapter\Adapter as Db;
use Icinga\Module\Eventtracker\Db\ConfigStore;
use Icinga\Module\Eventtracker\Engine\InputRunner;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use function React\Promise\resolve;

class InputAndChannelRunner implements DbBasedComponent
{
    /** @var Db */
    protected $db;

    /** @var LoopInterface */
    protected $loop;

    /** @var InputRunner */
    protected $runner;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(LoopInterface $loop, LoggerInterface $logger)
    {
        $this->loop = $loop;
        $this->logger = $logger;
    }

    /**
     * @param Db $db
     * @return \React\Promise\ExtendedPromiseInterface
     */
    public function initDb(Db $db)
    {
        $this->db = $db;

        $store = new ConfigStore($db, $this->logger);
        $this->runner = new InputRunner($this->loop, $store);
        $this->runner->start();

        return resolve();
    }

    /**
     * @return \React\Promise\ExtendedPromiseInterface
     */
    public function stopDb()
    {
        if ($this->runner) {
            $this->runner->stop();
            $this->runner = null;
        }

        return resolve();
    }

    public function __destruct()
    {
        $this->stopDb();
        $this->loop = null;
    }
}
