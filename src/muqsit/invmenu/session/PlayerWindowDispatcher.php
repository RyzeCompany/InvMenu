<?php

declare(strict_types=1);

namespace muqsit\invmenu\session;

use BadMethodCallException;
use Closure;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\session\network\PlayerNetwork;
use pocketmine\block\inventory\BlockInventory;
use pocketmine\inventory\Inventory;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;

final class PlayerWindowDispatcher{

	public const STATE_SENDING = 0;
	public const STATE_FINALIZING = 1;
	public const STATE_COMPLETED = 2;

	private ?TaskHandler $task_handler = null;
	private ?Closure $container_open_callback = null;
	private bool $started = false;

	private bool $confirmation_requested = false;

	/** @var list<Closure(bool) : void> */
	private array $callbacks = [];

	public ?bool $result = null;
	/** @deprecated Retained for source compatibility; protocol violations are never acknowledgements. */
	public int $n_finalization_acks = 0;
	public int $state = self::STATE_SENDING;

	/** @var array{InvMenu, string|null, Closure|null}|null */
	public ?array $after_finalization = null;

	public function __construct(
		readonly public PlayerSession $session,
		readonly public InvMenuInfo $info,
		public int $retry_timeo = 20, // ticks
		public int $finalization_timeo = 20 // ticks
	){}

	public function start() : void{
		if($this->started || $this->result !== null){
			return;
		}
		$this->started = true;
		if(!$this->session->player->isConnected()){
			$this->setResult(false);
			return;
		}
		$this->info->graphic->send($this->session->player, $this->info->graphic_name);
		$this->session->network->waitUntil(PlayerNetwork::DELAY_TYPE_OPERATION, $this->info->graphic->getAnimationDuration(), function(bool $success) : void{
			if($this->result !== null){
				return;
			}
			$success = $success && $this->registerContainerOpenCallbacks();
			$success = $success && $this->info->graphic->sendInventory($this->session->player, $this->info->menu->getInventory());
			if($success && $this->result === null){
				$this->session->current = $this->info;
				// The open callback runs before InventoryManager has sent the
				// ContainerOpenPacket. Delay one tick so the echo request follows
				// the actual open packet in the client stream.
				$this->task_handler = InvMenuHandler::getRegistrant()->getScheduler()->scheduleDelayedTask(
					new ClosureTask($this->run(...)), 1
				);
			}else{
				$this->setResult(false);
			}
		});
	}

	private function registerContainerOpenCallbacks() : bool{
		$translator = $this->info->graphic->getNetworkTranslator();
		$callbacks = $this->session->player->getNetworkSession()->getInvManager()?->getContainerOpenCallbacks();
		if($translator === null || $callbacks === null){
			return false;
		}
		$previous = $callbacks->toArray();
		$this->container_open_callback = function(int $window_id, Inventory $inventory) use($translator, $callbacks, $previous) : ?array{
			if($inventory !== $this->info->menu->getInventory()){
				return null;
			}

			$callbacks->remove($this->container_open_callback);
			$this->container_open_callback = null;

			$packets = null;
			foreach($previous as $callback){
				$packets = $callback($window_id, $inventory);
				if($packets !== null){
					break;
				}
			}

			$packets ??= [ContainerOpenPacket::blockInv($window_id, WindowTypes::CONTAINER, $inventory instanceof BlockInventory ?
				BlockPosition::fromVector3($inventory->getHolder()) :
				new BlockPosition(0, 0, 0)
			)];

			foreach($packets as $packet){
				if($packet instanceof ContainerOpenPacket){
					$translator->translate($this->session, $this->info, $packet);
				}
			}
			return $packets;
		};
		// Take priority over other container open callbacks.
		// PocketMine's default container open callback disallows any BlockInventory
		// from having a custom callback
		$callbacks->clear();
		$callbacks->add($this->container_open_callback, ...$previous);
		return true;
	}

