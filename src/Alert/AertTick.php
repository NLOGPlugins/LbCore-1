<?php
namespace Alert;

use pocketmine\scheduler\PluginTask;
use Alert\Alert;

/**
 * Task for alert add-on, counts time to send alert messages in lobby, 
 * in countdown and in tournaments
 */
class AlertTick extends PluginTask {
	/**@var int*/
	private $lastTick = 0;
	/**@var Alert*/
	private $alert;

	public function __construct($plugin, $alert) {
		parent::__construct($plugin);
		$this->lastTick = time();
		$this->alert = $alert;
	}

	/**
	 * Permanently check if it's time to send alert message,
	 * also update arena data
	 * 
	 * @param $currentTick
	 */
	public function onRun($currentTick) {
		$this->lastTick = time();
		if($this->alert->timeBeforeLobbyAlert > 0){
			$this->alert->timeBeforeLobbyAlert--;
		}
		if($this->alert->timeBeforeLobbyAlert == 0){
			$this->alert->timeBeforeLobbyAlert = Alert::$LOBBY_ALERT_INTERVAL;
			$this->alert->sendLobbyMessage();
		}
		
		foreach ($this->alert->timeBeforeCountdownAlert as $arenaId => $timeBeforeCountdownAlert){
			if($timeBeforeCountdownAlert > 0){
				$this->alert->timeBeforeCountdownAlert[$arenaId]--;
			}
			if($timeBeforeCountdownAlert == 0){
				$this->alert->timeBeforeCountdownAlert[$arenaId] = Alert::$COUNTDOWN_ALERT_INTERVAL;
				$this->alert->sendCountdownMessage($arenaId);
			}
		}
		
		foreach ($this->alert->timeBeforeGameAlert as $arenaId => $timeBeforeGameAlert){
			if($timeBeforeGameAlert > 0){
				$this->alert->timeBeforeGameAlert[$arenaId]--;
			}
			if($timeBeforeGameAlert == 0){
				$this->alert->timeBeforeGameAlert[$arenaId] = Alert::$GAME_ALERT_INTERVAL;
				$this->alert->sendGameMessage($arenaId);
			}
		}
		
		if($this->lastTick % 5 == 0){
			$this->alert->updateArenas();
		}

	}
}
