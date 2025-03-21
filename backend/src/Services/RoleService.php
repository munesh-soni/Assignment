<?php

namespace App\Services;

use PDO;
use App\Config\Database;
use App\Services\AuditLogService;

class RoleService
{
    private $db;
    private $auditLogService;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->auditLogService = new AuditLogService();
    }

    public function createRole(array $roleData, int $applicationId): array
    {
        $this->validateRoleData($roleData);

        $stmt = $this->db->prepare('
            INSERT INTO roles (name, description, application_id, created_at, updated_at)
            VALUES (:name, :description, :application_id, NOW(), NOW())
        ');

        $stmt->execute([
            'name' => $roleData['name'],
            'description' => $roleData['description'],
            'application_id' => $applicationId
        ]);

        $roleId = $this->db->lastInsertId();
        $role = $this->getRoleById($roleId);

        $this->auditLogService->log(
            $applicationId,
            null,
            'role_created',
            ['name' => $roleData['name']]
        );

        return $role;
    }

    public function updateRole(int $roleId, array $roleData, int $applicationId): array
    {
        $this->validateRoleData($roleData, true);

        $updates = [];
        $params = ['role_id' => $roleId];

        if (isset($roleData['name'])) {
            $updates[] = 'name = :name';
            $params['name'] = $roleData['name'];
        }

        if (isset($roleData['description'])) {
            $updates[] = 'description = :description';
            $params['description'] = $roleData['description'];
        }

        if (empty($updates)) {
            return $this->getRoleById($roleId);
        }

        $updates[] = 'updated_at = NOW()';
        $sql = 'UPDATE roles SET ' . implode(', ', $updates) . ' WHERE id = :role_id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $role = $this->getRoleById($roleId);

        $this->auditLogService->log(
            $applicationId,
            null,
            'role_updated',
            ['name' => $roleData['name'] ?? $role['name']]
        );

        return $role;
    }

    public function deleteRole(int $roleId, int $applicationId): bool
    {
        $role = $this->getRoleById($roleId);
        if (!$role) {
            return false;
        }

        // Check if role is assigned to any users
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count 
            FROM user_roles 
            WHERE role_id = :role_id
        ');
        $stmt->execute(['role_id' => $roleId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            throw new \Exception('Cannot delete role that is assigned to users');
        }

        $stmt = $this->db->prepare('DELETE FROM roles WHERE id = :role_id');
        $result = $stmt->execute(['role_id' => $roleId]);

        if ($result) {
            $this->auditLogService->log(
                $applicationId,
                null,
                'role_deleted',
                ['name' => $role['name']]
            );
        }

        return $result;
    }

    public function getRoleById(int $roleId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, name, description, application_id, created_at, updated_at 
            FROM roles 
            WHERE id = :role_id
        ');
        
        $stmt->execute(['role_id' => $roleId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getRolesByApplication(int $applicationId): array
    {
        $stmt = $this->db->prepare('
            SELECT id, name, description, created_at, updated_at 
            FROM roles 
            WHERE application_id = :application_id
            ORDER BY name ASC
        ');
        
        $stmt->execute(['application_id' => $applicationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function assignRoleToUser(int $roleId, int $userId, int $applicationId): bool
    {
        $role = $this->getRoleById($roleId);
        if (!$role || $role['application_id'] !== $applicationId) {
            throw new \Exception('Invalid role');
        }

        // Check if role is already assigned
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count 
            FROM user_roles 
            WHERE role_id = :role_id AND user_id = :user_id
        ');
        $stmt->execute([
            'role_id' => $roleId,
            'user_id' => $userId
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            return true; // Role is already assigned
        }

        $stmt = $this->db->prepare('
            INSERT INTO user_roles (role_id, user_id, created_at)
            VALUES (:role_id, :user_id, NOW())
        ');
        
        $result = $stmt->execute([
            'role_id' => $roleId,
            'user_id' => $userId
        ]);

        if ($result) {
            $this->auditLogService->log(
                $applicationId,
                $userId,
                'role_assigned',
                ['role_name' => $role['name']]
            );
        }

        return $result;
    }

    public function removeRoleFromUser(int $roleId, int $userId, int $applicationId): bool
    {
        $role = $this->getRoleById($roleId);
        if (!$role || $role['application_id'] !== $applicationId) {
            throw new \Exception('Invalid role');
        }

        $stmt = $this->db->prepare('
            DELETE FROM user_roles 
            WHERE role_id = :role_id AND user_id = :user_id
        ');
        
        $result = $stmt->execute([
            'role_id' => $roleId,
            'user_id' => $userId
        ]);

        if ($result) {
            $this->auditLogService->log(
                $applicationId,
                $userId,
                'role_removed',
                ['role_name' => $role['name']]
            );
        }

        return $result;
    }

    public function getUserRoles(int $userId, int $applicationId): array
    {
        $stmt = $this->db->prepare('
            SELECT r.id, r.name, r.description 
            FROM roles r
            JOIN user_roles ur ON ur.role_id = r.id
            WHERE ur.user_id = :user_id AND r.application_id = :application_id
            ORDER BY r.name ASC
        ');
        
        $stmt->execute([
            'user_id' => $userId,
            'application_id' => $applicationId
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function validateRoleData(array $roleData, bool $isUpdate = false): void
    {
        if (!$isUpdate) {
            if (empty($roleData['name'])) {
                throw new \Exception('Role name is required');
            }
        }

        if (isset($roleData['name'])) {
            if (strlen($roleData['name']) < 3 || strlen($roleData['name']) > 50) {
                throw new \Exception('Role name must be between 3 and 50 characters');
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $roleData['name'])) {
                throw new \Exception('Role name can only contain letters, numbers, and underscores');
            }
        }

        if (isset($roleData['description'])) {
            if (strlen($roleData['description']) > 200) {
                throw new \Exception('Description cannot exceed 200 characters');
            }
        }
    }
} 