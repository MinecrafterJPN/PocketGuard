<?php

namespace MinecrafterJPN;

use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\level\Level;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\tile\Chest;

class PocketGuard extends PluginBase implements Listener {
    /** @var PocketGuardDatabaseManager  */
    private $databaseManager;

    /** array */
    private $queue;
    private $targetBlocks;

    // Constants
    const NOT_LOCKED = -1;
    const NORMAL_LOCK = 0;
    const PASSCODE_LOCK = 1;
    const PUBLIC_LOCK = 2;

    public function onLoad() {
	}

	public function onEnable() {
        @mkdir($this->getDataFolder());
        $this->queue = [];
        $this->targetBlocks = [
            Block::CHEST,
            Block::FURNACE,
            Block::BURNING_FURNACE,
            Block::LIT_FURNACE,
            Block::TRAPDOOR,
            Block::FENCE_GATE,
            Block::FENCE_GATE_ACACIA,
            Block::FENCE_GATE_BIRCH,
            Block::FENCE_GATE_DARK_OAK,
            Block::FENCE_GATE_JUNGLE,
            Block::FENCE_GATE_SPRUCE,
            Block::DOOR_BLOCK,
            Block::STONECUTTER
        ];
        $this->databaseManager = new PocketGuardDatabaseManager($this->getDataFolder() . 'PocketGuard.sqlite3');
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

	public function onDisable() {
	}

	public function onCommand(CommandSender $sender, Command $command, $label, array $args) {
        if (!($sender instanceof Player)) {
            $sender->sendMessage('Must be run in the world!');
            return true;
        }
        if (isset($this->queue[$sender->getName()])) {
            $sender->sendMessage('You have already had the task to execute!');
            return true;
        }
        switch (strtolower($command->getName())) {
            case 'pg':
                $option = strtolower(array_shift($args));
                switch ($option) {
                    case 'lock':
                    case 'unlock':
                    case 'public':
                    case 'info':
                        $this->queue[$sender->getName()] = [$option];
                        break;

                    case 'passlock':
                    case 'passunlock':
                        if (is_null($passcode = array_shift($args))) {
                            $sender->sendMessage('Usage: /pg passlock <passcode>');
                            return true;
                        }
                        $this->queue[$sender->getName()] = [$option, $passcode];
                        break;

                    case 'share':
                        if (is_null($target = array_shift($args))) {
                            $sender->sendMessage('Usage: /pg share <player>');
                            return true;
                        }
                        $this->queue[$sender->getName()] = [$option, $target];
                        break;

                    default:
                        $sender->sendMessage("/pg $option dose not exist!");
                        $sender->sendMessage('/pg <lock | unlock | public | info>');
                        $sender->sendMessage('/pg <passlock | passunlock | share>');
                        return true;
                }
                $sender->sendMessage("[$option] Touch the target block!");
                return true;

            case 'spg':
                $option = strtolower(array_shift($args));
                switch ($option) {
                    case 'unlock':
                        $unlockOption =strtolower(array_shift($args));
                        switch ($unlockOption) {
                            case 'a':
                            case 'all':
                                $this->databaseManager->deleteAll();
                                $sender->sendMessage('Successfully unlocked all blocks');
                                break;

                            case 'p':
                            case 'player':
                                $target = array_shift($args);
                                if (is_null($target)) {
                                    $sender->sendMessage('/spg unlock player <player>');
                                    return true;
                                }
                                $this->databaseManager->deletePlayerData($target);
                                $sender->sendMessage("Successfully unlocked all $target's blocks");
                                break;

                            default:
                                $sender->sendMessage("/pg unlock $unlockOption dose not exist!");
                                $sender->sendMessage('/spg unlock <all | player>');
                                return true;
                        }
                        break;
                      
                    default:
                        $sender->sendMessage("/spg $option dose not exist!");
                        $sender->sendMessage('/spg <unlock>');
                        return true;
                }
                return true;
        }
        return false;
	}

    public function onPlayerBreakBlock(BlockBreakEvent $event) {
        // Prohibit breaking locked blocks
        if (in_array($event->getBlock()->getID(), $this->targetBlocks) and $this->databaseManager->isLocked($event->getBlock())) {
            $block = $event->getBlock();
            $owner = $this->databaseManager->getOwner($block);
            $attribute = $this->databaseManager->getAttribute($block);
            $pairChestTile = null;
            if (($tile = $block->getLevel()->getTile($block)) instanceof Chest) $pairChestTile = $tile->getPair();
            if ($owner === $event->getPlayer()->getName()) {
                $this->databaseManager->unlock($block);
                if ($pairChestTile instanceof Chest) $this->databaseManager->unlock($pairChestTile);
                $event->getPlayer()->sendMessage('Successfully unlocked the chest');
            } elseif ($attribute !== self::NOT_LOCKED and !$event->getPlayer()->hasPermission("pocketguard.op")) {
                $event->getPlayer()->sendMessage('The chest has been locked');
                $event->getPlayer()->sendMessage('Try "/pg info" to get more info about the chest');
                $event->setCancelled();
            }
        }
    }

    public function onPlayerBlockPlace(BlockPlaceEvent $event) {
        // Prohibit placing chest next to locked chest
        if ($event->getItem()->getID() === Item::CHEST) {
            $cs = $this->getSideChest($event->getPlayer()->getLevel(), $event->getBlock()->x, $event->getBlock()->y, $event->getBlock()->z);
            if (!is_null($cs)) {
                foreach ($cs as $c) {
                    if ($this->databaseManager->isLocked($c)) {
                        $event->getPlayer()->sendMessage('Cannot place chest next to locked chest');
                        $event->setCancelled();
                        return;
                    }
                }
            }
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event) {
        // Execute task
        if (in_array($event->getBlock()->getID(), $this->targetBlocks)) {
            $block = $event->getBlock();
            $owner = $this->databaseManager->getOwner($block);
            $attribute = $this->databaseManager->getAttribute($block);
            $pairChestTile = null;
            if (($tile = $block->getLevel()->getTile($block)) instanceof Chest) $pairChestTile = $tile->getPair();
            if (isset($this->queue[$event->getPlayer()->getName()])) {
                $task = $this->queue[$event->getPlayer()->getName()];
                $taskName = array_shift($task);
                switch ($taskName) {
                    case 'lock':
                        if ($attribute === self::NOT_LOCKED) {
                            $this->databaseManager->normalLock($block, $event->getPlayer()->getName());
                            if ($pairChestTile instanceof Chest) $this->databaseManager->normalLock($pairChestTile, $event->getPlayer()->getName());
                            $event->getPlayer()->sendMessage('Successfully locked!');
                        } else {
                            $event->getPlayer()->sendMessage('The block has already been locked');
                        }
                        break;

                    case 'unlock':
                        if ($owner === $event->getPlayer()->getName() and $attribute === self::NORMAL_LOCK) {
                            $this->databaseManager->unlock($block);
                            if ($pairChestTile instanceof Chest) $this->databaseManager->unlock($pairChestTile);
                            $event->getPlayer()->sendMessage('Successfully unlocked!');
                        } else {
                            $event->getPlayer()->sendMessage('The block is not locked with normal lock');
                        }
                        break;

                    case 'public':
                        if ($attribute === self::NOT_LOCKED) {
                            $this->databaseManager->publicLock($block, $event->getPlayer()->getName());
                            if ($pairChestTile instanceof Chest) $this->databaseManager->publicLock($pairChestTile, $event->getPlayer()->getName());
                            $event->getPlayer()->sendMessage('Successfully public lock!');
                        } else {
                            $event->getPlayer()->sendMessage('The block has already been locked');
                        }
                        break;

                    case 'info':
                        if ($attribute !== self::NOT_LOCKED) {
                            $message = "Owner: $owner LockType: ";
                            switch ($attribute) {
                                case self::NORMAL_LOCK:
                                    $message .= 'Normal';
                                    break;

                                case self::PASSCODE_LOCK:
                                    $message .= 'Passcode';
                                    break;

                                case self::PUBLIC_LOCK:
                                    $message .= 'Public';
                                    break;
                            }
                            $event->getPlayer()->sendMessage($message);
                        } else {
                            $event->getPlayer()->sendMessage('The block is not locked');
                        }
                        break;

                    case 'passlock':
                        if ($attribute === self::NOT_LOCKED) {
                            $passcode = array_shift($task);
                            $this->databaseManager->passcodeLock($block, $event->getPlayer()->getName(), $passcode);
                            if ($pairChestTile instanceof Chest) $this->databaseManager->passcodeLock($pairChestTile, $event->getPlayer()->getName(), $passcode);
                            $event->getPlayer()->sendMessage("Successfully locked with passcode \"$passcode\"!");
                        } else {
                            $event->getPlayer()->sendMessage('The block has already been locked');
                        }
                        break;

                    case 'passunlock':
                        if ($attribute === self::PASSCODE_LOCK) {
                            $passcode = array_shift($task);
                            if ($this->databaseManager->checkPasscode($block, $passcode)) {
                                $this->databaseManager->unlock($block);
                                if ($pairChestTile instanceof Chest) $this->databaseManager->unlock($pairChestTile);
                                $event->getPlayer()->sendMessage('Successfully unlocked!');
                            } else {
                                $event->getPlayer()->sendMessage('Failed to unlock due to wrong passcode');
                            }
                        } else {
                            $event->getPlayer()->sendMessage('The block is not locked with passcode');
                        }
                        break;

                    case 'share':
                        break;
                }
                $event->setCancelled();
                unset($this->queue[$event->getPlayer()->getName()]);
            } elseif($attribute !== self::NOT_LOCKED and $attribute !== self::PUBLIC_LOCK and $owner !== $event->getPlayer()->getName() and !$event->getPlayer()->hasPermission("pocketguard.op")) {
                $event->getPlayer()->sendMessage('The block has been locked');
                $event->getPlayer()->sendMessage('Try "/pg info" to investigate the block');
                $event->setCancelled();
            }
        }
    }

    private function getSideChest(Level $level, $x, $y, $z) {
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