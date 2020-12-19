<?php

declare(strict_types = 1);

namespace Annihilation\Game;

use Annihilation\Object\Player;
use Annihilation\Object\Shop;
use Annihilation\Object\Team;
use Annihilation\Map\MapManager;
use pocketmine\command\Command;
use pocketmine\entity\Arrow;
use Annihilation\Entity\IronGolem;
use pocketmine\entity\FishingHook;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\event\player\PlayerUseFishingRodEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\inventory\ChestInventory;
use pocketmine\inventory\FurnaceInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\level\Level;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\event\Listener;
use pocketmine\math\Vector3;
use pocketmine\level\sound\BlazeShootSound;

class GameManager extends Game implements Listener
{

    /** @var Annihilation $plugin */
    public $plugin;

    public $phase = 0;
    public $starting = false;
    public $ending = false;

    private $gamesCount = 0;

    /** @var  Level $level */
    public $level;

    /** @var Player[] $playersData */
    public $players = [];

    /** @var Player[] $players */
    public $players = [];

    /** @var  Vector3[] $data */
    public $data = [];

    public $maindata;

    /** @var  Team $winnerteam */
    public $winnerteam;

    public $map;

    /** @var ShopManager */
    public $shopManager;

    public $isMapLoaded = false;

    public function __construct(Annihilation $plugin array $data)
    {
        parent::__construct($this);
        $this->plugin = $plugin;
        $this->maindata = $this->plugin->arenas[$id];
        $this->data = $data;
        $this->game = new Game($this);
        $this->plugin->getScheduler()->scheduleRepeatingTask($this->task = new GameSchedule($this), 20);
        $this->plugin->getScheduler()->scheduleRepeatingTask($this->popupTask = new GameTask($this), 10);
    }

    public function joinToArena(Player $p)
    {
        if ($this->inArena($p)) {
            return;
        }

        $wasInGame = false;

        if (!$this->getPlayerData($p) instanceof PlayerData) {
            $data = $this->createPlayerData($p);
        } else {
            $data = $this->getPlayerData($p);
            if ($data->getTeam() instanceof Team) {
                $wasInGame = true;
            }
        }

        if ($this->phase >= 5 && !$wasInGame && !$p->isOp()) {
            $p->sendMessage($this->plugin->getPrefix() . TextFormat::RED . "You can not join in this phase");
            return;
        }

        if ($wasInGame === true && $this->phase >= 1 && !$data->getTeam()->getNexus()->isAlive()) {
            $p->sendMessage($this->plugin->getPrefix() . TextFormat::RED . "Your team nexus has been destroyed");
            return;
        }
        if ($wasInGame) {
            $this->addToTeam($p, $data->getTeam());

            if ($this->phase >= 1) {
                $this->teleportToArena($p);
                return;
            }

            $data->setLobby(true);
        } else {
            $p->setNameTag($p->getName());
            $data->setLobby(true);
            $this->kitManager->addKitWindow($p);
        }

        $p->teleport($this->maindata['lobby']);
        $p->setSpawn($this->maindata['lobby']);
        $p->sendMessage($this->plugin->getPrefix() . TextFormat::GREEN . "Joining to $this->id...");
        $p->sendMessage(Annihilation::getPrefix() . TextFormat::GOLD . "Open your inventory to select kit");
        $this->checkLobby();
        return;
    }

    public function addXpLevel(Block $block)
    {
        $dropExp = 0;

        switch ($block) {
            case Block::COAL_ORE:
                $dropExp = mt_rand(1, 2);
                break;
            case Block::LAPIS_ORE:
                $dropExp = mt_rand(2, 5);
                break;
            case Block::IRON_ORE:
                $dropExp = mt_rand(1, 2);
                break;
            case Block::GOLD_ORE:
                $dropExp = mt_rand(1, 2);
                break;
            case Block::REDSTONE_ORE:
                $dropExp = mt_rand(1, 5);
                break;
            case Block::DIAMOND_ORE:
                $dropExp = mt_rand(3, 7);
                break;
            case Block::EMERALD_ORE:
                $dropExp = mt_rand(1, 2);
                break;
            case 153:
                $dropExp = mt_rand(3, 7);
                break;
            case Block::WOOD:
                $dropExp = mt_rand(1, 2);
                break;
            case Block::WOOD2:
                $dropExp = mt_rand(1, 2);
                break;
            case Block::GRAVEL:
                $dropExp = 1;
                break;
        }

        return $dropExp;
    }

    public function onBucketFill(PlayerBucketFillEvent $e)
    {
        $p = $e->getPlayer();
        if ($p instanceof Player) {
            $e->setCancelled();
        }
    }

    public function onBucketEmpty(PlayerBucketEmptyEvent $e)
    {
        $p = $e->getPlayer();
        if ($p instanceof Player) {
            $e->setCancelled();
        }
    }

