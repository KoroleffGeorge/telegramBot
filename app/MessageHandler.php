<?php

namespace App;

use Longman\TelegramBot\Request;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class MessageHandler
{
    private $pdo;
    private $encryptionKey;
    private $logger;

    public function __construct($pdo, $encryptionKey, $logFile = 'history.log')
    {
        $this->pdo = $pdo;
        $this->encryptionKey = $encryptionKey;
        $this->logger = new Logger('telegram_bot');
        $this->logger->pushHandler(new StreamHandler($logFile, Logger::INFO));
    }

    public function handleMessage($message)
    {
        $telegramId = $message->getFrom()->getId();
        $text = $message->getText();

        if ($this->isUserExists($telegramId)) {
            $this->processUserMessage($telegramId, $text);
        } else {
            $this->createNewUser($telegramId);
        }
    }

    private function isUserExists($telegramId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE telegram_id = :telegram_id");
        $stmt->execute(['telegram_id' => $telegramId]);
        return $stmt->fetch() !== false;
    }

    private function createNewUser($telegramId)
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("INSERT INTO users (telegram_id, balance) VALUES (:telegram_id, :balance)");
            $stmt->execute(['telegram_id' => $telegramId, 'balance' => $this->encryptData('0.00')]);

            $stmt = $this->pdo->prepare("INSERT INTO operations (amount, user_id) VALUES (:amount, :telegram_id)");
            $stmt->execute(['amount' => $this->encryptData('0.00'), 'telegram_id' => $telegramId]);

            $this->pdo->commit();

            Request::sendMessage([
                'chat_id' => $telegramId,
                'text' => 'Добро пожаловать! Ваш баланс: 0.00 USD',
            ]);

            $this->logger->info("User ID: $telegramId, Operation: create_user, Message: User created with balance 0.00 USD");
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error("User ID: $telegramId, Operation: create_user_error, Message: " . $e->getMessage());
            Request::sendMessage([
                'chat_id' => $telegramId,
                'text' => 'Произошла ошибка при создании пользователя. Пожалуйста, попробуйте позже.',
            ]);
        }
    }

    private function processUserMessage($telegramId, $text)
    {
        if ($this->isNumericInput($text)) {
            $amount = $this->parseAmount($text);
            if ($amount > 0) {
                $this->addBalance($telegramId, $amount);
            } else if ($amount < 0) {
                $this->subtractBalance($telegramId, abs($amount));
            } else {
                $balance = $this->getBalance($telegramId);
                Request::sendMessage([
                    'chat_id' => $telegramId,
                    'text' => "Текущий баланс: $balance USD",
                ]);
            }
        } else {
            $this->sendInvalidInputMessage($telegramId);
        }
    }

    private function isNumericInput($text)
    {
        return is_numeric(str_replace(',', '.', $text));
    }

    private function parseAmount($text)
    {
        return floatval(str_replace(',', '.', $text));
    }

    private function addBalance($telegramId, $amount)
    {
        $this->pdo->beginTransaction();
        try {
            $currentBalance = $this->getBalance($telegramId);

            $stmt = $this->pdo->prepare("UPDATE users SET balance = :amount WHERE telegram_id = :telegram_id");
            $stmt->execute(['amount' => $this->encryptData($currentBalance + $amount), 'telegram_id' => $telegramId]);

            $stmt = $this->pdo->prepare("INSERT INTO operations (amount, user_id) VALUES (:amount, :telegram_id)");
            $stmt->execute(['amount' => $this->encryptData($amount), 'telegram_id' => $telegramId]);

            $balance = $this->getBalance($telegramId);
            $this->pdo->commit();

            Request::sendMessage([
                'chat_id' => $telegramId,
                'text' => "Баланс пополнен на $amount USD. Текущий баланс: $balance USD.",
            ]);

            $this->logger->info("User ID: $telegramId, Operation: add_balance, Message: Added $amount USD. New balance: $balance USD");
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error("User ID: $telegramId, Operation: add_balance_error, Message: " . $e->getMessage());
            Request::sendMessage([
                'chat_id' => $telegramId,
                'text' => 'Произошла ошибка при обновлении баланса. Пожалуйста, попробуйте позже.',
            ]);
        }
    }

    private function subtractBalance($telegramId, $amount)
    {
        $currentBalance = $this->getBalance($telegramId);
        if ($currentBalance >= $amount) {
            $this->pdo->beginTransaction();
            try {
                $currentBalance = $this->getBalance($telegramId);

                $stmt = $this->pdo->prepare("UPDATE users SET balance = :amount WHERE telegram_id = :telegram_id");
                $stmt->execute(['amount' => $this->encryptData($currentBalance - $amount), 'telegram_id' => $telegramId]);

                $stmt = $this->pdo->prepare("INSERT INTO operations (amount, user_id) VALUES (:amount, :telegram_id)");
                $stmt->execute(['amount' => $this->encryptData(-$amount), 'telegram_id' => $telegramId]);

                $balance = $this->getBalance($telegramId);
                $this->pdo->commit();

                Request::sendMessage([
                    'chat_id' => $telegramId,
                    'text' => "Списано $amount USD. Текущий баланс: $balance USD.",
                ]);

                $this->logger->info("User ID: $telegramId, Operation: subtract_balance, Message: Subtracted $amount USD. New balance: $balance USD");
            } catch (\Exception $e) {
                $this->pdo->rollBack();
                $this->logger->error("User ID: $telegramId, Operation: subtract_balance_error, Message: " . $e->getMessage());
                Request::sendMessage([
                    'chat_id' => $telegramId,
                    'text' => 'Произошла ошибка при обновлении баланса. Пожалуйста, попробуйте позже.',
                ]);
            }
        } else {
            $this->sendInsufficientFundsMessage($telegramId);
            $this->logger->error("User ID: $telegramId, Operation: insufficient_funds, Message: Insufficient funds for operation");
        }
    }

    private function getBalance($telegramId)
    {
        $stmt = $this->pdo->prepare("SELECT balance FROM users WHERE telegram_id = :telegram_id");
        $stmt->execute(['telegram_id' => $telegramId]);
        $encryptedBalance = $stmt->fetchColumn();
        return $this->decryptData($encryptedBalance);
    }

    private function sendInvalidInputMessage($telegramId)
    {
        Request::sendMessage([
            'chat_id' => $telegramId,
            'text' => 'Некорректный ввод! Введите числовое значение!',
        ]);
    }

    private function sendInsufficientFundsMessage($telegramId)
    {
        Request::sendMessage([
            'chat_id' => $telegramId,
            'text' => "Недостаточно средств на счете!",
        ]);
    }

    private function encryptData($data)
    {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $this->encryptionKey, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }

    private function decryptData($data)
    {
        list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
        return openssl_decrypt($encrypted_data, 'aes-256-cbc', $this->encryptionKey, 0, $iv);
    }
}