<?php

namespace plugin\MonsterEntity;

use pocketmine\block\Block;
use pocketmine\block\Water;
use pocketmine\entity\Effect;
use pocketmine\entity\Entity;
use pocketmine\entity\Monster as MonsterEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Timings;
use pocketmine\level\Level;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Math;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\Byte;
use pocketmine\network\Network;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\network\protocol\EntityEventPacket;
use pocketmine\Player;
use pocketmine\Server;

abstract class Monster extends MonsterEntity{

    /** @var Vector3 */
    public $stayVec = null;
    public $stayTime = 0;

    /** @var Vector3 */
    protected $target = null;
    /** @var Entity|null */
    protected $attacker = null;

    protected $lastTick = 0;
    protected $moveTime = 0;
    protected $created = false;
    protected $attackDelay = 0;

    private $damage = [];
    private $movement = true;

    public abstract function updateTick();

    public function onUpdate($currentTick){
        return false;
    }

    public function isCreated(){
        return $this->created;
    }

    public function isMovement(){
        return $this->movement;
    }

    public function setMovement($value){
        $this->movement = (bool) $value;
    }

    /**
     * @return int[]
     */
    public function getDamage(){
        return $this->damage;
    }

    /**
     * @param float|float[] $damage
     * @param int $difficulty
     */
    public function setDamage($damage, $difficulty = null){
        $difficulty = $difficulty === null ? Server::getInstance()->getDifficulty() : (int) $difficulty;
        if(is_array($damage)) $this->damage = $damage;
        elseif($difficulty >= 1 && $difficulty <= 3) $this->damage[$difficulty] = (float) $damage;
    }

    protected function initEntity(){
        Entity::initEntity();
        if(!isset($this->namedtag->Movement)){
            $this->namedtag->Movement = new Byte("Movement", (int) $this->isMovement());
        }
        $this->setMovement($this->namedtag["Movement"]);
    }

    public function saveNBT(){
        parent::saveNBT();
        $this->namedtag->Movement = new Byte("Movement", $this->isMovement());
    }

    public function spawnTo(Player $player){
        if(isset($this->hasSpawned[$player->getId()]) or !isset($player->usedChunks[Level::chunkHash($this->chunk->getX(), $this->chunk->getZ())])) return;

        $pk = new AddEntityPacket();
        $pk->eid = $this->getID();
        $pk->type = static::NETWORK_ID;
        $pk->x = $this->x;
        $pk->y = $this->y;
        $pk->z = $this->z;
        $pk->speedX = 0;
        $pk->speedY = 0;
        $pk->speedZ = 0;
        $pk->yaw = $this->yaw;
        $pk->pitch = $this->pitch;
        $pk->metadata = $this->dataProperties;
        $player->dataPacket($pk);

        $this->hasSpawned[$player->getId()] = $player;
    }

    public function updateMovement(){
        $this->lastX = $this->x;
        $this->lastY = $this->y;
        $this->lastZ = $this->z;
        $this->lastYaw = $this->yaw;
        $this->lastPitch = $this->pitch;

        foreach($this->hasSpawned as $player) $player->addEntityMovement($this->id, $this->x, $this->y, $this->z, $this->yaw, $this->pitch, $this->yaw);
    }

    public function attack($damage, EntityDamageEvent $source){
        if($this->attacker instanceof Entity) return;
        if($this instanceof PigZombie
            && ($source->getCause() === EntityDamageEvent::CAUSE_FIRE
                || $source->getCause() === EntityDamageEvent::CAUSE_FIRE_TICK
                || $source->getCause() === EntityDamageEvent::CAUSE_LAVA)
        ){
            $source->setCancelled();
        }

        Entity::attack($damage, $source);

        if($source->isCancelled()) return;

        $this->attackTime = 10;
        if($source instanceof EntityDamageByEntityEvent){
            $this->stayTime = 0;
            $this->stayVec = null;
            $this->moveTime = 100;
            $this->attacker = $source->getDamager();
            if($this instanceof PigZombie) $this->setAngry(1000);
        }
        $pk = new EntityEventPacket();
        $pk->eid = $this->getId();
        $pk->event = !$this->isAlive() ? 3 : 2;
        Server::broadcastPacket($this->hasSpawned, $pk->setChannel(Network::CHANNEL_WORLD_EVENTS));
    }

