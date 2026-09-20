<?php

namespace kuoto\protocol;

class ChangeDimensionPacket extends DataPacket
{
    const NETWORK_ID = 0x36;

    const DIMENSION_NORMAL = 0;
    const DIMENSION_NETHER = 1;

    public $dimension;

    public $x;
    public $y;
    public $z;

    public function encode()
    {
        $this->reset();
        $this->putByte($this->dimension);
        $this->putFloat($this->x);
        $this->putFloat($this->y);
        $this->putFloat($this->z);
        $this->putByte(0);
    }

    public function decode()
    {
        $this->dimension = $this->getByte();
        $this->x = $this->getFloat();
        $this->y = $this->getFloat();
        $this->z = $this->getFloat();
        $this->getByte();
    }
}
