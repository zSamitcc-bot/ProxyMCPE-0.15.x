<?php

namespace kuoto\event;

/**
 * Implementacion estandar de {@link Cancellable}.
 */
trait CancellableTrait
{
    /** @var bool */
    private $cancelled = false;

    /** @return bool */
    public function isCancelled()
    {
        return $this->cancelled;
    }

    /** @param bool $value */
    public function setCancelled($value = true)
    {
        $this->cancelled = (bool) $value;
    }
}
