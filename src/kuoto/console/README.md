# `kuoto\console`

Gestiona la consola interactiva del proxy: lee comandos escritos por el operador y los pasa a `kuoto\command\CommandMap` para su ejecución.

## `Console`
Orquesta la consola: en cada tick lee hasta `MAX_LINES_PER_TICK` (16) líneas pendientes a través de un `ConsoleReader` y las despacha como comandos usando el `CommandMap`. Admite distintos modos de lectura (`auto` y los descritos abajo).

## Lectura de stdin: por qué hay varias implementaciones

Leer la entrada estándar (`stdin`) sin bloquear **no es portable**: en Linux/macOS basta con `stream_set_blocking($stdin, false)`, pero en Windows esa llamada no tiene ningún efecto sobre el handle de la consola y `fread()` sigue bloqueando. Como el proxy lee la consola dentro de su bucle principal (tick loop), una lectura bloqueante congelaría todo el proxy hasta que alguien pulsara Enter. Por eso existe la interfaz `ConsoleReader` con varias implementaciones intercambiables:

| Implementación | Cuándo se usa |
|---|---|
| `StdinReader` | Lectura directa y no bloqueante de `stdin`, válida en Linux/macOS |
| `ProcessReader` | Lanza un subproceso auxiliar para leer la entrada, usado como alternativa en entornos donde la lectura directa no es viable (p. ej. Windows) |
| `ThreadedConsole` | Lee la consola en un hilo aparte (`\Thread`/`\Volatile`, requiere la extensión `pthreads`) y expone las líneas leídas de forma thread-safe |

`ConsoleReader` (interfaz) define el contrato común: devolver la siguiente línea completa o `null` si no hay nada pendiente, sin bloquear nunca la ejecución.

## Relación con el resto del proyecto

- Usa `kuoto\command\CommandMap` para ejecutar los comandos introducidos.
- Es instanciada y gestionada por `kuoto\server\SynapseServer`.
- Usa `kuoto\utils\Logger` y `kuoto\utils\TextFormat` para mostrar mensajes formateados.
