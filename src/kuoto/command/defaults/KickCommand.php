<?php

namespace kuoto\command\defaults;

use kuoto\command\Command;
use kuoto\server\SynapseServer;

class KickCommand extends Command
{
	public function __construct(SynapseServer $proxy)
	{
		parent::__construct($proxy, 'kick', 'Expulsa a un jugador por nombre o UUID', 'kick <nombre|uuid> [razon]', array('k'));
	}

	public function execute($arguments)
	{
		if (trim($arguments) === '') {
			return $this->sendUsage();
		}

		$parts = explode(' ', trim($arguments), 2);
		$target = $parts[0];
		$reason = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : 'Expulsado por un administrador';
		$maybeUuid = strtolower(str_replace('-', '', $target));

		if (strlen($maybeUuid) === 32 && preg_match('/^[0-9a-fA-F]{32}$/', $maybeUuid) === 1) {
			$uuidHex = $maybeUuid;
		} else {
			$uuidHex = $this->getManager()->getUuidByName($target);
			if ($uuidHex === null) {
				$this->getLogger()->warning("Jugador no encontrado (ni por nombre ni por UUID): {$target}");
				return false;
			}
		}

		$resolvedUuid = $this->getManager()->resolvePlayerUuid($uuidHex);
		if ($resolvedUuid === null) {
			$this->getLogger()->warning("Jugador no encontrado: {$target}");
			return false;
		}

		$serverHash = $this->getManager()->getPlayerServer($resolvedUuid);
		if ($serverHash === null) {
			$this->getLogger()->warning("El jugador {$target} no esta asociado a ningun servidor");
			return false;
		}

		$server = $this->getManager()->getServer($serverHash);
		if ($server === null) {
			$this->getLogger()->warning("El servidor {$serverHash} ya no esta conectado");
			return false;
		}

		$sendUuid = $resolvedUuid;
		$playerInfo = $this->getManager()->getPlayerInfo($resolvedUuid);
		if ($playerInfo !== null && isset($playerInfo['backendUuid']) && preg_match('/^[0-9a-fA-F]{32}$/', $playerInfo['backendUuid']) === 1) {
			$sendUuid = strtolower($playerInfo['backendUuid']);
		}

		$binaryUuid = hex2bin($sendUuid);
		if ($binaryUuid === false || strlen($binaryUuid) !== 16) {
			$this->getLogger()->warning("UUID invalido para expulsar al jugador {$target}");
			return false;
		}

		$server->sendPlayerLogout($binaryUuid, $reason);
		$this->getManager()->unregisterPlayer($resolvedUuid);
		$rakProxy = $this->getManager()->getRakProxy();
		if ($rakProxy !== null) {
			$rakProxy->handlePlayerLogout($binaryUuid, $reason);
		}

		$this->getLogger()->info("Jugador {$target} expulsado: {$reason}");
		return true;
	}
}