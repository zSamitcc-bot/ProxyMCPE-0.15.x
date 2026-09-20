<?php

namespace kuoto\event\player;

use kuoto\event\Cancellable;
use kuoto\event\CancellableTrait;

/**
 * Se lanza en el proxy (RakLibProxy) cuando un jugador termina el handshake
 * RakNet y ya se ha leido su paquete de login, ANTES de elegirle un backend.
 *
 * Es el sitio correcto para whitelist, baneos por IP o mantenimiento:
 * cancelarlo impide que el jugador llegue a entrar a ningun servidor.
 */
class PlayerPreLoginEvent extends PlayerEvent implements Cancellable
{
    use CancellableTrait;

    /** @var string */
    private $address;
    /** @var int */
    private $port;
    /** @var string */
    private $kickMessage = 'No puedes entrar en este momento';

    /**
     * @param string $uuid binario
     * @param string $address
     * @param int $port
     */
    public function __construct($uuid, $address, $port)
    {
        parent::__construct($uuid);
        $this->address = $address;
        $this->port = $port;
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
