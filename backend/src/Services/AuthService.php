<?php

namespace App\Services;

use PDO;
use App\Config\Database;
use App\Services\JWTService;
use App\Services\AuditLogService;

class AuthService
{
    private $db;
    private $jwtService;
    private $auditLogService;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->jwtService = new JWTService();
        $this->auditLogService = new AuditLogService();
    }

    public function authenticate(string $username, string $password, int $applicationId): array
    {
        $stmt = $this->db->prepare('
            SELECT u.*, a.api_key 
            FROM users u 
            JOIN applications a ON a.id = :application_id 
            WHERE u.username = :username
        ');
        
        $stmt->execute([
            'username' => $username,
            'application_id' => $applicationId
        ]);
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            $this->auditLogService->log(
                $applicationId,
                null,
                'login_failed',
                ['username' => $username, 'reason' => 'invalid_credentials']
            );
            throw new \Exception('Invalid credentials');
        }

        $tokens = $this->jwtService->generateTokens($user, $applicationId);

        $this->auditLogService->log(
            $applicationId,
            $user['id'],
            'login_success',
            ['username' => $username]
        );

        return [
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email']
            ],
            'tokens' => $tokens
        ];
    }

    public function refreshToken(string $refreshToken, int $applicationId): array
    {
        $decoded = $this->jwtService->validateToken($refreshToken);

        if (!$this->jwtService->isRefreshToken($decoded)) {
            throw new \Exception('Invalid refresh token');
        }

        if ($this->jwtService->getApplicationId($decoded) !== $applicationId) {
            throw new \Exception('Invalid application');
        }

        $userId = $this->jwtService->getUserId($decoded);
        $user = $this->getUserById($userId);

        if (!$user) {
            throw new \Exception('User not found');
        }

        $tokens = $this->jwtService->generateTokens($user, $applicationId);

        $this->auditLogService->log(
            $applicationId,
            $userId,
            'token_refresh',
            ['username' => $user['username']]
        );

        return $tokens;
    }

    public function validateApiKey(string $apiKey, int $applicationId): bool
    {
        $stmt = $this->db->prepare('
            SELECT api_key 
            FROM applications 
            WHERE id = :application_id
        ');
        
        $stmt->execute(['application_id' => $applicationId]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);

        return $application && hash_equals($application['api_key'], $apiKey);
    }

    private function getUserById(int $userId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, username, email 
            FROM users 
            WHERE id = :user_id
        ');
        
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} 