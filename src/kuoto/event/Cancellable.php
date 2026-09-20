<?php

namespace kuoto\event;

/**
 * Los eventos que implementan esta interfaz pueden ser cancelados por un
 * listener. Cancelar un evento significa que la accion por defecto del proxy
 * NO se ejecutara (no se autentica el servidor, no se reenvia el paquete,
 * no se ejecuta el comando, etc.).
 */
interface Cancellable
{
    /** @return bool */
    public function isCancelled();

    /** @param bool $value */
    public function setCancelled($value = true);
}
