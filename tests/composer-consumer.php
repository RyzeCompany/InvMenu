<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';
$directory = $argv[2] ?? '';
if($directory === ''){
	fwrite(STDERR, "Usage: composer-consumer.php <pocketmine|ryzemp|verify> <directory> [server]\n");
	exit(1);
}

if($mode === 'verify'){
	require $directory . '/vendor/autoload.php';
	$package_path = \Composer\InstalledVersions::getInstallPath('ryzecompany/invmenu');
	$original_path = \Composer\InstalledVersions::getInstallPath('muqsit/invmenu');
	if($package_path === null || $original_path !== null){
		throw new RuntimeException('The fork must be installed without a second upstream copy.');
	}
	$menu = new ReflectionClass(\muqsit\invmenu\InvMenu::class);
	if(!str_starts_with((string) $menu->getFileName(), $package_path . '/src/')){
		throw new RuntimeException('The original namespace must load from the fork.');
	}
	$expected = ($argv[3] ?? '') === 'ryzemp' ? 2 : 1;
	$actual = (new ReflectionMethod(\pocketmine\world\World::class, 'createBlockUpdatePackets'))->getNumberOfRequiredParameters();
	if($actual !== $expected){
		throw new RuntimeException("Unexpected World API: expected $expected required parameters, got $actual.");
	}
	echo "Composer installation, original namespace, replacement and real World API verified.\n";
	exit(0);
}

if(!in_array($mode, ['pocketmine', 'ryzemp'], true)){
	throw new InvalidArgumentException('Unknown server: ' . $mode);
}
if(!is_dir($directory) && !mkdir($directory, 0777, true)){
	throw new RuntimeException('Cannot create consumer directory.');
}
$require = [
	'ryzecompany/invmenu' => '4.7.5',
	// Verify that existing dependencies on the upstream package are satisfied
	// by this fork rather than installing duplicate PHP classes.
	'muqsit/invmenu' => '^4.7.4',
	$mode === 'ryzemp' ? 'ryzemcbe/ryzemp' : 'pocketmine/pocketmine-mp' => $mode === 'ryzemp' ? '1.0.0-beta.3' : '^5.41.0'
];
$manifest = [
	'name' => 'invmenu-tests/consumer',
	'repositories' => [[
		'type' => 'path',
		'url' => dirname(__DIR__),
		'options' => ['symlink' => false, 'versions' => ['ryzecompany/invmenu' => '4.7.5']]
	]],
	'require' => $require,
	'config' => ['allow-plugins' => false]
];
file_put_contents($directory . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
