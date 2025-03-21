<?php

namespace App\Services;

use PDO;
use App\Config\Database;
use App\Config\Redis;
use App\Services\AuditLogService;

class PermissionService
{
    private $db;
    private $redis;
    private $auditLogService;
    private $cacheExpiry = 3600; // 1 hour

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->redis = Redis::getInstance()->getConnection();
        $this->auditLogService = new AuditLogService();
    }

    public function createPermission(array $permissionData, int $applicationId): array
    {
        $this->validatePermissionData($permissionData);

        $stmt = $this->db->prepare('
            INSERT INTO permissions (name, description, resource, action, application_id, created_at, updated_at)
            VALUES (:name, :description, :resource, :action, :application_id, NOW(), NOW())
        ');

        $stmt->execute([
            'name' => $permissionData['name'],
            'description' => $permissionData['description'],
            'resource' => $permissionData['resource'],
            'action' => $permissionData['action'],
            'application_id' => $applicationId
        ]);

        $permissionId = $this->db->lastInsertId();
        $permission = $this->getPermissionById($permissionId);

        $this->auditLogService->log(
            $applicationId,
            null,
            'permission_created',
            ['name' => $permissionData['name']]
        );

        return $permission;
    }

    public function updatePermission(int $permissionId, array $permissionData, int $applicationId): array
    {
        $this->validatePermissionData($permissionData, true);

        $updates = [];
        $params = ['permission_id' => $permissionId];

        if (isset($permissionData['name'])) {
            $updates[] = 'name = :name';
            $params['name'] = $permissionData['name'];
        }

        if (isset($permissionData['description'])) {
            $updates[] = 'description = :description';
            $params['description'] = $permissionData['description'];
        }

        if (isset($permissionData['resource'])) {
            $updates[] = 'resource = :resource';
            $params['resource'] = $permissionData['resource'];
        }

        if (isset($permissionData['action'])) {
            $updates[] = 'action = :action';
            $params['action'] = $permissionData['action'];
        }

        if (empty($updates)) {
            return $this->getPermissionById($permissionId);
        }

        $updates[] = 'updated_at = NOW()';
        $sql = 'UPDATE permissions SET ' . implode(', ', $updates) . ' WHERE id = :permission_id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $permission = $this->getPermissionById($permissionId);

        $this->auditLogService->log(
            $applicationId,
            null,
            'permission_updated',
            ['name' => $permissionData['name'] ?? $permission['name']]
        );

        return $permission;
    }

    public function deletePermission(int $permissionId, int $applicationId): bool
    {
        $permission = $this->getPermissionById($permissionId);
        if (!$permission) {
            return false;
        }

        // Check if permission is assigned to any roles
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count 
            FROM role_permissions 
            WHERE permission_id = :permission_id
        ');
        $stmt->execute(['permission_id' => $permissionId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            throw new \Exception('Cannot delete permission that is assigned to roles');
        }

        $stmt = $this->db->prepare('DELETE FROM permissions WHERE id = :permission_id');
        $result = $stmt->execute(['permission_id' => $permissionId]);

        if ($result) {
            $this->auditLogService->log(
                $applicationId,
                null,
                'permission_deleted',
                ['name' => $permission['name']]
            );
        }

        return $result;
    }

    public function getPermissionById(int $permissionId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, name, description, resource, action, application_id, created_at, updated_at 
            FROM permissions 
            WHERE id = :permission_id
        ');
        
        $stmt->execute(['permission_id' => $permissionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getPermissionsByApplication(int $applicationId): array
    {
        $stmt = $this->db->prepare('
            SELECT id, name, description, resource, action, created_at, updated_at 
            FROM permissions 
            WHERE application_id = :application_id
            ORDER BY resource, action ASC
        ');
        
        $stmt->execute(['application_id' => $applicationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function assignPermissionToRole(int $permissionId, int $roleId, int $applicationId): bool
    {
        $permission = $this->getPermissionById($permissionId);
        if (!$permission || $permission['application_id'] !== $applicationId) {
            throw new \Exception('Invalid permission');
        }

        // Check if permission is already assigned
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count 
            FROM role_permissions 
            WHERE permission_id = :permission_id AND role_id = :role_id
        ');
        $stmt->execute([
            'permission_id' => $permissionId,
            'role_id' => $roleId
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            return true; // Permission is already assigned
        }

        $stmt = $this->db->prepare('
            INSERT INTO role_permissions (permission_id, role_id, created_at)
            VALUES (:permission_id, :role_id, NOW())
        ');
        
        $result = $stmt->execute([
            'permission_id' => $permissionId,
            'role_id' => $roleId
        ]);

        if ($result) {
            $this->auditLogService->log(
                $applicationId,
                null,
                'permission_assigned',
                ['permission_name' => $permission['name']]
            );
            $this->clearRolePermissionCache($roleId);
        }

        return $result;
    }

    public function removePermissionFromRole(int $permissionId, int $roleId, int $applicationId): bool
    {
        $permission = $this->getPermissionById($permissionId);
        if (!$permission || $permission['application_id'] !== $applicationId) {
            throw new \Exception('Invalid permission');
        }

        $stmt = $this->db->prepare('
            DELETE FROM role_permissions 
            WHERE permission_id = :permission_id AND role_id = :role_id
        ');
        
        $result = $stmt->execute([
            'permission_id' => $permissionId,
            'role_id' => $roleId
        ]);

        if ($result) {
            $this->auditLogService->log(
                $applicationId,
                null,
                'permission_removed',
                ['permission_name' => $permission['name']]
            );
            $this->clearRolePermissionCache($roleId);
        }

        return $result;
    }

    public function getRolePermissions(int $roleId, int $applicationId): array
    {
        $cacheKey = "role_permissions:{$roleId}:{$applicationId}";
        $cached = $this->redis->get($cacheKey);

        if ($cached !== false) {
            return json_decode($cached, true);
        }

        $stmt = $this->db->prepare('
            SELECT p.id, p.name, p.description, p.resource, p.action 
            FROM permissions p
            JOIN role_permissions rp ON rp.permission_id = p.id
            WHERE rp.role_id = :role_id AND p.application_id = :application_id
            ORDER BY p.resource, p.action ASC
        ');
        
        $stmt->execute([
            'role_id' => $roleId,
            'application_id' => $applicationId
        ]);
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->redis->setex($cacheKey, $this->cacheExpiry, json_encode($permissions));

        return $permissions;
    }

    public function hasPermission(int $userId, string $resource, string $action, int $applicationId): bool
    {
        $cacheKey = "user_permission:{$userId}:{$resource}:{$action}:{$applicationId}";
        $cached = $this->redis->get($cacheKey);

        if ($cached !== false) {
            return (bool)$cached;
        }

        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count
            FROM permissions p
            JOIN role_permissions rp ON rp.permission_id = p.id
            JOIN user_roles ur ON ur.role_id = rp.role_id
            WHERE ur.user_id = :user_id 
            AND p.resource = :resource 
            AND p.action = :action
            AND p.application_id = :application_id
        ');
        
        $stmt->execute([
            'user_id' => $userId,
            'resource' => $resource,
            'action' => $action,
            'application_id' => $applicationId
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $hasPermission = $result['count'] > 0;

        $this->redis->setex($cacheKey, $this->cacheExpiry, (int)$hasPermission);

        return $hasPermission;
    }

    private function clearRolePermissionCache(int $roleId): void
    {
        $pattern = "role_permissions:{$roleId}:*";
        $keys = $this->redis->keys($pattern);
        
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
    }

    private function validatePermissionData(array $permissionData, bool $isUpdate = false): void
    {
        if (!$isUpdate) {
            if (empty($permissionData['name']) || empty($permissionData['resource']) || empty($permissionData['action'])) {
                throw new \Exception('Missing required fields');
            }
        }

        if (isset($permissionData['name'])) {
            if (strlen($permissionData['name']) < 3 || strlen($permissionData['name']) > 50) {
                throw new \Exception('Permission name must be between 3 and 50 characters');
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $permissionData['name'])) {
                throw new \Exception('Permission name can only contain letters, numbers, and underscores');
            }
        }

        if (isset($permissionData['description'])) {
            if (strlen($permissionData['description']) > 200) {
                throw new \Exception('Description cannot exceed 200 characters');
            }
        }

        if (isset($permissionData['resource'])) {
            if (strlen($permissionData['resource']) < 3 || strlen($permissionData['resource']) > 50) {
                throw new \Exception('Resource must be between 3 and 50 characters');
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $permissionData['resource'])) {
                throw new \Exception('Resource can only contain letters, numbers, and underscores');
            }
        }

        if (isset($permissionData['action'])) {
            if (strlen($permissionData['action']) < 3 || strlen($permissionData['action']) > 50) {
                throw new \Exception('Action must be between 3 and 50 characters');
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $permissionData['action'])) {
                throw new \Exception('Action can only contain letters, numbers, and underscores');
            }
        }
    }
} 