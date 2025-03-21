<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

class JWTService
{
    private $secretKey;
    private $algorithm;
    private $issuer;
    private $audience;
    private $expiration;
    private $refreshExpiration;

    public function __construct()
    {
        $this->secretKey = $_ENV['JWT_SECRET_KEY'];
        $this->algorithm = 'HS256';
        $this->issuer = $_ENV['APP_URL'];
        $this->audience = $_ENV['APP_URL'];
        $this->expiration = (int)$_ENV['JWT_EXPIRATION'];
        $this->refreshExpiration = (int)$_ENV['JWT_REFRESH_EXPIRATION'];
    }

    public function generateTokens(array $user, int $applicationId): array
    {
        $issuedAt = time();
        $accessExpire = $issuedAt + $this->expiration;
        $refreshExpire = $issuedAt + $this->refreshExpiration;

        $accessToken = $this->generateToken($user, $applicationId, $issuedAt, $accessExpire);
        $refreshToken = $this->generateToken($user, $applicationId, $issuedAt, $refreshExpire, true);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->expiration,
            'token_type' => 'Bearer'
        ];
    }

    private function generateToken(array $user, int $applicationId, int $issuedAt, int $expire, bool $isRefresh = false): string
    {
        $payload = [
            'iat' => $issuedAt,
            'exp' => $expire,
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email']
            ],
            'application_id' => $applicationId,
            'is_refresh' => $isRefresh
        ];

        return JWT::encode($payload, $this->secretKey, $this->algorithm);
    }

    public function validateToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, $this->algorithm));
            return (array)$decoded;
        } catch (ExpiredException $e) {
            throw new \Exception('Token has expired');
        } catch (SignatureInvalidException $e) {
            throw new \Exception('Invalid token signature');
        } catch (\Exception $e) {
            throw new \Exception('Invalid token');
        }
    }

    public function isRefreshToken(array $decoded): bool
    {
        return isset($decoded['is_refresh']) && $decoded['is_refresh'] === true;
    }

    public function getUserId(array $decoded): int
    {
        return $decoded['user']['id'];
    }

    public function getApplicationId(array $decoded): int
    {
        return $decoded['application_id'];
    }
} 