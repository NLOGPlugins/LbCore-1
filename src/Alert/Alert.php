<?php

namespace Alert;

use Alert\AlertTick;
use Alert\AlertEventListener;
use LbCore\player\LbPlayer;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

/**
 * Base class for alert logic, enables Listener and task,
 * prepares messages for different game plugins, different situations (lobby, tournament, countdown)
 */
class Alert {
	/*	 * @var array */
	private $langs = array(
		"English" => "en",
		"German" => "de",
		"Dutch" => "du",
		"Spanish" => "es"
	);
	/*	 * @var array */
	private $files = array(
		"lobby",
		"countdown",
		"game",
		"random"
	);
	/*	 * @var atring */
	private $alertsPath;
	/*	 * @var array */
	private $gameTypes = array(
		"bh" => "BountyHunter",
		"sg" => "SurvivalGames",
		"ctf" => "CaptureTheFlag",
		"sp" => "Engine",
		"fl" => "Fleet"
	);
	/*	 * @var string */
	private $server;
	/*	 * @var string */
	private $rdns;
	/*	 * @var string */
	private $gameType = false;
	/*	 * @var Plugin */
	private $gamePlugin;
	/*	 * @var string */
	private $alerts = array();
	/*	 * @var array */
	private $needMessage = array(
		LbPlayer::IN_LOBBY => true,
		LbPlayer::IN_COUNTDOWN => array(),
		LbPlayer::IN_GAME => array()
	);
	/*	 * @var int */
	public $timeBeforeLobbyAlert;
	/*	 * @var int */
	public $timeBeforeCountdownAlert;
	/*	 * @var int */
	public $timeBeforeGameAlert;
	/*	 * @var int */
	private $lobbyPostponeCount = 0;

//	const LOBBY_ALERT_INTERVAL = 90;
//	const COUNTDOWN_ALERT_INTERVAL = 20;
//	const GAME_ALERT_INTERVAL = 120;
//	const POSTPONED_INTERVAL = 5;
	public static $LOBBY_ALERT_INTERVAL = 30;
	public static $POSTPONE_MAX_COUNT_IN_LOBBY = 6;
	public static $COUNTDOWN_ALERT_INTERVAL = 20;
	public static $GAME_ALERT_INTERVAL = 120;
	public static $POSTPONED_INTERVAL = 5;

	/**
	 * Prepare alerts data for different languages,
	 * set default core options and options depending on game plugin
	 * 
	 * @param Plugin $plugin
	 */
	public function __construct($plugin) {
		foreach ($this->langs as $lang) {
			$this->alerts[$lang] = array();
			foreach ($this->files as $file) {
				$this->alerts[$lang][$file] = array();
			}
		}
		$this->server = Server::getInstance();
		$this->alertsPath = __DIR__ . "/data/";
		$this->timeBeforeLobbyAlert = self::$LOBBY_ALERT_INTERVAL;
		$this->timeBeforeCountdownAlert = array();
		$this->timeBeforeGameAlert = array();
		$this->server->getScheduler()->scheduleRepeatingTask(new AlertTick($plugin, $this), 20);
		$this->rdns = $this->server->getConfigString('server-dns', 'unknown.lbsg.net');
		$this->getFromJson("core");
		$gameType = $this->getGameType();
		if ($gameType) {
			$this->gameType = $gameType["game"];
			$this->gamePlugin = $this->server->getPluginManager()->getPlugin($gameType["game"]);
			$this->getFromJson($gameType["prefix"]);
		}
		$this->server->getPluginManager()->registerEvents(
				new AlertEventListener($this), $plugin
		);
	}