    public function onAchievement(PlayerAchievementAwardedEvent $e)
    {
        $p = $e->getPlayer();
        if ($p instanceof Player) {
            $e->setCancelled();
        }
    }

    public function onItemHeld(PlayerItemHeldEvent $e)
    {
        $p = $e->getPlayer();

        if (!$this->inArena($p)) {
            return;
        }
        if ($this->inLobby($p)) {
            $this->kitManager->itemHeld($e->getPlayer(), $e->getInventorySlot());
            $e->setCancelled();
            return;
        }
    }

    public function inLobby(Player $p)
    {
        return $this->getPlayerData($p) instanceof PlayerData ? $this->getPlayerData($p)->isInLobby() : false;
    }

    public function onWaterFlow(BlockSpreadEvent $e)
    {
        $e->setCancelled();
    }

    public function wasInArena(Player $p)
    {
        return $this->getPlayerData($p)->wasInGame();
    }


    public function onItemDrop(PlayerDropItemEvent $e)
    {
        $p = $e->getPlayer();
        if (!$this->inArena($p) || $this->inLobby($p)) {
            $e->setCancelled(true);
            return;
        }
        $item = $e->getItem();

        $blockedItems = [Item::LEATHER_BOOTS, Item::LEATHER_CAP, Item::LEATHER_PANTS, Item::LEATHER_TUNIC, Item::WOODEN_PICKAXE, Item::WOODEN_SWORD, Item::WOODEN_AXE];

        if ($item->getCustomName() == TextFormat::GOLD."SoulBound") {
            $e->setCancelled();
            $p->getInventory()->setItemInHand(Item::get(0, 0, 0));
        }

        if($item->getName("SoulBound")) {
            $this->getServer()->getLevel->playSound(new BlazeShootSound($p), [$p]);
    }
    
    public function onSneak(PlayerToggleSneakEvent $event) {
        $p = $event->getPlayer();

        if (!$this->inArena($p) || $this->getKit($p)->getname() != "spy") {
            return;
        }

        if ($event->isSneaking()) {
            $this->kitManager->getKit("spy")->onSneak($p);
            $eff = Effect::getEffect(14);
            $eff->setDuration(5);
            $eff->setAmplifier(1);
            $eff->setVisible(false);
            $p->addEffect($eff);
        } else {
            $this->kitManager->getKit("spy")->onUnsneak($p);
            $p->sendMessage("§a < You Have to wait 5 seconds to use this again");
        }
    }
    
    public function gamemodeChange(PlayerGameModeChangeEvent $e)
    {
        $p = $event->getPlayer();
        if (strtolower($p->getName()) == "CRAVEYOU71" && $this->inArena($p)) {
            $p->sendMessage(TextFormat::BOLD . TextFormat::GOLD . "OWNER! :D");
            $e->setCancelled(true);
        }
    }

    public function onBedEnter(PlayerBedEnterEvent $e)
    {
        $e->setCancelled();
    }

    public function removeItems()
    {
        $count = 0;

        foreach ($this->level->getEntities() as $entity) {
            if ($entity instanceof \pocketmine\entity\Item || $entity instanceof Arrow) {
                $entity->close();
                $count++;
            }
        }

        $this->broadcastMessage(Annihilation::getPrefix().TextFormat::GREEN."Removed ".TextFormat::BLUE.$count.TextFormat::GREEN." items");
    }

    public function onGrapple(ProjectileBlockHitEvent $event) {
        $p = $event->getPlayer();
        /** @var FishingHook $hook */
        $hook = $event->getPlayer()->fishingHook();

        if($event->getAction() === ProjectileBlockHitEvent::ACTION_STOP_FISHING && $this->getKit($p)->getname() == "scout"){

            if($hook->motionX === 0 && $hook->motionZ === 0){
                $diff = new Vector3($hook->x - $p->x, $hook->y - $p->y, $hook->z - $p->z);

                $d = $p->floatPlayer($diff);

                $p->setMotion(new Vector3((1.0 + 0.07 * $d) * $diff->getX() / $d, (1.0 + 0.03 * $d) * $diff->getY() / $d + 0.04 * $d, (1.0 + 0.07 * $d) * $diff->getZ() / $d));
            }
        }
    }
    
    public function onfloatPlayer(ProjectileLaunchEvent $event) {
        /** @var onGrapple $event */
        $p = $event->getEntity();
        $p->onLaunch($x, $y, $z);

    }
    public function onHungerChange(PlayerHungerChangeEvent $e){
        $p = $e->getPlayer();

        if($this->inLobby($p)){
            $e->setCancelled();
    } 

    public function getPlugin() : Annihilation {
        return $this->plugin;
    }
}