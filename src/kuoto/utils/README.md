# `kuoto\utils`

Utilidades genéricas usadas en todo el proyecto: logging, formato de texto por consola y tablas.

## Clases

### `Logger`
Logger sencillo con niveles (`DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERROR`, `CRITICAL`), soporte de colores en terminal y volcado opcional a un archivo (`logging.file` en `server.properties`). Es la clase usada en todo el proyecto para reportar lo que ocurre en el proxy.

### `TextFormat`
Constantes de códigos de escape ANSI (`RESET`, `BOLD`, colores como `DARK_BLUE`, `GOLD`, `GRAY`, etc.) usadas para dar color y estilo a los mensajes impresos en la consola, siguiendo la misma convención de colores que Minecraft/PocketMine.

### `ConsoleTable`
Utilidad para construir e imprimir tablas con cabecera, ancho de columnas automático y color de marco configurable; se usa por ejemplo en comandos como `ListCommand` o `PlayersCommand` para mostrar listados de forma tabulada.

## Relación con el resto del proyecto

Es una dependencia transversal: prácticamente todos los demás namespaces (`server`, `network`, `command`, `console`, `plugin`) usan `Logger` y/o `TextFormat`.