    public function updateMove($tick = 1){
        $target = null;
        if($this->isMovement()){
            $target = $this->getTarget();
            $x = $target->x - $this->x;
            $y = $target->y - $this->y;
            $z = $target->z - $this->z;
            $atn = atan2($z, $x);
            if($this->stayTime > 0){
                $this->move(0, 0);
                $this->stayTime -= $tick;
                if($this->stayTime <= 0) $this->stayVec = null;
            }else{
                $speed = [
                    Zombie::NETWORK_ID => 0.11,
                    Creeper::NETWORK_ID => 0.09,
                    Skeleton::NETWORK_ID => 0.1,
                    Spider::NETWORK_ID => 0.113,
                    PigZombie::NETWORK_ID => 0.115,
                    Enderman::NETWORK_ID => 0.121
                ];
                $add = $this instanceof PigZombie && $this->isAngry() ? 0.132 : $speed[static::NETWORK_ID];
                if(!$this->onGround && $this->lastY !== null) $this->motionY -= $this->gravity;
                $this->move(cos($atn) * $add * $tick, sin($atn) * $add * $tick, $this->motionY * $tick);
            }
            $this->setRotation(rad2deg($atn - M_PI_2), rad2deg(-atan2($y, sqrt($x ** 2 + $z ** 2))));
        }
        $this->updateMovement();
        return $target;
    }

    public function getCollisionCubes(AxisAlignedBB $bb){
        $minX = (int) ($bb->minX);
        $minY = (int) ($bb->minY);
        $minZ = (int) ($bb->minZ);
        $maxX = (int) ($bb->maxX + 1);
        $maxY = (int) ($bb->maxY + 1);
        $maxZ = (int) ($bb->maxZ + 1);

        $collides = [];
        $v = new Vector3();
        for($v->z = $minZ; $v->z <= $maxZ; ++$v->z){
            for($v->x = $minX; $v->x <= $maxX; ++$v->x){
                for($v->y = $minY - 1; $v->y <= $maxY; ++$v->y){
                    $block = $this->level->getBlock($v);
                    if($block->getBoundingBox() !== null) $collides[] = $block;
                }
            }
        }

        foreach($this->level->getCollidingEntities($bb->grow(0.25, 0.25, 0.25), $this) as $ent){
            $collides[] = $ent;
        }

        return $collides;
    }

    public function move($dx, $dz, $dy = 0){
        $movX = $dx;
        $movY = $dy;
        $movZ = $dz;
        $list = $this->getCollisionCubes($this->boundingBox->getOffsetBoundingBox($dx, $dy, $dz));
        foreach($list as $target){
            if(!$target instanceof Block && !$target instanceof Entity) continue;
            $bb = $target->getBoundingBox();
            $minY = (int) $this->boundingBox->minY;
            if(in_array($bb->minY, [$minY, $minY + 1, $minY + 2])){
                if($this->boundingBox->maxZ > $bb->minZ && $this->boundingBox->minZ < $bb->maxZ){
                    if($this->boundingBox->maxX + $dx >= $bb->minX and $this->boundingBox->maxX <= $bb->minX){
                        if(($x1 = $bb->minX - ($this->boundingBox->maxX + $dx)) < 0) $dx += $x1;
                    }
                    if($this->boundingBox->minX + $dx <= $bb->maxX and $this->boundingBox->minX >= $bb->maxX){
                        if(($x1 = $bb->maxX - ($this->boundingBox->minX + $dx)) > 0) $dx += $x1;
                    }
                }
                if($this->boundingBox->maxX > $bb->minX && $this->boundingBox->minX < $bb->maxX){
                    if($this->boundingBox->maxZ + $dz >= $bb->minZ and $this->boundingBox->maxZ <= $bb->minZ){
                        if(($z1 = $bb->minZ - ($this->boundingBox->maxZ + $dz)) < 0) $dz += $z1;
                    }
                    if($this->boundingBox->minZ + $dz <= $bb->maxZ and $this->boundingBox->minZ >= $bb->maxZ){
                        if(($z1 = $bb->maxZ - ($this->boundingBox->minZ + $dz)) > 0) $dz += $z1;
                    }
                }
                if($target instanceof Block && $minY + 1 === $bb->maxY && $dy === 0){
                    $dy = 0.3;
                }
            }
            if(
                $this->boundingBox->maxX > $bb->minX
                and $this->boundingBox->minX < $bb->maxX
                and $this->boundingBox->maxZ > $bb->minZ
                and $this->boundingBox->minZ < $bb->maxZ
            ){
                if($this->boundingBox->maxY + $dy >= $bb->minY and $this->boundingBox->maxY <= $bb->minY){
                    if(($y1 = $bb->minY - ($this->boundingBox->maxY + $dy)) < 0) $dy += $y1;
                }
                if($this->boundingBox->minY + $dy <= $bb->maxY and $this->boundingBox->minY >= $bb->maxY){
                    if(($y1 = $bb->maxY - ($this->boundingBox->minY + $dy)) > 0) $dy += $y1;
                }
            }
        }
        $this->boundingBox->offset($dx, $dy, $dz);
        $this->setComponents($this->x + $dx, $this->y + $dy, $this->z + $dz);

        $this->updateFallState($dy, $this->onGround = ($movY != $dy and $movY < 0));
        if($this->onGround) $this->motionY = 0;

        $this->isCollidedVertically = $movY != $dy;
        $this->isCollidedHorizontally = ($movX != $dx or $movZ != $dz);
        $this->isCollided = ($this->isCollidedHorizontally or $this->isCollidedVertically);
    }

