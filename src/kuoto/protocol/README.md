# `kuoto\protocol`

Define el protocolo **Synapse** usado para la comunicación entre el proxy Kuoto y los servidores backend (PocketMine-MP).

## `Info`

Constantes del protocolo: versión actual (`CURRENT_PROTOCOL`) y los identificadores (`opcode`) de cada tipo de paquete:

| Constante | Valor | Uso |
|---|---|---|
| `HEARTBEAT_PACKET` | `0x01` | Latido periódico entre proxy y backend |
| `CONNECT_PACKET` | `0x02` | Registro de un backend en el proxy |
| `DISCONNECT_PACKET` | `0x03` | Desconexión de un backend |
| `REDIRECT_PACKET` | `0x04` | Redirección de un jugador |
| `PLAYER_LOGIN_PACKET` | `0x05` | Notificación de login de un jugador |
| `PLAYER_LOGOUT_PACKET` | `0x06` | Notificación de logout de un jugador |
| `INFORMATION_PACKET` | `0x07` | Información/metadatos genéricos |
| `TRANSFER_PACKET` | `0x08` | Transferencia de un jugador entre servidores |
| `BROADCAST_PACKET` | `0x09` | Difusión de un mensaje/paquete a todos los backends |
| `FAST_PLAYER_LIST_PACKET` | `0x0a` | Lista rápida de jugadores conectados |

También incluye `Info::getPacketName()` para convertir un id de paquete en su nombre legible (útil para logging/depuración).

## Clases de paquete

Cada tipo de paquete tiene su propia clase (todas heredan de `DataPacket`), responsable de serializar/deserializar sus datos específicos:

- `ConnectPacket`, `DisconnectPacket`
- `HeartbeatPacket`
- `RedirectPacket`, `TransferPacket`
- `PlayerLoginPacket`, `PlayerLogoutPacket`
- `InformationPacket`, `BroadcastPacket`, `FastPlayerListPacket`
- `ChangeDimensionPacket`, `ClientDisconnectPacket`

### `PacketPool`
Registro central que, dado el id de un paquete (ver `Info`), instancia la clase concreta correspondiente para decodificar los bytes recibidos.

## Relación con el resto del proyecto

- Usa `kuoto\raklib\BinaryStream` para leer/escribir los campos de cada paquete.
- Es consumido por `kuoto\network` (para hablar con los clientes/backends) y por `kuoto\server` (para reaccionar a los paquetes recibidos, disparando eventos en `kuoto\event`).
