<?php

namespace App\Db;

use PDO;

class Database
{
    private $pdo;

    public function __construct($host, $port, $dbname, $user, $password)
    {
        $dsn = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $host,
            $port,
            $dbname,
            $user,
            $password
        );
        $this->pdo = new PDO($dsn);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getPdo()
    {
        return $this->pdo;
    }
}