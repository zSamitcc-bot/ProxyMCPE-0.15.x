<?php

namespace kuoto\network\clientconnection;

use kuoto\protocol\Info;
use kuoto\protocol\ConnectPacket;
use kuoto\protocol\DisconnectPacket;
use kuoto\protocol\HeartbeatPacket;
use kuoto\protocol\InformationPacket;
use kuoto\protocol\RedirectPacket;
use kuoto\protocol\PlayerLoginPacket;
use kuoto\protocol\PlayerLogoutPacket;
use kuoto\protocol\TransferPacket;
use kuoto\protocol\BroadcastPacket;
use kuoto\protocol\FastPlayerListPacket;

use kuoto\event\player\PlayerLoginEvent;
use kuoto\event\player\PlayerLogoutEvent;
use kuoto\event\player\PlayerTransferEvent;
use kuoto\event\server\ServerAuthenticatedEvent;
use kuoto\event\server\ServerConnectEvent;
use kuoto\event\server\ServerHeartbeatEvent;

/**
 * Manejadores de cada tipo de paquete Synapse que puede llegar de un
 * backend (CONNECT, HEARTBEAT, DISCONNECT, PLAYER_LOGIN/LOGOUT,
 * REDIRECT, TRANSFER, BROADCAST, FAST_PLAYER_LIST) y el reenvio del
 * login a los demas backends.
 *
 * Extraido de ClientConnection para mantener ese archivo por debajo
 * de un tamano manejable. No cambia ningun comportamiento: sigue
 * operando sobre las mismas propiedades de ClientConnection via $this.
 */
trait PacketHandlersTrait
{
    /**
     * @param ConnectPacket $pk
     */
    private function handleConnect(ConnectPacket $pk)
    {
        $this->logger->info("Server Connection: {$this->hash} - protocolo v{$pk->protocol}");

        $event = new ServerConnectEvent($this, $pk->protocol, $pk->maxPlayers, $pk->isMainServer, $pk->description);
        $event->call();
        if ($event->isCancelled()) {
            $this->logger->warning("Conexion de {$this->hash} rechazada por un listener: " . $event->getKickMessage());
            $this->sendDisconnect(DisconnectPacket::TYPE_GENERIC, $event->getKickMessage());
            $this->disconnect($event->getKickMessage());
            return;
        }

        if ($pk->protocol !== Info::CURRENT_PROTOCOL) {
            $this->logger->error("Protocolo incorrecto desde {$this->hash}: se esperaba v" . Info::CURRENT_PROTOCOL . ", got v{$pk->protocol}");
            $this->sendDisconnect(DisconnectPacket::TYPE_WRONG_PROTOCOL, "Version de protocolo incorrecta. Se esperaba v" . Info::CURRENT_PROTOCOL);
            return;
        }

        if ($pk->password !== $this->server->getPassword()) {
            $this->logger->error("Contrasena incorrecta desde {$this->hash}");
            $this->sendInformation(InformationPacket::TYPE_LOGIN, InformationPacket::INFO_LOGIN_FAILED);
            $this->disconnect('Autenticacion fallida');
            return;
        }

        $this->authenticated = true;
        $this->maxPlayers = $pk->maxPlayers;
        $this->isMainServer = $pk->isMainServer;
        $this->description = $pk->description;
        $this->serverName = $this->ip . ':' . $this->port;

        $typeStr = $this->isMainServer ? 'MAIN' : 'SUB';
        $this->logger->info("Server Backend: {$this->hash} autenticado ({$this->description}, {$typeStr}, PlayerMax={$this->maxPlayers})");

        $this->sendInformation(InformationPacket::TYPE_LOGIN, InformationPacket::INFO_LOGIN_SUCCESS);

        $this->server->removePendingConnection($this->hash);
        $this->manager->registerServer($this);

        $this->manager->sendClientList($this);

        $authEvent = new ServerAuthenticatedEvent($this);
        $authEvent->call();
    }

    /**
     * @param HeartbeatPacket $pk
     */
    private function handleHeartbeat(HeartbeatPacket $pk)
    {
        $this->lastHeartbeat = microtime(true);
        $this->tps = $pk->tps;
        $this->load = $pk->load;
        $this->upTime = $pk->upTime;

        $event = new ServerHeartbeatEvent($this, $pk->tps, $pk->load, $pk->upTime);
        $event->call();
    }

    /**
     * @param DisconnectPacket $pk
     */
    private function handleDisconnect(DisconnectPacket $pk)
    {
        $this->logger->info("El servidor {$this->hash} se desconecta: {$pk->message}");
        $this->disconnect($pk->message);
    }

    /**
     * @param PlayerLoginPacket $pk
     */
    private function handlePlayerLogin(PlayerLoginPacket $pk)
{
    $packetUuidHex = strtolower(str_replace('-', '', bin2hex($pk->uuid)));

    $internalUuidHex = $this->manager->resolvePlayerUuid($packetUuidHex);

    if ($internalUuidHex === null) {
        $rakProxy = $this->manager->getRakProxy();

        if ($rakProxy !== null) {
            $internalUuidHex = $rakProxy->getSessionUuidHexByBackendUuid(
                $packetUuidHex
            );
        }
    }

    if ($internalUuidHex !== null) {
        $this->manager->movePlayer(
            $internalUuidHex,
            $this->hash
        );

        $this->manager->setBackendUuid(
            $internalUuidHex,
            $packetUuidHex
        );
    } else {
        $internalUuidHex = $packetUuidHex;

        $this->manager->registerPlayer(
            $internalUuidHex,
            $this->hash,
            $pk->address,
            $pk->port
        );
    }

    $this->players[$internalUuidHex] = $this->hash;

    $event = new PlayerLoginEvent(
        $pk->uuid,
        $this,
        $pk->address,
        $pk->port,
        $pk->isFirstTime
    );

    $event->call();

    if ($event->isCancelled()) {
        return;
    }

    $this->forwardLoginPacket($pk);
}

