<?php

namespace MinecrafterJPN;

use pocketmine\level\Position;

class PocketGuardDatabaseManager
{
    /** @var \SQLite3 */
    private $db;

    public function __construct($path)
    {
        $this->db = new \SQLite3($path . "PocketGuard.sqlite3");
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
        $result = $this->db->query("SELECT * FROM chests WHERE x = $x AND y = $y AND z = $z");
        //TODO
    }

    public function getOwner(Position $chest)
    {
        $x = $chest->x;
        $y = $chest->y;
        $z = $chest->z;
        $result = $this->db->query("SELECT owner FROM chests WHERE x = $x AND y = $y AND z = $z")->fetchArray(SQLITE3_ASSOC);
        return $result === false ? null : $result['owner'];
    }

    public function getAttribute(Position $chest)
    {
        $x = $chest->x;
        $y = $chest->y;
        $z = $chest->z;
        $result = $this->db->query("SELECT attribute FROM chests WHERE x = $x AND y = $y AND z = $z")->fetchArray(SQLITE3_ASSOC);
        return $result === false ? null : $result['attribute'];
    }

    public function normalLock(Position $chest)
    {

    }

    public function unlock(Position $chest)
    {

    }

    public function publicLock(Position $chest)
    {

    }

    public function passcodeLock(Position $chest, $passcode)
    {

    }

    /**
     * @param Position $chest
     * @param $passcode
     * @return bool
     */
    public function checkPasscode(Position $chest, $passcode)
    {
        return true;
    }
}