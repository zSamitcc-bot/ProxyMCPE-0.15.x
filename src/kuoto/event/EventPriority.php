<?php

namespace kuoto\event;

/**
 * Prioridades de ejecucion, identicas en semantica a las de PocketMine.
 *
 * Los handlers se ejecutan de LOWEST a MONITOR. MONITOR se ejecuta al final y
 * NUNCA debe modificar el evento: solo sirve para observar el resultado final.
 */
abstract class EventPriority
{
    const LOWEST  = 5;
    const LOW     = 4;
    const NORMAL  = 3;
    const HIGH    = 2;
    const HIGHEST = 1;
    const MONITOR = 0;

    const ALL = array(
        self::LOWEST,
        self::LOW,
        self::NORMAL,
        self::HIGH,
        self::HIGHEST,
        self::MONITOR,
    );

    private static $NAMES = array(
        self::LOWEST  => 'LOWEST',
        self::LOW     => 'LOW',
        self::NORMAL  => 'NORMAL',
        self::HIGH    => 'HIGH',
        self::HIGHEST => 'HIGHEST',
        self::MONITOR => 'MONITOR',
    );

    /**
     * @param string $name
     * @return int
     * @throws \InvalidArgumentException
     */
    public static function fromString($name)
    {
        $key = strtoupper(trim($name));
        $flipped = array_flip(self::$NAMES);
        if (!isset($flipped[$key])) {
            throw new \InvalidArgumentException("Prioridad de evento desconocida: {$name}");
        }
        return $flipped[$key];
    }

    /**
     * @param int $priority
     * @return string
     */
    public static function toString($priority)
    {
        return isset(self::$NAMES[$priority]) ? self::$NAMES[$priority] : 'UNKNOWN';
    }

    /**
     * @param int $priority
     * @return bool
     */
    public static function isValid($priority)
    {
        return isset(self::$NAMES[$priority]);
    }
}
