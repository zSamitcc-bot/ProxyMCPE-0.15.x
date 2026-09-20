<?php

namespace kuoto\event\server;

use kuoto\network\ClientConnection;

/**
 * Se lanza cada vez que un backend reporta su estado (TPS, carga y uptime).
 * Util para balanceo de carga o para alertas de lag.
 */
class ServerHeartbeatEvent extends ServerEvent
{
    /** @var float */
    private $tps;
    /** @var float */
    private $load;
    /** @var int */
    private $upTime;

    /**
     * @param ClientConnection $server
     * @param float $tps
     * @param float $load
     * @param int $upTime
     */
    public function __construct(ClientConnection $server, $tps, $load, $upTime)
    {
        parent::__construct($server);
        $this->tps = $tps;
        $this->load = $load;
        $this->upTime = $upTime;
    }

    /** @return float */
    public function getTps()
    {
        return $this->tps;
    }

    /** @return float */
    public function getLoad()
    {
        return $this->load;
    }

    /** @return int */
    public function getUpTime()
    {
        return $this->upTime;
    }
}
