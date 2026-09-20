<?php

namespace kuoto\event\player;

use kuoto\event\Cancellable;
use kuoto\event\CancellableTrait;
use kuoto\network\ClientConnection;

/**
 * Se lanza cuando un backend confirma el login de un jugador
 * (PlayerLoginPacket recibido desde PocketMine).
 *
 * Cancelarlo evita que el login se propague al resto de servidores.
 */
class PlayerLoginEvent extends PlayerEvent implements Cancellable
{
    use CancellableTrait;

    /** @var ClientConnection */
    private $server;
    /** @var string */
    private $address;
    /** @var int */
    private $port;
    /** @var bool */
    private $firstTime;

    /**
     * @param string $uuid binario
     * @param ClientConnection $server
     * @param string $address
     * @param int $port
     * @param bool $firstTime
     */
    public function __construct($uuid, ClientConnection $server, $address, $port, $firstTime)
    {
        parent::__construct($uuid);
        $this->server = $server;
        $this->address = $address;
        $this->port = $port;
        $this->firstTime = (bool) $firstTime;
    }

    /** @return ClientConnection */
    public function getServer()
    {
        return $this->server;
    }

    /** @return string */
    public function getAddress()
    {
        return $this->address;
    }

    /** @return int */
    public function getPort()
    {
        return $this->port;
    }

    /** @return bool */
    public function isFirstTime()
    {
        return $this->firstTime;
    }
}
