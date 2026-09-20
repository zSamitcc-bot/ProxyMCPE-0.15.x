<?php

namespace kuoto\network\raklibproxy;

use kuoto\protocol\ChangeDimensionPacket;
use kuoto\protocol\ClientDisconnectPacket;
use kuoto\protocol\PlayerLoginPacket;
use kuoto\protocol\PlayerLogoutPacket;
use kuoto\protocol\RedirectPacket;
use kuoto\protocol\TransferPacket;
use kuoto\event\player\PlayerPreLoginEvent;
use kuoto\event\player\PlayerServerSelectEvent;
use kuoto\server\ServerManager;

/**
 * Asignacion de backend, transferencia entre servidores y desconexion
 * de sesiones (transferPlayer, assignToServer, selectServer,
 * sendDisconnect, disconnectSession, handlePlayerDisconnect).
 *
 * Extraido de RakLibProxy para mantener ese archivo por debajo de un
 * tamano manejable. No cambia ningun comportamiento.
 */
trait ServerAssignmentTrait
{
    public function transferPlayer($uuid, $targetHash)
{
    $uuidHex = strtolower(str_replace('-', '', bin2hex($uuid)));

    $target = $this->manager->getServer($targetHash);

    if ($target === null || !$target->isAuthenticated()) {
        $this->logger->warning(
            "No se puede trasladar {$uuidHex}: destino {$targetHash} no disponible"
        );

        return false;
    }

    foreach ($this->sessions as $key => &$session) {
        if ($session['state'] !== self::STATE_CONNECTED) {
            continue;
        }

        $match = false;

        if (isset($session['uuidHex'])
            && strtolower($session['uuidHex']) === $uuidHex) {

            $match = true;
        }

        if (!$match
            && isset($session['backendUuidHex'])
            && strtolower($session['backendUuidHex']) === $uuidHex) {

            $match = true;
        }

        if (!$match) {
            continue;
        }

        $internalUuidHex = $session['uuidHex'];

        $oldHash = isset($session['backendHash'])
            ? $session['backendHash']
            : null;

        if ($oldHash === $targetHash) {
            $this->logger->warning(
                "El jugador {$internalUuidHex} ya esta conectado a {$targetHash}"
            );

            return false;
        }

        $currentDimension = isset($session['dimension'])
            ? $session['dimension']
            : ChangeDimensionPacket::DIMENSION_NORMAL;

        $fakeDimension = $currentDimension === ChangeDimensionPacket::DIMENSION_NETHER
            ? ChangeDimensionPacket::DIMENSION_NORMAL
            : ChangeDimensionPacket::DIMENSION_NETHER;

        $this->sendChangeDimension(
            $session,
            $fakeDimension
        );

        $session['dimension'] = $fakeDimension;

        if ($oldHash !== null) {
            $oldServer = $this->manager->getServer($oldHash);

            if ($oldServer !== null) {
                $logout = new PlayerLogoutPacket();
                $logout->uuid = $session['uuid'];
                $logout->reason = 'transfer';

                $oldServer->sendPacket($logout);

                $this->logger->info(
                    "PlayerLogout enviado a {$oldHash} para {$internalUuidHex}"
                );
            }
        }

        $moved = $this->manager->movePlayer(
            $internalUuidHex,
            $targetHash
        );

        if (!$moved) {
            $this->manager->registerPlayer(
                $internalUuidHex,
                $targetHash,
                $session['address'],
                $session['port']
            );
        }

        $session['backendHash'] = $targetHash;
        $session['waitingForBackend'] = true;
        $session['backendWaitStarted'] = microtime(true);

        $loginPacket = isset($session['loginPacket'])
            ? $session['loginPacket']
            : '';

        $login = new PlayerLoginPacket();
        $login->uuid = $session['uuid'];
        $login->address = $session['address'];
        $login->port = $session['port'];
        $login->isFirstTime = false;
        $login->cachedLoginPacket = $loginPacket;

        $target->sendPacket($login);

        $this->logger->info(
            "PlayerLogin reenviado a {$targetHash} para {$internalUuidHex}"
        );

        $transfer = new TransferPacket();
        $transfer->uuid = $session['uuid'];
        $transfer->clientHash = $targetHash;

        $target->sendPacket($transfer);

        $this->logger->info(
            "TransferPacket enviado a {$targetHash} para {$internalUuidHex}"
        );

        if (isset($session['pendingBuffers'])
            && !empty($session['pendingBuffers'])) {

            foreach ($session['pendingBuffers'] as $buf) {
                $redirect = new RedirectPacket();
                $redirect->uuid = $session['uuid'];
                $redirect->direct = false;
                $redirect->mcpeBuffer = $buf;

                $target->sendPacket($redirect);
            }

            $session['pendingBuffers'] = array();
        }

        $this->logger->info(
            "Ruta de {$internalUuidHex} cambiada: "
            . $oldHash . " -> " . $targetHash
        );

        return true;
    }

    $resolved = $this->manager->resolvePlayerUuid($uuidHex);

    if ($resolved !== null) {
        foreach ($this->sessions as $key => &$session) {
            if (!isset($session['uuidHex'])) {
                continue;
            }

            if (strtolower($session['uuidHex']) !== $resolved) {
                continue;
            }

            if ($session['state'] !== self::STATE_CONNECTED) {
                continue;
            }

            return $this->transferPlayer(
                $session['uuid'],
                $targetHash
            );
        }
    }

    $this->logger->warning(
        "No se encontro sesion RakNet para UUID {$uuidHex}"
    );

    return false;
}

