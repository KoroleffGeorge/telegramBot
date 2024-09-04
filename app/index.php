<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/config.php';

use App\Db\Database;
use App\TelegramBot;

$database = new Database(DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD);
$pdo = $database->getPdo();

$bot = new TelegramBot(BOT_TOKEN, BOT_NAME, $pdo);
$bot->run();