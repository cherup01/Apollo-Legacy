<?php

/*
 * DevTools plugin for PocketMine-MP
 * Copyright (C) 2014 PocketMine Team <https://github.com/PocketMine/DevTools>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
*/

namespace DevTools;

use DevTools\commands\ExtractPluginCommand;
use DevTools\commands\GeneratePluginCommand;
use FolderPluginLoader\FolderPluginLoader;
use pocketmine\command\Command;
use pocketmine\command\CommandExecutor;
use pocketmine\command\CommandSender;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\permission\Permission;
use pocketmine\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginLoadOrder;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class DevTools extends PluginBase implements CommandExecutor{

	public function onLoad(){
		$map = $this->getServer()->getCommandMap();
		$map->register("devtools", new ExtractPluginCommand($this));
		$map->register("devtools", new GeneratePluginCommand($this));
	}

	public function onEnable(){
		@mkdir($this->getDataFolder());

		$this->getServer()->getPluginManager()->registerInterface("FolderPluginLoader\\FolderPluginLoader");
		$this->getServer()->getPluginManager()->loadPlugins($this->getServer()->getPluginPath(), ["FolderPluginLoader\\FolderPluginLoader"]);
		$this->getLogger()->info("Registered folder plugin loader");
		$this->getServer()->enablePlugins(PluginLoadOrder::STARTUP);

	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		switch($command->getName()){
			case "makeplugin":
				if(isset($args[0]) and $args[0] === "FolderPluginLoader"){
					return $this->makePluginLoader($sender, $command, $label, $args);
				}else{
					return $this->makePluginCommand($sender, $command, $label, $args);
				}
			case "makeserver":
				return $this->makeServerCommand($sender, $command, $label, $args);
			case "checkperm":
				return $this->permissionCheckCommand($sender, $command, $label, $args);
			default:
				return false;
		}
	}

	private function permissionCheckCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		$target = $sender;
		if(!isset($args[0])){
			return false;
		}
		$node = strtolower($args[0]);
		if(isset($args[1])){
			if(($player = $this->getServer()->getPlayer($args[1])) instanceof Player){
				$target = $player;
			}else{
				return false;
			}
		}

		if($target !== $sender and !$sender->hasPermission("devtools.command.checkperm.other")){
			$sender->sendMessage(TextFormat::RED . "You do not have permissions to check other players.");
			return true;
		}else{
			$sender->sendMessage(TextFormat::GREEN . "---- " . TextFormat::WHITE . "Permission node " . $node . TextFormat::GREEN . " ----");
			$perm = $this->getServer()->getPluginManager()->getPermission($node);
			if($perm instanceof Permission){
				$desc = TextFormat::GOLD . "Description: " . TextFormat::WHITE . $perm->getDescription() . "\n";
				$desc .= TextFormat::GOLD . "Default: " . TextFormat::WHITE . $perm->getDefault() . "\n";
				$children = "";
				foreach($perm->getChildren() as $name => $true){
					$children .= $name . ", ";
				}
				$desc .= TextFormat::GOLD . "Children: " . TextFormat::WHITE . substr($children, 0, -2) . "\n";
			}else{
				$desc = TextFormat::RED . "Permission does not exist\n";
				$desc .= TextFormat::GOLD . "Default: " . TextFormat::WHITE . Permission::$DEFAULT_PERMISSION . "\n";
			}
			$sender->sendMessage($desc);
			$sender->sendMessage(TextFormat::YELLOW . $target->getName() . TextFormat::WHITE . " has it set to " . ($target->hasPermission($node) === true ? TextFormat::GREEN . "true" : TextFormat::RED . "false"));
			return true;
		}
	}

	private function makePluginLoader(CommandSender $sender, Command $command, string $label, array $args) : bool{
		$pharPath = $this->getDataFolder() . DIRECTORY_SEPARATOR . "FolderPluginLoader.phar";
		if(file_exists($pharPath)){
			$sender->sendMessage("Phar plugin already exists, overwriting...");
			\Phar::unlinkArchive($pharPath);
		}
		$phar = new \Phar($pharPath);
		$phar->setMetadata([
			"name" => "FolderPluginLoader",
			"version" => "1.0.1",
			"main" => "FolderPluginLoader\\Main",
			"api" => ["1.0.0", "2.0.0"],
			"depend" => [],
			"description" => "Loader of folder plugins",
			"authors" => ["PocketMine Team"],
			"website" => "https://github.com/PocketMine/DevTools",
			"creationDate" => time()
		]);
		$phar->setStub('<?php __HALT_COMPILER();');
		$phar->setSignatureAlgorithm(\Phar::SHA1);
		$phar->startBuffering();

		$phar->addFromString("plugin.yml", "name: FolderPluginLoader\nversion: 1.0.1\nmain: FolderPluginLoader\\Main\napi: [1.0.0, 2.0.0]\nload: STARTUP\n");
		$phar->addFile($this->getFile() . "src/FolderPluginLoader/FolderPluginLoader.php", "src/FolderPluginLoader/FolderPluginLoader.php");
		$phar->addFile($this->getFile() . "src/FolderPluginLoader/Main.php", "src/FolderPluginLoader/Main.php");

		foreach($phar as $file => $finfo){
			/** @var \PharFileInfo $finfo */
			if($finfo->getSize() > (1024 * 512)){
				$finfo->compress(\Phar::GZ);
			}
		}
		$phar->stopBuffering();
		$sender->sendMessage("Folder plugin loader has been created on " . $pharPath);
		return true;
	}

	private function makePluginCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		$pluginName = trim(implode(" ", $args));
		if($pluginName === "" or !(($plugin = Server::getInstance()->getPluginManager()->getPlugin($pluginName)) instanceof Plugin)){
			$sender->sendMessage(TextFormat::RED . "Invalid plugin name, check the name case.");
			return true;
		}
		$description = $plugin->getDescription();

		if(!($plugin->getPluginLoader() instanceof FolderPluginLoader)){
			$sender->sendMessage(TextFormat::RED . "Plugin " . $description->getName() . " is not in folder structure.");
			return true;
		}

		$pharPath = $this->getDataFolder() . DIRECTORY_SEPARATOR . $description->getName() . "_v" . $description->getVersion() . ".phar";

		$metadata = [
			"name" => $description->getName(),
			"version" => $description->getVersion(),
			"main" => $description->getMain(),
			"api" => $description->getCompatibleApis(),
			"depend" => $description->getDepend(),
			"description" => $description->getDescription(),
			"authors" => $description->getAuthors(),
			"website" => $description->getWebsite(),
			"creationDate" => time()
		];

		if($description->getName() === "DevTools"){
			$stub = '<?php require("phar://". __FILE__ ."/src/DevTools/ConsoleScript.php"); __HALT_COMPILER();';
		}else{
			$stub = '<?php echo "PocketMine-MP plugin ' . $description->getName() . ' v' . $description->getVersion() . '\nThis file has been generated using DevTools v' . $this->getDescription()->getVersion() . ' at ' . date("r") . '\n----------------\n";if(extension_loaded("phar")){$phar = new \Phar(__FILE__);foreach($phar->getMetadata() as $key => $value){echo ucfirst($key).": ".(is_array($value) ? implode(", ", $value):$value)."\n";}} __HALT_COMPILER();';
		}

		$reflection = new \ReflectionClass("pocketmine\\plugin\\PluginBase");
		$file = $reflection->getProperty("file");
		$file->setAccessible(true);
		$filePath = rtrim(str_replace("\\", "/", $file->getValue($plugin)), "/") . "/";

		$this->buildPhar($sender, $pharPath, $filePath, [], $metadata, $stub, \Phar::SHA1);

		$sender->sendMessage("Phar plugin " . $description->getName() . " v" . $description->getVersion() . " has been created on " . $pharPath);
		return true;
	}

	private function makeServerCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if(strpos(\pocketmine\PATH, "phar://") === 0){
			$sender->sendMessage(TextFormat::RED . "This command can only be used on a server running from source code");
			return true;
		}

		$server = $sender->getServer();
		$pharPath = $this->getDataFolder() . DIRECTORY_SEPARATOR . $server->getName() . "_" . $server->getPocketMineVersion() . ".phar";

		$metadata = [
			"name" => $server->getName(),
			"version" => $server->getPocketMineVersion(),
			"api" => $server->getApiVersion(),
			"minecraft" => $server->getVersion(),
			"creationDate" => time(),
			"protocol" => ProtocolInfo::CURRENT_PROTOCOL
		];

		$stub = '<?php require_once("phar://". __FILE__ ."/src/pocketmine/PocketMine.php");  __HALT_COMPILER();';

		$filePath = realpath(\pocketmine\PATH) . "/";
		$filePath = rtrim(str_replace("\\", "/", $filePath), "/") . "/";

		$this->buildPhar($sender, $pharPath, $filePath, ['src', 'vendor'], $metadata, $stub, \Phar::SHA1);

		$sender->sendMessage($server->getName() . " " . $server->getPocketMineVersion() . " Phar file has been created on " . $pharPath);

		return true;
	}

	private function preg_quote_array(array $strings, string $delim = null) : array{
		return array_map(function(string $str) use ($delim) : string{ return preg_quote($str, $delim); }, $strings);
	}

	private function buildPhar(CommandSender $sender, string $pharPath, string $basePath, array $includedPaths, array $metadata, string $stub, int $signatureAlgo = \Phar::SHA1){
		if(file_exists($pharPath)){
			$sender->sendMessage("Phar file already exists, overwriting...");
			\Phar::unlinkArchive($pharPath);
		}

		$sender->sendMessage("[DevTools] Adding files...");

		$start = microtime(true);
		$phar = new \Phar($pharPath);
		$phar->setMetadata($metadata);
		$phar->setStub($stub);
		$phar->setSignatureAlgorithm($signatureAlgo);
		$phar->startBuffering();

		//If paths contain any of these, they will be excluded
		$excludedSubstrings = [
			"/.", //"Hidden" files, git information etc
			realpath($pharPath) //don't add the phar to itself
		];

		$regex = sprintf('/^(?!.*(%s))^%s(%s).*/i',
			implode('|', $this->preg_quote_array($excludedSubstrings, '/')), //String may not contain any of these substrings
			preg_quote($basePath, '/'), //String must start with this path...
			implode('|', $this->preg_quote_array($includedPaths, '/')) //... and must be followed by one of these relative paths, if any were specified. If none, this will produce a null capturing group which will allow anything.
		);

		$count = count($phar->buildFromDirectory($basePath, $regex));
		$sender->sendMessage("[DevTools] Added $count files");

		$sender->sendMessage("[DevTools] Checking for compressible files...");
		foreach($phar as $file => $finfo){
			/** @var \PharFileInfo $finfo */
			if($finfo->getSize() > (1024 * 512)){
				$sender->sendMessage("[DevTools] Compressing " . $finfo->getFilename());
				$finfo->compress(\Phar::GZ);
			}
		}
		$phar->stopBuffering();

		$sender->sendMessage("[DevTools] Done in " . round(microtime(true) - $start, 3) . "s");
	}

}