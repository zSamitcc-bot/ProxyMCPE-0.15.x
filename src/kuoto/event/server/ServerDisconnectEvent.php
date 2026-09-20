<?php

namespace kuoto\event\server;

use kuoto\network\ClientConnection;

/**
 * Se lanza cuando un backend se desconecta, por la razon que sea (timeout,
 * error de socket, DisconnectPacket o apagado del proxy).
 */
class ServerDisconnectEvent extends ServerEvent
{
    /** @var string */
    private $reason;

    /**
     * @param ClientConnection $server
     * @param string $reason
     */
    public function __construct(ClientConnection $server, $reason)
    {
        parent::__construct($server);
        $this->reason = $reason;
    }

    /** @return string */
    public function getReason()
    {
        return $this->reason;
    }
}
