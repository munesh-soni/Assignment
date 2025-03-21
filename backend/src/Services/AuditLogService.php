<?php

namespace App\Services;

use PDO;

class AuditLogService
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function log(array $data): void
    {
        $sql = "INSERT INTO audit_logs (
            user_id,
            application_id,
            action,
            resource,
            ip_address,
            user_agent,
            status,
            details
        ) VALUES (
            :user_id,
            :application_id,
            :action,
            :resource,
            :ip_address,
            :user_agent,
            :status,
            :details
        )";

        $stmt = $this->db->prepare($sql);
        
        // Ensure sensitive data is not logged
        $sanitizedDetails = $this->sanitizeDetails($data['details'] ?? []);

        $stmt->execute([
            'user_id' => $data['user_id'] ?? null,
            'application_id' => $data['application_id'],
            'action' => $data['action'],
            'resource' => $data['resource'],
            'ip_address' => $data['ip_address'],
            'user_agent' => $data['user_agent'] ?? null,
            'status' => $data['status'],
            'details' => json_encode($sanitizedDetails)
        ]);
    }

    private function sanitizeDetails(array $details): array
    {
        // List of fields to remove from logs
        $sensitiveFields = [
            'password',
            'token',
            'refresh_token',
            'api_key',
            'secret',
            'credit_card',
            'ssn'
        ];

        return array_filter($details, function($key) use ($sensitiveFields) {
            return !in_array(strtolower($key), $sensitiveFields);
        }, ARRAY_FILTER_USE_KEY);
    }

    public function getAuditLogs(
        int $applicationId,
        ?int $userId = null,
        ?string $action = null,
        ?string $startDate = null,
        ?string $endDate = null,
        int $page = 1,
        int $limit = 50
    ): array {
        $conditions = ['application_id = :application_id'];
        $params = ['application_id' => $applicationId];

        if ($userId) {
            $conditions[] = 'user_id = :user_id';
            $params['user_id'] = $userId;
        }

        if ($action) {
            $conditions[] = 'action = :action';
            $params['action'] = $action;
        }

        if ($startDate) {
            $conditions[] = 'created_at >= :start_date';
            $params['start_date'] = $startDate;
        }

        if ($endDate) {
            $conditions[] = 'created_at <= :end_date';
            $params['end_date'] = $endDate;
        }

        $offset = ($page - 1) * $limit;
        
        $sql = "SELECT * FROM audit_logs 
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} 