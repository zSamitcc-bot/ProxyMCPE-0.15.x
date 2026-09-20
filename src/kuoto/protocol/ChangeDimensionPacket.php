<?php

namespace kuoto\protocol;

/**
 * Espejo del ChangeDimensionPacket real de PocketMine (mismo NETWORK_ID y
 * mismo formato binario: byte dimension + 3 floats de posicion + byte final
 * en 0). El proxy lo usa para forzar la pantalla de carga del cliente
 * (RakLibProxy::sendChangeDimension) cuando se traslada un jugador de un
 * backend a otro, asi se tapa el salto en vez de que el jugador vea el
 * mundo viejo congelado mientras el nuevo servidor termina el login.
 */
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
