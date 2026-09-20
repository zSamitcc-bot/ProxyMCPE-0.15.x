<?php

namespace kuoto\protocol;

class ConnectPacket extends DataPacket
{
    const NETWORK_ID = Info::CONNECT_PACKET;

    /** @var int */
    public $protocol = Info::CURRENT_PROTOCOL;
    /** @var int */
    public $maxPlayers = 0;
    /** @var bool */
    public $isMainServer = false;
    /** @var string */
    public $description = '';
    /** @var string */
    public $password = '';

    public function encode()
    {
        $this->reset();
        $this->putInt($this->protocol);
        $this->putInt($this->maxPlayers);
        $this->putByte($this->isMainServer ? 1 : 0);
        $this->putString($this->description);
        $this->putString($this->password);
    }

    public function decode()
    {
        $this->protocol = $this->getInt();
        $this->maxPlayers = $this->getInt();
        $this->isMainServer = ($this->getByte() === 1);
        $this->description = $this->getString();
        $this->password = $this->getString();
    }
}
