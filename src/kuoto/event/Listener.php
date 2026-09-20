<?php

namespace kuoto\event;

/**
 * Interfaz marcadora, igual que en PocketMine.
 *
 * Una clase que la implemente puede registrarse con
 * EventManager::registerEvents($listener) y sus metodos publicos que reciban
 * un unico parametro de tipo Event seran detectados automaticamente.
 *
 * Ejemplo:
 *
 *   class MiListener implements Listener {
 *       public function onLogin(PlayerLoginEvent $ev){ ... }
 *   }
 */
interface Listener
{
}
