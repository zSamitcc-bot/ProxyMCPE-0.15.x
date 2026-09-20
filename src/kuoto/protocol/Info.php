<?php

namespace kuoto\protocol;

class Info
{
    const CURRENT_PROTOCOL = 6;

    const HEARTBEAT_PACKET       = 0x01;
    const CONNECT_PACKET         = 0x02;
    const DISCONNECT_PACKET      = 0x03;
    const REDIRECT_PACKET        = 0x04;
    const PLAYER_LOGIN_PACKET    = 0x05;
    const PLAYER_LOGOUT_PACKET   = 0x06;
    const INFORMATION_PACKET     = 0x07;
    const TRANSFER_PACKET        = 0x08;
    const BROADCAST_PACKET       = 0x09;
    const FAST_PLAYER_LIST_PACKET = 0x0a;

    /**
     * @param int $id
     * @return string
     */
    public static function getPacketName($id)
    {
        $names = array(
            self::HEARTBEAT_PACKET       => 'HeartbeatPacket',
            self::CONNECT_PACKET         => 'ConnectPacket',
            self::DISCONNECT_PACKET      => 'DisconnectPacket',
            self::REDIRECT_PACKET        => 'RedirectPacket',
            self::PLAYER_LOGIN_PACKET    => 'PlayerLoginPacket',
            self::PLAYER_LOGOUT_PACKET   => 'PlayerLogoutPacket',
            self::INFORMATION_PACKET     => 'InformationPacket',
            self::TRANSFER_PACKET        => 'TransferPacket',
            self::BROADCAST_PACKET       => 'BroadcastPacket',
            self::FAST_PLAYER_LIST_PACKET => 'FastPlayerListPacket',
        );

        return isset($names[$id]) ? $names[$id] : 'Unknown(0x' . dechex($id) . ')';
    }
}
