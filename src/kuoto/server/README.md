# `kuoto\server`

Núcleo del proxy: arranca el servicio, mantiene la configuración cargada desde `server.properties` y coordina el resto de subsistemas (red, eventos, plugins, consola).

## Clases

### `SynapseServer`
Clase principal del proxy. Se encarga de:
- Guardar la configuración de arranque (`bindIp`, `port`, `password`, `maxServers`, etc.).
- Inicializar el `EventManager`, el `PluginLoader`, la `Console` y el `RakLibProxy`/`ClientConnection`.
- Disparar los eventos de ciclo de vida del proxy (`ProxyStartEvent`, `ProxyShutdownEvent`).
- Exponer el punto de entrada para el bucle principal del servidor.

### `ServerManager`
Lleva el registro de los servidores backend conectados vía el protocolo Synapse: altas, bajas, heartbeats y consulta de servidores disponibles (usado, por ejemplo, para repartir jugadores entre servidores o transferirlos).

## Relación con el resto del proyecto

- Depende de `kuoto\command`, `kuoto\console`, `kuoto\event`, `kuoto\network`, `kuoto\plugin` y `kuoto\utils`.
- Es el punto de entrada usado por `server.php` para levantar el proxy.
