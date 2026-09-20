<?php

namespace kuoto\protocol;

class PlayerLoginPacket extends DataPacket
{
    const NETWORK_ID = Info::PLAYER_LOGIN_PACKET;

    /** @var string */
    public $uuid = '';
    /** @var string */
    public $address = '';
    /** @var int */
    public $port = 0;
    /** @var bool */
    public $isFirstTime = false;
    /** @var string */
    public $cachedLoginPacket = '';

    public function encode()
    {
        $this->reset();
        $this->putUUID($this->uuid);
        $this->putString($this->address);
        $this->putInt($this->port);
        $this->putByte($this->isFirstTime ? 1 : 0);
        $this->putString($this->cachedLoginPacket);
    }

    public function decode()
    {
        $this->uuid = $this->getUUID();
        $this->address = $this->getString();
        $this->port = $this->getInt();
        $this->isFirstTime = ($this->getByte() === 1);
        $this->cachedLoginPacket = $this->getString();
    }
}
