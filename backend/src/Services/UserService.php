<?php

namespace App\Services;

use PDO;
use App\Config\Database;
use App\Services\AuditLogService;

class UserService
{
    private $db;
    private $auditLogService;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->auditLogService = new AuditLogService();
    }

    public function createUser(array $userData, int $applicationId): array
    {
        $this->validateUserData($userData);

        $stmt = $this->db->prepare('
            INSERT INTO users (username, email, password, created_at, updated_at)
            VALUES (:username, :email, :password, NOW(), NOW())
        ');

        $stmt->execute([
            'username' => $userData['username'],
            'email' => $userData['email'],
            'password' => password_hash($userData['password'], PASSWORD_DEFAULT)
        ]);

        $userId = $this->db->lastInsertId();
        $user = $this->getUserById($userId);

        $this->auditLogService->log(
            $applicationId,
            $userId,
            'user_created',
            ['username' => $userData['username'], 'email' => $userData['email']]
        );

        return $user;
    }

    public function updateUser(int $userId, array $userData, int $applicationId): array
    {
        $this->validateUserData($userData, true);

        $updates = [];
        $params = ['user_id' => $userId];

        if (isset($userData['username'])) {
            $updates[] = 'username = :username';
            $params['username'] = $userData['username'];
        }

        if (isset($userData['email'])) {
            $updates[] = 'email = :email';
            $params['email'] = $userData['email'];
        }

        if (isset($userData['password'])) {
            $updates[] = 'password = :password';
            $params['password'] = password_hash($userData['password'], PASSWORD_DEFAULT);
        }

        if (empty($updates)) {
            return $this->getUserById($userId);
        }

        $updates[] = 'updated_at = NOW()';
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :user_id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $user = $this->getUserById($userId);

        $this->auditLogService->log(
            $applicationId,
            $userId,
            'user_updated',
            ['username' => $userData['username'] ?? $user['username']]
        );

        return $user;
    }

    public function deleteUser(int $userId, int $applicationId): bool
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :user_id');
        $result = $stmt->execute(['user_id' => $userId]);

        if ($result) {
            $this->auditLogService->log(
                $applicationId,
                $userId,
                'user_deleted',
                ['username' => $user['username']]
            );
        }

        return $result;
    }

    public function getUserById(int $userId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, username, email, created_at, updated_at 
            FROM users 
            WHERE id = :user_id
        ');
        
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getUserByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, username, email, created_at, updated_at 
            FROM users 
            WHERE username = :username
        ');
        
        $stmt->execute(['username' => $username]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function validateUserData(array $userData, bool $isUpdate = false): void
    {
        if (!$isUpdate) {
            if (empty($userData['username']) || empty($userData['email']) || empty($userData['password'])) {
                throw new \Exception('Missing required fields');
            }
        }

        if (isset($userData['username'])) {
            if (strlen($userData['username']) < 3 || strlen($userData['username']) > 50) {
                throw new \Exception('Username must be between 3 and 50 characters');
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $userData['username'])) {
                throw new \Exception('Username can only contain letters, numbers, and underscores');
            }
        }

        if (isset($userData['email'])) {
            if (!filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Invalid email format');
            }
        }

        if (isset($userData['password'])) {
            if (strlen($userData['password']) < 8) {
                throw new \Exception('Password must be at least 8 characters long');
            }
        }
    }
} 