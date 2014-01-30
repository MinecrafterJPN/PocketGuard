<?php

/*
 __PocketMine Plugin__
name=PocketGuard
description=PocketGuard guards your chest against thieves.
version=1.3
author=MinecrafterJPN
class=PocketGuard
apiversion=11
*/

class PocketGuard implements Plugin
{
	private $api, $db, $queue;

	const NOT_LOCKED = -1;
	const NORMAL_LOCK = 0;
	const PASSCODE_LOCK = 1;
	const PUBLIC_LOCK = 2;

	public function __construct(ServerAPI $api, $server = false)
	{
		$this->api = $api;
	}

	public function init()
	{
		$this->loadDB();
		$this->queue = array();
		$this->api->addHandler("player.block.touch", array($this, "eventHandler"));
		$this->api->console->register("pg", "Main command of PocketGuard", array($this, "commandHandler"));
		$this->api->console->register("spg", "Main command of PocketGuard", array($this, "superCommandHandler"));
		$this->api->ban->cmdWhitelist("pg");
	}

	public function eventHandler($data, $event)
	{		
		$username = $data['player']->username;
		if ($data['type'] === "place" and $data['item']->getID() === CHEST) {
			$c = $this->getSideChest($data['block']->x, $data['block']->y, $data['block']->z);
 			if ($c !== false) {
				$cInfo = $this->getChestInfo($c->x, $c->y, $c->z);
				$isLocked = ($cInfo === self::NOT_LOCKED) ? false : true;
				if ($isLocked) {
					$this->api->chat->sendTo(false, "[PocketGuard] Cannot place chest next to locked chest", $username);
					return false;
				}
			} 
		}
		if (($data['target']->getID() === CHEST) or ($data['target']->getID() === DOOR_BLOCK)) {
			$chestInfo = $this->getChestInfo($data['target']->x, $data['target']->y, $data['target']->z);
			$owner = ($chestInfo === self::NOT_LOCKED) ? $chestInfo : $chestInfo['owner'];
			$attribute = $chestInfo === self::NOT_LOCKED ? $chestInfo : $chestInfo['attribute'];
			$pairChest = false;
			if ($data['target']->getID() === CHEST) {
				$pairChest = $this->api->tile->get(new Position($data['target']->x, $data['target']->y, $data['target']->z, $data['target']->level));
				if ($pairChest instanceof Tile) {
					$pairChest = $pairChest->isPaired() ? $pairChest->getPair() : false;
				}
			}
			//$targetName = ($data['target']->getID() === CHEST) ? "chest" : "door";
			if (isset($this->queue[$username])) {
				$task = $this->queue[$username];
				switch ($task[0]) {
					case "lock":
						if ($attribute === self::NOT_LOCKED) {
							$this->lock($username, $data['target']->x, $data['target']->y, $data['target']->z, self::NORMAL_LOCK);
							if ($pairChest !== false) $this->lock($username, $pairChest->x, $pairChest->y, $pairChest->z, self::NORMAL_LOCK);
							$this->api->chat->sendTo(false, "[PocketGuard] Completed to lock.", $username);
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] The chest has already been guarded by other player.", $username);
						}
						break;
					case "unlock":
						if (($owner === $username) and ($attribute === self::NORMAL_LOCK)) {
							$this->unlock($data['target']->x, $data['target']->y, $data['target']->z);
							if ($pairChest !== false) $this->unlock($pairChest->x, $pairChest->y, $pairChest->z);
							$this->api->chat->sendTo(false, "[PocketGuard] Completed to unlock.", $username);
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] The chest is not guarded by normal lock.", $username);
						}
						break;
					case "public":
						if ($attribute === self::NOT_LOCKED) {
							$this->lock($username, $data['target']->x, $data['target']->y, $data['target']->z, self::PUBLIC_LOCK);
							if ($pairChest !== false) $this->lock($username, $pairChest->x, $pairChest->y, $pairChest->z, self::PUBLIC_LOCK);
							$this->api->chat->sendTo(false, "[PocketGuard] Completed to make the chest into a public one.", $username);
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] That chest has already been guarded by other player.", $username);
						}
						break;
					case "info":
						if ($attribute !== self::NOT_LOCKED) {
							$this->info($data['target']->x, $data['target']->y, $data['target']->z, $username);
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] The chest is not guarded.", $username);
						}
						break;
					case "passlock":
						if ($attribute === self::NOT_LOCKED) {
							$passcode = $task[1];
							$this->lock($username, $data['target']->x, $data['target']->y, $data['target']->z, self::PASSCODE_LOCK, $passcode);
							if ($pairChest !== false) $this->lock($username, $pairChest->x, $pairChest->y, $pairChest->z, self::PASSCODE_LOCK, $passcode);
							$this->api->chat->sendTo(false, "[PocketGuard] Completed to lock. Passcode:$passcode", $username);
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] The chest has already been guarded by other player.", $username);
						}
						break;
					case "passunlock":
						if ($attribute === self::PASSCODE_LOCK) {
							$passcode = $task[1];
							if ($this->checkPasscode($data['target']->x, $data['target']->y, $data['target']->z, $passcode)) {
								$this->unlock($data['target']->x, $data['target']->y, $data['target']->z);
								if ($pairChest !== false) $this->unlock($pairChest->x, $pairChest->y, $pairChest->z);
								$this->api->chat->sendTo(false, "[PocketGuard] Completed to unlock.", $username);
							} else {
								$this->api->chat->sendTo(false, "[PocketGuard] Failed to unlock due to wrong passcode.", $username);
							}
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] That chest is not guarded by passcode.", $username);
						}
						break;
					case "share":
						break;
				}
				unset($this->queue[$username]);
				return false;
			} elseif ($owner !== $username and $attribute !== self::PUBLIC_LOCK and $attribute !== self::NOT_LOCKED) {
				$this->api->chat->sendTo(false, "[PocketGuard] That chest has been guarded.", $username);
				$this->api->chat->sendTo(false, "[PocketGuard] If you want to know the detail, use /pg info", $username);
				return false;
			} else {
				if ($owner === $username and $data['type'] === 'break' and $attribute !== self::NOT_LOCKED) {
					$this->unlock($data['target']->x, $data['target']->y, $data['target']->z);
					if ($pairChest !== false) $this->unlock($pairChest->x, $pairChest->y, $pairChest->z);
					$this->api->chat->sendTo(false, "[PocketGuard] Completed to unlock.", $username);
				} elseif (($owner !== $username) and ($data['type'] === "break") and ($attribute === self::PUBLIC_LOCK)) {
					$this->api->chat->sendTo(false, "[PocketGuard] The player who is not owner cannot break public chest.", $username);
					return false;
				}
			}
		}
	}

	public function CommandHandler($cmd, $args, $issuer, $alias)
	{
		$subCmd = strtolower($args[0]);
		$output = "";
		if ($issuer === "console") {
			if ($subCmd === "system") {
			} else {
				$output .= "[PocketGuard] Must be run on the world.";
			}
		} elseif(isset($this->queue[$issuer->username])) {
			$output .= "[PocketGuards] You have already had the task to do!";
		} else {
			switch ($subCmd) {
				case "lock":
				case "unlock":
				case "public":
				case "info":
					$this->queue[$issuer->username] = array($subCmd);
					break;
				case "passlock":
				case "passunlock":
					$passcode = $args[1];
					$this->queue[$issuer->username] = array($subCmd, $passcode);
					break;
				case "share":
					$target = $args[1];
					$this->queue[$issuer->username] = array($subCmd, $target);
					break;
				default:
					$output .= "[PocketGuards] \"$subCmd\" command dose not exist!";
					return $output;
			}
			$output .= "[PocketGuard][CMD:" . $subCmd . "] Touch the target chest!";
		}
		return $output;
	}

	public function superCommandHandler($cmd, $args, $issuer, $alias)
	{
		$output = "";
		$mode = strtolower($args[0]);
		switch ($mode) {
			case "unlock":
				$option = strtolower($args[1]);
				switch ($option) {
					case "a":
					case "all":
						$this->db->exec("DELETE FROM chests");
						$output .= "[PocketGuard] Completed to unlock all chests";
						break;
					case "p":
					case "player":
						$target = $args[2];
						$this->db->exec("DELETE FROM chests WHERE owner = \"$target\"");
						$output .= "[PocketGuard] Completed to unlock all ${target}'s chests";						
						break;
					default:
						$output .= "[PocketGuard] \"$option\" option dose not exist!";
						break;
				}
				break;
			
			default:
				$output .= "[PocketGuard] \"$mode\" mode dose not exist!";
				break;
		}
		return $output;
	}

	private function loadDB()
	{
		$this->db = new SQLite3($this->api->plugin->configPath($this) . "PocketGuard.sqlite3");
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS chests(
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			owner TEXT NOT NULL,
			x INTEGER NOT NULL,
			y INTEGER NOT NULL,
			z INTEGER NOT NULL,
			attribute INTEGER NOT NULL,
			passcode TEXT
			)"
		);
	}

	private function getSideChest($x, $y, $z)
	{
		$item = $this->api->level->getDefault()->getBlock(new Vector3($x + 1, $y, $z));
		if ($item->getID() === CHEST) return $item;
		$item = $this->api->level->getDefault()->getBlock(new Vector3($x - 1, $y, $z));
		if ($item->getID() === CHEST) return $item;
		$item = $this->api->level->getDefault()->getBlock(new Vector3($x, $y, $z + 1));
		if ($item->getID() === CHEST) return $item;
		$item = $this->api->level->getDefault()->getBlock(new Vector3($x, $y, $z - 1));
		if ($item->getID() === CHEST) return $item;
		return false;
	}

	private function getChestInfo($x, $y, $z)
	{
		$stmt = $this->db->prepare("SELECT * FROM chests WHERE x = $x AND y = $y AND z = $z");
		$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
		$stmt->close();
		return $result === false ? self::NOT_LOCKED : $result;
	}

	private function getAttribute($x, $y, $z)
	{
		$stmt = $this->db->prepare("SELECT attribute FROM chests WHERE x = $x AND y = $y AND z = $z");
		$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
		$stmt->close();
		return $result === false ? self::NOT_LOCKED : $result['attribute'];
	}

	private function getOwner($x, $y, $z)
	{
		$stmt = $this->db->prepare("SELECT owner FROM chests WHERE x = $x AND y = $y AND z = $z");
		$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
		$stmt->close();
		return $result === false ? self::NOT_LOCKED : $result['owner'];
	}

	private function lock($owner, $x, $y, $z, $attribute, $passcode = "")
	{
		$this->db->exec("INSERT INTO chests (owner, x, y, z, attribute, passcode) VALUES (\"$owner\", $x, $y, $z, $attribute, \"$passcode\")");
	}

	private function unlock($x, $y, $z)
	{
		$this->db->exec("DELETE FROM chests WHERE x = $x AND y = $y AND z = $z");
	}

	private function checkPasscode($x, $y, $z, $passcode)
	{
		$stmt = $this->db->prepare("SELECT * FROM chests WHERE x = $x AND y = $y AND z = $z AND passcode = $passcode");
		$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
		return $result === false ? false : true;
	}

	private function info($x, $y, $z, $username)
	{
		$stmt = $this->db->prepare("SELECT owner, attribute FROM chests WHERE x = $x AND y = $y AND z = $z");
		$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
		$stmt->close();
		$owner = $result['owner'];
		$attribute = $result['attribute'];
		switch ($attribute) {
			case self::NORMAL_LOCK:
				$lockType = "Normal";
				break;
			case self::PASSCODE_LOCK:
				$lockType = "Passcode";
				break;
			case self::PUBLIC_LOCK:
				$lockType = "Public";
				break;
		}
		$this->api->chat->sendTo(false, "[PocketGuard] Owner:$owner LockType:$lockType", $username);
	}

	public function __destruct()
	{
		$this->db->close();
	}
}
