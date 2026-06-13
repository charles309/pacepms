<?php

declare(strict_types=1);

/**
 * Global blacklist of defaulting tenants (global DB).
 */
final class Blacklist
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Look up an active (unresolved) blacklist entry by national id.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByNationalId(string $nationalId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM global_blacklist
             WHERE tenant_national_id = :nid AND resolved = 0
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':nid' => $nationalId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function add(
        string $nationalId,
        string $fullName,
        string $phone,
        string $email,
        string $lastUnitId,
        int $lastClientId,
        float $amountOwed,
        string $vacateReport
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO global_blacklist
                (tenant_national_id, full_name, phone, email, last_pms_unit_id,
                 last_client_id, amount_owed, vacate_report, resolved)
             VALUES (:nid, :name, :phone, :email, :uid, :cid, :owed, :report, 0)'
        );
        $stmt->execute([
            ':nid'    => $nationalId,
            ':name'   => $fullName,
            ':phone'  => $phone,
            ':email'  => $email,
            ':uid'    => $lastUnitId,
            ':cid'    => $lastClientId,
            ':owed'   => round($amountOwed, 2),
            ':report' => $vacateReport,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->query(
            'SELECT id, tenant_national_id, full_name, phone, email, last_pms_unit_id,
                    last_client_id, amount_owed, vacate_report, resolved, flagged_at
             FROM global_blacklist ORDER BY id DESC'
        )->fetchAll();
    }

    public function countActive(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM global_blacklist WHERE resolved = 0')
            ->fetchColumn();
    }

    /**
     * Mark a blacklist entry resolved. Returns true if a row changed.
     */
    public function resolve(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE global_blacklist SET resolved = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
