<?php

namespace kuoto\event;

use kuoto\utils\Logger;

/**
 * Un handler concreto ya registrado: el callable a ejecutar, su prioridad y si
 * debe ignorar los eventos cancelados.
 */
class RegisteredListener
{
    /** @var callable */
    private $handler;
    /** @var int */
    private $priority;
    /** @var bool */
    private $handleCancelled;
    /** @var Listener|null */
    private $owner;
    /** @var string */
    private $name;
    /** @var Logger|null */
    private static $logger = null;

    /**
     * @param callable $handler
     * @param int $priority
     * @param bool $handleCancelled
     * @param Listener|null $owner
     * @param string $name
     */
    public function __construct($handler, $priority, $handleCancelled = false, Listener $owner = null, $name = 'closure')
    {
        if (!EventPriority::isValid($priority)) {
            throw new \InvalidArgumentException("Prioridad invalida: {$priority}");
        }
        if (!is_callable($handler)) {
            throw new \InvalidArgumentException("El handler debe ser callable");
        }

        $this->handler = $handler;
        $this->priority = $priority;
        $this->handleCancelled = (bool) $handleCancelled;
        $this->owner = $owner;
        $this->name = $name;
    }

    /** @param Logger $logger */
    public static function setLogger(Logger $logger)
    {
        self::$logger = $logger;
    }

    /** @return int */
    public function getPriority()
    {
        return $this->priority;
    }

    /** @return Listener|null */
    public function getOwner()
    {
        return $this->owner;
    }

    /** @return string */
    public function getName()
    {
        return $this->name;
    }

    /** @return bool */
    public function isHandlingCancelled()
    {
        return $this->handleCancelled;
    }

    /**
     * Ejecuta el handler. Un listener que lance una excepcion nunca debe tumbar
     * el tick del proxy: se registra y se continua con el resto de handlers.
     *
     * @param Event $event
     */
    public function callEvent(Event $event)
    {
        if ($event instanceof Cancellable && $event->isCancelled() && !$this->handleCancelled) {
            return;
        }

        try {
            call_user_func($this->handler, $event);
        } catch (\Throwable $e) {
            if (self::$logger !== null) {
                self::$logger->error(
                    "Error en el listener {$this->name} para " . $event->getName() . ": "
                    . get_class($e) . ': ' . $e->getMessage()
                    . ' en ' . $e->getFile() . ':' . $e->getLine()
                );
            }
        }
    }
}