    /**
     * Busca la sesion cuyo UUID "real" de cuenta (el que decodifica el
     * backend del login chain, guardado en backendUuidHex una vez llega el
     * primer RedirectPacket) coincide con el que se pasa, y devuelve el
     * UUID interno del proxy -- el que se usa en todos lados (ServerManager,
     * /kick, sesiones RakNet). Los paquetes que vienen del backend (como
     * FastPlayerListPacket) solo traen el UUID real de cuenta, nunca el
     * interno del proxy, asi que hace falta esta traduccion para poder
     * asociar un nombre de jugador a la sesion correcta.
     *
     * @param string $backendUuidHex
     * @return string|null
     */
    public function getSessionUuidHexByBackendUuid($backendUuidHex)
    {
        foreach ($this->sessions as $session) {
            if (isset($session['backendUuidHex']) && $session['backendUuidHex'] === $backendUuidHex) {
                return $session['uuidHex'];
            }
        }

        return null;
    }

    /**
     * Assign player to a backend server
     */
    private function assignToServer($key, &$session)
    {
        if (isset($session['backendHash']) && $session['backendHash'] !== null) {
            return; // Already assigned
        }

        $servers = $this->manager->getAuthenticatedServers();
        if (empty($servers)) {
            $this->logger->warning("No PocketMine servers available for {$session['address']}:{$session['port']}");
            $this->logger->warning(
    "No PocketMine servers available for {$session['address']}:{$session['port']}"
);
$this->disconnectSession($key, $session);

return;
        }

        // Ultimo punto en el que se puede rechazar a un jugador (whitelist,
        // baneos, mantenimiento...) antes de meterlo en un backend.
        $preLogin = new PlayerPreLoginEvent($session['uuid'], $session['address'], $session['port']);
        $preLogin->call();
        if ($preLogin->isCancelled()) {
            $this->logger->info(
                "Login de {$session['address']}:{$session['port']} cancelado por un listener: "
                . $preLogin->getKickMessage()
            );
            $this->disconnectSession($key, $session);
            return;
        }

        $bestServer = $this->selectServer($servers);

        if ($bestServer !== null) {
            // Un listener puede sobreescribir la eleccion del balanceador
            // (lobbies, colas, VIPs...) o cancelar la asignacion.
            $selectEvent = new PlayerServerSelectEvent(
                $session['uuid'],
                $session['address'],
                $session['port'],
                $bestServer,
                $servers
            );
            $selectEvent->call();
            if ($selectEvent->isCancelled()) {
                $this->logger->info("Asignacion de servidor cancelada para {$session['address']}:{$session['port']}");
                return;
            }
            $bestServer = $selectEvent->getTargetServer();

            $session['backendHash'] = $bestServer->getHash();
            $this->logger->info("Jugador {$session['address']}:{$session['port']} -> {$bestServer->getHash()}");

            // Register the player with the manager so getPlayerCount()/getPlayerServer()
            // reflect reality. ClientConnection::handlePlayerLogin() (the only other
            // caller of registerPlayer()) only fires when the proxy RECEIVES a
            // PlayerLoginPacket from a backend, but here the proxy is the one SENDING
            // it to the backend -- so without this call the player is never registered
            // and the player counter stays stuck at 0 forever.
            $this->manager->registerPlayer($session['uuidHex'], $bestServer->getHash(), $session['address'], $session['port']);

            // Send PlayerLoginPacket to backend with the login packet
            $loginPacket = isset($session['loginPacket']) && $session['loginPacket'] !== null ? $session['loginPacket'] : '';

            $pk = new PlayerLoginPacket();
            $pk->uuid = $session['uuid'];
            $pk->address = $session['address'];
            $pk->port = $session['port'];
            $pk->isFirstTime = true;
            $pk->cachedLoginPacket = $loginPacket;

            $bestServer->sendPacket($pk);
            $session['waitingForBackend'] = true;
            // Stamped so handleRedirectFromBackend() can tell a genuinely-pending
            // login apart from a session that has been "waiting" forever because
            // its backend reply was lost (see tick()'s expiry pass below) -- without
            // this, a stuck session can silently steal the UUID mapping meant for a
            // different, currently-connecting player.
            $session['backendWaitStarted'] = microtime(true);
            $this->logger->debug("PlayerLogin enviado para {$session['address']}:{$session['port']} (loginPacket=" . strlen($loginPacket) . "b)");

            // Now forward any pending buffered packets
            if (isset($session['pendingBuffers']) && !empty($session['pendingBuffers'])) {
                $this->logger->debug("Forwarding " . count($session['pendingBuffers']) . " buffered packets for {$key}");
                foreach ($session['pendingBuffers'] as $buf) {
                    $redirect = new RedirectPacket();
                    $redirect->uuid = $session['uuid'];
                    $redirect->direct = false;
                    $redirect->mcpeBuffer = $buf;
                    $bestServer->sendPacket($redirect);
                }
                $session['pendingBuffers'] = [];
            }
        }
    }

