<?php

declare(strict_types=1);

// Small API fixtures allow the actual InvMenu classes to be exercised on both
// World signatures without loading a server or any PocketMine native extension.
namespace pocketmine\math{
	class Vector3{
		public function __construct(public float $x = 0, public float $y = 0, public float $z = 0){}
	}
}

namespace pocketmine\block{
	class Block{
		public function __construct(private int $state_id){}
		public function getStateId() : int{ return $this->state_id; }
	}
}

namespace pocketmine\block\inventory{
	interface BlockInventory extends \pocketmine\inventory\Inventory{}
}

namespace pocketmine\inventory{
	interface Inventory{}
	class SimpleInventory implements Inventory{
		public function __construct(public int $size = 27){}
	}
}

namespace pocketmine\item{
	class Item{}
}

namespace pocketmine\inventory\transaction\action{
	class SlotChangeAction{
		public function __construct(private \pocketmine\inventory\Inventory $inventory){}
		public function getInventory() : \pocketmine\inventory\Inventory{ return $this->inventory; }
		public function getSourceItem() : \pocketmine\item\Item{ return new \pocketmine\item\Item(); }
		public function getTargetItem() : \pocketmine\item\Item{ return new \pocketmine\item\Item(); }
	}
}

namespace pocketmine\inventory\transaction{
	class InventoryTransaction{
		public function __construct(private \pocketmine\player\Player $player, private array $actions){}
		public function getSource() : \pocketmine\player\Player{ return $this->player; }
		public function getActions() : array{ return $this->actions; }
	}
}

namespace pocketmine\network\mcpe\convert{
	class TypeConverter{
		public function __construct(public int $protocol_id){}
		public static function getInstance() : self{ return new self(2193); }
		public function getBlockTranslator() : self{ return $this; }
		public function internalIdToNetworkId(int $id) : int{ return $this->protocol_id * 10000 + $id; }
	}
}

namespace pocketmine\network\mcpe\protocol\types{
	class BlockPosition extends \pocketmine\math\Vector3{
		public static function fromVector3(\pocketmine\math\Vector3 $v) : self{ return new self($v->x, $v->y, $v->z); }
	}
}

namespace pocketmine\network\mcpe\protocol\types\inventory{
	class WindowTypes{
		public const NONE = -9;
		public const CONTAINER = 0;
		public const HOPPER = 8;
	}
}

namespace pocketmine\network\mcpe\protocol{
	interface ClientboundPacket{}
	class UpdateBlockPacket implements ClientboundPacket{
		public const FLAG_NETWORK = 2;
		public const DATA_LAYER_NORMAL = 0;
		public static function create(\pocketmine\network\mcpe\protocol\types\BlockPosition $position, int $id, int $flags, int $layer) : self{
			$p = new self();
			$p->blockPosition = $position;
			$p->blockRuntimeId = $id;
			return $p;
		}
		public \pocketmine\network\mcpe\protocol\types\BlockPosition $blockPosition;
		public int $blockRuntimeId;
	}
	class BlockActorDataPacket implements ClientboundPacket{}
	class ContainerOpenPacket implements ClientboundPacket{
		public int $windowId;
		public int $windowType;
		public \pocketmine\network\mcpe\protocol\types\BlockPosition $blockPosition;
		public static function blockInv(int $id, int $type, \pocketmine\network\mcpe\protocol\types\BlockPosition $position) : self{
			$p = new self();
			$p->windowId = $id;
			$p->windowType = $type;
			$p->blockPosition = $position;
			return $p;
		}
	}
	class ContainerClosePacket implements ClientboundPacket{
		public const NETWORK_ID = 0x2f;
		public int $windowId;
		// Deliberately uninitialized, matching BedrockProtocol on pre-1.21 decode.
		public int $windowType;
		public bool $server = false;
		public static function create(int $id, int $type, bool $server) : self{
			$p = new self();
			$p->windowId = $id;
			$p->windowType = $type;
			$p->server = $server;
			return $p;
		}
	}
	class NetworkStackLatencyPacket implements ClientboundPacket{
		public const NETWORK_ID = 0x73;
		public static function create(int $timestamp, bool $response) : self{ return new self($timestamp, $response); }
		public function __construct(public int $timestamp, public bool $needResponse){}
	}
	class PacketViolationWarningPacket{
		public const NETWORK_ID = 0x9c;
		public const SEVERITY_WARNING = 0;
		public function getPacketId() : int{ return self::NETWORK_ID; }
		public function getType() : int{ return -1; }
		public function getSeverity() : int{ return self::SEVERITY_WARNING; }
	}
}

