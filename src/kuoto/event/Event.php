<?php

namespace kuoto\event;

/**
 * Clase base de todos los eventos, al estilo de PocketMine.
 *
 * Uso tipico:
 *
 *   $ev = new PlayerLoginEvent($player);
 *   $ev->call();
 *   if($ev->isCancelled()){ return; }
 *
 * Los eventos que se pueden cancelar implementan {@link Cancellable}
 * (normalmente usando {@link CancellableTrait}).
 */
abstract class Event
{
    /** @var string|null cache del nombre corto del evento */
    protected $eventName = null;

    /**
     * Nombre legible del evento (por defecto el nombre de la clase).
     * @return string
     */
    public function getName()
    {
        return $this->eventName === null ? get_class($this) : $this->eventName;
    }

    /**
     * @return bool
     */
    public function isCancelled()
    {
        // Los eventos cancelables sobreescriben esto via CancellableTrait.
        return false;
    }

    /**
     * Lanza el evento hacia todos los listeners registrados.
     *
     * Devuelve el propio evento para poder encadenar:
     *   if((new MiEvento())->call()->isCancelled()){ ... }
     *
     * @return $this
     */
    public function call()
    {
        foreach (HandlerListManager::getInstance()->getHandlersFor(get_class($this)) as $registration) {
            /** @var RegisteredListener $registration */
            $registration->callEvent($this);
        }

        return $this;
    }
}
