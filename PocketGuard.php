<?php

/*
 __PocketMine Plugin__
name=PocketGuard
description=PocketGuard guards your chest against thieves.
version=1.1
author=MinecrafterJPN
class=PocketGuard
apiversion=10
*/

class PocketGuard implements Plugin
{
	private $api, $db, $queue = array();

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
		$this->api->addHandler("player.block.touch", array($this, "eventHandler"));
		$this->api->console->register("pg", "Main command of PocketGuard", array($this, "commandHandler"));
	}

	public function eventHandler($data, $event)
	{		
		$username = $data['player']->username;
		if ($data['type'] === "place" and $data['item']->getID() === CHEST) {
			$c = $this->getSideChest($data['block']);
			if ($c !== false) {
				$cInfo = $this->getChestInfo($c->x, $c->y, $c->z);
				$attr = $cInfo === self::NOT_LOCKED ? $cInfo : $cInfo['attribute'];
				if ($attr !== self::NOT_LOCKED) {
					$this->api->chat->sendTo(false, "[PocketGuard] Cannot place chest next to locked chest.", $username);
					return false;
				}
			}
		}
		if ($data['target']->getID() === CHEST) {
			$chestInfo = $this->getChestInfo($data['target']->x, $data['target']->y, $data['target']->z);
			$owner = $chestInfo === self::NOT_LOCKED ? $chestInfo : $chestInfo['owner'];
			$attribute = $chestInfo === self::NOT_LOCKED ? $chestInfo : $chestInfo['attribute'];
			$pairChest = $this->api->tile->get(new Position($data['target']->x, $data['target']->y, $data['target']->z, $this->api->level->getDefault()))->getPair();
			if (isset($this->queue[$username])) {
				$task = $this->queue[$username];
				switch ($task[0]) {
					case "lock":
						if ($attribute === self::NOT_LOCKED) {
							$this->lock($username, $data['target']->x, $data['target']->y, $data['target']->z, self::NORMAL_LOCK);
							if ($pairChest !== false) $this->lock($username, $pairChest->x, $pairChest->y, $pairChest->z, self::NORMAL_LOCK);
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] The chest has already been guarded by other player.", $username);
						}
						break;
					case "unlock":
						if ($owner === $username and $attribute === self::NORMAL_LOCK) {
							$this->unlock($data['target']->x, $data['target']->y, $data['target']->z, $username);
							if ($pairChest !== false) $this->unlock($pairChest->x, $pairChest->y, $pairChest->z, $username);
						} elseif ($attribute === self::NOT_LOCKED) {
							$this->api->chat->sendTo(false, "[PocketGuard] The chest is not guarded.", $username);
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] The chest has been guarded by other player or by other method.", $username);
						}
						break;
					case "public":
						if ($attribute === self::NOT_LOCKED) {
							$this->lock($username, $data['target']->x, $data['target']->y, $data['target']->z, self::PUBLIC_LOCK);
							if ($pairChest !== false) $this->lock($username, $pairChest->x, $pairChest->y, $pairChest->z, self::PUBLIC_LOCK);
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
							$this->lock($username, $data['target']->x, $data['target']->y, $data['target']->z, self::PASSCODE_LOCK, $task[1]);
							if ($pairChest !== false) $this->lock($username, $pairChest->x, $pairChest->y, $pairChest->z, self::NORMAL_LOCK, $task[1]);
						} else {
							$this->api->chat->sendTo(false, "[PocketGuard] The chest has already been guarded by other player.", $username);
						}
						break;
					case "passunlock":
						if ($attribute === self::PASSCODE_LOCK) {
							if ($this->checkPasscode($data['target']->x, $data['target']->y, $data['target']->z, $task[1])) {
								$this->unlock($data['target']->x, $data['target']->y, $data['target']->z, $username);
								if ($pairChest !== false) $this->unlock($pairChest->x, $pairChest->y, $pairChest->z, $username);
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
					$this->unlock($data['target']->x, $data['target']->y, $data['target']->z, $username);
					if ($pairChest !== false) $this->unlock($pairChest->x, $pairChest->y, $pairChest->z, $username);
				} elseif ($owner !== $username and $data['type'] === 'break' and $attribute === self::PUBLIC_LOCK) {
					$this->api->chat->sendTo(false, "[PocketGuard] The player who is not owner cannot break public chest.", $username);
					return false;
				}
			}
		}
	}

	public function CommandHandler($cmd, $args, $issuer, $alias)
	{
		$subCmd = $args[0];
		$output = "";
		if ($issuer === 'console') {
			$output .= "[PocketGuard] Must be run on the world.";
		} elseif(isset($this->queue[$issuer->username])) {
			$output .= "[PocketGuards] You still have the task to do!";
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
					$output .= "[PocketGuards] Such command dose not exist!";
					return $output;
			}
			$output .= "[PocketGuards][CMD:" . $subCmd . "] Touch the target chest!";
		}
		return $output;
	}

	private function loadDB()
	{
		$this->db = new SQLite3($this->api->plugin->configPath($this) . "PocketGuard.sqlite3");
		$stmt = $this->db->prepare(
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
		$stmt->execute();
		$stmt->close();
	}

	private function getSideChest($data)
	{
		$item = $data->level->getBlock(new Vector3($data->x + 1, $data->y, $data->z));
		if ($item->getID() === CHEST) return $item;
		$item = $data->level->getBlock(new Vector3($data->x - 1, $data->y, $data->z));
		if ($item->getID() === CHEST) return $item;
		$item = $data->level->getBlock(new Vector3($data->x, $data->y, $data->z + 1));
		if ($item->getID() === CHEST) return $item;
		$item = $data->level->getBlock(new Vector3($data->x, $data->y, $data->z - 1));
		if ($item->getID() === CHEST) return $item;
		return false;
	}

	private function getChestInfo($x, $y, $z)
	{
		$stmt = $this->db->prepare("SELECT * FROM chests WHERE x = :x AND y = :y AND z = :z");
		$stmt->bindValue(":x", $x);
		$stmt->bindValue(":y", $y);
		$stmt->bindValue(":z", $z);
		$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
		$stmt->close();
		if ($result === false) {
			return self::NOT_LOCKED;
		} else {
			return $result;
		}
	}

	private function getAttribute($x, $y, $z)
	{
		$stmt = $this->db->prepare("SELECT attribute FROM chests WHERE x = :x AND y = :y AND z = :z");
		$stmt->bindValue(":x", $x);
		$stmt->bindValue(":y", $y);
		$stmt->bindValue(":z", $z);
		$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
		$stmt->close();
		if ($result === false) {
			$ret = self::NOT_LOCKED;
		} else {
			$ret = $result['attribute'];
		}
		return $ret;
	}

	private function getOwner($x, $y, $z)
	{
		$stmt = $this->db->prepare("SELECT owner FROM chests WHERE x = :x AND y = :y AND z = :z");
		$stmt->bindValue(":x", $x);
		$stmt->bindValue(":y", $y);
		$stmt->bindValue(":z", $z);
		$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
		$stmt->close();
		if ($result === false) {
			$ret = self::NOT_LOCKED;
		} else {
			$ret = $result['owner'];
		}
		return $ret;
	}

	private function lock($owner, $x, $y, $z, $attribute, $passcode = null)
	{
		$stmt = $this->db->prepare("INSERT INTO chests (owner, x, y, z, attribute, passcode) VALUES (:owner, :x, :y, :z, :attribute, :passcode)");
		$stmt->bindValue(":owner", $owner);
		$stmt->bindValue(":x", $x);
		$stmt->bindValue(":y", $y);
		$stmt->bindValue(":z", $z);
		$stmt->bindValue(":attribute", $attribute);
		$stmt->bindValue(":passcode", $passcode);
		$stmt->execute();
		$stmt->close();
		if ($attribute === self::PASSCODE_LOCK) {
			$this->api->chat->sendTo(false, "[PocketGuard] Completed to lock. Passcode:$passcode", $owner);
		} else {
			$this->api->chat->sendTo(false, "[PocketGuard] Completed to lock.", $owner);
		}
	}

	private function unlock($x, $y, $z, $username)
	{
		$stmt = $this->db->prepare("DELETE FROM chests WHERE x = :x AND y = :y AND z = :z");
		$stmt->bindValue(":x", $x);
		$stmt->bindValue(":y", $y);
		$stmt->bindValue(":z", $z);
		$stmt->execute();
		$this->api->chat->sendTo(false, "[PocketGuard] Completed to unlock.", $username);
		$stmt->close();
	}

	private function checkPasscode($x, $y, $z, $passcode)
	{
		$stmt = $this->db->prepare("SELECT * FROM chests WHERE x = :x AND y = :y AND z = :z AND passcode = :passcode");
		$stmt->bindValue(":x", $x);
		$stmt->bindValue(":y", $y);
		$stmt->bindValue(":z", $z);
		$stmt->bindValue(":passcode", $passcode);
		$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
		return $result === false ? false : true;
	}

	private function info($x, $y, $z, $username)
	{
		$stmt = $this->db->prepare("SELECT owner, attribute FROM chests WHERE x = :x AND y = :y AND z = :z");
		$stmt->bindValue(":x", $x);
		$stmt->bindValue(":y", $y);
		$stmt->bindValue(":z", $z);
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
