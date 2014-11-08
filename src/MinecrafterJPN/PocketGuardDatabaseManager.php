<?php

namespace MinecrafterJPN;

use pocketmine\level\Position;

class PocketGuardDatabaseManager
{
    const NOT_LOCKED = -1;
    const NORMAL_LOCK = 0;
    const PASSCODE_LOCK = 1;
    const PUBLIC_LOCK = 2;

    /** @var \SQLite3 */
    private $db;

    public function __construct($path)
    {
        $this->db = new \SQLite3($path);
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

    /**
     *
     */
    public function deleteAll()
    {
        $this->db->exec("DELETE FROM chests");
    }

    /**
     * @param string $target
     */
    public function deletePlayerData($target)
    {
        $this->db->exec("DELETE FROM chests WHERE owner = \"$target\"");
    }

    /**
     * @param Position $chest
     * @return bool
     */
    public function isLocked(Position $chest)
    {
        $x = $chest->x;
        $y = $chest->y;
        $z = $chest->z;
        $result = $this->db->query("SELECT attribute FROM chests WHERE x = $x AND y = $y AND z = $z")->fetchArray();
        return $result !== false;
    }

    /**
     * @param Position $chest
     * @return null|string
     */
    public function getOwner(Position $chest)
    {
        $x = $chest->x;
        $y = $chest->y;
        $z = $chest->z;
        $result = $this->db->query("SELECT owner FROM chests WHERE x = $x AND y = $y AND z = $z")->fetchArray(SQLITE3_ASSOC);
        return $result === false ? null : $result['owner'];
    }

    /**
     * @param Position $chest
     * @return null|int
     */
    public function getAttribute(Position $chest)
    {
        $x = $chest->x;
        $y = $chest->y;
        $z = $chest->z;
        $result = $this->db->query("SELECT attribute FROM chests WHERE x = $x AND y = $y AND z = $z")->fetchArray(SQLITE3_ASSOC);
        return $result === false ? self::NOT_LOCKED : $result['attribute'];
    }

    private function lock($x, $y, $z, $owner, $attribute, $passcode = "")
    {
        $this->db->exec("INSERT INTO chests (owner, x, y, z, attribute, passcode) VALUES (\"$owner\", $x, $y, $z, $attribute, \"$passcode\")");
    }

    /**
     * @param Position $chest
     * @param string $owner
     */
    public function normalLock(Position $chest, $owner)
    {
        $x = $chest->x;
        $y = $chest->y;
        $z = $chest->z;
        $this->lock($x, $y, $z, $owner, self::NORMAL_LOCK);
    }

    /**
     * @param Position $chest
     */
    public function unlock(Position $chest)
    {
        $x = $chest->x;
        $y = $chest->y;
        $z = $chest->z;
        $this->db->exec("DELETE FROM chests WHERE x = $x AND y = $y AND z = $z");
    }

    /**
     * @param Position $chest
     * @param string $owner
     */
    public function publicLock(Position $chest, $owner)
    {
        $x = $chest->x;
        $y = $chest->y;
        $z = $chest->z;
        $this->lock($x, $y, $z, $owner, self::PUBLIC_LOCK);
    }

    /**
     * @param Position $chest
     * @param string $owner
     * @param string $passcode
     */
    public function passcodeLock(Position $chest, $owner, $passcode)
    {
        $x = $chest->x;
        $y = $chest->y;
        $z = $chest->z;
        $this->lock($x, $y, $z, $owner, self::PASSCODE_LOCK, $passcode);
    }

    /**
     * @param Position $chest
     * @param $passcode
     * @return bool
     */
    public function checkPasscode(Position $chest, $passcode)
    {
        $x = $chest->x;
        $y = $chest->y;
        $z = $chest->z;
        $result = $this->db->query("SELECT passcode FROM chests WHERE x = $x AND y = $y AND z = $z")->fetchArray(SQLITE3_ASSOC);
        return $result['passcode'] === $passcode;
    }
}