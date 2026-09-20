<?php

namespace kuoto\raklib;

/**
 * Helpers binarios estilo raklib/pocketmine.
 *
 * Antes esta logica estaba duplicada y con bugs en dos sitios distintos:
 *  - kuoto\protocol\DataPacket (putLong/getLong hacian el split de 64 bits a
 *    mano y sin comprobar limites del buffer)
 *  - kuoto\network\RakLibProxy (seccion "BINARY HELPERS": packLong/unpackLong
 *    y readLTriad/writeLTriad), donde packLong() ni siquiera enmascaraba la
 *    mitad alta antes de meterla en pack('N', ...).
 *
 * Todo eso vive ahora aqui, en un solo sitio, usando los formatos nativos de
 * 64 bits de PHP (J/P), que existen desde PHP 5.6.3 y evitan por completo el
 * shifting manual (y sus bugs).
 */
class Binary
{
    /**
     * @param string $bytes
     * @return int
     */
    public static function signByte($bytes)
    {
        $value = ord($bytes);
        return $value >= 128 ? $value - 256 : $value;
    }

    public static function unsignByte($bytes)
    {
        return ord($bytes) & 0xff;
    }

    /** @return int -128..127 */
    public static function readByte($str, $offset = 0)
    {
        return self::signByte($str[$offset]);
    }

    /** @return int 0..255 */
    public static function readUnsignedByte($str, $offset = 0)
    {
        return self::unsignByte($str[$offset]);
    }

    /** @return string */
    public static function writeByte($value)
    {
        return chr($value & 0xff);
    }

    /** Big-endian, unsigned. */
    public static function readShort($str, $offset = 0)
    {
        return unpack('n', substr($str, $offset, 2))[1];
    }

    /** Big-endian, signed. */
    public static function readSignedShort($str, $offset = 0)
    {
        $value = self::readShort($str, $offset);
        return $value >= 32768 ? $value - 65536 : $value;
    }

    public static function writeShort($value)
    {
        return pack('n', $value & 0xffff);
    }

    /** Little-endian, unsigned. */
    public static function readLShort($str, $offset = 0)
    {
        return unpack('v', substr($str, $offset, 2))[1];
    }

    public static function readSignedLShort($str, $offset = 0)
    {
        $value = self::readLShort($str, $offset);
        return $value >= 32768 ? $value - 65536 : $value;
    }

    public static function writeLShort($value)
    {
        return pack('v', $value & 0xffff);
    }

    /**
     * Triad de 24 bits, big-endian (formato "plano" de raklib, distinto del
     * usado en el wire de los datagramas RakNet, que va en little-endian:
     * usar readLTriad/writeLTriad para eso).
     */
    public static function readTriad($str, $offset = 0)
    {
        return (self::unsignByte($str[$offset]) << 16)
            | (self::unsignByte($str[$offset + 1]) << 8)
            | self::unsignByte($str[$offset + 2]);
    }

    public static function writeTriad($value)
    {
        return chr(($value >> 16) & 0xff) . chr(($value >> 8) & 0xff) . chr($value & 0xff);
    }

    /**
     * Triad de 24 bits, little-endian. Es el formato real que usa RakNet en
     * datagramas (message index / order index / sequence numbers de ACK-NACK).
     */
    public static function readLTriad($str, $offset = 0)
    {
        return self::unsignByte($str[$offset])
            | (self::unsignByte($str[$offset + 1]) << 8)
            | (self::unsignByte($str[$offset + 2]) << 16);
    }

    public static function writeLTriad($value)
    {
        return chr($value & 0xff) . chr(($value >> 8) & 0xff) . chr(($value >> 16) & 0xff);
    }

