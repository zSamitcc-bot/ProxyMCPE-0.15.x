<?php

namespace kuoto\event;

use kuoto\utils\Logger;

/**
 * Punto de entrada del API de eventos, equivalente al PluginManager de
 * PocketMine en lo que a eventos se refiere.
 *
 *   $events = EventManager::getInstance();
 *   $events->registerEvents(new MiListener());
 *
 * o bien registrando un solo handler a mano:
 *
 *   $events->registerEvent(PlayerLoginEvent::class, function(PlayerLoginEvent $ev){
 *       ...
 *   }, EventPriority::HIGH);
 */
class EventManager
{
    /** @var EventManager|null */
    private static $instance = null;

    /** @var Logger|null */
    private $logger = null;

    /** @return EventManager */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new EventManager();
        }
        return self::$instance;
    }

    /** @param Logger $logger */
    public function setLogger(Logger $logger)
    {
        $this->logger = $logger;
        RegisteredListener::setLogger($logger);
    }

    /**
     * Registra automaticamente todos los metodos publicos del listener que
     * reciban exactamente un parametro de tipo Event.
     *
     * La prioridad y el handleCancelled se pueden indicar en el docblock del
     * metodo, igual que en PocketMine:
     *
     *   /** @priority HIGHEST *\/
     *   /** @handleCancelled *\/
     *
     * @param Listener $listener
     * @return int numero de handlers registrados
     */
    public function registerEvents(Listener $listener)
    {
        $registered = 0;
        $reflection = new \ReflectionClass(get_class($listener));

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isAbstract() || $method->getNumberOfParameters() !== 1) {
                continue;
            }

            $parameters = $method->getParameters();
            $eventClassName = $this->resolveParameterClass($parameters[0]);
            if ($eventClassName === null || !is_subclass_of($eventClassName, Event::class)) {
                continue;
            }

            $doc = $method->getDocComment();
            $doc = ($doc === false) ? '' : $doc;

            $priority = EventPriority::NORMAL;
            if (preg_match('/@priority[ \t]+([a-zA-Z]+)/', $doc, $matches) > 0) {
                try {
                    $priority = EventPriority::fromString($matches[1]);
                } catch (\InvalidArgumentException $e) {
                    $this->warn("Prioridad invalida en " . $reflection->getName() . '::' . $method->getName() . '()');
                }
            }

            $handleCancelled = preg_match('/@handleCancelled/', $doc) > 0
                || preg_match('/@ignoreCancelled[ \t]+false/i', $doc) > 0;

            $this->registerEvent(
                $eventClassName,
                array($listener, $method->getName()),
                $priority,
                $handleCancelled,
                $listener,
                $reflection->getShortName() . '::' . $method->getName()
            );
            $registered++;
        }

        if ($registered === 0) {
            $this->warn("El listener " . $reflection->getName() . " no expone ningun handler de eventos");
        }

        return $registered;
    }

    /**
     * Registra un unico handler.
     *
     * @param string $eventClass
     * @param callable $handler
     * @param int $priority
     * @param bool $handleCancelled
     * @param Listener|null $owner
     * @param string|null $name
     * @return RegisteredListener
     */
    public function registerEvent($eventClass, $handler, $priority = EventPriority::NORMAL, $handleCancelled = false, Listener $owner = null, $name = null)
    {
        if (!class_exists($eventClass) && !interface_exists($eventClass)) {
            throw new \InvalidArgumentException("La clase de evento {$eventClass} no existe");
        }

        $registration = new RegisteredListener(
            $handler,
            $priority,
            $handleCancelled,
            $owner,
            $name === null ? $eventClass : $name
        );

        HandlerListManager::getInstance()->getListFor($eventClass)->register($registration);

        return $registration;
    }

    /**
     * @param Listener|null $listener null = todos
     */
    public function unregisterAll(Listener $listener = null)
    {
        HandlerListManager::getInstance()->unregisterAll($listener);
    }

    /**
     * Numero total de handlers registrados (util para el comando "stats").
     * @return int
     */
    public function getHandlerCount()
    {
        $count = 0;
        foreach (HandlerListManager::getInstance()->getAll() as $list) {
            foreach (EventPriority::ALL as $priority) {
                $count += count($list->getListenersByPriority($priority));
            }
        }
        return $count;
    }

    /**
     * Obtiene el nombre de clase del primer parametro de un metodo.
     *
     * Hay que cubrir dos mundos:
     *  - PHP 7.0: ReflectionParameter::getType() ya existe, pero devuelve un
     *    ReflectionType "pelado"; la clase ReflectionNamedType NO existe hasta
     *    7.1, asi que un "instanceof \ReflectionNamedType" aqui seria siempre
     *    false y no se registraria NINGUN handler. En 7.0 se usa getClass().
     *  - PHP 8: getClass() esta deprecado y avisa, asi que ahi se usa
     *    getType()/ReflectionNamedType.
     *
     * @param \ReflectionParameter $parameter
     * @return string|null
     */
    private function resolveParameterClass(\ReflectionParameter $parameter)
    {
        if (class_exists('ReflectionNamedType', false) && method_exists($parameter, 'getType')) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType) {
                return $type->isBuiltin() ? null : $type->getName();
            }
            return null; // sin tipo, o union/intersection: no aplica a eventos
        }

        // PHP 7.0
        $class = $parameter->getClass();
        return $class === null ? null : $class->getName();
    }

    /** @param string $message */
    private function warn($message)
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        }
    }
}
