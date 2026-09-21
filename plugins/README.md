# `plugins`

Carpeta donde se colocan los plugins que `kuoto\plugin\PluginLoader` carga automáticamente al arrancar el proxy.

## `QueueListener.php`

Plugin de ejemplo incluido con el proyecto. Implementa `kuoto\event\Listener` y muestra cómo:

- Escuchar `PlayerServerSelectEvent` para decidir a qué servidor backend se envía un jugador (por ejemplo, manteniendo una ranking de servers cuando el servidor principal está lleno).
- Escuchar `ProxyPingEvent` para participar en la respuesta al ping/MOTD del proxy.

Sirve como plantilla de partida para escribir nuevos plugins: basta con crear un nuevo archivo `.php` en esta carpeta con una clase que implemente `Listener` y métodos `on<NombreDeEvento>(EventoConcreto $event)`.