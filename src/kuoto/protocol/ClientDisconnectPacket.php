<?php

namespace kuoto\protocol;

class ClientDisconnectPacket extends DataPacket
{
    const NETWORK_ID = 0x15;

    public $message = '';

    public function encode()
    {
        $this->reset();
        $this->putString($this->message);
    }

    public function decode()
    {
        $this->message = $this->getString();
    }
}