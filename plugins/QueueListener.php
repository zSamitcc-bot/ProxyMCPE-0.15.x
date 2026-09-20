<?php

use kuoto\event\Listener;
use kuoto\event\player\PlayerServerSelectEvent;
use kuoto\event\player\PlayerLogoutEvent;
use kuoto\event\proxy\ProxyPingEvent;
use kuoto\server\SynapseServer;

class QueueListener implements Listener
{
    private $proxy;
    private $queue = array();

    public function __construct(SynapseServer $proxy)
    {
        $this->proxy = $proxy;
    }

    public function onServerSelect(PlayerServerSelectEvent $event)
    {
        $uuid = bin2hex($event->getUuid());
        $main = null;

        
        foreach ($this->proxy->getManager()->getServers() as $server) {
    $info = $server->getInfo();

    if (isset($info['isMainServer']) && $info['isMainServer']) {
        $main = $server;
        break;
    }
}

        if ($main === null) {
            if (!in_array($uuid, $this->queue, true)) {
                $this->queue[] = $uuid;
            }

            $event->setCancelled();

            $this->proxy->getLogger()->info(
                '[Queue] MAIN no disponible | Jugador en cola: ' .
                $uuid . ' | Posicion: ' . $this->getPosition($uuid)
            );

            return;
        }

        $info = $main->getInfo();

        $players = isset($info['playerCount'])
            ? (int) $info['playerCount']
            : 0;

        $maxPlayers = isset($info['maxPlayers'])
            ? (int) $info['maxPlayers']
            : 0;

        if ($maxPlayers > 0 && $players >= $maxPlayers) {
            if (!in_array($uuid, $this->queue, true)) {
                $this->queue[] = $uuid;
            }

            $event->setCancelled();

            $this->proxy->getLogger()->info(
                '[Queue] MAIN lleno ' .
                $players . '/' . $maxPlayers .
                ' | Jugador en cola: ' . $uuid .
                ' | Posicion: ' . $this->getPosition($uuid)
            );

            return;
        }

        $this->remove($uuid);
        $event->setTargetServer($main);
    }

    public function onLogout(PlayerLogoutEvent $event)
    {
        $uuid = bin2hex($event->getUuid());

        $this->remove($uuid);
    }

    public function onPing(ProxyPingEvent $event)
    {
        $main = null;

        foreach ($this->proxy->getManager()->getServers() as $server) {
            if (strtoupper($server->getHash()) === 'MAIN') {
                $main = $server;
                break;
            }
        }

        if ($main === null) {
            $event->setMotd('§c§lKUOTO NETWORK');
            $event->setSubMotd('§7MAIN §cOFFLINE §8| §fCola: §e' . count($this->queue));
            $event->setPlayerCount(0);
            $event->setMaxPlayers(0);
            return;
        }

        $info = $main->getInfo();

        $players = isset($info['playerCount'])
            ? (int) $info['playerCount']
            : 0;

        $maxPlayers = isset($info['maxPlayers'])
            ? (int) $info['maxPlayers']
            : 0;

        $queue = count($this->queue);

        $event->setMotd('§b§lKUOTO NETWORK');

        if ($maxPlayers > 0 && $players >= $maxPlayers) {
            $event->setSubMotd(
                '§c§lMAIN LLENO §8| §f' .
                $players . '/' . $maxPlayers .
                ' §8| §eCola: ' . $queue
            );
        } elseif ($queue > 0) {
            $event->setSubMotd(
                '§a§lMAIN §8| §f' .
                $players . '/' . $maxPlayers .
                ' §8| §eCola: ' . $queue
            );
        } else {
            $event->setSubMotd(
                '§a§lMAIN §8| §f' .
                $players . '/' . $maxPlayers .
                ' §8| §aSin cola'
            );
        }

        $event->setPlayerCount($players);
        $event->setMaxPlayers($maxPlayers);
    }

    public function getQueue()
    {
        return $this->queue;
    }

    public function getPosition($uuid)
    {
        $position = array_search($uuid, $this->queue, true);

        if ($position === false) {
            return 0;
        }

        return $position + 1;
    }

    public function remove($uuid)
    {
        $position = array_search($uuid, $this->queue, true);

        if ($position === false) {
            return false;
        }

        unset($this->queue[$position]);
        $this->queue = array_values($this->queue);

        return true;
    }

    public function getSize()
    {
        return count($this->queue);
    }
}