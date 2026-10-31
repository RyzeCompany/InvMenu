<?php

declare(strict_types=1);

namespace muqsit\invmenu\type\graphic\network;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\world\World;
use ReflectionMethod;

final class WorldBlockUpdate{

	/**
	 * @param list<Vector3> $positions
	 * @return list<ClientboundPacket>
	 */
	public static function createPackets(World $world, TypeConverter $converter, array $positions) : array{
		// PocketMine takes positions only; RyzeMP requires the recipient's palette.
		// Detect the API once, without tying compatibility to a software name.
		static $requires_converter = null;
		$requires_converter ??= (new ReflectionMethod(World::class, "createBlockUpdatePackets"))->getNumberOfRequiredParameters() === 2;
		return $requires_converter ? $world->createBlockUpdatePackets($converter, $positions) : $world->createBlockUpdatePackets($positions);
	}
}
