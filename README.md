# InvMenu
Create and manage virtual inventories in RyzeMP.

This RyzeCompany fork uses RyzeMP's multiversion network API. The public
`muqsit\invmenu` namespace, menu types and transaction listeners are unchanged.
Fake blocks use the recipient's session palette; closing restores the real block
and block entity through the matching PocketMine or RyzeMP World API.

The compatibility code handles pre-1.21 close packets without `windowType`,
preserves each menu's window type during retries and expires missing latency
responses after 100 server ticks. An expired pending batch reports failure and
releases its callbacks; a late response cannot reopen the expired menu.

RyzeMP's accepted protocol list remains the source of truth for client versions.
Support in this library does not add new protocols to the server. Real Bedrock
clients must still be checked for opening, closing, item movement and mixed-version
shared inventories before a production rollout.

## Installation and setup

### Composer

The Composer package name for this fork is `ryzecompany/invmenu`; the PHP
namespace remains `muqsit\invmenu`. In an existing Composer project, run:

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require ryzecompany/invmenu:^4.7.5
```

If Packagist has not indexed this fork yet, add its public repository before
the require command:

```bash
composer config repositories.invmenu vcs https://github.com/RyzeCompany/InvMenu.git
```

The package requires PHP 8.1+ and installs `ryzemcbe/ryzemp:^5.44.2-p1` automatically.
The numeric Composer API version corresponds to the API in RyzeMP 1.0.0-beta.4;
the server keeps its independent version. The beta setting allows the tagged
BedrockProtocol and BedrockData dependencies while stable releases remain preferred.
This fork replaces `muqsit/invmenu` at its own version, so dependencies on a
compatible upstream version do not install a second copy of the same classes.
Existing plugins keep their imports, menu types and transaction listeners.

To use RyzeMP's API directly in another plugin project, configure the same beta
and stable preferences, then run `composer require --dev ryzemcbe/ryzemp:^5.44.2-p1`.
See [the RyzeMP Composer API guide](https://github.com/RyzeCompany/RyzeMP/blob/main/docs/COMPOSER_API.md).

Executing API code requires the compatible PocketMine PHP runtime and its native
extensions. For IDE/static-analysis installation on an ordinary PHP workstation,
add `--ignore-platform-req='ext-*'` to the require command; this does not supply
the runtime required to execute the API. Keep Composer's security policy enabled.

For plugins distributed as PHARs, include the Composer dependency in the plugin
build using your normal virion/bundling process, excluding the server dependencies.
A dependency in `composer.json` alone does not embed it in an already compiled plugin.

### Virion
Build [this fork](https://github.com/RyzeCompany/InvMenu) as a virion with Poggit
and place the resulting .phar file in your `virions/` folder. Builds from
`Muqsit/InvMenu` do not include this fork's RyzeMP compatibility changes.
Read [installation](https://github.com/Muqsit/InvMenu/wiki/Installation) and [using in a plugin](https://github.com/Muqsit/InvMenu/wiki/Using-InvMenu-in-a-plugin)
for a more elaborate guide on how to setup InvMenu library.

Rebuild every plugin that embeds InvMenu using this fork. Replacing a server PHAR
or copying a virion next to an already compiled plugin does not replace the
plugin's bundled copy of InvMenu.

> [!NOTE]
> You must register `InvMenuHandler` before you can use InvMenu.
> ```php
> // in class MyPlugin extends PluginBase:
> protected function onEnable() : void{
> 	if(!InvMenuHandler::isRegistered()){
> 		InvMenuHandler::register($this);
> 	}
> }

## Create a virtual inventory
Quick start, use `InvMenu::create(InvMenu::TYPE_CHEST)->send($player);` to display a virtual chest inventory to a player.

