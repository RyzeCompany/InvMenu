<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuEventHandler;
use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\session\InvMenuInfo;
use muqsit\invmenu\session\PlayerManager;
use muqsit\invmenu\session\PlayerSession;
use muqsit\invmenu\session\PlayerWindowDispatcher;
use muqsit\invmenu\session\network\NetworkStackLatencyEntry;
use muqsit\invmenu\session\network\PlayerNetwork;
use muqsit\invmenu\session\network\handler\PlayerNetworkHandler;
use muqsit\invmenu\type\InvMenuType;
use muqsit\invmenu\type\graphic\BlockInvMenuGraphic;
use muqsit\invmenu\type\graphic\InvMenuGraphic;
use muqsit\invmenu\type\graphic\network\InvMenuGraphicNetworkTranslator;
use muqsit\invmenu\type\graphic\network\WindowTypeInvMenuGraphicNetworkTranslator;
use pocketmine\block\Block;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PacketViolationWarningPacket;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\player\Player;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\world\World;

error_reporting(E_ALL);
set_error_handler(static function(int $severity, string $message, string $file, int $line) : never{
	throw new ErrorException($message, 0, $severity, $file, $line);
});

function same(mixed $expected, mixed $actual, string $message = '') : void{
	if($expected !== $actual){ throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}

final class TestGraphic implements InvMenuGraphic{
	public int $sent = 0;
	public int $removed = 0;
	public function __construct(private int $type = WindowTypes::CONTAINER, private int $animation = 0){}
	public function send(Player $player, ?string $name) : void{ ++$this->sent; }
	public function sendInventory(Player $player, Inventory $inventory) : bool{ return $player->setCurrentWindow($inventory); }
	public function remove(Player $player) : void{ ++$this->removed; }
	public function getNetworkTranslator() : ?InvMenuGraphicNetworkTranslator{ return new WindowTypeInvMenuGraphicNetworkTranslator($this->type); }
	public function getAnimationDuration() : int{ return $this->animation; }
}

final class TestMenuType implements InvMenuType{
	public function __construct(public TestGraphic $graphic){}
	public function createInventory() : Inventory{ return new SimpleInventory(); }
	public function createGraphic(InvMenu $menu, Player $player) : ?InvMenuGraphic{ return $this->graphic; }
}

function fixture(int $protocol = 827) : array{
	$scheduler = new TaskScheduler();
	$plugin = new class($scheduler) implements \pocketmine\plugin\Plugin{
		public function __construct(private TaskScheduler $scheduler){}
		public function getScheduler() : TaskScheduler{ return $this->scheduler; }
	};
	(new ReflectionProperty(InvMenuHandler::class, 'registrant'))->setValue(null, $plugin);
	$network = new NetworkSession(new TypeConverter($protocol));
	$player = new Player($network, new World());
	$handler = new class implements PlayerNetworkHandler{
		private int $next = 100;
		public function createNetworkStackLatencyEntry(Closure $then) : NetworkStackLatencyEntry{ return new NetworkStackLatencyEntry(++$this->next, $then); }
	};
	$session = new PlayerSession($player, new PlayerNetwork($network, $handler));
	$manager = (new ReflectionClass(PlayerManager::class))->newInstanceWithoutConstructor();
	(new ReflectionProperty(PlayerManager::class, 'sessions'))->setValue($manager, [$player->getId() => $session]);
	(new ReflectionProperty(InvMenuHandler::class, 'player_manager'))->setValue(null, $manager);
	$events = new InvMenuEventHandler($manager);
	$player->close_listener = $events->onInventoryClose(...);
	return [$player, $session, $network, $scheduler, $events];
}

function latestLatency(NetworkSession $network) : int{
	foreach(array_reverse($network->packets) as $packet){
		if($packet instanceof NetworkStackLatencyPacket){ return $packet->timestamp; }
	}
	throw new RuntimeException('No latency request sent');
}

function acknowledge(InvMenuEventHandler $handler, NetworkSession $network) : void{
	$handler->onDataPacketReceive(new \pocketmine\event\server\DataPacketReceiveEvent($network, new PacketViolationWarningPacket()));
}

$tests = [];
$tests['virtual blocks use each recipient palette, including auxiliary barriers'] = static function() : void{
	foreach([589, 827, 844, 975, 2169, 2193] as $protocol){
		[$player, , $network] = fixture($protocol);
		$graphic = new BlockInvMenuGraphic(new Block(54), new Vector3(4, 65, -8));
		$graphic->send($player, null);
		same($protocol * 10000 + 54, $network->packets[0]->blockRuntimeId);
	}
};
$tests['closing restores real block and tile with the correct World API'] = static function() : void{
	[$player, , $network] = fixture();
	$graphic = new BlockInvMenuGraphic(new Block(54), new Vector3(4, 65, -8));
	$graphic->send($player, null);
	$graphic->remove($player);
	$protocol = ($GLOBALS['argv'][1] ?? '') === 'ryzemp' ? 827 : 2193;
	$expected = $protocol * 10000 + 7;
	//$network now contains the fake block followed by the real block and its tile.
	if($network->packets[1]->blockRuntimeId !== $expected){ throw new RuntimeException('Restored using the wrong palette'); }
	if(!$network->packets[2] instanceof \pocketmine\network\mcpe\protocol\BlockActorDataPacket){ throw new RuntimeException('Lost real block entity data'); }
	$graphic->remove($player);
	same(3, count($network->packets), 'Cleanup must be idempotent.');
};
$tests['dimension changes do not restore old coordinates in the destination'] = static function() : void{
	[$player, , $network] = fixture();
	$old = $player->world;
	$graphic = new BlockInvMenuGraphic(new Block(54), new Vector3(4, 65, -8));
	$graphic->send($player, null);
	$player->world = new World();
	$graphic->remove($player);
	same([], $player->world->restored);
	same([], $old->restored);
	same(1, count($network->packets));
};
$tests['legacy close rejection works without an initialized windowType'] = static function() : void{
	[$player, $session, $network, , $events] = fixture(589);
	$graphic = new TestGraphic();
	$menu = new InvMenu(new TestMenuType($graphic));
	$session->dispatcher = new PlayerWindowDispatcher($session, new InvMenuInfo($menu, $graphic, null));
	foreach([[255, null, true], [-1, -9, true], [255, 247, true], [255, 0, false], [1, null, false]] as [$id, $type, $cancelled]){
		$packet = new ContainerClosePacket();
		$packet->windowId = $id;
		if($type !== null){ $packet->windowType = $type; }
		$event = new \pocketmine\event\server\DataPacketReceiveEvent($network, $packet);
		$events->onDataPacketReceive($event);
		same($cancelled, $event->cancelled);
	}
	$session->dispatcher->finalize();
};
$tests['hopper retries retain their actual window type'] = static function() : void{
	[$player, $session, $network, $scheduler] = fixture();
	$menu = new InvMenu(new TestMenuType(new TestGraphic(WindowTypes::HOPPER)));
	$menu->send($player);
	$scheduler->advance(1);
	$closes = array_values(array_filter($network->packets, static fn($p) => $p instanceof ContainerClosePacket));
	same(1, count($closes));
	same(WindowTypes::HOPPER, $closes[0]->windowType);
	$session->dispatcher->setResult(false);
};
$tests['cancelled opening cleans its dispatcher and reports failure once'] = static function() : void{
	[$player, $session, $network, $scheduler] = fixture();
	$player->cancel_next_open = true;
	$graphic = new TestGraphic();
	$results = [];
	(new InvMenu(new TestMenuType($graphic)))->send($player, callback: static function(bool $ok) use(&$results) : void{ $results[] = $ok; });
	same([false], $results);
	same(null, $session->dispatcher);
	same(null, $session->current);
	same(1, $graphic->removed);
	same(1, count($network->manager->callbacks->toArray()));
	same(0, $scheduler->activeCount());
};
$tests['result callbacks may immediately open another menu'] = static function() : void{
	[$player, $session, $network, , $events] = fixture();
	$player->cancel_next_open = true;
	$results = [];
	$second = new InvMenu(new TestMenuType(new TestGraphic()));
	(new InvMenu(new TestMenuType(new TestGraphic())))->send($player, callback: static function(bool $ok) use(&$results, $second, $player) : void{
		$results[] = $ok;
		$second->send($player, callback: static function(bool $ok) use(&$results) : void{ $results[] = $ok; });
	});
	same($second->getInventory(), $player->getCurrentWindow());
	same($second, $session->current?->menu);
	acknowledge($events, $network);
	same([false, true], $results);
	same(null, $session->dispatcher);
};
$tests['duplicate completion and finalization do not repeat callbacks'] = static function() : void{
	[$player, $session, $network, $scheduler, $events] = fixture();
	$results = [];
	(new InvMenu(new TestMenuType(new TestGraphic())))->send($player, callback: static function(bool $ok) use(&$results) : void{ $results[] = $ok; });
	$dispatcher = $session->dispatcher;
	acknowledge($events, $network);
	$dispatcher->setResult(false);
	$dispatcher->finalize();
	$dispatcher->finalize();
	same([true], $results);
	same(0, $scheduler->activeCount());
};
$tests['closing during dispatch fails once and stops all retries'] = static function() : void{
	[$player, $session, , $scheduler] = fixture();
	$graphic = new TestGraphic();
	$results = [];
	(new InvMenu(new TestMenuType($graphic)))->send($player, callback: static function(bool $ok) use(&$results) : void{ $results[] = $ok; });
	$player->removeCurrentWindow();
	$scheduler->advance(150);
	same([false], $results);
	same(1, $graphic->removed);
	same(null, $session->dispatcher);
	same(null, $session->current);
	same(0, $scheduler->activeCount());
};
$tests['missing latency echoes expire the current request and its queue'] = static function() : void{
	[, $session, $network, $scheduler] = fixture();
	$results = [];
	$then = static function(bool $ok) use(&$results) : bool{ $results[] = $ok; return false; };
	$session->network->wait(0, $then);
	$stale = latestLatency($network);
	$session->network->wait(0, $then);
	$scheduler->advance(100);
	$session->network->notify($stale);
	same([false, false], $results);
	same(0, $scheduler->activeCount());
	$session->network->wait(0, $then);
	$session->network->notify(latestLatency($network));
	same([false, false, true], $results);
};
$tests['a callback can enqueue another latency request without losing it'] = static function() : void{
	[, $session, $network, $scheduler] = fixture();
	$results = [];
	$session->network->wait(0, static function(bool $ok) use($session, &$results) : bool{
		$results[] = $ok;
		$session->network->wait(0, static function(bool $ok) use(&$results) : bool{ $results[] = $ok; return false; });
		return false;
	});
	$session->network->notify(latestLatency($network));
	$session->network->notify(latestLatency($network));
	same([true, true], $results);
	same(0, $scheduler->activeCount());
};
$tests['zero-duration waits respect an already active latency barrier'] = static function() : void{
	[, $session, $network] = fixture();
	$results = [];
	$session->network->wait(0, static function(bool $ok) use(&$results) : bool{ $results[] = 'first'; return false; });
	$session->network->waitUntil(1, 0, static function(bool $ok) use(&$results) : void{ $results[] = 'second'; });
	same([], $results);
	$session->network->notify(latestLatency($network));
	same(['first'], $results);
	$session->network->notify(latestLatency($network));
	same(['first', 'second'], $results);
};
$tests['disconnect finalization drains callbacks and rejects further waits'] = static function() : void{
	[, $session, $network, $scheduler] = fixture();
	$results = [];
	$then = static function(bool $ok) use(&$results) : bool{ $results[] = $ok; return false; };
	$session->network->wait(0, $then);
	$session->network->wait(0, $then);
	$session->network->finalize();
	$count = count($network->packets);
	$session->network->wait(0, $then);
	$session->network->waitUntil(0, 0, $then);
	same([false, false, false, false], $results);
	same($count, count($network->packets));
	same(0, $scheduler->activeCount());
};
$tests['readonly menus still cancel native inventory transactions'] = static function() : void{
	[$player, , $network, , $events] = fixture();
	$menu = new InvMenu(new TestMenuType(new TestGraphic()));
	$clicks = 0;
	$menu->setListener(InvMenu::readonly(static function() use(&$clicks) : void{ ++$clicks; }));
	$menu->send($player);
	acknowledge($events, $network);
	$action = new \pocketmine\inventory\transaction\action\SlotChangeAction($menu->getInventory());
	$transaction = new \pocketmine\inventory\transaction\InventoryTransaction($player, [$action]);
	$event = new \pocketmine\event\inventory\InventoryTransactionEvent($transaction);
	$events->onInventoryTransaction($event);
	same(true, $event->cancelled);
	same(1, $clicks);
};

$failed = 0;
foreach($tests as $name => $test){
	try{
		$test();
		echo "PASS: $name\n";
	}catch(Throwable $e){
		++$failed;
		fwrite(STDERR, "FAIL: $name\n$e\n");
	}
}
echo count($tests) . ' scenarios; ' . $failed . " failures.\n";
exit($failed === 0 ? 0 : 1);
