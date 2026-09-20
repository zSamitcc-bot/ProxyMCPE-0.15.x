<?php

namespace kuoto\network\raklibproxy;

use kuoto\protocol\PlayerLoginPacket;
use kuoto\protocol\RedirectPacket;
use kuoto\server\ServerManager;

/**
 * Datos que llegan del backend hacia el cliente (RedirectPacket) y
 * el manejo de logout del jugador que reporta el backend.
 *
 * Extraido de RakLibProxy para mantener ese archivo por debajo de un
 * tamano manejable. No cambia ningun comportamiento.
 */
trait BackendRedirectTrait
{
    public function handleRedirectFromBackend($uuid, $data, $backendHash = null)
    {
        $uuidHex = bin2hex($uuid);

        // Find the session for this UUID
        $session = null;
        $sessionKey = null;

        // Try direct UUID match first -- either the original proxy-assigned uuid
        // (used before the backend has replied even once), or the backend's own
        // "real" uuid once we've learned it below.
        foreach ($this->sessions as $key => &$sess) {
            if ((isset($sess['uuidHex']) && $sess['uuidHex'] === $uuidHex)
                || (isset($sess['backendUuidHex']) && $sess['backendUuidHex'] === $uuidHex)) {
                $session = &$sess;
                $sessionKey = $key;
                break;
            }
        }

        // No direct match -- this is expected for the *first* redirect after a
        // login: the proxy sent its own temporary per-connection UUID in
        // PlayerLoginPacket, and the backend replies using the account's real
        // UUID (decoded from the login chain) instead. We have to map the two
        // together here.
        //
        // IMPORTANT: RedirectPacket carries no field that ties it back to a
        // specific session (no address/port, no echo of the original UUID), so
        // the only thing we can go on is "which session(s) are still genuinely
        // waiting on a backend reply". Collect ALL such candidates rather than
        // grabbing the first one blindly -- a stale/ghost session whose reply
        // was lost (client vanished without a clean disconnect) must never be
        // able to steal the mapping meant for the player who is actually
        // connecting right now, or that real player's game data gets sent to a
        // dead socket and they silently time out a moment after joining.
        if ($session === null) {
            $candidates = [];
            foreach ($this->sessions as $key => &$sess) {
                if ($sess['state'] === self::STATE_CONNECTED && !empty($sess['waitingForBackend'])) {
                    // Prefer sessions assigned to the same backend server this
                    // redirect actually came from, when we know it.
                    if ($backendHash !== null && isset($sess['backendHash']) && $sess['backendHash'] !== $backendHash) {
                        continue;
                    }
                    $candidates[$key] = &$sess;
                }
            }
            unset($sess);

            if (count($candidates) > 1) {
                $this->logger->warning("Ambiguous backend UUID mapping for {$uuidHex}: " . count($candidates) . " sessions waiting simultaneously (" . implode(', ', array_keys($candidates)) . ") -- picking the oldest pending login");
            }

            // Break ties (or just pick the only candidate) by earliest
            // backendWaitStarted, i.e. whichever login was actually sent first.
            $bestKey = null;
            $bestTime = INF;
            foreach ($candidates as $key => &$sess) {
                $started = isset($sess['backendWaitStarted']) ? $sess['backendWaitStarted'] : 0;
                if ($started < $bestTime) {
                    $bestTime = $started;
                    $bestKey = $key;
                }
            }
            unset($sess);

            if ($bestKey !== null) {
                $sess = &$this->sessions[$bestKey];
                // DO NOT overwrite $sess['uuid']/uuidHex here. That uuid is what
                // the proxy originally sent the backend in PlayerLoginPacket, and
                // the backend's internal player map (Synapse::$players in the real
                // SynapseAPI-derived backend code) is keyed by that exact value
                // forever -- it's never re-indexed when the backend later learns
                // the player's real account uuid from the login chain. Every
                // RedirectPacket the proxy sends to the backend for this player
                // (movement, chat, everything post-login) MUST keep using the
                // original uuid, or the backend's isset($this->players[$uuid])
                // check silently fails and drops the packet -- which is exactly
                // what caused the ~15s-after-join kick: the client got no more
                // world/keepalive responses and timed out on its own.
                //
                // We only need the backend's uuid to recognize *its own* outgoing
                // RedirectPackets for this player from now on, so stash it under
                // a separate key instead of replacing the routing uuid.
                $sess['backendUuid'] = $uuid;
                $sess['backendUuidHex'] = $uuidHex;
                $sess['waitingForBackend'] = false;
                // Sin esto, ServerManager::resolvePlayerUuid() nunca aprende la
                // relacion uuid-real <-> uuid-interno, y cualquier comando que
                // reciba el UUID real de cuenta (kick, etc.) falla con
                // "jugador no encontrado" aunque el jugador este conectado.
                $this->manager->setBackendUuid($sess['uuidHex'], $uuidHex);
                $this->logger->info("UUID mapeado desde el backend: {$sess['uuidHex']} <-> {$uuidHex}");
                $session = &$sess;
                $sessionKey = $bestKey;
            }
        }

        if ($session === null || $session['state'] !== self::STATE_CONNECTED) {
            $this->logger->debug("No session found for UUID {$uuidHex}");
            return false;
        }

        // Check if data is already a batch packet (starts with 0xFE)
        if (ord($data[0]) === 0xfe && strlen($data) > 10) {
            $this->sendEncapsulated($session, $data, self::RELIABLE_ORDERED);
            return true;
        }

        // Backend sends raw MCPE packets.
        // Genisys batch format: 0xFE + 0x06 + compressed_length(4) + gzcompress(payload)
        // Payload: int(length) + packet_data
        $payload = pack("N", strlen($data)) . $data;
        $compressed = @gzcompress($payload);
        if ($compressed !== false && strlen($compressed) > 0) {
            $batch = chr(0xfe) . chr(0x06) . pack("N", strlen($compressed)) . $compressed;
            $this->sendEncapsulated($session, $batch, self::RELIABLE_ORDERED);
        } else {
            $this->sendEncapsulated($session, $data, self::RELIABLE_ORDERED);
        }
        return true;
    }

    public function handlePlayerLogout($uuid, $reason = '')
{
    $uuidHex = strtolower(str_replace('-', '', bin2hex($uuid)));

    foreach ($this->sessions as $key => &$session) {
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

        if ($session['state'] === self::STATE_CONNECTED) {
            $message = $reason !== 'xd'
                ? $reason
                : 'Has sido expulsado del servidor';

            $this->sendDisconnect(
                $session,
                $message
            );
        }

        $internalUuidHex = $session['uuidHex'];

        $this->manager->unregisterPlayer(
            $internalUuidHex
        );

        $address = isset($session['address'])
            ? $session['address']
            : 'unknown';

        $port = isset($session['port'])
            ? $session['port']
            : 'unknown';

        unset($this->sessions[$key]);

        $this->logger->info(
            "Sesion eliminada para {$address}:{$port}"
            . ($reason !== ''
                ? " (motivo: {$reason})"
                : '')
        );

        return true;
    }

    $resolved = $this->manager->resolvePlayerUuid(
        $uuidHex
    );

    if ($resolved !== null) {
        $this->manager->unregisterPlayer(
            $resolved
        );
    }

    return false;
}
}
