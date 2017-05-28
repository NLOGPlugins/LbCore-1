<?php

namespace Alert;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;

/**
 * Listener for chat event to delay alert messages
 */
class AlertEventListener implements Listener {
	/**@var Alert*/
	private $alert;
	protected $excludeDelayGameType = array('fl');
	
	public function __construct($alert) {
		$this->alert = $alert;
	}
	
	/**
	 * 
	 * @param PlayerChatEvent $event
	 */
	public function onPlayerChat(PlayerChatEvent $event) {
		$player = $event->getPlayer();
		$gameType = $this->alert->getGameType();
		if ($gameType &&
				!in_array($gameType['prefix'], $this->excludeDelayGameType)) {
			$this->alert->postponeMessage($player);
		}
	}
}