	public function run() : void{
		if($this->state !== self::STATE_SENDING || $this->confirmation_requested){
			return;
		}
		$this->task_handler = null;
		$player = $this->session->player;
		if(!$player->isConnected() || $player->getCurrentWindow() !== $this->info->menu->getInventory()){
			$this->setResult(false);
			return;
		}
		$this->confirmation_requested = true;
		// This is an ordered transport barrier, not a proof of client-side
		// rendering. It is intentionally bounded by PlayerNetwork's timeout.
		// Never send a forged client-response ContainerClosePacket to obtain
		// a PacketViolationWarning as an artificial acknowledgement.
		$this->session->network->wait(PlayerNetwork::DELAY_TYPE_OPERATION, function(bool $ack) use ($player) : bool{
			if($this->state === self::STATE_SENDING){
				$this->setResult($ack && $player->isConnected() && $player->getCurrentWindow() === $this->info->menu->getInventory());
			}
			return false;
		});
	}

	public function setResult(bool $result) : void{
		if($this->result !== null){
			return;
		}
		$this->result = $result;
		$this->state = self::STATE_FINALIZING;
		if(!$result && $this->session->current === $this->info){
			// this belongs here so we do not end up calling inventory close listener on failed dispatches
			$this->session->current = null;
		}
		$this->task_handler?->cancel();
		$this->task_handler = null;
		if($this->session->player->isConnected()){
			$manager = $this->session->player->getNetworkSession()->getInvManager();
			if($this->container_open_callback !== null){
				$manager?->getContainerOpenCallbacks()->remove($this->container_open_callback);
			}
			if($result){
				$this->session->player->getNetworkSession()->getInvManager()?->syncContents($this->info->menu->getInventory());
			}else{
				// A failed server-initiated opening is not a client close.
				// Let the native window lifecycle send a server-initiated
				// close instead of forging a client response.
				if($this->session->player->getCurrentWindow() === $this->info->menu->getInventory()){
					$this->session->player->removeCurrentWindow();
				}
				$this->info->graphic->remove($this->session->player);
			}
		}
		$this->container_open_callback = null;
		$callbacks = $this->callbacks;
		$this->callbacks = [];
		foreach($callbacks as $callback){
			$callback($result);
		}
		if($this->state === self::STATE_COMPLETED){
			return;
		}
		// No synthetic close packets remain; finalization does not wait for
		// protocol violations or retry-close acknowledgements.
		$this->finalize();
	}

	/**
	 * @param Closure(bool) : void $callback
	 */
	public function addCallback(Closure $callback) : void{
		if($this->result !== null){
			$callback($this->result);
		}else{
			$this->callbacks[] = $callback;
		}
	}

	public function finalize() : void{
		if($this->state === self::STATE_COMPLETED){
			return;
		}
		if($this->result === null){
			$this->setResult(false);
			if($this->state === self::STATE_COMPLETED){
				return;
			}
		}
		$this->task_handler?->cancel();
		$this->task_handler = null;
		$this->state = self::STATE_COMPLETED;
		$this->n_finalization_acks = 0;
		if($this->session->dispatcher === $this){
			$this->session->dispatcher = null;
		}
		$after_finalization = $this->after_finalization;
		$this->after_finalization = null;
		if($after_finalization !== null){
			if($this->session->player->isConnected()){
				[$menu, $name, $cb] = $after_finalization;
				$menu->send($this->session->player, $name, $cb);
			}elseif($after_finalization[2] !== null){
				$after_finalization[2](false);
			}
		}
	}

	public function then(InvMenu $menu, ?string $name, ?Closure $callback) : void{
		$this->state !== self::STATE_COMPLETED || throw new BadMethodCallException("No need to call then() on completed dispatcher");
		if($this->after_finalization !== null && $this->after_finalization[2] !== null){
			$this->after_finalization[2](false);
		}
		$this->after_finalization = [$menu, $name, $callback];
	}
}

