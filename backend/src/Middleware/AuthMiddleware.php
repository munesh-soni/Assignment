<?php

namespace App\Middleware;

use App\Services\JWTService;
use App\Services\PermissionService;
use App\Services\AuditLogService;

class AuthMiddleware
{
    private $jwtService;
    private $permissionService;
    private $auditLogService;

    public function __construct()
    {
        $this->jwtService = new JWTService();
        $this->permissionService = new PermissionService();
        $this->auditLogService = new AuditLogService();
    }

    public function handle($request, $next)
    {
        try {
            // Get the Authorization header
            $authHeader = $request->getHeader('Authorization');
            if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                throw new \Exception('No token provided');
            }

            $token = $matches[1];
            $decoded = $this->jwtService->validateToken($token);

            // Check if token is a refresh token
            if ($this->jwtService->isRefreshToken($decoded)) {
                throw new \Exception('Refresh token cannot be used for authorization');
            }

            // Get user ID and application ID from token
            $userId = $this->jwtService->getUserId($decoded);
            $applicationId = $this->jwtService->getApplicationId($decoded);

            // Get the resource and action from the request
            $resource = $request->getResource();
            $action = $request->getAction();

            // Check if user has permission
            $hasPermission = $this->permissionService->hasPermission(
                $userId,
                $resource,
                $action,
                $applicationId
            );

            if (!$hasPermission) {
                $this->auditLogService->log(
                    $applicationId,
                    $userId,
                    'access_denied',
                    [
                        'resource' => $resource,
                        'action' => $action,
                        'reason' => 'insufficient_permissions'
                    ]
                );
                throw new \Exception('Access denied');
            }

            // Log successful access
            $this->auditLogService->log(
                $applicationId,
                $userId,
                'access_granted',
                [
                    'resource' => $resource,
                    'action' => $action
                ]
            );

            // Add user and application information to request
            $request->setUser($decoded['user']);
            $request->setApplicationId($applicationId);

            return $next($request);

        } catch (\Exception $e) {
            $this->auditLogService->log(
                $applicationId ?? null,
                $userId ?? null,
                'auth_error',
                [
                    'error' => $e->getMessage(),
                    'resource' => $request->getResource() ?? null,
                    'action' => $request->getAction() ?? null
                ]
            );

            return response()->json([
                'error' => 'Unauthorized',
                'message' => $e->getMessage()
            ], 401);
        }
    }
} 