	/**
	 * Get alerts from suitable file by plugin name
	 * 
	 * @param string $pluginName
	 */
	private function getFromJson($pluginName) {
		foreach ($this->langs as $lang) {
			foreach ($this->files as $file) {
				$path = $this->alertsPath . "{$lang}/{$file}_alerts_{$pluginName}.json";
				if (!file_exists($path)) {
					continue;
				}

				$data = json_decode(file_get_contents($path));
				if (!$data || !is_array($data)) {
					continue;
				}

				foreach ($data as $mk => $messages) {
					if (!is_array($messages)) {
						continue;
					}

					foreach ($messages as $lk => $line) {
						if (stripos($line, '$TRANSLATE') !== false) {
							unset($data[$mk][$lk]);
						}
					}
				}
				$this->alerts[$lang][$file] = array_merge($this->alerts[$lang][$file], $data);
			}
		}
	}

	/**
	 * Send message to all players in lobby
	 */
	public function sendLobbyMessage() {
//		if ($this->needMessage[LbPlayer::IN_LOBBY]) {
			$messages = array();
			foreach ($this->server->getOnlinePlayers() as $p) {
				if ($p->getState() == LbPlayer::IN_LOBBY) {
					if ($message = $this->prepareMessage($p, $messages, "lobby")) {
						$this->sendAlertMessage($p, $message);
					}
				}
			}
			//clear postpone interval
			$this->lobbyPostponeCount = 0;
//		} else {
//			$this->needMessage[LbPlayer::IN_LOBBY] = true;
//			$this->needLobbyAlert = true;
//		}

	}

	/**
	 * Send countdown messages to all players on specified arena
	 * 
	 * @param int $arenaId
	 */
	public function sendCountdownMessage($arenaId) {
		if (!isset($this->needMessage[LbPlayer::IN_COUNTDOWN][$arenaId]) || $this->needMessage[LbPlayer::IN_COUNTDOWN][$arenaId]) {
			$messages = array();
			foreach ($this->server->getOnlinePlayers() as $p) {
				if ($p->getState() == LbPlayer::IN_COUNTDOWN && $p->getCurrentArenaId() == $arenaId) {
					if ($message = $this->prepareMessage($p, $messages, "countdown")) {
						$this->sendAlertMessage($p, $message);
					}
				}
			}
		} else {
			$this->needMessage[LbPlayer::IN_COUNTDOWN][$arenaId] = true;
		}
	}

	/**
	 * Send game messages to all players on specified arena
	 * 
	 * @param int $arenaId
	 */
	public function sendGameMessage($arenaId) {
		if (!isset($this->needMessage[LbPlayer::IN_GAME][$arenaId]) || $this->needMessage[LbPlayer::IN_GAME][$arenaId]) {
			$messages = array();
			foreach ($this->server->getOnlinePlayers() as $p) {
				if ($p->getState() == LbPlayer::IN_GAME && $p->getCurrentArenaId() == $arenaId) {
					if ($message = $this->prepareMessage($p, $messages, "game")) {
						$this->sendAlertMessage($p, $message);
					}
				}
			}
		} else {
			$this->needMessage[LbPlayer::IN_GAME][$arenaId] = true;
		}
	}

	/**
	 * Repeating method called from AlertTick to authomatically update 
	 * amount of receivers inside arena and increment time
	 */
	public function updateArenas() {
		$timeBeforeCountdownAlert = array();
		$timeBeforeGameAlert = array();
		$players = $this->server->getOnlinePlayers();
		foreach ($players as $player) {
			$arenaId = $player->getCurrentArenaId();
			if ($player->getState() == LbPlayer::IN_COUNTDOWN) {
				if (!isset($this->timeBeforeCountdownAlert[$arenaId])) {
					$timeBeforeCountdownAlert[$arenaId] = self::$COUNTDOWN_ALERT_INTERVAL;
				} else {
					$timeBeforeCountdownAlert[$arenaId] = $this->timeBeforeCountdownAlert[$arenaId];
				}
			}
			if ($player->getState() == LbPlayer::IN_GAME) {
				if (!isset($this->timeBeforeGameAlert[$arenaId])) {
					$timeBeforeGameAlert[$arenaId] = self::$GAME_ALERT_INTERVAL;
				} else {
					$timeBeforeGameAlert[$arenaId] = $this->timeBeforeGameAlert[$arenaId];
				}
			}
		}
		$this->timeBeforeCountdownAlert = $timeBeforeCountdownAlert;
		$this->timeBeforeGameAlert = $timeBeforeGameAlert;
	}

