<?php

declare(strict_types=1);

namespace muqsit\invmenu\type\graphic;

use muqsit\invmenu\type\graphic\network\InvMenuGraphicNetworkTranslator;
use muqsit\invmenu\type\graphic\network\WorldBlockUpdate;
use pocketmine\block\Block;
use pocketmine\inventory\Inventory;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\player\Player;
use pocketmine\world\World;

final class BlockInvMenuGraphic implements PositionedInvMenuGraphic{

	private ?World $world = null;

	public function __construct(
		readonly private Block $block,
		readonly private Vector3 $position,
		readonly private ?InvMenuGraphicNetworkTranslator $network_translator = null,
		readonly private int $animation_duration = 0
	){}

	public function getPosition() : Vector3{
		return $this->position;
	}

	public function send(Player $player, ?string $name) : void{
		$this->world = $player->getWorld();
		$network = $player->getNetworkSession();
		$network->sendDataPacket(UpdateBlockPacket::create(BlockPosition::fromVector3($this->position), $network->getTypeConverter()->getBlockTranslator()->internalIdToNetworkId($this->block->getStateId()), UpdateBlockPacket::FLAG_NETWORK, UpdateBlockPacket::DATA_LAYER_NORMAL));
	}

	public function sendInventory(Player $player, Inventory $inventory) : bool{
		return $player->setCurrentWindow($inventory);
	}

	public function remove(Player $player) : void{
		$world = $this->world;
		$this->world = null;
		// A dimension transfer already discarded the old client-side blocks. Do not
		// overwrite these coordinates in the destination world with an old graphic.
		if($world === null || $world !== $player->getWorld()){
			return;
		}
		$network = $player->getNetworkSession();
		foreach(WorldBlockUpdate::createPackets($world, $network->getTypeConverter(), [$this->position]) as $packet){
			$network->sendDataPacket($packet);
		}
	}

	public function getNetworkTranslator() : ?InvMenuGraphicNetworkTranslator{
		return $this->network_translator;
	}

	public function getAnimationDuration() : int{
		return $this->animation_duration;
	}
}

