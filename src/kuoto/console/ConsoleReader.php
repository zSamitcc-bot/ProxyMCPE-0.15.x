<?php

namespace kuoto\console;

/**
 * Fuente de lineas de comando para la consola.
 *
 * Existe porque leer stdin sin bloquear NO es portable: en Linux/macOS basta
 * con stream_set_blocking($stdin, false), pero en Windows esa llamada no tiene
 * ningun efecto sobre el handle de la consola y fread() bloquea igual. Como el
 * proxy lee la consola dentro de su tick loop, un fread bloqueante congela
 * TODO: no se aceptan backends ni se atiende a los jugadores hasta que alguien
 * pulse Enter.
 */
interface ConsoleReader
{
    /**
     * Devuelve la siguiente linea completa, o null si no hay nada pendiente.
     * NUNCA debe bloquear.
     *
     * @return string|null
     */
    public function readLine();

    /** @return bool */
    public function isOpen();

    public function close();
}
