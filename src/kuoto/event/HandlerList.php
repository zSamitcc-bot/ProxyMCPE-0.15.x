<?php

namespace kuoto\event;

/**
 * Lista de handlers de UNA clase de evento concreta, separados por prioridad.
 *
 * Igual que en PocketMine, cada HandlerList conoce la lista de su clase padre,
 * de modo que registrarse sobre un evento base (p.ej. ServerEvent) tambien
 * recibe todos sus hijos.
 */
class HandlerList
{
    /** @var string */
    private $class;
    /** @var HandlerList|null */
    private $parentList;
    /** @var RegisteredListener[][] priority => listeners */
    private $handlerSlots = array();

    /**
     * @param string $class
     * @param HandlerList|null $parentList
     */
    public function __construct($class, HandlerList $parentList = null)
    {
        $this->class = $class;
        $this->parentList = $parentList;
        foreach (EventPriority::ALL as $priority) {
            $this->handlerSlots[$priority] = array();
        }
    }

    /** @return string */
    public function getEventClass()
    {
        return $this->class;
    }

    /** @return HandlerList|null */
    public function getParent()
    {
        return $this->parentList;
    }

    /**
     * @param RegisteredListener $listener
     */
    public function register(RegisteredListener $listener)
    {
        $this->handlerSlots[$listener->getPriority()][] = $listener;
    }

    /**
     * @param RegisteredListener|Listener $object
     */
    public function unregister($object)
    {
        foreach ($this->handlerSlots as $priority => $listeners) {
            foreach ($listeners as $index => $listener) {
                $match = ($object instanceof RegisteredListener)
                    ? ($listener === $object)
                    : ($listener->getOwner() === $object);
                if ($match) {
                    unset($this->handlerSlots[$priority][$index]);
                }
            }
            $this->handlerSlots[$priority] = array_values($this->handlerSlots[$priority]);
        }
    }

    public function clear()
    {
        foreach (EventPriority::ALL as $priority) {
            $this->handlerSlots[$priority] = array();
        }
    }

    /**
     * Handlers de ESTA lista para una prioridad concreta.
     *
     * @param int $priority
     * @return RegisteredListener[]
     */
    public function getListenersByPriority($priority)
    {
        return isset($this->handlerSlots[$priority]) ? $this->handlerSlots[$priority] : array();
    }

    /**
     * Todos los handlers de esta lista y de sus padres, ya ordenados de
     * LOWEST a MONITOR.
     *
     * @return RegisteredListener[]
     */
    public function getListeners()
    {
        $result = array();
        foreach (EventPriority::ALL as $priority) {
            $list = $this;
            while ($list !== null) {
                foreach ($list->getListenersByPriority($priority) as $listener) {
                    $result[] = $listener;
                }
                $list = $list->getParent();
            }
        }
        return $result;
    }
}
