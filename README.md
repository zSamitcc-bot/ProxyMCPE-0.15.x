# Kuoto

Kuoto es un servidor proxy para **Minecraft: Pocket Edition (MCPE)**, escrito en PHP puro, inspirado en el protocolo **Synapse**. Actúa como punto de entrada único para los jugadores y reenvía sus conexiones a uno o varios servidores backend (por ejemplo, instancias de **PocketMine-MP**), permitiendo balanceo de carga, transferencias entre servidores sin reconexión visible para el jugador, y una consola/administración centralizada.

## ¿Qué hace?

- Recibe las conexiones UDP (RakNet) de los clientes de MCPE.
- Habla el protocolo Synapse con los servidores backend para reenviar el tráfico del juego.
- Gestiona el ciclo de vida de los servidores conectados (registro, heartbeat, desconexión).
- Expone un sistema de eventos y plugins para extender su comportamiento.
- Ofrece una consola interactiva con comandos administrativos.

## Estructura del proyecto

```
.
├── bin/            # Binarios de PHP y utilidades para ejecutar el proxy en Windows
├── plugins/         # Plugins/listeners que se cargan en tiempo de ejecución
├── src/
│   ├── autoload.php # Autoloader PSR-4 simple para el namespace kuoto\
│   └── kuoto/        # Código fuente principal (ver README.md de cada subcarpeta)
├── server.php        # Punto de entrada: arranca el proxy
├── server.properties # Configuración del servidor
├── start.cmd          # Script de arranque para Windows
└── test.php           # Script de pruebas manuales
```

Cada subcarpeta de `src/kuoto/` tiene su propio `README.md` con el detalle de su responsabilidad:

| Carpeta | Responsabilidad |
|---|---|
| [`src/kuoto/server`](src/kuoto/server/README.md) | Núcleo del proxy y gestión de servidores backend |
| [`src/kuoto/network`](src/kuoto/network/README.md) | Proxy RakNet/UDP y conexiones con los backends |
| [`src/kuoto/protocol`](src/kuoto/protocol/README.md) | Paquetes del protocolo Synapse |
| [`src/kuoto/raklib`](src/kuoto/raklib/README.md) | Utilidades binarias de bajo nivel (RakLib) |
| [`src/kuoto/event`](src/kuoto/event/README.md) | Sistema de eventos tipo PocketMine |
| [`src/kuoto/plugin`](src/kuoto/plugin/README.md) | Carga de plugins en tiempo de ejecución |
| [`src/kuoto/command`](src/kuoto/command/README.md) | Comandos de consola |
| [`src/kuoto/console`](src/kuoto/console/README.md) | Lectura de entrada de consola |
| [`src/kuoto/utils`](src/kuoto/utils/README.md) | Utilidades varias (logging, formato de texto, tablas) |
| [`plugins`](plugins/README.md) | Plugin de ejemplo incluido con el proyecto |

## Requisitos

- PHP (el proyecto incluye binarios de PHP para Windows en `bin/php`).
- Uno o más servidores backend compatibles con el protocolo Synapse (p. ej. PocketMine-MP con el plugin Synapse).

## Configuración

La configuración vive en [`server.properties`](server.properties):

```properties
server.bind-ip=0.0.0.0
server.port=10305
server.password=123456
server.max-servers=50
server.description=Kuoto Central Server
server.rak-port=19132
server.motd=Kuoto Proxy
server.sub-motd=A Minecraft PE Proxy
server.gamemode=Survival
server.protocol=84
server.version=0.15.10
server.max-players=20

console.enabled=true

logging.level=info
logging.file=kuoto.log
```

## Uso

```bash
php server.php
```

O, en Windows, ejecutando `start.cmd`.

## Plugins

Kuoto puede cargar plugins PHP colocados en la carpeta `plugins/`. Un plugin implementa la interfaz `kuoto\event\Listener` y se registra en el `EventManager` para reaccionar a eventos del proxy (conexión de jugadores, selección de servidor, etc.). Ver [`plugins/README.md`](plugins/README.md) para un ejemplo.

## Licencia

Añade aquí la licencia bajo la que se distribuye este proyecto.
