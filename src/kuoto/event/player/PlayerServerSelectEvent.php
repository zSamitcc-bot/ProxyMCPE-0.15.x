<?php

namespace kuoto\event\player;

use kuoto\event\Cancellable;
use kuoto\event\CancellableTrait;
use kuoto\network\ClientConnection;

/**
 * Se lanza justo despues de que el balanceador elija un backend para un
 * jugador nuevo y ANTES de enviarle el PlayerLoginPacket.
 *
 * Permite sobreescribir la eleccion (setTargetServer) para implementar
 * lobbies, colas, rangos VIP, etc. Cancelarlo deja al jugador sin servidor.
 */
class PlayerServerSelectEvent extends PlayerEvent implements Cancellable
{
    use CancellableTrait;

    /** @var ClientConnection */
    private $targetServer;
    /** @var ClientConnection[] */
    private $availableServers;
    /** @var string */
    private $address;
    /** @var int */
    private $port;

    /**
     * @param string $uuid binario
     * @param string $address
     * @param int $port
     * @param ClientConnection $targetServer eleccion por defecto del balanceador
     * @param ClientConnection[] $availableServers
     */
    public function __construct($uuid, $address, $port, ClientConnection $targetServer, array $availableServers)
    {
        parent::__construct($uuid);
        $this->address = $address;
        $this->port = $port;
        $this->targetServer = $targetServer;
        $this->availableServers = $availableServers;
    }

    /** @return ClientConnection */
    public function getTargetServer()
    {
        return $this->targetServer;
    }

    /** @param ClientConnection $server */
    public function setTargetServer(ClientConnection $server)
    {
        $this->targetServer = $server;
    }

    /** @return ClientConnection[] */
    public function getAvailableServers()
    {
        return $this->availableServers;
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
}