    /**
     * @param PlayerLogoutPacket $pk
     */
    private function handlePlayerLogout(PlayerLogoutPacket $pk)
{
    $uuidHex = strtolower(str_replace('-', '', bin2hex($pk->uuid)));

    $resolvedUuid = $this->manager->resolvePlayerUuid($uuidHex);

    if ($resolvedUuid === null) {
        $this->logger->debug(
            "Logout recibido para jugador no registrado: {$uuidHex}"
        );
        return;
    }

    $currentServer = $this->manager->getPlayerServer($resolvedUuid);

    unset($this->players[$uuidHex]);

    if ($currentServer !== $this->hash) {
        $this->logger->debug(
            "Logout ignorado: {$resolvedUuid} pertenece actualmente a {$currentServer}, recibido de {$this->hash}"
        );
        return;
    }

    $event = new PlayerLogoutEvent(
        $pk->uuid,
        $this,
        $pk->reason
    );

    $event->call();

    $this->logger->info(
        "Jugador {$uuidHex} salió de {$this->hash}"
    );

    $this->manager->unregisterPlayer($resolvedUuid);

    $this->manager->forwardToAllExcept($this, $pk);

    $rakProxy = $this->manager->getRakProxy();

    if ($rakProxy !== null) {
        $rakProxy->handlePlayerLogout(
            $pk->uuid,
            $pk->reason
        );
    }
}

    /**
     * @param RedirectPacket $pk
     */
    private function handleRedirect(RedirectPacket $pk)
    {
        if ($this->logger->isDebugEnabled()) {
            $uuidHex = bin2hex($pk->uuid);
            $this->logger->debug("Redirect de {$this->hash} uuid={$uuidHex} dataLen=" . strlen($pk->mcpeBuffer));
        }

        // Forward to RakLib proxy (player client)
        $rakProxy = $this->manager->getRakProxy();
        if ($rakProxy !== null) {
            $rakProxy->handleRedirectFromBackend($pk->uuid, $pk->mcpeBuffer, $this->hash);
        }
    }

    /**
     * @param TransferPacket $pk
     */
    private function handleTransfer(TransferPacket $pk)
{
    $uuidHex = bin2hex($pk->uuid);

    $event = new PlayerTransferEvent(
        $pk->uuid,
        $this,
        $pk->clientHash
    );

    $event->call();

    if ($event->isCancelled()) {
        $this->logger->info(
            "Traslado de {$uuidHex} cancelado por un listener"
        );
        return;
    }

    $targetHash = $event->getTargetHash();

    $this->logger->info(
        "Traslado solicitado: {$uuidHex} de {$this->hash} a {$targetHash}"
    );

    $target = $this->manager->getServer($targetHash);

    if ($target === null || !$target->isAuthenticated()) {
        $this->logger->warning(
            "El destino {$targetHash} no existe o no esta autenticado"
        );
        return;
    }

    $rakProxy = $this->manager->getRakProxy();

    if ($rakProxy === null) {
        $this->logger->warning(
            "No hay RakLibProxy disponible para trasladar {$uuidHex}"
        );
        return;
    }

    if (!$rakProxy->transferPlayer($pk->uuid, $targetHash)) {
        $this->logger->warning(
            "No se pudo cambiar la ruta RakNet de {$uuidHex}"
        );
        return;
    }

    $this->logger->info(
        "Traslado de {$uuidHex} preparado hacia {$targetHash}"
    );
}

    /**
     * @param BroadcastPacket $pk
     */
    private function handleBroadcast(BroadcastPacket $pk)
    {
        foreach ($pk->entries as $uuid) {
            $uuidHex = bin2hex($uuid);
            $targetHash = $this->manager->getPlayerServer($uuidHex);
            if ($targetHash !== null) {
                $target = $this->manager->getServer($targetHash);
                if ($target !== null) {
                    $redirect = new RedirectPacket();
                    $redirect->uuid = $uuid;
                    $redirect->direct = $pk->direct;
                    $redirect->mcpeBuffer = $pk->payload;
                    $target->sendPacket($redirect);
                }
            }
        }
    }

    /**
     * @param FastPlayerListPacket $pk
     */
    private function handleFastPlayerList(FastPlayerListPacket $pk)
    {
        $uuidHex = bin2hex($pk->sendTo);
        $targetHash = $this->manager->getPlayerServer($uuidHex);
        if ($targetHash !== null) {
            $target = $this->manager->getServer($targetHash);
            if ($target !== null) {
                $target->sendPacket($pk);
            }
        }
    }

    private function forwardLoginPacket(PlayerLoginPacket $pk)
{
    $this->manager->forwardToAllExcept($this, $pk);
}
}
