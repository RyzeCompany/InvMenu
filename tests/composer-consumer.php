<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';
$directory = $argv[2] ?? '';
if($directory === ''){
	fwrite(STDERR, "Usage: composer-consumer.php <prepare|public|verify> <directory>\n");
	exit(1);
}

if($mode === 'verify'){
	require $directory . '/vendor/autoload.php';
	$package_path = \Composer\InstalledVersions::getInstallPath('ryzecompany/invmenu');
	$original_path = \Composer\InstalledVersions::getInstallPath('muqsit/invmenu');
	if($package_path === null || $original_path !== null){
		throw new RuntimeException('The fork must be installed without a second upstream copy.');
	}
	$package_path = realpath($package_path);
	if($package_path === false){
		throw new RuntimeException('Cannot resolve the installed fork directory.');
	}
	$menu = new ReflectionClass(\muqsit\invmenu\InvMenu::class);
	if(!str_starts_with((string) $menu->getFileName(), $package_path . '/src/')){
		throw new RuntimeException('The original namespace must load from the fork.');
	}
	if(\Composer\InstalledVersions::getInstallPath('ryzemcbe/ryzemp') === null || \Composer\InstalledVersions::getInstallPath('pocketmine/pocketmine-mp') !== null){
		throw new RuntimeException('InvMenu must install RyzeMP without a second PocketMine implementation.');
	}
	$expected = 2;
	$actual = (new ReflectionMethod(\pocketmine\world\World::class, 'createBlockUpdatePackets'))->getNumberOfRequiredParameters();
	if($actual !== $expected){
		throw new RuntimeException("Unexpected World API: expected $expected required parameters, got $actual.");
	}
	$converter = \pocketmine\network\mcpe\convert\TypeConverter::getInstance(\pocketmine\network\mcpe\protocol\ProtocolInfo::CURRENT_PROTOCOL);
	foreach([\pocketmine\block\VanillaBlocks::CHEST(), \pocketmine\block\VanillaBlocks::HOPPER()] as $block){
		$converter->getBlockTranslator()->internalIdToNetworkId($block->getStateId());
	}
	$types = new \muqsit\invmenu\type\InvMenuTypeRegistry();
	foreach([\muqsit\invmenu\InvMenu::TYPE_CHEST => 27, \muqsit\invmenu\InvMenu::TYPE_DOUBLE_CHEST => 54, \muqsit\invmenu\InvMenu::TYPE_HOPPER => 5] as $type => $size){
		if($types->get($type)->createInventory()->getSize() !== $size){
			throw new RuntimeException('Unexpected inventory size: ' . $type);
		}
	}
	echo "Composer installation, automatic RyzeMP dependency, original namespace, replacement and real inventory/protocol API verified.\n";
	exit(0);
}

if(!in_array($mode, ['prepare', 'public'], true)){
	throw new InvalidArgumentException('Unknown mode: ' . $mode);
}
if(!is_dir($directory) && !mkdir($directory, 0777, true)){
	throw new RuntimeException('Cannot create consumer directory.');
}
$require = [
	'ryzecompany/invmenu' => '4.7.5',
	// Verify that existing dependencies on the upstream package are satisfied
	// by this fork rather than installing duplicate PHP classes.
	'muqsit/invmenu' => '^4.7.4'
];
$manifest = [
	'name' => 'invmenu-tests/consumer',
	'repositories' => [[
		'type' => 'path',
		'url' => dirname(__DIR__),
		'options' => ['symlink' => false, 'versions' => ['ryzecompany/invmenu' => '4.7.5']]
	]],
	'require' => $require,
	'minimum-stability' => 'beta',
	'prefer-stable' => true,
	'config' => ['allow-plugins' => false]
];
if($mode === 'public'){
	unset($manifest['repositories']);
}
file_put_contents($directory . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