namespace pocketmine\scheduler{
	class ClosureTask{
		public function __construct(private \Closure $closure){}
		public function onRun() : void{ ($this->closure)(); }
	}
	class TaskHandler{
		public bool $cancelled = false;
		public function __construct(public ClosureTask $task, public int $due, public int $period = 0){}
		public function cancel() : void{ $this->cancelled = true; }
	}
	class TaskScheduler{
		private int $tick = 0;
		private array $tasks = [];
		public function scheduleDelayedTask(ClosureTask $task, int $delay) : TaskHandler{
			return $this->tasks[] = new TaskHandler($task, $this->tick + $delay);
		}
		public function scheduleRepeatingTask(ClosureTask $task, int $period) : TaskHandler{
			return $this->tasks[] = new TaskHandler($task, $this->tick + $period, $period);
		}
		public function advance(int $ticks) : void{
			for($i = 0; $i < $ticks; ++$i){
				++$this->tick;
				foreach($this->tasks as $handler){
					if(!$handler->cancelled && $handler->due <= $this->tick){
						$handler->task->onRun();
						if($handler->period > 0){ $handler->due = $this->tick + $handler->period; }
						else{ $handler->cancel(); }
					}
				}
			}
		}
		public function activeCount() : int{ return count(array_filter($this->tasks, static fn($h) => !$h->cancelled)); }
	}
}

namespace pocketmine\utils{
	class ObjectSet implements \IteratorAggregate{
		private array $values = [];
		public function add(\Closure ...$values) : void{ foreach($values as $v){ $this->values[spl_object_id($v)] = $v; } }
		public function remove(\Closure $value) : void{ unset($this->values[spl_object_id($value)]); }
		public function clear() : void{ $this->values = []; }
		public function toArray() : array{ return array_values($this->values); }
		public function getIterator() : \Traversable{ return new \ArrayIterator($this->values); }
	}
}

namespace pocketmine\network\mcpe{
	class InventoryManager{
		public \pocketmine\utils\ObjectSet $callbacks;
		public int $syncs = 0;
		public array $removed = [];
		private int $next_id = 1;
		public function __construct(private NetworkSession $network){
			$this->callbacks = new \pocketmine\utils\ObjectSet();
			$this->callbacks->add(static fn(int $id, \pocketmine\inventory\Inventory $inventory) => null);
		}
		public function getContainerOpenCallbacks() : \pocketmine\utils\ObjectSet{ return $this->callbacks; }
		public function open(\pocketmine\inventory\Inventory $inventory) : void{
			$id = $this->next_id++;
			foreach($this->callbacks as $callback){
				if(($packets = $callback($id, $inventory)) !== null){
					foreach($packets as $packet){ $this->network->sendDataPacket($packet); }
					return;
				}
			}
			throw new \RuntimeException('Unsupported inventory');
		}
		public function syncContents(\pocketmine\inventory\Inventory $inventory) : void{ ++$this->syncs; }
		public function onClientRemoveWindow(int $id) : void{ $this->removed[] = $id; }
	}
	class NetworkSession{
		public array $packets = [];
		public bool $send_success = true;
		public ?\pocketmine\player\Player $player = null;
		public InventoryManager $manager;
		public function __construct(private \pocketmine\network\mcpe\convert\TypeConverter $converter){ $this->manager = new InventoryManager($this); }
		public function getTypeConverter() : \pocketmine\network\mcpe\convert\TypeConverter{ return $this->converter; }
		public function getInvManager() : InventoryManager{ return $this->manager; }
		public function getPlayer() : ?\pocketmine\player\Player{ return $this->player; }
		public function sendDataPacket(\pocketmine\network\mcpe\protocol\ClientboundPacket $packet) : bool{ $this->packets[] = $packet; return $this->send_success; }
	}
}

