<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthController
{
    private $secretKey;
    private $algorithm;
    private $issuer;
    private $audience;

    public function __construct()
    {
        $this->secretKey = $_ENV['JWT_SECRET_KEY'];
        $this->algorithm = 'HS256';
        $this->issuer = $_ENV['APP_URL'];
        $this->audience = $_ENV['APP_URL'];
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        // Here you would validate credentials against database
        // This is a mock implementation
        if ($data['username'] === 'admin' && $data['password'] === 'password') {
            $user = [
                'id' => 1,
                'username' => 'admin',
                'email' => 'admin@example.com',
                'roles' => ['admin', 'user']
            ];

            $token = $this->generateToken($user);
            
            $responseData = [
                'token' => $token,
                'user' => $user
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['error' => 'Invalid credentials']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    public function validate(Request $request, Response $response): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');
        $token = str_replace('Bearer ', '', $authHeader);

        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, $this->algorithm));
            $response->getBody()->write(json_encode($decoded->user));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['error' => 'Invalid token']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
    }

    private function generateToken(array $user): string
    {
        $issuedAt = time();
        $expire = $issuedAt + 3600; // 1 hour

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expire,
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'user' => $user
        ];

        return JWT::encode($payload, $this->secretKey, $this->algorithm);
    }
} 