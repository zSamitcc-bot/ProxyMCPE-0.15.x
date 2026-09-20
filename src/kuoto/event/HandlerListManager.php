<?php

namespace kuoto\event;

/**
 * Registro global de HandlerList (una por clase de evento).
 *
 * Es el equivalente al HandlerListManager de PocketMine: permite que
 * Event::call() encuentre sus handlers sin necesidad de inyectar nada.
 */
class HandlerListManager
{
    /** @var HandlerListManager|null */
    private static $globalInstance = null;

    /** @var HandlerList[] className => list */
    private $allLists = array();

    /**
     * Instancia global.
     *
     * Se llama getInstance() y no global() -- que seria lo analogo a
     * PocketMine -- porque "global" es una palabra reservada: usarla como
     * nombre de metodo depende del lexer contextual, y este proyecto tiene que
     * funcionar en PHP 7.0.
     *
     * @return HandlerListManager
     */
    public static function getInstance()
    {
        if (self::$globalInstance === null) {
            self::$globalInstance = new HandlerListManager();
        }
        return self::$globalInstance;
    }

    /**
     * Devuelve (creando si hace falta) la lista de handlers de una clase de
     * evento, enlazada con la de su clase padre.
     *
     * @param string $class
     * @return HandlerList
     */
    public function getListFor($class)
    {
        if (isset($this->allLists[$class])) {
            return $this->allLists[$class];
        }

        $reflection = new \ReflectionClass($class);
        if (!$reflection->isSubclassOf(Event::class) && $class !== Event::class) {
            throw new \InvalidArgumentException("{$class} no es una clase de evento");
        }
        if ($reflection->isAbstract() && $class !== Event::class) {
            // Las clases abstractas si pueden tener lista: sirven para
            // escuchar "todos los eventos de esta familia".
        }

        $parent = $reflection->getParentClass();
        $parentList = null;
        if ($parent !== false) {
            $parentList = $this->getListFor($parent->getName());
        }

        $this->allLists[$class] = new HandlerList($class, $parentList);
        return $this->allLists[$class];
    }

    /**
     * Handlers aplicables a una clase de evento (incluidos los de sus padres),
     * ya ordenados por prioridad.
     *
     * @param string $class
     * @return RegisteredListener[]
     */
    public function getHandlersFor($class)
    {
        return $this->getListFor($class)->getListeners();
    }

    /** @return HandlerList[] */
    public function getAll()
    {
        return $this->allLists;
    }

    /**
     * Elimina todos los handlers de un listener concreto, o todos si no se
     * pasa nada.
     *
     * @param Listener|null $listener
     */
    public function unregisterAll(Listener $listener = null)
    {
        foreach ($this->allLists as $list) {
            if ($listener === null) {
                $list->clear();
            } else {
                $list->unregister($listener);
            }
        }
    }
}