    public static function readInt($str, $offset = 0)
    {
        $value = unpack('N', substr($str, $offset, 4))[1];
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public static function writeInt($value)
    {
        return pack('N', $value);
    }

    public static function readLInt($str, $offset = 0)
    {
        $value = unpack('V', substr($str, $offset, 4))[1];
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public static function writeLInt($value)
    {
        return pack('V', $value);
    }

    public static function readFloat($str, $offset = 0)
    {
        return unpack('f', strrev(substr($str, $offset, 4)))[1];
    }

    public static function writeFloat($value)
    {
        return strrev(pack('f', $value));
    }

    public static function readLFloat($str, $offset = 0)
    {
        return unpack('f', substr($str, $offset, 4))[1];
    }

    public static function writeLFloat($value)
    {
        return pack('f', $value);
    }

    public static function readDouble($str, $offset = 0)
    {
        return unpack('d', strrev(substr($str, $offset, 8)))[1];
    }

    public static function writeDouble($value)
    {
        return strrev(pack('d', $value));
    }

    public static function readLDouble($str, $offset = 0)
    {
        return unpack('d', substr($str, $offset, 8))[1];
    }

    public static function writeLDouble($value)
    {
        return pack('d', $value);
    }

    /**
     * 64 bits, big-endian.
     *
     * OJO: aqui NO se puede usar pack('J', ...)/unpack('P', ...) (los
     * formatos nativos de 64 bits de PHP): en un build de PHP de 32 bits
     * (como el php.exe de 32 bits que trae este proyecto para Windows) esos
     * format codes directamente no existen y pack()/unpack() lanzan un
     * warning y devuelven false -- por eso el long, en la practica,
     * quedaba corrupto para cualquiera que corriera el server con ese PHP.
     *
     * En vez de eso se parte el valor en dos mitades de 32 bits a mano.
     * Clave para que no se pierda precision: la mitad alta se trata como
     * un entero CON SIGNO de 32 bits (igual que en complemento a dos), no
     * como un numero sin signo de hasta 2^32-1. Multiplicar un numero
     * pequeno con signo (p.ej. -1 o 5) por 2^32 es siempre exacto en un
     * float de doble precision; multiplicar 0xFFFFFFFF (unsigned) por 2^32
     * ya no lo es, porque el resultado necesita mas de 53 bits para
     * representarse exacto. Esto es lo que rompia el redondeo de valores
     * negativos pequenos (timestamps/ping, etc.) en la primera version.
     */
    public static function readLong($str, $offset = 0)
    {
        if (self::has64BitPack()) {
            return unpack('J', substr($str, $offset, 8))[1];
        }

        $high = self::unpackUint32(substr($str, $offset, 4));
        if ($high >= 2147483648.0) { // 2^31: bit de signo puesto
            $high -= 4294967296.0; // 2^32
        }
        $low = self::unpackUint32(substr($str, $offset + 4, 4));

        return self::normalizeInt($high * 4294967296.0 + $low);
    }

    public static function writeLong($value)
    {
        if (self::has64BitPack()) {
            return pack('J', $value);
        }

        $value = (float) $value;
        $high = floor($value / 4294967296.0);
        $low = $value - $high * 4294967296.0; // siempre cae en 0..2^32-1

        return self::packUint32($high) . self::packUint32($low);
    }

    /** 64 bits, little-endian (formato real de los GUID/ping de RakNet). */
    public static function readLLong($str, $offset = 0)
    {
        if (self::has64BitPack()) {
            return unpack('P', substr($str, $offset, 8))[1];
        }

        $low = self::unpackUint32(strrev(substr($str, $offset, 4)));
        $high = self::unpackUint32(strrev(substr($str, $offset + 4, 4)));
        if ($high >= 2147483648.0) {
            $high -= 4294967296.0;
        }

        return self::normalizeInt($high * 4294967296.0 + $low);
    }

    public static function writeLLong($value)
    {
        if (self::has64BitPack()) {
            return pack('P', $value);
        }

        $value = (float) $value;
        $high = floor($value / 4294967296.0);
        $low = $value - $high * 4294967296.0;

        return strrev(self::packUint32($low)) . strrev(self::packUint32($high));
    }

    /**
     * true si esta build de PHP soporta los format codes nativos de 64 bits
     * de pack()/unpack() ('J'/'P', desde PHP 5.6.3). En la practica esto
     * coincide siempre con PHP_INT_SIZE === 8 (build de 64 bits): en un PHP
     * de 32 bits (p.ej. el php.exe de 32 bits para Windows que trae este
     * proyecto) esos format codes no existen y pack()/unpack() lanzan un
     * warning y devuelven false, dejando el long corrupto -- por eso solo
     * se usan aqui cuando sabemos que van a funcionar; si no, se cae al
     * metodo portable de mas abajo (partir el valor a mano en dos mitades
     * de 32 bits).
     */
    private static function has64BitPack()
    {
        return PHP_INT_SIZE === 8;
    }

    /**
     * Empaqueta un valor de 32 bits (puede venir con signo, o como float si
     * viene de partir un long de 64) en 4 bytes big-endian, sin pasar por
     * pack('N', ...): un float fuera de rango de int de 32 bits produce
     * resultados no definidos al convertirlo dentro de pack() en un build
     * de PHP de 32 bits.
     */
    private static function packUint32($value)
    {
        $value = fmod((float) $value, 4294967296.0);
        if ($value < 0) {
            $value += 4294967296.0;
        }

        return chr((int) fmod(floor($value / 16777216), 256))
            . chr((int) fmod(floor($value / 65536), 256))
            . chr((int) fmod(floor($value / 256), 256))
            . chr((int) fmod($value, 256));
    }

    /** Lee 4 bytes big-endian como entero sin signo (int o float si no cabe). */
    private static function unpackUint32($bytes)
    {
        return (self::unsignByte($bytes[0]) * 16777216)
            + (self::unsignByte($bytes[1]) * 65536)
            + (self::unsignByte($bytes[2]) * 256)
            + self::unsignByte($bytes[3]);
    }

    /**
     * Si el valor cabe en un int nativo de esta build de PHP (64 bits: casi
     * siempre; 32 bits: solo valores pequenos) se devuelve como int, igual
     * que antes. Si no cabe, se devuelve como float -- sigue sirviendo para
     * comparar por igualdad (GUIDs, uuids) aunque no para aritmetica exacta,
     * que es la unica opcion real en un PHP de 32 bits para un numero de 64
     * bits.
     */
    private static function normalizeInt($value)
    {
        if ($value >= PHP_INT_MIN && $value <= PHP_INT_MAX) {
            $asInt = (int) $value;
            if ((float) $asInt === $value) {
                return $asInt;
            }
        }

        return $value;
    }
}