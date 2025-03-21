<?php

namespace App\Services;

use PDO;
use App\Config\Database;
use App\Services\AuditLogService;

class ApplicationService
{
    private $db;
    private $auditLogService;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->auditLogService = new AuditLogService();
    }

    public function createApplication(array $applicationData): array
    {
        $this->validateApplicationData($applicationData);

        $apiKey = bin2hex(random_bytes(32));
        
        $stmt = $this->db->prepare('
            INSERT INTO applications (name, description, api_key, created_at, updated_at)
            VALUES (:name, :description, :api_key, NOW(), NOW())
        ');

        $stmt->execute([
            'name' => $applicationData['name'],
            'description' => $applicationData['description'],
            'api_key' => $apiKey
        ]);

        $applicationId = $this->db->lastInsertId();
        $application = $this->getApplicationById($applicationId);

        $this->auditLogService->log(
            $applicationId,
            null,
            'application_created',
            ['name' => $applicationData['name']]
        );

        return $application;
    }

    public function updateApplication(int $applicationId, array $applicationData): array
    {
        $this->validateApplicationData($applicationData, true);

        $updates = [];
        $params = ['application_id' => $applicationId];

        if (isset($applicationData['name'])) {
            $updates[] = 'name = :name';
            $params['name'] = $applicationData['name'];
        }

        if (isset($applicationData['description'])) {
            $updates[] = 'description = :description';
            $params['description'] = $applicationData['description'];
        }

        if (empty($updates)) {
            return $this->getApplicationById($applicationId);
        }

        $updates[] = 'updated_at = NOW()';
        $sql = 'UPDATE applications SET ' . implode(', ', $updates) . ' WHERE id = :application_id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $application = $this->getApplicationById($applicationId);

        $this->auditLogService->log(
            $applicationId,
            null,
            'application_updated',
            ['name' => $applicationData['name'] ?? $application['name']]
        );

        return $application;
    }

    public function deleteApplication(int $applicationId): bool
    {
        $application = $this->getApplicationById($applicationId);
        if (!$application) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM applications WHERE id = :application_id');
        $result = $stmt->execute(['application_id' => $applicationId]);

        if ($result) {
            $this->auditLogService->log(
                $applicationId,
                null,
                'application_deleted',
                ['name' => $application['name']]
            );
        }

        return $result;
    }

    public function getApplicationById(int $applicationId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, name, description, created_at, updated_at 
            FROM applications 
            WHERE id = :application_id
        ');
        
        $stmt->execute(['application_id' => $applicationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getApplicationByApiKey(string $apiKey): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, name, description, created_at, updated_at 
            FROM applications 
            WHERE api_key = :api_key
        ');
        
        $stmt->execute(['api_key' => $apiKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function regenerateApiKey(int $applicationId): array
    {
        $application = $this->getApplicationById($applicationId);
        if (!$application) {
            throw new \Exception('Application not found');
        }

        $newApiKey = bin2hex(random_bytes(32));
        
        $stmt = $this->db->prepare('
            UPDATE applications 
            SET api_key = :api_key, updated_at = NOW() 
            WHERE id = :application_id
        ');
        
        $stmt->execute([
            'application_id' => $applicationId,
            'api_key' => $newApiKey
        ]);

        $application = $this->getApplicationById($applicationId);

        $this->auditLogService->log(
            $applicationId,
            null,
            'api_key_regenerated',
            ['name' => $application['name']]
        );

        return $application;
    }

    private function validateApplicationData(array $applicationData, bool $isUpdate = false): void
    {
        if (!$isUpdate) {
            if (empty($applicationData['name'])) {
                throw new \Exception('Application name is required');
            }
        }

        if (isset($applicationData['name'])) {
            if (strlen($applicationData['name']) < 3 || strlen($applicationData['name']) > 100) {
                throw new \Exception('Application name must be between 3 and 100 characters');
            }
        }

        if (isset($applicationData['description'])) {
            if (strlen($applicationData['description']) > 500) {
                throw new \Exception('Description cannot exceed 500 characters');
            }
        }
    }
} 