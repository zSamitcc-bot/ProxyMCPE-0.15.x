<?php

namespace kuoto\raklib;

/**
 * Envoltorio con estado (buffer + offset) sobre Binary. Es la misma idea que
 * raklib\utils\BinaryStream / pocketmine\utils\BinaryStream: en vez de andar
 * pasando ($data, $offset) a todas las funciones estaticas, el stream lleva
 * su propio cursor y comprueba que no se lea mas alla del buffer.
 *
 * kuoto\protocol\DataPacket extiende esta clase.
 */
class BinaryStream
{
    /** @var string */
    protected $buffer;

    /** @var int */
    protected $offset;

    public function __construct($buffer = '', $offset = 0)
    {
        $this->buffer = $buffer;
        $this->offset = $offset;
    }

    public function getBuffer()
    {
        return $this->buffer;
    }

    public function setBuffer($buffer, $offset = 0)
    {
        $this->buffer = $buffer;
        $this->offset = $offset;
    }

    public function getOffset()
    {
        return $this->offset;
    }

    public function setOffset($offset)
    {
        $this->offset = $offset;
    }

    public function rewind()
    {
        $this->offset = 0;
    }

    /** @return bool true si no quedan bytes por leer */
    public function feof()
    {
        return !isset($this->buffer[$this->offset]);
    }

    /** @return int bytes que quedan por leer */
    public function getRemaining()
    {
        return max(0, strlen($this->buffer) - $this->offset);
    }

    /**
     * Lee $len bytes crudos, avanzando el cursor.
     *
     * @throws BinaryDataException si no quedan $len bytes en el buffer
     */
    public function get($len)
    {
        if ($len < 0) {
            $this->offset = strlen($this->buffer) - 1;
            return '';
        }

        if ($len === 0) {
            return '';
        }

        if ($this->getRemaining() < $len) {
            throw new BinaryDataException(
                "No se pueden leer {$len} bytes: solo quedan {$this->getRemaining()} en el buffer"
            );
        }

        $bytes = substr($this->buffer, $this->offset, $len);
        $this->offset += $len;
        return $bytes;
    }

    /** Devuelve y consume todo lo que queda en el buffer. */
    public function getRemainingBytes()
    {
        $bytes = substr($this->buffer, $this->offset);
        $this->offset = strlen($this->buffer);
        return $bytes;
    }

    public function put($str)
    {
        $this->buffer .= $str;
    }

    // ---- byte ----

    public function getByte()
    {
        return Binary::unsignByte($this->get(1));
    }

    public function getSignedByte()
    {
        return Binary::signByte($this->get(1));
    }

    public function putByte($value)
    {
        $this->buffer .= Binary::writeByte($value);
    }

    // ---- bool ----

    public function getBool()
    {
        return $this->getByte() !== 0;
    }

    public function putBool($value)
    {
        $this->putByte($value ? 1 : 0);
    }

    // ---- short (16 bits) ----

    public function getShort()
    {
        return Binary::readShort($this->get(2));
    }

    public function getSignedShort()
    {
        return Binary::readSignedShort($this->get(2));
    }

    public function putShort($value)
    {
        $this->buffer .= Binary::writeShort($value);
    }

    public function getLShort()
    {
        return Binary::readLShort($this->get(2));
    }

    public function getSignedLShort()
    {
        return Binary::readSignedLShort($this->get(2));
    }

    public function putLShort($value)
    {
        $this->buffer .= Binary::writeLShort($value);
    }

    // ---- triad (24 bits) ----

    public function getTriad()
    {
        return Binary::readTriad($this->get(3));
    }

    public function putTriad($value)
    {
        $this->buffer .= Binary::writeTriad($value);
    }

    public function getLTriad()
    {
        return Binary::readLTriad($this->get(3));
    }

    public function putLTriad($value)
    {
        $this->buffer .= Binary::writeLTriad($value);
    }

    // ---- int (32 bits) ----

    public function getInt()
    {
        return Binary::readInt($this->get(4));
    }

    public function putInt($value)
    {
        $this->buffer .= Binary::writeInt($value);
    }

    public function getLInt()
    {
        return Binary::readLInt($this->get(4));
    }

    public function putLInt($value)
    {
        $this->buffer .= Binary::writeLInt($value);
    }

    // ---- long (64 bits) ----

    public function getLong()
    {
        return Binary::readLong($this->get(8));
    }

    public function putLong($value)
    {
        $this->buffer .= Binary::writeLong($value);
    }

    public function getLLong()
    {
        return Binary::readLLong($this->get(8));
    }

    public function putLLong($value)
    {
        $this->buffer .= Binary::writeLLong($value);
    }

    // ---- float / double ----

    public function getFloat()
    {
        return Binary::readFloat($this->get(4));
    }

    public function putFloat($value)
    {
        $this->buffer .= Binary::writeFloat($value);
    }

    public function getLFloat()
    {
        return Binary::readLFloat($this->get(4));
    }

    public function putLFloat($value)
    {
        $this->buffer .= Binary::writeLFloat($value);
    }

    public function getDouble()
    {
        return Binary::readDouble($this->get(8));
    }

    public function putDouble($value)
    {
        $this->buffer .= Binary::writeDouble($value);
    }

    public function getLDouble()
    {
        return Binary::readLDouble($this->get(8));
    }

    public function putLDouble($value)
    {
        $this->buffer .= Binary::writeLDouble($value);
    }

    // ---- string / uuid ----

    /** String precedida de un short (16 bits) con su longitud. */
    public function getString()
    {
        $length = $this->getShort();
        return $this->get($length);
    }

    public function putString($value)
    {
        $this->putShort(strlen($value));
        $this->buffer .= $value;
    }

    /** UUID crudo de 16 bytes (sin formatear). */
    public function getUUID()
    {
        return $this->get(16);
    }

    public function putUUID($uuid)
    {
        $this->buffer .= $uuid;
    }
}
