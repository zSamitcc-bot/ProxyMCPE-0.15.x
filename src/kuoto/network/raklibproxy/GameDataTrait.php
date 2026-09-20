<?php

namespace kuoto\network\raklibproxy;

use kuoto\protocol\PlayerLoginPacket;
use kuoto\protocol\RedirectPacket;

/**
 * Reenvio de datos de juego (MCPE) del cliente al backend: desempaquetado
 * de batches, deteccion del login del jugador y descompresion de batches
 * que llegan del backend.
 *
 * Extraido de RakLibProxy para mantener ese archivo por debajo de un
 * tamano manejable. No cambia ningun comportamiento.
 */
trait GameDataTrait
{
    // ========== GAME DATA ==========

    /**
     * Forward game data from client to backend server
     * Handles the MCPE login flow: buffer packets until we find the real login,
     * then send PlayerLoginPacket to backend with the actual login data.
     *
     * IMPORTANT: the real SPP backend (Synapse::getPacket()/SynLibInterface::putPacket())
     * never decompresses zlib -- it only strips a single leading 0xFE byte and treats
     * whatever follows as ONE already-decoded packet. Every MCPE 0.15.x batch (0xFE +
     * zlib-compressed [length+data]*N) coming from the real client MUST therefore be
     * unwrapped into its individual packets here, with one RedirectPacket sent per
     * packet -- forwarding the raw compressed buffer as-is (as before) would leave the
     * backend unable to decode anything sent after the initial login (movement, chat,
     * inventory, chunk requests, etc. are almost always sent batched).
     */
    private function forwardGameData($key, &$session, $buffer)
    {
        if ($session['backendHash'] === null) {
            $packetId = ord($buffer[0]);

            // MCPE 0.15.x Genisys login packet is 0x01, sent unbatched
            if ($packetId === 0x01) {
                $session['loginPacket'] = $buffer;
                $this->logger->debug("Got raw login packet from {$key} (" . strlen($buffer) . "b)");
                $this->assignToServer($key, $session);
                return;
            }

            if (!isset($session['pendingBuffers'])) {
                $session['pendingBuffers'] = [];
            }

            if ($packetId === 0xfe) {
                // Unwrap the batch into individual packets (see unwrapBatch() doc)
                // and look for the login packet (0x01) among them.
                $packets = $this->unwrapBatch($buffer);
                $loginData = null;
                foreach ($packets as $pkData) {
                    if ($loginData === null && strlen($pkData) > 0 && ord($pkData[0]) === 0x01) {
                        $loginData = $pkData;
                        continue;
                    }
                    // Queue every other unwrapped packet as an individual pending
                    // buffer -- never the raw batch -- so assignToServer() can later
                    // forward them one RedirectPacket at a time, matching what the
                    // backend expects.
                    $session['pendingBuffers'][] = $pkData;
                }

                if ($loginData !== null) {
                    $session['loginPacket'] = $loginData;
                    $this->logger->debug("Extracted login from batch for {$key} (" . strlen($loginData) . "b)");
                    $this->assignToServer($key, $session);
                }
                return;
            }

            // Any other pre-login packet (e.g. 0x00 keepalive) is already a single
            // unbatched packet - buffer it as-is.
            $session['pendingBuffers'][] = $buffer;
            $this->logger->debug("Buffering pre-login packet 0x" . dechex($packetId) . " from {$key} (" . strlen($buffer) . "b)");
            return;
        }

        $server = $this->manager->getServer($session['backendHash']);
        if ($server === null || !$server->isAuthenticated()) {
            return;
        }

        // Unwrap batches here too: post-login client traffic is batched just as
        // often as the login packet was, and the backend can't decompress it either.
        $packets = (strlen($buffer) > 0 && ord($buffer[0]) === 0xfe) ? $this->unwrapBatch($buffer) : [$buffer];

        foreach ($packets as $pkData) {
            $redirect = new RedirectPacket();
            $redirect->uuid = $session['uuid'];
            $redirect->direct = false;
            $redirect->mcpeBuffer = $pkData;
            $server->sendPacket($redirect);
        }
    }

