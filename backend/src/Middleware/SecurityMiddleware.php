<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Redis;

class SecurityMiddleware implements MiddlewareInterface
{
    private $redis;
    private $rateLimit;
    private $rateLimitWindow;

    public function __construct()
    {
        $this->redis = new Redis();
        $this->redis->connect($_ENV['REDIS_HOST'], $_ENV['REDIS_PORT']);
        $this->rateLimit = (int)$_ENV['RATE_LIMIT'] ?? 100;
        $this->rateLimitWindow = (int)$_ENV['RATE_LIMIT_WINDOW'] ?? 3600;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        // Get client IP
        $ip = $this->getClientIp($request);
        
        // Check rate limit
        if (!$this->checkRateLimit($ip)) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode([
                'error' => 'Rate limit exceeded'
            ]));
            return $response
                ->withStatus(429)
                ->withHeader('Content-Type', 'application/json');
        }

        // Add security headers
        $response = $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-XSS-Protection', '1; mode=block')
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->withHeader('Content-Security-Policy', "default-src 'self'");

        return $response;
    }

    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        return $serverParams['HTTP_X_FORWARDED_FOR'] ?? 
               $serverParams['HTTP_CLIENT_IP'] ?? 
               $serverParams['REMOTE_ADDR'] ?? 
               '0.0.0.0';
    }

    private function checkRateLimit(string $ip): bool
    {
        $key = "rate_limit:{$ip}";
        $current = $this->redis->get($key);

        if (!$current) {
            $this->redis->setex($key, $this->rateLimitWindow, 1);
            return true;
        }

        if ($current >= $this->rateLimit) {
            return false;
        }

        $this->redis->incr($key);
        return true;
    }
} 