    /**
     * Balanceo por defecto: el backend autenticado con menos jugadores.
     *
     * Extraido de assignToServer() para que la politica de balanceo sea una
     * sola cosa facil de cambiar (o de sustituir desde un listener con
     * PlayerServerSelectEvent).
     *
     * @param ClientConnection[] $servers
     * @return ClientConnection|null
     */
    private function selectServer(array $servers)
    {
        $best = null;
        $minPlayers = PHP_INT_MAX;

        foreach ($servers as $server) {
            $info = $server->getInfo();
            if ($info['playerCount'] < $minPlayers) {
                $minPlayers = $info['playerCount'];
                $best = $server;
            }
        }

        return $best;
    }


    /**
     * Manda un ChangeDimensionPacket directo al cliente para forzar la
     * pantalla de carga ("Generando terreno"). Se usa como truco visual al
     * trasladar un jugador entre backends: el cliente tapa el mundo viejo
     * con la pantalla de carga mientras el nuevo servidor termina su login,
     * en vez de dejar ver el mundo congelado o el vacio.
     */
    private function sendChangeDimension(&$session, $dimension, $x = 0.0, $y = 0.0, $z = 0.0)
{
    $pk = new ChangeDimensionPacket();
    $pk->dimension = $dimension;
    $pk->x = $x;
    $pk->y = $y;
    $pk->z = $z;
    $pk->encode();

    $this->sendEncapsulated(
        $session,
        $pk->getBuffer(),
        self::RELIABLE_ORDERED
    );
}

    private function sendDisconnect(&$session, $message)
{
    $pk = new ClientDisconnectPacket();
    $pk->message = $message;
    $pk->encode();

    $this->logger->info(
        "Enviando DisconnectPacket a {$session['address']}:{$session['port']}: {$message}"
    );

    $this->logger->info(
        "Disconnect HEX: " . bin2hex($pk->getBuffer())
    );

    $this->sendEncapsulated(
        $session,
        $pk->getBuffer(),
        self::RELIABLE_ORDERED
    );
}
    /**
     * Cierra una sesion de jugador desde el lado del proxy (sin backend
     * asignado todavia), avisando al cliente.
     *
     * @param string $key
     * @param array $session
     */
    private function disconnectSession($key, &$session)
{
    if ($session['state'] === self::STATE_CONNECTED) {
        $this->sendEncapsulated(
            $session,
            chr(0x15) . chr(0x00),
            self::RELIABLE_ORDERED
        );
    }

    $this->manager->unregisterPlayer($session['uuidHex']);

    unset($this->sessions[$key]);
    
}

    /**
     * Handle player disconnect
     */
    private function handlePlayerDisconnect($key, &$session)
    {
        if ($session['backendHash'] !== null) {
            $server = $this->manager->getServer($session['backendHash']);
            if ($server !== null) {
                $pk = new PlayerLogoutPacket();
                $pk->uuid = $session['uuid'];
                $server->sendPacket($pk);
            }
        }
        // Mirror the registerPlayer() call in assignToServer() -- otherwise the
        // manager's player map (and therefore getPlayerCount()/getPlayerServer())
        // leaks an entry every time a player disconnects.
        $this->manager->unregisterPlayer($session['uuidHex']);
        unset($this->sessions[$key]);
    }
}
