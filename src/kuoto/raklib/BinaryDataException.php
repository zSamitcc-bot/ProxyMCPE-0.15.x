<?php

namespace kuoto\raklib;

/**
 * Se lanza cuando un BinaryStream intenta leer mas bytes de los que quedan
 * en el buffer. Antes, en DataPacket, esto simplemente generaba un notice de
 * PHP ("Uninitialized string offset") y seguia leyendo basura (0x00) en
 * silencio -- por ejemplo un TransferPacket truncado terminaba decodificando
 * un uuid/clientHash incorrecto en vez de fallar de forma clara.
 */
class BinaryDataException extends \RuntimeException
{
}
