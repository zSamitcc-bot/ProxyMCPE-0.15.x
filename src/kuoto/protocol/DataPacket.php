<?php

namespace kuoto\protocol;

use kuoto\raklib\BinaryStream;

/**
 * Antes esta clase reimplementaba a mano todas las lecturas/escrituras
 * binarias (putByte, putLong, getUUID...) sin comprobar limites del buffer.
 * El bug real estaba en putLong()/getLong(): partian el valor de 64 bits en
 * dos mitades de 32 bits a mano y sin enmascarar bien la mitad alta, y
 * getByte()/getString() podian leer offsets fuera del buffer sin avisar
 * (un notice de PHP silencioso), devolviendo datos corruptos en vez de un
 * error claro -- por ejemplo un TransferPacket truncado terminaba con un
 * uuid/clientHash mal decodificado en lugar de fallar.
 *
 * Ahora todo eso vive en kuoto\raklib\BinaryStream (que a su vez usa
 * kuoto\raklib\Binary para los formatos de bajo nivel), asi que DataPacket
 * solo se encarga de anteponer/leer el id del paquete.
 */
abstract class DataPacket extends BinaryStream
{
    const NETWORK_ID = 0;

    abstract public function encode();
    abstract public function decode();

    protected function reset()
    {
        $this->buffer = chr(static::NETWORK_ID & 0xff);
        $this->offset = 1;
    }
}
