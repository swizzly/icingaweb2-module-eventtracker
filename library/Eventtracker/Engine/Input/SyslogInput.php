<?php

namespace Icinga\Module\Eventtracker\Engine\Input;

use Evenement\EventEmitterTrait;
use gipfl\Translation\StaticTranslator;
use Icinga\Module\Eventtracker\Engine\SettingsProperty;
use Icinga\Module\Eventtracker\Web\Form\Input\SyslogForm;
use Icinga\Module\Eventtracker\Stream\BufferedReader;
use Icinga\Module\Eventtracker\Syslog\SyslogParser;
use InvalidArgumentException;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\UnixServer;

class SyslogInput extends SimpleInputConstructor
{
    use EventEmitterTrait;
    use SettingsProperty;

    /** @var string */
    protected $socket;

    /** @var UnixServer */
    protected $server;

    /** @var LoopInterface */
    protected $loop;

    protected function initialize()
    {
        $settings = $this->getSettings();
        // socket_type: unix, udp;
        //   unix -> socket_path
        //   udp -> listening_address, listening_port -> not yet
        switch ($settings->getRequired('socket_type')) {
            case 'udp':
                throw new InvalidArgumentException('UDP Sockets are not supported yet');
            case 'unix':
                $this->socket = $settings->getRequired('socket_path');
                break;
            default:
                throw new InvalidArgumentException(
                    $settings->getRequired('socket_type') . ' is not a valid Syslog socket type'
                );
        }
    }

    public static function getSettingsSubForm()
    {
        return SyslogForm::class;
    }

    public static function getLabel()
    {
        return StaticTranslator::get()->translate('Syslog Receiver');
    }

    public static function getDescription()
    {
        return StaticTranslator::get()->translate(
            'Accepts Syslog on either a UDP or a UNIX socket'
        );
    }

    public function run(LoopInterface $loop)
    {
        $this->loop = $loop;
        $this->start();
    }

    public function start()
    {
        if ($this->server) {
            return;
        }

        $this->server = $this->createUnixSocket($this->socket, $this->loop);
        $this->initiateEventHandlers($this->server);
    }

    public function stop()
    {
        if ($this->server) {
            $this->server->close();
            $this->server = null;
        }
    }

    public function pause()
    {
        if ($this->server) {
            $this->server->pause();
        }
    }

    public function resume()
    {
        if ($this->server) {
            $this->server->resume();
        } else {
            $this->start();
        }
    }

    protected function initiateEventHandlers(UnixServer $server)
    {
        $server->on('connection', function (ConnectionInterface $connection) {
            $this->logger->notice('Got a new connection on ' . $this->socket);
            $buffer = new BufferedReader($this->loop);
            $buffer->on('line', function ($line) {
                // echo "< $line";
                if ($line === '') {
                    return;
                }
                if ($line === '-- MARK --') {
                    return;
                }
                try {
                    $event = SyslogParser::parseLine($line);
                    if ($event->object_name !== 'eventtracker'
                        || ! isset($event->attributes->syslog_sender_pid)
                        || $event->attributes->syslog_sender_pid !== posix_getpid()
                    ) {
                        $this->emit('event', [$event]);
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Failed to process '$line': " . $e->getMessage());
                    echo $e->getTraceAsString();
                }
            });
            $connection->pipe($buffer);
            $connection->on('end', function () {
                $this->logger->notice('Connection closed');
            });
        });
        $server->on('error', function ($error) {
            $this->emit('error', [$error]);
        });
    }

    protected function createUnixSocket($uri, $loop)
    {
        if (file_exists($uri)) {
            $this->logger->warning("Removing orphaned socket '$uri'");
            unlink($uri);
        }

        $old = umask(0000);
        $socket = new UnixServer($uri, $loop);
        $this->logger->notice("Listening on '$uri'");
        umask($old);

        return $socket;
    }

    protected function log($message)
    {
        // TODO.
        echo "$message\n";
    }
}
