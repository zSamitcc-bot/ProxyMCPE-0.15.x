# `bin`

Binarios de terceros usados para ejecutar Kuoto en **Windows** sin tener que instalar PHP ni un entorno Cygwin por separado. Esta carpeta no contiene código propio del proyecto: son herramientas redistribuidas.

## Contenido

| Archivo/carpeta | Descripción |
|---|---|
| `php/` | Distribución portable de **PHP** para Windows (binarios oficiales de php.net, con `php.exe`, `php-win.exe`, `php-cgi.exe`, `phpdbg.exe` y sus DLLs de dependencias). Incluye `php.ini`, `php.ini-development` y `php.ini-production` como plantillas de configuración, además de `pthreadVC2.dll`, necesaria para el soporte de hilos (`pthreads`) que usa `kuoto\console\ThreadedConsole`. |
| `cygwin1.dll` | Librería de compatibilidad de **Cygwin**, requerida por `mintty.exe`. |
| `mintty.exe` | Emulador de terminal (usado por Cygwin) para dar una consola más completa en Windows que la consola por defecto de `cmd.exe`. |
| `cygwin-console-helper.exe` | Utilidad auxiliar de Cygwin para la integración de `mintty` con la consola de Windows. |
| `pocketmine.ico` | Icono usado por el script/acceso directo de arranque en Windows. |

## Relación con el resto del proyecto

Es usado por [`start.cmd`](../start.cmd) en la raíz del proyecto, que apunta a `bin/php/php.exe` (opcionalmente a través de `mintty.exe`) para lanzar [`PocketMine-MP.phar`](../PocketMine-MP.phar) con una terminal más cómoda en Windows.

En Linux/macOS esta carpeta no es necesaria: basta con tener PHP instalado en el sistema y ejecutar `php PocketMine-MP.phar` directamente.

## Cómo ejecutar en Windows

1. **Descomprime** el proyecto completo (el `.zip`/`.rar` que descargaste) en una carpeta local. Esta carpeta `bin/php` debe quedar tal cual, sin recomprimir ni mover sus archivos por separado: `php.exe` necesita el resto de DLLs a su lado (`php7ts.dll`, `libssh2.dll`, `pthreadVC2.dll`, etc.) para poder arrancar.
2. Verifica que el **puerto** configurado en [`server.properties`](../server.properties) esté libre y, si vas a aceptar conexiones desde fuera de tu red local, abierto/redirigido en el router y el firewall de Windows:
   - `server.port` (por defecto `10305`, TCP) — puerto por el que los servidores backend (PocketMine) se conectan al proxy.
   - `server.rak-port` (por defecto `19132`, UDP) — puerto por el que los **jugadores** (clientes de MCPE) se conectan al proxy.
3. Ejecuta `start.cmd` desde la raíz del proyecto (doble clic o `start.cmd` en `cmd.exe`). Este script invoca `bin\php\php.exe PocketMine-MP.phar`, opcionalmente a través de `mintty.exe`, para lanzar el proxy con una consola interactiva.
4. Si `start.cmd` no abre nada o se cierra al instante, ejecuta manualmente `bin\php\php.exe server.php` desde una consola (`cmd.exe`) para ver el mensaje de error.

> Nota: estos binarios son software de terceros (PHP Group, proyecto Cygwin) con sus propias licencias — ver `php/license.txt` y `php/readme-redist-bins.txt`. No se distribuyen aquí bajo la licencia del propio proyecto Kuoto.