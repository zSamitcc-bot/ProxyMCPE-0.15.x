<?php

namespace kuoto\event\server;

use kuoto\event\Event;
use kuoto\network\ClientConnection;

/**
 * Base de todos los eventos relacionados con un servidor backend (PocketMine)
 * conectado al proxy. Escuchar esta clase recibe TODOS sus hijos.
 */
abstract class ServerEvent extends Event
{
    /** @var ClientConnection */
    protected $server;

    /** @param ClientConnection $server */
    public function __construct(ClientConnection $server)
    {
        $this->server = $server;
    }

    /** @return ClientConnection */
    public function getServer()
    {
        return $this->server;
    }

    /** @return string ip:puerto del backend */
    public function getHash()
    {
        return $this->server->getHash();
    }
}
