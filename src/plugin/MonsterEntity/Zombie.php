<?php

namespace plugin\MonsterEntity;

use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\Item as ItemItem;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\Short;
use pocketmine\nbt\tag\String;
use pocketmine\Player;

class Zombie extends Monster{
    const NETWORK_ID = 32;

    public $width = 0.72;
    public $length = 0.4;
    public $height = 1.8;
    public $eyeHeight = 1.62;

    protected function initEntity(){
        parent::initEntity();

        $this->setDamage([0, 3, 4, 6]);
        $this->lastTick = microtime(true);
        if(!isset($this->namedtag->id)){
            $this->namedtag->id = new String("id", "Enderman");
        }
        if(!isset($this->namedtag->Health)){
            $this->namedtag->Health = new Short("Health", $this->getMaxHealth());
        }
        $this->setHealth($this->namedtag["Health"]);
        $this->created = true;
    }

    public function getName(){
        return "좀비";
    }

    public function updateTick(){
        $tick = (microtime(true) - $this->lastTick) * 20;
        if(!$this->isAlive()){
            $this->deadTicks += $tick;
            if($this->deadTicks >= 25) $this->close();
            return;
        }

        $this->attackDelay += $tick;
        if($this->knockBackCheck($tick)) return;

        $this->moveTime += $tick;
        $target = $this->updateMove($tick);
        if($target instanceof Player){
            if($this->attackDelay >= 16 && $this->distanceSquared($target) <= 0.81){
                $this->attackDelay = 0;
                $ev = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->getDamage()[$this->server->getDifficulty()]);
                $target->attack($ev->getFinalDamage(), $ev);
            }
        }elseif($target instanceof Vector3){
            if($this->distance($target) <= 1){
                $this->moveTime = 800;
            }elseif($this->x == $this->lastX or $this->z == $this->lastZ){
                $this->moveTime += 20;
            }
        }
        $this->entityBaseTick($tick);
        $this->updateMovement();
        $this->lastTick = microtime(true);
    }
	public function getDrops() {
    	$drops = [ ];
    	if ($this->lastDamageCause instanceof EntityDamageByEntityEvent) {
    		switch (mt_rand ( 0, 2 )) {
    			case 0 :
    				$drops [] = ItemItem::get ( ItemItem::FEATHER, 0, 1 );
    				break;
    			case 1 :
    				$drops [] = ItemItem::get ( ItemItem::CARROT, 0, 1 );
    				break;
    			case 2 :
    				$drops [] = ItemItem::get ( ItemItem::POTATO, 0, 1 );
    				break;
    		}
    	}
    	return $drops;
    }
}
