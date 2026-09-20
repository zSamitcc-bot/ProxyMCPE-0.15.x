<?php

namespace kuoto\protocol;

class RedirectPacket extends DataPacket
{
    const NETWORK_ID = Info::REDIRECT_PACKET;

    /** @var string */
    public $uuid = '';
    /** @var bool */
    public $direct = false;
    /** @var string */
    public $mcpeBuffer = '';

    public function encode()
    {
        $this->reset();
        $this->putUUID($this->uuid);
        $this->putByte($this->direct ? 1 : 0);
        $this->putString($this->mcpeBuffer);
    }

    public function decode()
    {
        $this->uuid = $this->getUUID();
        $this->direct = ($this->getByte() === 1);
        $this->mcpeBuffer = $this->getString();
    }
}
