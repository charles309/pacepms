<?php

declare(strict_types=1);

/**
 * Tenants live in the client's isolated DB.
 */
final class Tenant
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(PDO $clientPdo, string $email): ?array
    {
        $stmt = $clientPdo->prepare('SELECT * FROM tenants WHERE email = :email AND is_active = 1 LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(PDO $clientPdo, int $id): ?array
    {
        $stmt = $clientPdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByUnit(PDO $clientPdo, string $pmsUnitId): ?array
    {
        $stmt = $clientPdo->prepare(
            'SELECT * FROM tenants WHERE pms_unit_id = :uid AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':uid' => $pmsUnitId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNationalId(PDO $clientPdo, string $nationalId): ?array
    {
        $stmt = $clientPdo->prepare('SELECT * FROM tenants WHERE national_id = :nid LIMIT 1');
        $stmt->execute([':nid' => $nationalId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(
        PDO $clientPdo,
        string $pmsUnitId,
        string $nationalId,
        string $fullName,
        string $phone,
        string $email,
        string $moveInDate,
        string $passwordHash
    ): int {
        $stmt = $clientPdo->prepare(
            'INSERT INTO tenants
                (pms_unit_id, national_id, full_name, phone, email, move_in_date, password, is_active)
             VALUES (:uid, :nid, :name, :phone, :email, :move_in, :pass, 1)'
        );
        $stmt->execute([
            ':uid'     => $pmsUnitId,
            ':nid'     => $nationalId,
            ':name'    => $fullName,
            ':phone'   => $phone,
            ':email'   => $email,
            ':move_in' => $moveInDate,
            ':pass'    => $passwordHash,
        ]);
        return (int) $clientPdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(PDO $clientPdo): array
    {
        return $clientPdo->query(
            'SELECT id AS tenant_id, national_id, full_name, phone, email, pms_unit_id, move_in_date
             FROM tenants WHERE is_active = 1 ORDER BY id DESC'
        )->fetchAll();
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(PDO $clientPdo, int $tenantId, array $fields): void
    {
        $allowed = ['full_name', 'phone', 'email'];
        $set = [];
        $params = [':id' => $tenantId];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $set[] = "{$col} = :{$col}";
                $params[":{$col}"] = $fields[$col];
            }
        }
        if ($set === []) {
            return;
        }
        $clientPdo->prepare('UPDATE tenants SET ' . implode(', ', $set) . ' WHERE id = :id')
            ->execute($params);
    }

    /**
     * Move an active tenant into former_tenants and delete from tenants.
     * Caller is responsible for transaction boundaries.
     *
     * @param array<string, mixed> $tenant
     */
    public function archiveToFormer(
        PDO $clientPdo,
        array $tenant,
        string $vacateDate,
        string $vacateReason,
        float $outstanding,
        bool $isDefaulter
    ): void {
        $stmt = $clientPdo->prepare(
            'INSERT INTO former_tenants
                (original_tenant_id, pms_unit_id, national_id, full_name, phone, email,
                 move_in_date, vacate_date, vacate_reason, outstanding_balance, is_defaulter)
             VALUES
                (:otid, :uid, :nid, :name, :phone, :email, :move_in, :vacate, :reason, :bal, :def)'
        );
        $stmt->execute([
            ':otid'    => (int) $tenant['id'],
            ':uid'     => $tenant['pms_unit_id'],
            ':nid'     => $tenant['national_id'],
            ':name'    => $tenant['full_name'],
            ':phone'   => $tenant['phone'],
            ':email'   => $tenant['email'],
            ':move_in' => $tenant['move_in_date'],
            ':vacate'  => $vacateDate,
            ':reason'  => $vacateReason,
            ':bal'     => round($outstanding, 2),
            ':def'     => $isDefaulter ? 1 : 0,
        ]);

        $del = $clientPdo->prepare('DELETE FROM tenants WHERE id = :id');
        $del->execute([':id' => (int) $tenant['id']]);
    }
}
