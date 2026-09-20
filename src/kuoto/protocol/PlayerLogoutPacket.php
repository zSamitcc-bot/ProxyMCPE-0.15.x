<?php

namespace kuoto\protocol;

class PlayerLogoutPacket extends DataPacket
{
    const NETWORK_ID = Info::PLAYER_LOGOUT_PACKET;

    /** @var string */
    public $uuid = '';
    /** @var string */
    public $reason = '';

    public function encode()
    {
        $this->reset();
        $this->putUUID($this->uuid);
        $this->putString($this->reason);
    }

    public function decode()
    {
        $this->uuid = $this->getUUID();
        $this->reason = $this->getString();
    }
}
