<?php

namespace kuoto\protocol;

class BroadcastPacket extends DataPacket
{
    const NETWORK_ID = Info::BROADCAST_PACKET;

    /** @var string[] */
    public $entries = array();
    /** @var bool */
    public $direct = false;
    /** @var string */
    public $payload = '';

    public function encode()
    {
        $this->reset();
        $this->putByte($this->direct ? 1 : 0);
        $this->putShort(count($this->entries));
        foreach ($this->entries as $uuid) {
            $this->putUUID($uuid);
        }
        $this->putString($this->payload);
    }

    public function decode()
    {
        $this->direct = ($this->getByte() === 1);
        $count = $this->getShort();
        $this->entries = array();
        for ($i = 0; $i < $count; $i++) {
            $this->entries[] = $this->getUUID();
        }
        $this->payload = $this->getString();
    }
}
