<?php

namespace kuoto\protocol;

class FastPlayerListPacket extends DataPacket
{
    const NETWORK_ID = Info::FAST_PLAYER_LIST_PACKET;

    const TYPE_ADD = 0;
    const TYPE_REMOVE = 1;

    /** @var string */
    public $sendTo = '';
    /** @var int */
    public $type = self::TYPE_ADD;
    /** @var array */
    public $entries = array();

    public function encode()
    {
        $this->reset();
        $this->putUUID($this->sendTo);
        $this->putByte($this->type);
        $this->putInt(count($this->entries));
        foreach ($this->entries as $d) {
            if ($this->type === self::TYPE_ADD) {
                $this->putUUID($d[0]);
                $this->putLong($d[1]);
                $this->putString($d[2]);
            } else {
                $this->putUUID($d[0]);
            }
        }
    }

    public function decode()
    {
        // Not used server-side
    }
}
