<?php

namespace kuoto\protocol;

class HeartbeatPacket extends DataPacket
{
    const NETWORK_ID = Info::HEARTBEAT_PACKET;

    /** @var float */
    public $tps = 20.0;
    /** @var float */
    public $load = 0.0;
    /** @var int */
    public $upTime = 0;

    public function encode()
    {
        $this->reset();
        $this->putFloat($this->tps);
        $this->putFloat($this->load);
        $this->putLong($this->upTime);
    }

    public function decode()
    {
        $this->tps = $this->getFloat();
        $this->load = $this->getFloat();
        $this->upTime = $this->getLong();
    }
}
