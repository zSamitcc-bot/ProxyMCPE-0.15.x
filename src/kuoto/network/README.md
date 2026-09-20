# `kuoto\network`

Implementa toda la capa de red del proxy: el lado del cliente (protocolo RakNet/UDP hablado con el cliente de MCPE) y el lado del backend (conexión con los servidores Synapse).

## Clases principales

### `RakLibProxy`
Proxy UDP completo para clientes de MCPE. Implementa el protocolo RakLib de extremo a extremo:
- Ping/pong sin conexión (unconnected).
- Handshake de apertura de conexión (`OPEN_CONNECTION_REQUEST_1/2` y sus respuestas).
- Fase conectada (`CLIENT_CONNECT`, `SERVER_HANDSHAKE`, `CLIENT_HANDSHAKE`).
- Envío/recepción de paquetes de datos con ACK/NACK.
- Decodificación de paquetes encapsulados.
- Reenvío de los datos del juego hacia el backend de PocketMine correspondiente.

La lógica se organiza en traits dentro de `network/raklibproxy/` para mantener cada responsabilidad separada:

| Trait | Responsabilidad |
|---|---|
| `UnconnectedTrait` | Ping/pong y descubrimiento sin conexión |
| `DataPacketTrait` | Manejo de paquetes de datos RakNet |
| `GameDataTrait` | Reenvío de datos de juego al backend |
| `ServerAssignmentTrait` | Asignación de un servidor backend a cada cliente |
| `SendTrait` | Envío de paquetes por UDP |
| `BackendRedirectTrait` | Redirección/transferencia de un cliente entre backends |

### `ClientConnection`
Representa la conexión de un cliente individual con el backend Synapse (PocketMine). Su lógica de manejo de paquetes está separada en `clientconnection/PacketHandlersTrait.php`.

## Relación con el resto del proyecto

- Usa `kuoto\raklib` para la codificación/decodificación binaria de bajo nivel.
- Usa `kuoto\protocol` para construir e interpretar los paquetes del protocolo Synapse.
- Se apoya en `kuoto\server\ServerManager` para saber a qué backend enviar cada jugador.
- Usa `kuoto\utils\Logger` para el registro de eventos de red.
