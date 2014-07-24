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
        $result = $this->db->query("SELECT * FROM chests WHERE x = $chest->x AND y = $chest->y AND z = $chest->z");
        //TODO
    }
}