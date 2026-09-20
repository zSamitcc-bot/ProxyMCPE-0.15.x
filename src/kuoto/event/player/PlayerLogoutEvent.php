<?php

namespace kuoto\event\player;

use kuoto\network\ClientConnection;

/**
 * Se lanza cuando un jugador sale de un backend. No es cancelable: la salida
 * ya ha ocurrido y el proxy tiene que limpiar su estado si o si.
 */
class PlayerLogoutEvent extends PlayerEvent
{
    /** @var ClientConnection */
    private $server;
    /** @var string */
    private $reason;

    /**
     * @param string $uuid binario
     * @param ClientConnection $server
     * @param string $reason
     */
    public function __construct($uuid, ClientConnection $server, $reason)
    {
        parent::__construct($uuid);
        $this->server = $server;
        $this->reason = $reason;
    }

    /** @return ClientConnection */
    public function getServer()
    {
        return $this->server;
    }

    /** @return string */
    public function getReason()
    {
        return $this->reason;
    }
}