	/**
	 * Method used to make delay between important messages from
	 * @param LbPlayer $player
	 */
	public function postponeMessage(LbPlayer $player) {
		$arenaId = $player->getCurrentArenaId();
		if ($arenaId !== LbPlayer::NOT_IN_ARENA) {
			$this->needMessage[$player->getState()][$arenaId] = false;
			//in lobby make time to alert 5 seconds more, but not 30 seconds postpone total
		} elseif ($this->lobbyPostponeCount < self::$POSTPONE_MAX_COUNT_IN_LOBBY) {
			$this->timeBeforeLobbyAlert += self::$POSTPONED_INTERVAL;
			$this->lobbyPostponeCount++;
//			$this->needMessage[$player->getState()] = false;
		}
	}

	/**
	 * prepare message from array of available messages
	 * depending on player's language, game status and arena id
	 * 
	 * @param LbPlayer $player
	 * @param array $messages
	 * @param string $type
	 * @return string|boolean
	 */
	private function prepareMessage(LbPlayer $player, &$messages, $type) {
		if (isset($this->langs[$player->language])) {
			$lang = $this->langs[$player->language];
		} else {
			$lang = "en";
		}
		if (!isset($this->alerts[$lang][$type]) || count($this->alerts[$lang][$type]) == 0) {
			return false;
		}
		if (!isset($messages[$lang])) {
			$messages[$lang] = $this->alerts[$lang][$type][array_rand($this->alerts[$lang][$type])];
			if (is_array($messages[$lang])) {
				$messages[$lang] = implode("\n" . TextFormat::GRAY, $messages[$lang]);
			}
			if (stripos($messages[$lang], '$RANDOM') !== false) {
				$messages[$lang] = $this->alerts[$lang]["random"][array_rand($this->alerts[$lang]["random"])];
				if (is_array($messages[$lang])) {
					$messages[$lang] = implode("\n" . TextFormat::GRAY, $messages[$lang]);
				}
			} elseif (stripos($messages[$lang], '$CENSUS') !== false) {
				if ($this->gameType) {
					if (method_exists($this->gamePlugin, "getCensus") &&
							$player->getCurrentArenaId() != LbPlayer::NOT_IN_ARENA) {

						$messages[$lang] = $this->gamePlugin->getCensus($player->getCurrentArenaId());
						if ($messages[$lang]) {
							return $messages[$lang];
						}
					}
				}
				$messages[$lang] = false;
				return false;
			} elseif (stripos($messages[$lang], '$SERVER') !== false) {
				$messages[$lang] = "You are playing on server " . $this->rdns;
			} elseif (stripos($messages[$lang], '$MAP') !== false) {
				if ($this->gameType) {
					if (method_exists($this->gamePlugin, "map")) {
						$messages[$lang] = $this->gamePlugin->map($player);
						if ($messages[$lang]) {
							return $messages[$lang];
						}
					}
				}
				$messages[$lang] = false;
				return false;
			}
		}
		if ($messages[$lang]) {
			return TextFormat::GRAY . $messages[$lang];
		}
		return false;
	}

	/**
	 * Get current game plugin 
	 * 
	 * @return boolean
	 */
	public function getGameType() {
		foreach ($this->gameTypes as $prefix => $game) {
			if (!is_null($this->server->getPluginManager()->getPlugin($game))) {
				return array("prefix" => $prefix, "game" => $game);
			}
		}
		return false;
	}

	/**
	 * Send prepared message to player
	 * 
	 * @param Player $player
	 * @param string $message
	 */
	private function sendAlertMessage($player, $message) {
		if ($player->isVip() && (stripos($message, "VIP") !== false || stripos($message, "app") !== false)) {
			return;
		}
		if ($player->isRegistered() && stripos($message, "register") !== false) {
			return;
		}
		$player->sendMessage($message);
	}

}
