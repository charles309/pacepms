<?php

declare(strict_types=1);

/**
 * Admin -> tenant messages (client DB).
 */
final class Message
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(
        PDO $clientPdo,
        string $pmsUnitId,
        ?int $tenantId,
        string $senderRole,
        string $channel,
        string $subject,
        string $body
    ): int {
        $stmt = $clientPdo->prepare(
            'INSERT INTO messages (pms_unit_id, tenant_id, sender_role, channel, subject, body)
             VALUES (:uid, :tid, :role, :channel, :subject, :body)'
        );
        $stmt->execute([
            ':uid'     => $pmsUnitId,
            ':tid'     => $tenantId,
            ':role'    => $senderRole,
            ':channel' => $channel,
            ':subject' => $subject,
            ':body'    => $body,
        ]);
        return (int) $clientPdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByAdmin(PDO $clientPdo): array
    {
        return $clientPdo->query(
            'SELECT id, pms_unit_id, tenant_id, channel, subject, body, sent_at
             FROM messages ORDER BY id DESC'
        )->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForTenant(PDO $clientPdo, int $tenantId): array
    {
        $stmt = $clientPdo->prepare(
            'SELECT subject, body, channel, sent_at FROM messages WHERE tenant_id = :tid ORDER BY id DESC'
        );
        $stmt->execute([':tid' => $tenantId]);
        return $stmt->fetchAll();
    }
}
