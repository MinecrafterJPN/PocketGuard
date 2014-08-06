<?php

namespace MinecrafterJPN;

use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\level\Level;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\tile\Chest;

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
        @mkdir($this->getDataFolder());
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
                        if (is_null($passcode)) {
                            $sender->sendMessage("Usage: /pg passlock <passcode>");
                            return true;
                        }
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
                        return true;
                }
                $sender->sendMessage("[" .$option."] Touch the target chest!");
                return true;

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

    public function onPlayerBreakBlock(BlockBreakEvent $event)
    {
        if ($event->getBlock()->getID() === Item::CHEST) {
            $chest = $event->getBlock();
            $owner = $this->dbManager->getOwner($chest);
            $attribute = $this->dbManager->getAttribute($chest);
            $pairChestTile = null;
            if (($tile = $chest->getLevel()->getTile($chest)) instanceof Chest and $tile->isPaired()) $pairChestTile = $tile;
            if ($owner === $event->getPlayer()->getName()) {
                $this->dbManager->unlock($chest);
                if ($pairChestTile instanceof Chest) $this->dbManager->unlock($pairChestTile);
                $event->getPlayer()->sendMessage("Completed to unlock");
            } elseif ($owner !== $event->getPlayer()->getName() and $attribute !== self::NOT_LOCKED) {
                $event->getPlayer()->sendMessage("The chest has been locked");
                $event->getPlayer()->sendMessage("Try \"/pg info\" to get more info about the chest");
                $event->setCancelled();
            }
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event)
    {
        if ($event->getItem()->getID() === Item::CHEST) {
            $cs = $this->getSideChest($event->getPlayer()->getLevel(), $event->getBlock()->x, $event->getBlock()->y, $event->getBlock()->z);
            if (!is_null($cs)) {
                foreach ($cs as $c) {
                    if ($this->dbManager->isLocked($c)) {
                        $event->getPlayer()->sendMessage("Cannot place a chest next to a locked chest");
                        $event->setCancelled();
                        return;
                    }
                }
            }
        }
        if ($event->getBlock()->getID() === Item::CHEST) {
            $chest = $event->getBlock();
            $owner = $this->dbManager->getOwner($chest);
            $attribute = $this->dbManager->getAttribute($chest);
            $pairChestTile = null;
            if (($tile = $chest->getLevel()->getTile($chest)) instanceof Chest and $tile->isPaired()) $pairChestTile = $tile;
            if (isset($this->queue[$event->getPlayer()->getName()])) {
                $task = $this->queue[$event->getPlayer()->getName()];
                $taskName = array_shift($task);
                switch ($taskName) {
                    case "lock":
                        if ($attribute === self::NOT_LOCKED) {
                            $this->dbManager->normalLock($chest, $event->getPlayer()->getName());
                            if ($pairChestTile instanceof Chest) $this->dbManager->normalLock($pairChestTile, $event->getPlayer()->getName());
                            $event->getPlayer()->sendMessage("Completed to lock");
                        } else {
                            $event->getPlayer()->sendMessage("The chest has already been locked");
                        }
                        break;

                    case "unlock":
                        if ($owner === $event->getPlayer()->getName() and $attribute === self::NORMAL_LOCK) {
                            $this->dbManager->unlock($chest);
                            if ($pairChestTile instanceof Chest) $this->dbManager->unlock($pairChestTile);
                            $event->getPlayer()->sendMessage("Completed to unlock");
                        } else {
                            $event->getPlayer()->sendMessage("The chest is not locked with normal lock");
                        }
                        break;

                    case "public":
                        if ($attribute === self::NOT_LOCKED) {
                            $this->dbManager->publicLock($chest, $event->getPlayer()->getName());
                            if ($pairChestTile instanceof Chest) $this->dbManager->publicLock($pairChestTile, $event->getPlayer()->getName());
                            $event->getPlayer()->sendMessage("Completed to public lock");
                        } else {
                            $event->getPlayer()->sendMessage("The chest has already been locked");
                        }
                        break;

                    case "info":
                        if ($attribute !== self::NOT_LOCKED) {
                            $message = "Owner: $owner LockType: ";
                            switch ($attribute) {
                                case self::NORMAL_LOCK:
                                    $message .= "Normal";
                                    break;

                                case self::PASSCODE_LOCK:
                                    $message .= "Passcode";
                                    break;

                                case self::PUBLIC_LOCK:
                                    $message .= "Public";
                                    break;
                            }
                            $event->getPlayer()->sendMessage($message);
                        } else {
                            $event->getPlayer()->sendMessage("The chest is not locked");
                        }
                        break;

                    case "passlock":
                        if ($attribute === self::NOT_LOCKED) {
                            $passcode = array_shift($task);
                            $this->dbManager->passcodeLock($chest, $event->getPlayer()->getName(), $passcode);
                            if ($pairChestTile instanceof Chest) $this->dbManager->passcodeLock($pairChestTile, $event->getPlayer()->getName(), $passcode);
                            $event->getPlayer()->sendMessage("Completed to lock with passcode \"$passcode\"");
                        } else {
                            $event->getPlayer()->sendMessage("The chest has already been locked");
                        }
                        break;

                    case "passunlock":
                        if ($attribute === self::PASSCODE_LOCK) {
                            $passcode = array_shift($task);
                            if ($this->dbManager->checkPasscode($chest, $passcode)) {
                                $this->dbManager->unlock($chest);
                                if ($pairChestTile instanceof Chest) $this->dbManager->unlock($pairChestTile);
                                $event->getPlayer()->sendMessage("Completed to unlock");
                            } else {
                                $event->getPlayer()->sendMessage("Failed to unlock due to wrong passcode");
                            }
                        } else {
                            $event->getPlayer()->sendMessage("The chest is not locked with passcode");
                        }
                        break;

                    case "share":
                        break;
                }
                $event->setCancelled();
                unset($this->queue[$event->getPlayer()->getName()]);
            } elseif($owner !== $event->getPlayer()->getName() and $attribute !== self::PUBLIC_LOCK and $attribute !== self::NOT_LOCKED) {
                $event->getPlayer()->sendMessage("The chest has been locked");
                $event->getPlayer()->sendMessage("Try \"/pg info\" to get more info about the chest");
                $event->setCancelled();
            }
        }
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