`InvMenu::create($identifier)` creates an InvMenu instance. `$identifier` may be an identifier of a registered `InvMenuType` object.
InvMenu comes with 3 pre-registered inventory types of different sizes:
- `InvMenu::TYPE_CHEST` - a 27-slot normal chest inventory
- `InvMenu::TYPE_DOUBLE_CHEST` - a 54-slot double chest inventory
- `InvMenu::TYPE_HOPPER` - a 5-slot hopper inventory

```php
$menu = InvMenu::create(InvMenu::TYPE_CHEST);
$inventory = $menu->getInventory();
```

As `$inventory` implements [PocketMine's Inventory interface](https://github.com/pmmp/PocketMine-MP/blob/stable/src/inventory/Inventory.php), you get to access all the fancy PocketMine inventory methods.
```php
$menu->getInventory()->setContents([
	VanillaItems::DIAMOND_SWORD(),
	VanillaItems::DIAMOND_PICKAXE()
]);
$menu->getInventory()->addItem(VanillaItems::DIAMOND_AXE());
$menu->getInventory()->setItem(3, VanillaItems::GOLD_INGOT());
```
To send a menu to a player, use:
```php
/** @var Player $player */
$menu->send($player);
```
> [!TIP]
> One `InvMenu` can be sent to multiple players—even 2 players in different worlds, so everyone views and edits the same inventory as if it were one chest.


## Set a custom name
There are two ways to name an InvMenu. You can either specify a global name (see method A), or you can set a name at the time you send the menu (see method B).
```php
$menu->setName("Custom Name"); // method A
$menu->send($player, "Greetings, " . $player->getName()); // method B
```

## Verify whether a menu is sent successfully
`InvMenu::send()` is not guaranteed to succeed. A failure may arise from plugins cancelling InventoryOpenEvent, a disconnected player, or the player refusing the request (e.g., because they are in pause menu).
Use the `$callback` parameter to verify whether a menu has been opened.
```php
$menu->send($player, callback: function(bool $success) : void{
	if($success){
		// player is viewing the menu
	}
});
```

## Monitor movement of items
InvMenu comes with a listener whereby developers can write logic to monitor movement of items in and out of inventory, and thereby take action.
A listener is a callback with the following signature:
```php
/**
 * @param InvMenuTransaction $transaction
 *
 * Return $transaction->continue() to continue the transaction.
 * Return $transaction->discard() to cancel the transaction.
 * @return InvMenuTransactionResult
 */
Closure(InvMenuTransaction $transaction) : InvMenuTransactionResult;
```
- `InvMenuTransaction::getPlayer()` returns the `Player` that triggered the transaction.
- `InvMenuTransaction::getItemClicked()` returns the `Item` the player clicked in the menu. You may also use `InvMenuTransaction::getOut()`.
- `InvMenuTransaction::getItemClickedWith()` returns the `Item` the player had in their hand when clicking an item. You may also use `InvMenuTransaction::getIn()`.
- `InvMenuTransaction::getAction()` returns `SlotChangeAction` - you can get the slot that the player clicked in the menu.
- `InvMenuTransaction::getTransaction()` returns the complete `InventoryTransaction` holding all the above information.
```php
$menu->setListener(function(InvMenuTransaction $transaction) : InvMenuTransactionResult{
	$player = $transaction->getPlayer();
	$itemClicked = $transaction->getItemClicked();
	$itemClickedWith = $transaction->getItemClickedWith();
	$action = $transaction->getAction();
	$txn = $transaction->getTransaction();
	return $transaction->continue();
});
```
The listener below does not allow players to take out apples from the menu:
```php
$menu->setListener(function(InvMenuTransaction $transaction) : InvMenuTransactionResult{
	if($transaction->getItemClicked()->getTypeId() === ItemTypeIds::APPLE){
		$player->sendMessage("You cannot take apples out of that inventory.");
		return $transaction->discard();
	}
	return $transaction->continue();
});
```

There are two methods you can use to prevent players from editing the menu. Either create a listener that `discard()`s
the transaction, or use `InvMenu::readonly()`.
```php
$menu->setListener(function(InvMenuTransaction $transaction) : InvMenuTransactionResult{
	return $transaction->discard();
});

$menu->setListener(InvMenu::readonly()); // equivalent shorthand of the above

// you can also pass a callback in InvMenu::readonly()
$menu->setListener(InvMenu::readonly(function(DeterministicInvMenuTransaction $transaction) : void{
	// do something
}));
```
Alternatively, you may choose to write your own `InventoryTransactionEvent` listener that works on transactions on
`$menu->getInventory()`. However, an InvMenu listener is enough to fulfil most tasks.

## Execute a task post-transaction
Few actions are not possible to invoke at the time a player is viewing an inventory, such as sending a form—a player
cannot view a form while viewing an inventory. Close the menu and utilize `InvMenuTransactionResult::then()` callback to
achieve this.
```php
$menu->setListener(function(InvMenuTransaction $transaction) : InvMenuTransactionResult{
	$transaction->getPlayer()->removeCurrentWindow();
	return $transaction->discard()->then(function(Player $player) : void{
		$player->sendForm(new Form());
	});
});

// or if you are using InvMenu::readonly():
$menu->setListener(InvMenu::readonly(function(DeterministicInvMenuTransaction $transaction) : void{
	$transaction->getPlayer()->removeCurrentWindow();
	$transaction->then(function(Player $player) : void{
		$player->sendForm(new Form());
	});
}));
```

## Monitor menu close events
Register an inventory close callback to run whenever a player closes the menu. An inventory close callback takes the
following signature:
```php
/**
 * @param Player $player the player that closed the menu
 * @param Inventory $inventory the inventory of the menu
 */
Closure(Player $player, Inventory $inventory) : void;
```
```php
$menu->setInventoryCloseListener(function(Player $player, Inventory $inventory) : void{
	$player->sendMessage("You are no longer viewing the menu.");
});
```
Inventory close listener is fired during both—server-initiated requests (i.e., `$player->removeCurrentWindow()`) and
when the player closes the inventory on their end.

## Advanced usage: Register a custom InvMenuType
> [!IMPORTANT]
> PocketMine does not register a dispenser block. As of PocketMine v5, the task of registering missing vanilla blocks is
> excessively laborious and hence beyond the scope of this guide. [pmmp/RegisterBlocksDemoPM5](https://github.com/pmmp/RegisterBlocksDemoPM5)
> has a nice guide on how to achieve this. **Still overwhelmed?** I wrote a [drag-n-drop example plugin](https://gist.github.com/Muqsit/8884e0f75b317c332a56e01740bbfe98)
> that does all of it and registers a `/dispenser` command. With DevTools plugin installed, simply copy the code and
> paste it in a new "DispenserInvMenuPlugin.php" file in your server's plugin folder.

InvMenu does not provide a 9-slot dispenser inventory. But you can still achieve this by registering a dispenser InvMenuType.
You'll need to specify inventory size, block actor identifier (tile identifier), and the window type (network property) for
the creation of the graphic (block) and inventory parts.
```php
public const TYPE_DISPENSER = "myplugin:dispenser";

protected function onEnable() : void{
	InvMenuHandler::getTypeRegistry()->register(self::TYPE_DISPENSER, InvMenuTypeBuilders::BLOCK_ACTOR_FIXED()
		->setBlock(ExtraVanillaBlocks::DISPENSER())
		->setSize(9)
		->setBlockActorId("Dispenser")
		->setNetworkWindowType(WindowTypes::DISPENSER)
	->build());
}
```
Sweet! Now you can create a dispenser menu using:
```php
$menu = InvMenu::create(self::TYPE_DISPENSER);
```

## InvMenu Wiki
Applications, examples, tutorials and featured projects using InvMenu can be found on the [InvMenu Wiki](https://github.com/Muqsit/InvMenu/wiki/InvMenu-v4.0).