namespace pocketmine\world{
	class WorldData{
		public array $restored = [];
		protected function buildUpdates(\pocketmine\network\mcpe\convert\TypeConverter $converter, array $positions) : array{
			$this->restored[] = [$converter->protocol_id, $positions];
			return [\pocketmine\network\mcpe\protocol\UpdateBlockPacket::create(
				\pocketmine\network\mcpe\protocol\types\BlockPosition::fromVector3($positions[0]),
				$converter->internalIdToNetworkId(7), 2, 0
			), new \pocketmine\network\mcpe\protocol\BlockActorDataPacket()];
		}
	}
	if(($GLOBALS['argv'][1] ?? '') === 'ryzemp'){
		class World extends WorldData{
			public function createBlockUpdatePackets(\pocketmine\network\mcpe\convert\TypeConverter $converter, array $positions) : array{ return $this->buildUpdates($converter, $positions); }
		}
	}else{
		class World extends WorldData{
			public function createBlockUpdatePackets(array $positions) : array{ return $this->buildUpdates(\pocketmine\network\mcpe\convert\TypeConverter::getInstance(), $positions); }
		}
	}
}

namespace pocketmine\player{
	class Player{
		public bool $connected = true;
		public bool $cancel_next_open = false;
		public ?\Closure $close_listener = null;
		private ?\pocketmine\inventory\Inventory $current = null;
		public function __construct(private \pocketmine\network\mcpe\NetworkSession $network, public \pocketmine\world\World $world){ $network->player = $this; }
		public function getId() : int{ return spl_object_id($this); }
		public function getNetworkSession() : \pocketmine\network\mcpe\NetworkSession{ return $this->network; }
		public function getWorld() : \pocketmine\world\World{ return $this->world; }
		public function isConnected() : bool{ return $this->connected; }
		public function getCurrentWindow() : ?\pocketmine\inventory\Inventory{ return $this->current; }
		public function setCurrentWindow(\pocketmine\inventory\Inventory $inventory) : bool{
			if($this->cancel_next_open){ $this->cancel_next_open = false; return false; }
			$this->network->manager->open($inventory);
			$this->current = $inventory;
			return true;
		}
		public function removeCurrentWindow() : void{
			if($this->current !== null){
				$old = $this->current;
				$this->current = null;
				if($this->close_listener !== null){ ($this->close_listener)(new \pocketmine\event\inventory\InventoryCloseEvent($this, $old)); }
			}
		}
	}
}

namespace pocketmine\event{
	interface Listener{}
}

namespace pocketmine\event\server{
	class DataPacketReceiveEvent{
		public bool $cancelled = false;
		public function __construct(private \pocketmine\network\mcpe\NetworkSession $origin, private object $packet){}
		public function getOrigin() : \pocketmine\network\mcpe\NetworkSession{ return $this->origin; }
		public function getPacket() : object{ return $this->packet; }
		public function cancel() : void{ $this->cancelled = true; }
	}
}

namespace pocketmine\event\inventory{
	class InventoryCloseEvent{
		public function __construct(private \pocketmine\player\Player $player, private \pocketmine\inventory\Inventory $inventory){}
		public function getPlayer() : \pocketmine\player\Player{ return $this->player; }
		public function getInventory() : \pocketmine\inventory\Inventory{ return $this->inventory; }
	}
	class InventoryTransactionEvent{
		public bool $cancelled = false;
		public function __construct(private \pocketmine\inventory\transaction\InventoryTransaction $transaction){}
		public function getTransaction() : \pocketmine\inventory\transaction\InventoryTransaction{ return $this->transaction; }
		public function cancel() : void{ $this->cancelled = true; }
	}
}

namespace pocketmine\plugin{
	interface Plugin{ public function getScheduler() : \pocketmine\scheduler\TaskScheduler; }
}

namespace{
	spl_autoload_register(static function(string $class) : void{
		if(str_starts_with($class, 'muqsit\\invmenu\\')){
			$path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $class) . '.php';
			if(is_file($path)){ require $path; }
		}
	});
}
