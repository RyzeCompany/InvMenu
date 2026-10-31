<?php

declare(strict_types=1);

namespace muqsit\invmenu\session\network;

use Closure;
use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\session\network\handler\PlayerNetworkHandler;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use SplQueue;

final class PlayerNetwork{

	public const DELAY_TYPE_ANIMATION_WAIT = 0;
	public const DELAY_TYPE_OPERATION = 1;
	private const RESPONSE_TIMEOUT_TICKS = 100;

	private ?NetworkStackLatencyEntry $current = null;
	private ?TaskHandler $timeout_task = null;
	private bool $processing = false;
	private bool $finalized = false;

	/** @var SplQueue<NetworkStackLatencyEntry> */
	private SplQueue $queue;

	public function __construct(
		readonly private NetworkSession $network_session,
		readonly private PlayerNetworkHandler $handler
	){
		$this->queue = new SplQueue();
	}

	public function finalize() : void{
		$this->finalized = true;
		$this->dropPending();
	}

	public function dropPending() : void{
		$pending = $this->queue;
		$this->queue = new SplQueue();
		if($this->current !== null){
			$pending->unshift($this->current);
			$this->current = null;
		}
		$this->timeout_task?->cancel();
		$this->timeout_task = null;
		$processing = $this->processing;
		$this->processing = true;
		try{
			foreach($pending as $entry){
				($entry->then)(false);
			}
		}finally{
			$this->processing = $processing;
			$this->advance();
		}
	}

	/**
	 * @param self::DELAY_TYPE_* $type
	 * @param Closure(bool) : bool $then
	 */
	public function wait(int $type, Closure $then) : void{
		if($this->finalized){
			$then(false);
			return;
		}
		$this->queue->enqueue($this->handler->createNetworkStackLatencyEntry($then));
		$this->advance();
	}

	/**
	 * Waits at least $wait_ms before calling $then(true).
	 *
	 * @param self::DELAY_TYPE_* $type
	 * @param int $wait_ms
	 * @param Closure(bool) : bool $then
	 */
	public function waitUntil(int $type, int $wait_ms, Closure $then) : void{
		if($this->finalized){
			$then(false);
			return;
		}
		if($wait_ms <= 0 && $this->current === null && $this->queue->isEmpty() && !$this->processing){
			$then(true);
			return;
		}

		$started_at = microtime(true) * 1000;
		$this->wait($type, function(bool $success) use($wait_ms, $then, $started_at) : bool{
			$elapsed_ms = (microtime(true) * 1000) - $started_at;
			if(!$success || $elapsed_ms >= $wait_ms){
				$then($success);
				return false;
			}

			return true;
		});
	}

	private function advance() : void{
		if($this->current !== null || $this->processing || $this->finalized || $this->queue->isEmpty()){
			return;
		}
		$entry = $this->current = $this->queue->dequeue();
		$entry->sent_at = microtime(true) * 1000;
		// Expire the whole pending batch: no echo must not leave menu callbacks
		// blocked indefinitely or advance through a stale backlog after a timeout.
		$this->timeout_task = InvMenuHandler::getRegistrant()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($entry) : void{
			if($this->current === $entry){
				$this->dropPending();
			}
		}), self::RESPONSE_TIMEOUT_TICKS);
		if(!$this->network_session->sendDataPacket(NetworkStackLatencyPacket::create($entry->network_timestamp, true)) && $this->current === $entry){
			$this->processCurrent(false);
		}
	}

	private function processCurrent(bool $success) : void{
		if($this->current !== null){
			$current = $this->current;
			$this->current = null;
			$this->timeout_task?->cancel();
			$this->timeout_task = null;
			$this->processing = true;
			try{
				if(($current->then)($success) && $success && !$this->finalized){
					$this->queue->unshift($current);
				}
			}finally{
				$this->processing = false;
				$this->advance();
			}
		}
	}

	public function notify(int $timestamp) : void{
		if($this->current !== null && $timestamp === $this->current->timestamp){
			$this->processCurrent(true);
		}
	}
}

