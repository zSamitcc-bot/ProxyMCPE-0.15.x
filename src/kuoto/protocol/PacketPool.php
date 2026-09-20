<?php

namespace kuoto\protocol;

/**
 * Registro de paquetes del protocolo Synapse.
 *
 * Antes ClientConnection tenia un switch con 10 casos para instanciar el
 * paquete y ademas OTRO switch identico para despacharlo, asi que anadir un
 * paquete nuevo obligaba a tocar tres sitios (Info, createPacket y
 * handlePacket). Ahora el mapa id => clase vive solo aqui.
 */
abstract class PacketPool
{
    /** @var string[] networkId => nombre de clase */
    private static $pool = null;

    private static function init()
    {
        if (self::$pool !== null) {
            return;
        }

        self::$pool = array(
            Info::CONNECT_PACKET          => ConnectPacket::class,
            Info::HEARTBEAT_PACKET        => HeartbeatPacket::class,
            Info::DISCONNECT_PACKET       => DisconnectPacket::class,
            Info::REDIRECT_PACKET         => RedirectPacket::class,
            Info::PLAYER_LOGIN_PACKET     => PlayerLoginPacket::class,
            Info::PLAYER_LOGOUT_PACKET    => PlayerLogoutPacket::class,
            Info::INFORMATION_PACKET      => InformationPacket::class,
            Info::TRANSFER_PACKET         => TransferPacket::class,
            Info::BROADCAST_PACKET        => BroadcastPacket::class,
            Info::FAST_PLAYER_LIST_PACKET => FastPlayerListPacket::class,
        );
    }

    /**
     * Registra (o sustituye) el paquete asociado a un id. Permite que un
     * listener anada paquetes propios sin tocar el nucleo.
     *
     * @param int $id
     * @param string $className
     */
    public static function register($id, $className)
    {
        self::init();
        if (!is_subclass_of($className, DataPacket::class)) {
            throw new \InvalidArgumentException("{$className} no extiende DataPacket");
        }
        self::$pool[$id] = $className;
    }

    /**
     * Crea una instancia vacia del paquete con ese id.
     *
     * @param int $id
     * @return DataPacket|null null si el id es desconocido
     */
    public static function create($id)
    {
        self::init();
        if (!isset(self::$pool[$id])) {
            return null;
        }
        $class = self::$pool[$id];
        return new $class();
    }

    /**
     * Crea y decodifica un paquete a partir de su buffer crudo (el primer byte
     * es el id).
     *
     * @param string $buffer
     * @return DataPacket|null
     */
    public static function decode($buffer)
    {
        if ($buffer === '') {
            return null;
        }

        $packet = self::create(ord($buffer[0]));
        if ($packet === null) {
            return null;
        }

        $packet->setBuffer($buffer, 1);
        $packet->decode();
        return $packet;
    }

    /** @return string[] */
    public static function getAll()
    {
        self::init();
        return self::$pool;
    }
}