    public function knockBackCheck($tick = 1){
        if(!$this->isAlive() || !$this->attacker instanceof Entity) return false;

        if($this->moveTime > 5) $this->moveTime = 5;
        $this->moveTime -= $tick;
        $target = $this->attacker;
        $x = $target->x - $this->x;
        $y = $target->y - $this->y;
        $z = $target->z - $this->z;
        $atn = atan2($z, $x);
        $this->move(-cos($atn) * 0.3 * $tick, -sin($atn) * 0.3 * $tick, $this->moveTime > 3 ? 0.6 * $tick : 0);
        $this->setRotation(rad2deg(atan2($z, $x) - M_PI_2), rad2deg(-atan2($y, sqrt($x ** 2 + $z ** 2))));
        if((int) $this->moveTime <= 0) $this->attacker = null;
        $this->entityBaseTick($tick);
        $this->updateMovement();
        $this->lastTick = microtime(true);
        return true;
    }

    public function entityBaseTick($tickDiff = 1){
        Timings::$timerEntityBaseTick->startTiming();

        if(!$this->isAlive()) return false;
        $hasUpdate = Entity::entityBaseTick($tickDiff);

        if($this->isInsideOfSolid()){
            $hasUpdate = true;
            $ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_SUFFOCATION, 1);
            $this->attack($ev->getFinalDamage(), $ev);
        }

        if($this instanceof Enderman){
            if($this->level->getBlock(new Vector3(Math::floorFloat($this->x), Math::floorFloat($this->y), Math::floorFloat($this->z))) instanceof Water){
                $ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_DROWNING, 2);
                $this->attack($ev->getFinalDamage(), $ev);
                $this->teleport($this->add(mt_rand(-20, 20), mt_rand(-20, 20), mt_rand(-20, 20)));
            }
        }else{
            if(!$this->hasEffect(Effect::WATER_BREATHING) && $this->isInsideOfWater()){
                $hasUpdate = true;
                $airTicks = $this->getDataProperty(self::DATA_AIR) - $tickDiff;
                if($airTicks <= -20){
                    $airTicks = 0;
                    $ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_DROWNING, 2);
                    $this->attack($ev->getFinalDamage(), $ev);
                }
                $this->setDataProperty(self::DATA_AIR, self::DATA_TYPE_SHORT, $airTicks);
            }else{
                $this->setDataProperty(self::DATA_AIR, self::DATA_TYPE_SHORT, 300);
            }
        }

        if($this->attackTime > 0) $this->attackTime -= $tickDiff;
        Timings::$timerEntityBaseTick->stopTiming();
        return $hasUpdate;
    }

    /**
     * @return Player|Vector3
     */
    public function getTarget(){
        if(!$this->isMovement()) return new Vector3();
        if($this->stayTime > 0){
            if($this->stayVec === null or (mt_rand(1, 115) <= 3 and $this->stayTime % 20 === 0)) $this->stayVec = $this->add(mt_rand(-10, 10), mt_rand(-3, 3), mt_rand(-10, 10));
            return $this->stayVec;
        }
        $target = null;
        $nearDistance = PHP_INT_MAX;
        foreach($this->getViewers() as $p){
            if($p->spawned and $p->isAlive() and !$p->closed and $p->isSurvival() and ($distance = $this->distanceSquared($p)) <= 81){
                if($distance < $nearDistance){
                    $target = $p;
                    $nearDistance = $distance;
                    continue;
                }
            }
        }
        if(($target === null || ($this instanceof PigZombie && !$this->isAngry())) && $this->stayTime <= 0 && mt_rand(1, 420) === 1){
            $this->stayTime = mt_rand(100, 450);
            return $this->stayVec = $this->add(mt_rand(-10, 10), mt_rand(-3, 3), mt_rand(-10, 10));
        }
        if((!$this instanceof PigZombie && $target instanceof Player) || ($this instanceof PigZombie && $this->isAngry() && $target instanceof Player)){
            return $target;
        }elseif($this->moveTime >= mt_rand(650, 800) or !$this->target instanceof Vector3){
            $this->moveTime = 0;
            $this->target = $this->add(mt_rand(-100, 100), 0, mt_rand(-100, 100));
        }
        return $this->target;
    }

    public function close(){
        $this->created = false;
        parent::close();
    }

}
