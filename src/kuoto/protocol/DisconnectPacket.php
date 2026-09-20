<?php

namespace kuoto\protocol;

class DisconnectPacket extends DataPacket
{
    const NETWORK_ID = Info::DISCONNECT_PACKET;

    const TYPE_WRONG_PROTOCOL = 0;
    const TYPE_GENERIC = 1;

    /** @var int */
    public $type = self::TYPE_GENERIC;
    /** @var string */
    public $message = '';

    public function encode()
    {
        $this->reset();
        $this->putByte($this->type);
        $this->putString($this->message);
    }

    public function decode()
    {
        $this->type = $this->getByte();
        $this->message = $this->getString();
    }
}
