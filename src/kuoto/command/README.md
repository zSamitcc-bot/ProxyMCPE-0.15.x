# `kuoto\command`

Sistema de comandos de consola del proxy. Cada comando administrativo vive en su propia clase en lugar de ser un `case` más dentro de un switch gigante, lo que facilita añadir nuevos comandos sin tocar el resto del código.

## Clases base

### `Command`
Clase abstracta de la que heredan todos los comandos. Guarda su nombre, alias, descripción y forma de uso (`usage`), y da acceso al proxy (`SynapseServer`) para que el comando pueda actuar sobre él.

### `CommandMap`
Registro central de comandos: mapea nombre/alias a la instancia del `Command` correspondiente y se encarga de despachar la ejecución cuando llega una línea desde la consola. Antes de existir esta clase, la misma tabla de comandos estaba duplicada en `Console::handleCommand()` y en `SynapseServer::handleCommand()`, lo que obligaba a mantener ambas copias sincronizadas manualmente.

## Comandos incluidos (`defaults/`)

| Comando | Descripción |
|---|---|
| `HelpCommand` | Muestra la ayuda con la lista de comandos disponibles |
| `KickCommand` | Expulsa a un jugador conectado |
| `ListCommand` | Lista los servidores backend conectados |
| `PlayersCommand` | Lista los jugadores conectados |
| `SayCommand` | Envía un mensaje de difusión |
| `StatsCommand` | Muestra estadísticas del proxy |
| `StopCommand` | Detiene el proxy |

## Relación con el resto del proyecto

- Es usado por `kuoto\console\Console` para interpretar lo que el operador escribe en la terminal.
- Dispara `kuoto\event\console\ConsoleCommandEvent` al ejecutar un comando, permitiendo a los plugins interceptarlo.
- Usa `kuoto\utils\TextFormat` para dar formato a la salida por consola.
