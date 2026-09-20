<?php

namespace kuoto\protocol;

class TransferPacket extends DataPacket
{
    const NETWORK_ID = Info::TRANSFER_PACKET;

    /** @var string */
    public $uuid = '';
    /** @var string */
    public $clientHash = '';

    public function encode()
    {
        $this->reset();
        $this->putUUID($this->uuid);
        $this->putString($this->clientHash);
    }

    public function decode()
    {
        $this->uuid = $this->getUUID();
        $this->clientHash = $this->getString();
    }
}
