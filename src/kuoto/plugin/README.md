# `kuoto\plugin`

Carga en tiempo de ejecución los plugins ubicados en la carpeta `plugins/` del proyecto.

## `PluginLoader`

- Recibe la instancia del proxy (`SynapseServer`), un `Logger` y el directorio donde buscar plugins.
- Recorre los archivos PHP del directorio de plugins, los incluye y busca clases que implementen `kuoto\event\Listener`.
- Instancia cada listener encontrado y lo registra en el `EventManager` para que empiece a recibir eventos del proxy.
- Mantiene la lista de listeners cargados (`$listeners`).

## Cómo crear un plugin

1. Crear un archivo `.php` dentro de `plugins/`.
2. Definir una clase que implemente `kuoto\event\Listener`.
3. Añadir métodos que reciban como parámetro el evento que quieren manejar (por ejemplo `onServerSelect(PlayerServerSelectEvent $event)`), siguiendo el ejemplo de [`plugins/QueueListener.php`](../../../plugins/README.md).

## Relación con el resto del proyecto

- Depende de `kuoto\event` (interfaz `Listener` y `EventManager`) y de `kuoto\server\SynapseServer` (para dar a cada plugin acceso al proxy).
- Es invocado desde `kuoto\server\SynapseServer` durante el arranque del proxy.