    /**
     * Unwrap a MCPE 0.15.x batch packet (0xFE + zlib-compressed [length(4)+data]*N)
     * into its individual raw packets. Returns a list of raw packet byte strings,
     * each starting with its own real packet ID (never 0xFE).
     *
     * If the buffer isn't a batch, or decompression/parsing fails entirely, returns
     * the original buffer unchanged as the sole element -- the caller (or the
     * backend) will have to deal with it as-is, but we don't silently drop data.
     */
    private function unwrapBatch($buffer)
    {
        if (strlen($buffer) < 2 || ord($buffer[0]) !== 0xfe) {
            return [$buffer];
        }

        $compressed = substr($buffer, 1);

        if ($this->logger->isDebugEnabled()) {
            $this->logger->debug("Batch hex (first 20): " . bin2hex(substr($compressed, 0, 20)));
        }

        // Check if maybe no compression at all - try parsing as raw payload
        // MCPE batch without compression: [4-byte count] + [4-byte len + pk] * N
        $pkCount = unpack('N', substr($compressed, 0, 4))[1];
        if ($pkCount > 0 && $pkCount < 100) {
            $firstLen = unpack('N', substr($compressed, 4, 4))[1];
            if ($firstLen > 0 && $firstLen < strlen($compressed) - 8) {
                $parsed = $this->parseBatchPayload(substr($compressed, 4), 'no_count');
                if (!empty($parsed)) {
                    $this->logger->debug("Batch appears uncompressed: count={$pkCount} firstLen={$firstLen}");
                    return $parsed;
                }
            }
        }

        $decompressed = false;
        $method = 'none';

        // MCPE 0.15.x batch: 0xFE + count(1) + length(4) + zlib_data
        // ZLIB header (78 da) is at offset 6 of buffer
        $offsets = [6, 5, 1]; // Try offset 6 first (where 78da zlib header is)
        foreach ($offsets as $off) {
            if ($off >= strlen($buffer)) continue;
            $data = substr($buffer, $off);

            $result = @zlib_decode($data); // raw DEFLATE - what PocketMine uses
            if ($result !== false && strlen($result) > 0) {
                $decompressed = $result;
                $method = 'zlib_decode@' . $off;
                break;
            }

            $result = @gzuncompress($data); // ZLIB format
            if ($result !== false && strlen($result) > 0) {
                $decompressed = $result;
                $method = 'gzuncompress@' . $off;
                break;
            }

            $result = @gzdecode($data); // GZIP
            if ($result !== false && strlen($result) > 0) {
                $decompressed = $result;
                $method = 'gzdecode@' . $off;
                break;
            }

            $result = @gzinflate($data);
            if ($result !== false && strlen($result) > 0) {
                $decompressed = $result;
                $method = 'gzinflate@' . $off;
                break;
            }
        }

        if ($decompressed === false || strlen($decompressed) === 0) {
            $this->logger->debug("All batch decompression methods failed for len=" . strlen($buffer) . " - forwarding raw buffer unchanged");
            return [$buffer];
        }

        if ($this->logger->isDebugEnabled()) {
            $this->logger->debug("Batch method={$method} decompressed: " . strlen($compressed) . " -> " . strlen($decompressed) . " bytes");
            $this->logger->debug("Batch decompressed hex (first 32): " . bin2hex(substr($decompressed, 0, 32)));
        }

        foreach (['with_count' => 4, 'no_count' => 0] as $fname => $startOffset) {
            $parsed = $this->parseBatchPayload(substr($decompressed, $startOffset), $fname);
            if (!empty($parsed)) {
                return $parsed;
            }
        }

        // Last resort: the decompressed data might just be a single raw packet
        // with no [length+data] framing at all.
        if (strlen($decompressed) > 0) {
            $this->logger->debug("No [length+data] framing found in batch, treating decompressed data as a single packet");
            return [$decompressed];
        }

        return [$buffer];
    }

    /**
     * Parse a [length(4) + data] * N payload into individual packets.
     * @return string[] empty array if parsing didn't yield anything usable
     */
    private function parseBatchPayload($decompressed, $label)
    {
        $offset = 0;
        $found = [];
        while ($offset < strlen($decompressed)) {
            if ($offset + 4 > strlen($decompressed)) break;
            $pkLen = unpack('N', substr($decompressed, $offset, 4))[1];
            $offset += 4;
            if ($pkLen <= 0 || $pkLen > 65535 || $offset + $pkLen > strlen($decompressed)) {
                break;
            }
            $pkData = substr($decompressed, $offset, $pkLen);
            $offset += $pkLen;
            $found[] = $pkData;
        }

        if (!empty($found) && $this->logger->isDebugEnabled()) {
            $names = [];
            foreach ($found as $pkData) {
                $names[] = '0x' . dechex(ord($pkData[0])) . '(' . strlen($pkData) . 'b)';
            }
            $this->logger->debug("Batch {$label}: " . implode(', ', $names));
        }

        return $found;
    }


    private function decompressBatch($data)
    {
        $packets = [];
        if (strlen($data) < 2 || ord($data[0]) !== 0xfe) {
            return $packets;
        }

        // Try zlib_decode (raw DEFLATE) first - what PocketMine uses
        $decompressed = @zlib_decode(substr($data, 1));
        if ($decompressed === false || strlen($decompressed) === 0) {
            // Try gzuncompress (ZLIB format)
            $decompressed = @gzuncompress(substr($data, 1));
        }
        if ($decompressed === false || strlen($decompressed) === 0) {
            return $packets;
        }

        // Parse: [length(4) + data] * N
        $offset = 0;
        while ($offset < strlen($decompressed)) {
            if ($offset + 4 > strlen($decompressed)) break;
            $pkLen = unpack('N', substr($decompressed, $offset, 4))[1];
            $offset += 4;
            if ($pkLen <= 0 || $pkLen > 65535 || $offset + $pkLen > strlen($decompressed)) break;
            $packets[] = substr($decompressed, $offset, $pkLen);
            $offset += $pkLen;
        }

        return $packets;
    }
}
