# `kuoto\event`

Sistema de eventos del proxy, equivalente en espíritu al sistema de eventos de **PocketMine**: permite que los plugins reaccionen a lo que ocurre dentro de Kuoto sin modificar su código.

## Núcleo del sistema

### `EventManager`
Punto de entrada del API de eventos (singleton, `EventManager::getInstance()`). Permite:

```php
$events = EventManager::getInstance();
$events->registerEvents(new MiListener());
```

o registrar un único handler manualmente:

```php
$events->registerEvent(PlayerLoginEvent::class, function (PlayerLoginEvent $ev) {
    // ...
}, EventPriority::HIGH);
```

### `Listener`
Interfaz que deben implementar las clases que quieren escuchar eventos (típicamente, los plugins).

### `Event` / `Cancellable` / `CancellableTrait`
Clase base de todo evento y el mecanismo para marcar un evento como cancelable/cancelado, deteniendo así su procesamiento por defecto.

### `EventPriority`
Constantes de prioridad (`LOWEST`, `LOW`, `NORMAL`, `HIGH`, `HIGHEST`, `MONITOR`, etc.) que determinan el orden en que se ejecutan los listeners de un mismo evento.

### `HandlerList` / `HandlerListManager` / `RegisteredListener`
Estructuras internas que mantienen, por cada clase de evento, la lista de listeners registrados y su prioridad, y se encargan de invocarlos en orden cuando se dispara el evento.

## Eventos disponibles

| Subcarpeta | Eventos | Descripción |
|---|---|---|
| `proxy/` | `ProxyStartEvent`, `ProxyShutdownEvent`, `ProxyPingEvent` | Ciclo de vida del proxy y respuesta al ping de la lista de servidores |
| `server/` | `ServerConnectEvent`, `ServerDisconnectEvent`, `ServerAuthenticatedEvent`, `ServerHeartbeatEvent`, `ServerEvent` | Conexión, autenticación, heartbeat y desconexión de servidores backend |
| `player/` | `PlayerPreLoginEvent`, `PlayerLoginEvent`, `PlayerLogoutEvent`, `PlayerServerSelectEvent`, `PlayerTransferEvent`, `PlayerEvent` | Ciclo de vida de un jugador conectado a través del proxy |
| `network/` | `DataPacketReceiveEvent`, `DataPacketSendEvent` | Recepción/envío de paquetes crudos del protocolo |
| `console/` | `ConsoleCommandEvent` | Ejecución de un comando desde la consola |

## Relación con el resto del proyecto

- `kuoto\plugin\PluginLoader` registra los listeners de cada plugin en el `EventManager`.
- `kuoto\server`, `kuoto\network` y `kuoto\console` disparan estos eventos en los puntos relevantes de su lógica.
