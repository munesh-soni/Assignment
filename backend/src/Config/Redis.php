<?php

namespace App\Config;

use Redis;
use RedisException;

class Redis
{
    private static $instance = null;
    private $connection;

    private function __construct()
    {
        try {
            $this->connection = new Redis();
            $this->connection->connect(
                $_ENV['REDIS_HOST'],
                $_ENV['REDIS_PORT']
            );

            if (!empty($_ENV['REDIS_PASSWORD'])) {
                $this->connection->auth($_ENV['REDIS_PASSWORD']);
            }
        } catch (RedisException $e) {
            throw new RedisException("Redis connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): Redis
    {
        return $this->connection;
    }
} 