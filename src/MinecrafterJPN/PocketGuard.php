<?php

namespace MinecrafterJPN;

use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\level\Level;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;

class PocketGuard extends PluginBase implements Listener
{
    /** @var  PocketGuardDatabaseManager */
    private $dbManager;
    /** @var  array */
    private $queue;

    const NOT_LOCKED = -1;
    const NORMAL_LOCK = 0;
    const PASSCODE_LOCK = 1;
    const PUBLIC_LOCK = 2;

    public function onLoad()
	{
	}

	public function onEnable()
	{
        $this->dbManager = new PocketGuardDatabaseManager($this->getDataFolder());
        $this->queue = [];
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

	public function onDisable()
	{
	}

	public function onCommand(CommandSender $sender, Command $command, $label, array $args)
	{
        if (!($sender instanceof Player)) {
            $sender->sendMessage("Must be run in the world!");
            return true;
        }
        if (isset($this->queue[$sender->getName()])) {
            $sender->sendMessage("You have already had the task to do!");
            return true;
        }
        switch (strtolower($command->getName())) {
            case "pg":
                $option = strtolower(array_shift($args));
                switch ($option) {
                    case "lock":
                    case "unlock":
                    case "public":
                    case "info":
                        $this->queue[$sender->getName()] = [$option];
                        break;
                    case "passlock":
                    case "passunlock":
                        $passcode = array_shift($args);
                        $this->queue[$sender->getName()] = [$option, $passcode];
                        break;
                    case "share":
                        $target = array_shift($args);
                        $this->queue[$sender->getName()] = [$option, $target];
                        break;

                    default:
                        $sender->sendMessage("/pg $option dose not exist!");
                        $sender->sendMessage("/pg <lock | unlock | public | info>");
                        $sender->sendMessage("/pg <passlock | passunlock | share>");
                        break;
                }
                break;

            case "spg":
                $option = strtolower(array_shift($args));
                switch ($option) {
                    case "unlock":
                        $unlockOption =strtolower(array_shift($args));
                        switch ($unlockOption) {
                            case "a":
                            case "all":
                                $this->dbManager->deleteAll();
                                $sender->sendMessage("Completed to unlock all chests");
                                return true;

                            case "p":
                            case "player":
                                $target = array_shift($args);
                                if (is_null($target)) {
                                    $sender->sendMessage("Specify target player!");
                                    $sender->sendMessage("/spg unlock player <player>");
                                    return true;
                                }
                                $this->dbManager->deletePlayerData($target);
                                $sender->sendMessage("Completed to unlock all $target's chests");
                                return true;

                            default:
                                $sender->sendMessage("/pg unlock $unlockOption dose not exist!");
                                $sender->sendMessage("/spg unlock <all | player>");
                                return true;
                        }
                        break;
                      
                    default:
                        $sender->sendMessage("/spg $option dose not exist!");
                        $sender->sendMessage("/spg <unlock>");
                        return true;
                }
                break;
        }
        return false;
	}

    public function onPlayerPlaceBlock(BlockPlaceEvent $event)
    {
        if ($event->getBlockAgainst()->getID() === Item::CHEST) {
            $cs = $this->getSideChest($event->getPlayer()->getLevel(), $event->getBlockAgainst()->x, $event->getBlockAgainst()->y, $event->getBlockAgainst()->z);
            if (!is_null($cs)) {
                foreach ($cs as $c) {
                    if ($this->dbManager->isLocked($c)) {
                        $event->getPlayer()->sendMessage("Cannot place a chest next to a locked chest");
                        $event->setCancelled();
                    }
                }
            }
        }
    }

    public function onPlayerBreakBlock(BlockBreakEvent $event)
    {

    }

    public function onPlayerInteract(PlayerInteractEvent $event)
    {

    }

    private function getSideChest(Level $level, $x, $y, $z)
    {
        $sideChests = [];
        $item = $level->getBlock(new Vector3($x + 1, $y, $z));
        if ($item->getID() === Item::CHEST) $sideChests[] = $item;
        $item = $level->getBlock(new Vector3($x - 1, $y, $z));
        if ($item->getID() === Item::CHEST) $sideChests[] = $item;
        $item = $level->getBlock(new Vector3($x, $y, $z + 1));
        if ($item->getID() === Item::CHEST) $sideChests[] = $item;
        $item = $level->getBlock(new Vector3($x, $y, $z - 1));
        if ($item->getID() === Item::CHEST) $sideChests[] = $item;
        return empty($sideChests) ? null : $sideChests;
    }

}