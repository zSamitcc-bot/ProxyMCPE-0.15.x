<?php

namespace kuoto\event\server;

use kuoto\event\Cancellable;
use kuoto\event\CancellableTrait;
use kuoto\network\ClientConnection;

/**
 * Se lanza cuando un backend envia su ConnectPacket, ANTES de comprobar
 * protocolo y contrasena.
 *
 * Cancelarlo rechaza la conexion con el mensaje de setKickMessage().
 */
class ServerConnectEvent extends ServerEvent implements Cancellable
{
    use CancellableTrait;

    /** @var int */
    private $protocol;
    /** @var int */
    private $maxPlayers;
    /** @var bool */
    private $mainServer;
    /** @var string */
    private $description;
    /** @var string */
    private $kickMessage = 'Conexion rechazada por el proxy';

    /**
     * @param ClientConnection $server
     * @param int $protocol
     * @param int $maxPlayers
     * @param bool $mainServer
     * @param string $description
     */
    public function __construct(ClientConnection $server, $protocol, $maxPlayers, $mainServer, $description)
    {
        parent::__construct($server);
        $this->protocol = $protocol;
        $this->maxPlayers = $maxPlayers;
        $this->mainServer = (bool) $mainServer;
        $this->description = $description;
    }

    /** @return int */
    public function getProtocol()
    {
        return $this->protocol;
    }

    /** @return int */
    public function getMaxPlayers()
    {
        return $this->maxPlayers;
    }

    /** @return bool */
    public function isMainServer()
    {
        return $this->mainServer;
    }

    /** @return string */
    public function getDescription()
    {
        return $this->description;
    }

    /** @return string */
    public function getKickMessage()
    {
        return $this->kickMessage;
    }

    /** @param string $message */
    public function setKickMessage($message)
    {
        $this->kickMessage = $message;
    }
}
