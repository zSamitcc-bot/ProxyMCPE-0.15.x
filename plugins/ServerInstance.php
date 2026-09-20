<?php

use kuoto\event\Listener;
use kuoto\event\console\ConsoleCommandEvent;
use kuoto\event\player\PlayerLoginEvent;
use kuoto\event\player\PlayerLogoutEvent;
use kuoto\event\player\PlayerServerSelectEvent;
use kuoto\event\server\ServerConnectEvent;
use kuoto\event\server\ServerDisconnectEvent;
use kuoto\event\server\ServerHeartbeatEvent;
use kuoto\event\proxy\ProxyStartEvent;
use kuoto\event\proxy\ProxyShutdownEvent;
use kuoto\server\SynapseServer;

class KuotoMonitor implements Listener
{
    private $proxy;

    public function __construct(SynapseServer $proxy)
    {
        $this->proxy = $proxy;
    }

    public function onStart(ProxyStartEvent $event)
    {
        $this->proxy->getLogger()->info(
            '[KuotoMonitor] Plugin iniciado'
        );
    }

    public function onShutdown(ProxyShutdownEvent $event)
    {
        $this->proxy->getLogger()->info(
            '[KuotoMonitor] Plugin detenido'
        );
    }

    public function onLogin(PlayerLoginEvent $event)
    {
        $this->proxy->getLogger()->info(
            '[KuotoMonitor] Jugador conectado'
        );
    }

    public function onLogout(PlayerLogoutEvent $event)
    {
        $this->proxy->getLogger()->info(
            '[KuotoMonitor] Jugador desconectado'
        );
    }

    public function onServerConnect(ServerConnectEvent $event)
    {
        $this->proxy->getLogger()->info(
            '[KuotoMonitor] Backend conectado'
        );
    }

    public function onServerDisconnect(ServerDisconnectEvent $event)
    {
        $this->proxy->getLogger()->warning(
            '[KuotoMonitor] Backend desconectado'
        );
    }

    public function onServerSelect(PlayerServerSelectEvent $event)
    {
        $server = $event->getTargetServer();

        if ($server !== null) {
            $this->proxy->getLogger()->debug(
                '[KuotoMonitor] Servidor seleccionado: ' . $server->getHash()
            );
        }
    }

    public function onHeartbeat(ServerHeartbeatEvent $event)
    {
        if ($event->getTps() < 10) {
            $this->proxy->getLogger()->warning(
                '[KuotoMonitor] TPS bajo en ' .
                $event->getHash() .
                ': ' .
                number_format($event->getTps(), 1)
            );
        }
    }

    public function onCommand(ConsoleCommandEvent $event)
    {
        $command = strtolower(trim($event->getCommand()));

        if ($command === 'kstatus') {
            $this->showStatus();
            $event->setCancelled();
            return;
        }

        if ($command === 'kservers') {
            $this->showServers();
            $event->setCancelled();
            return;
        }

        if ($command === 'kplayers') {
            $this->showPlayers();
            $event->setCancelled();
            return;
        }
    }

    private function showStatus()
    {
        $manager = $this->proxy->getManager();
        $stats = $manager->getStats();

        $this->proxy->getLogger()->info(
            '========== KUOTO STATUS =========='
        );

        $this->proxy->getLogger()->info(
            'Estado: ' . ($this->proxy->isRunning() ? 'ONLINE' : 'OFFLINE')
        );

        $this->proxy->getLogger()->info(
            'Uptime: ' . $this->formatUptime($this->proxy->getUptime())
        );

        $this->proxy->getLogger()->info(
            'Servidores: ' .
            $stats['authenticatedServers'] .
            '/' .
            $stats['totalServers']
        );

        $this->proxy->getLogger()->info(
            'Jugadores: ' . $stats['totalPlayers']
        );

        $this->proxy->getLogger()->info(
            '=================================='
        );
    }

    private function showServers()
    {
        $manager = $this->proxy->getManager();
        $servers = $manager->getServers();

        $this->proxy->getLogger()->info(
            '========== KUOTO SERVERS =========='
        );

        if (count($servers) === 0) {
            $this->proxy->getLogger()->info(
                'No hay servidores conectados'
            );

            $this->proxy->getLogger()->info(
                '==================================='
            );

            return;
        }

        foreach ($servers as $server) {
            $hash = $server->getHash();
            $info = $server->getInfo();

            $players = $manager->getPlayerCountForServer($hash);

            $status = $server->isAuthenticated()
                ? 'ONLINE'
                : 'AUTH';

            $this->proxy->getLogger()->info(
                $hash .
                ' | ' .
                $status .
                ' | ' .
                $players .
                '/' .
                $info['maxPlayers'] .
                ' | TPS ' .
                number_format($info['tps'], 1)
            );
        }

        $this->proxy->getLogger()->info(
            '==================================='
        );
    }

    private function showPlayers()
    {
        $manager = $this->proxy->getManager();
        $players = $manager->getAllPlayers();

        $this->proxy->getLogger()->info(
            '========== KUOTO PLAYERS =========='
        );

        if (count($players) === 0) {
            $this->proxy->getLogger()->info(
                'No hay jugadores conectados'
            );

            $this->proxy->getLogger()->info(
                '==================================='
            );

            return;
        }

        foreach ($players as $uuidHex => $info) {
            $this->proxy->getLogger()->info(
                $uuidHex .
                ' -> ' .
                $info['server'] .
                ' | ' .
                $info['ip'] .
                ':' .
                $info['port']
            );
        }

        $this->proxy->getLogger()->info(
            '==================================='
        );
    }

    private function formatUptime($seconds)
    {
        $seconds = (int) $seconds;

        $days = (int) floor($seconds / 86400);
        $seconds %= 86400;

        $hours = (int) floor($seconds / 3600);
        $seconds %= 3600;

        $minutes = (int) floor($seconds / 60);
        $seconds %= 60;

        return sprintf(
            '%dd %02dh %02dm %02ds',
            $days,
            $hours,
            $minutes,
            $seconds
        );
    }
}
