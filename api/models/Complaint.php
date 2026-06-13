<?php

declare(strict_types=1);

/**
 * Complaints (client DB).
 */
final class Complaint
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(PDO $clientPdo, string $pmsUnitId, int $tenantId, string $subject, string $message): int
    {
        $stmt = $clientPdo->prepare(
            'INSERT INTO complaints (pms_unit_id, tenant_id, subject, message, status)
             VALUES (:uid, :tid, :subject, :message, "open")'
        );
        $stmt->execute([
            ':uid'     => $pmsUnitId,
            ':tid'     => $tenantId,
            ':subject' => $subject,
            ':message' => $message,
        ]);
        return (int) $clientPdo->lastInsertId();
    }

    /**
     * Admin list with tenant names + optional filters.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForAdmin(PDO $clientPdo, ?string $status, ?string $pmsUnitId): array
    {
        $where = [];
        $params = [];
        if ($status !== null) {
            $where[] = 'c.status = :status';
            $params[':status'] = $status;
        }
        if ($pmsUnitId !== null) {
            $where[] = 'c.pms_unit_id = :uid';
            $params[':uid'] = $pmsUnitId;
        }
        $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

        $sql = "SELECT c.id, c.pms_unit_id, t.full_name AS tenant_name, c.subject, c.message,
                       c.status, c.admin_reply, c.created_at, c.updated_at
                FROM complaints c
                LEFT JOIN tenants t ON t.id = c.tenant_id
                {$whereSql}
                ORDER BY c.id DESC";
        $stmt = $clientPdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForTenant(PDO $clientPdo, int $tenantId): array
    {
        $stmt = $clientPdo->prepare(
            'SELECT id, subject, message, status, admin_reply, created_at, updated_at
             FROM complaints WHERE tenant_id = :tid ORDER BY id DESC'
        );
        $stmt->execute([':tid' => $tenantId]);
        return $stmt->fetchAll();
    }

    public function reply(PDO $clientPdo, int $complaintId, string $reply, string $status): void
    {
        $stmt = $clientPdo->prepare(
            'UPDATE complaints SET admin_reply = :reply, status = :status WHERE id = :id'
        );
        $stmt->execute([':reply' => $reply, ':status' => $status, ':id' => $complaintId]);
    }

    public function countOpen(PDO $clientPdo): int
    {
        return (int) $clientPdo->query(
            'SELECT COUNT(*) FROM complaints WHERE status IN ("open","in_progress")'
        )->fetchColumn();
    }
}
