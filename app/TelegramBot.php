<?php

namespace App;

use Longman\TelegramBot\Telegram;

class TelegramBot
{
    private $client;
    private $messageHandler;

    public function __construct($token, $botName, $pdo)
    {
        $this->client = new Telegram($token, $botName);
        $this->client->useGetUpdatesWithoutDatabase();
        $this->messageHandler = new MessageHandler($pdo, 'jopa');
    }

    public function run()
    {
        while (true) {
            try {
                $serverResponse = $this->client->handleGetUpdates();

                if ($serverResponse->isOk()) {
                    $result = $serverResponse->getResult();

                    foreach ($result as $messageItem) {
                        $message = $messageItem->getMessage();
                        $this->messageHandler->handleMessage($message);
                    }
                }
            } catch (\Exception $e) {
                echo $e->getMessage();
            }
            sleep(1);
        }
    }
}