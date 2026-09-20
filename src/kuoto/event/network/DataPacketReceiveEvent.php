<?php

namespace kuoto\event\network;

use kuoto\event\Cancellable;
use kuoto\event\CancellableTrait;
use kuoto\event\Event;
use kuoto\network\ClientConnection;
use kuoto\protocol\DataPacket;

/**
 * Se lanza para CADA paquete del protocolo Synapse recibido desde un backend,
 * despues de decodificarlo y antes de procesarlo.
 *
 * Cancelarlo descarta el paquete (el proxy no ejecuta su handler).
 *
 * Ojo: esto se dispara muy a menudo (RedirectPacket incluido). Un handler
 * pesado aqui se nota en el TPS del proxy.
 */
class DataPacketReceiveEvent extends Event implements Cancellable
{
    use CancellableTrait;

    /** @var ClientConnection */
    private $origin;
    /** @var DataPacket */
    private $packet;

    /**
     * @param ClientConnection $origin
     * @param DataPacket $packet
     */
    public function __construct(ClientConnection $origin, DataPacket $packet)
    {
        $this->origin = $origin;
        $this->packet = $packet;
    }

    /** @return ClientConnection */
    public function getOrigin()
    {
        return $this->origin;
    }

    /** @return DataPacket */
    public function getPacket()
    {
        return $this->packet;
    }
}
