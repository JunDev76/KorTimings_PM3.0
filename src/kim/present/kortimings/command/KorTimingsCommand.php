<?php

/**
 *  ____                           _   _  ___
 * |  _ \ _ __ ___  ___  ___ _ __ | |_| |/ (_)_ __ ___
 * | |_) | '__/ _ \/ __|/ _ \ '_ \| __| ' /| | '_ ` _ \
 * |  __/| | |  __/\__ \  __/ | | | |_| . \| | | | | | |
 * |_|   |_|  \___||___/\___|_| |_|\__|_|\_\_|_| |_| |_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author  PresentKim (debe3721@gmail.com)
 * @link    https://github.com/PresentKim
 * @license https://www.gnu.org/licenses/lgpl-3.0 LGPL-3.0 License
 *
 *   (\ /)
 *  ( . .) ♥
 *  c(")(")
 *
 * @noinspection PhpIllegalPsrClassPathInspection
 * @noinspection PhpDocSignatureInspection
 * @noinspection SpellCheckingInspection
 */

declare(strict_types=1);

namespace kim\present\kortimings\command;

use kim\present\kortimings\utils\RomajaConverter;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\defaults\TimingsCommand;
use pocketmine\lang\TranslationContainer;
use pocketmine\Player;
use pocketmine\scheduler\BulkCurlTask;
use pocketmine\Server;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\InternetException;

use function http_build_query;
use function is_array;
use function json_decode;
use function strtolower;

final class KorTimingsCommand extends TimingsCommand{

    public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
        if(!$this->testPermission($sender))
            return true;

        //If not "paste" mode, pass it to be processed by PMMP
        if(strtolower($args[0] ?? "") !== "paste")
            return parent::execute($sender, $commandLabel, $args);

        $paste = true;
        if($paste){
            $fileTimings = fopen("php://temp", "r+b");
        }else{
            $index = 0;
            $timingFolder = $sender->getServer()->getDataPath() . "timings/";

            if(!file_exists($timingFolder)){
                mkdir($timingFolder, 0777);
            }
            $timings = $timingFolder . "timings.txt";
            while(file_exists($timings)){
                $timings = $timingFolder . "timings" . (++$index) . ".txt";
            }

            $fileTimings = fopen($timings, "a+b");
        }
        TimingsHandler::printTimings($fileTimings);

        if($paste){
            fseek($fileTimings, 0);
            $content = RomajaConverter::convert(stream_get_contents($fileTimings));
            $data = [
                "browser" => $agent = $sender->getServer()->getName() . " " . $sender->getServer()->getPocketMineVersion(),
                "data" => $content
            ];
            fclose($fileTimings);

            $host = $sender->getServer()->getProperty("timings.host", "timings.pmmp.io");

            $sender->getServer()->getAsyncPool()->submitTask(new class($sender, $host, $agent, $data) extends BulkCurlTask{
                /** @var string */
                private $host;

                /**
                 * @param string[] $data
                 *
                 * @phpstan-param array<string, string> $data
                 */
                public function __construct(CommandSender $sender, string $host, string $agent, array $data){
                    parent::__construct([
                        [
                            "page" => "https://$host?upload=true",
                            "extraOpts" => [
                                CURLOPT_HTTPHEADER => [
                                    "User-Agent: $agent",
                                    "Content-Type: application/x-www-form-urlencoded"
                                ],
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => http_build_query($data),
                                CURLOPT_AUTOREFERER => false,
                                CURLOPT_FOLLOWLOCATION => false
                            ]
                        ]
                    ], $sender);
                    $this->host = $host;
                }

                public function onCompletion(Server $server){
                    /** @var CommandSender $sender */
                    $sender = $this->fetchLocal();
                    if($sender instanceof Player and !$sender->isOnline()){ // TODO replace with a more generic API method for checking availability of CommandSender
                        return;
                    }
                    $result = $this->getResult()[0];
                    if($result instanceof InternetException){
                        $server->getLogger()->logException($result);
                        return;
                    }
                    $response = json_decode($result[0], true);
                    if(is_array($response) && isset($response["id"])){
                        Command::broadcastCommandMessage($sender, new TranslationContainer("pocketmine.command.timings.timingsRead", ["https://" . $this->host . "/?id=" . $response["id"]]));
                    }else{
                        Command::broadcastCommandMessage($sender, new TranslationContainer("pocketmine.command.timings.pasteError"));
                    }
                }
            });

            return true;
        }
        return true;
    }
}