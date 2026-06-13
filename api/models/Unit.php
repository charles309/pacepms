<?php

declare(strict_types=1);

/**
 * Unit registry. Global rows in pms_global.global_units; local rows in the
 * client's units table.
 */
final class Unit
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function createGlobal(
        string $pmsUnitId,
        string $pmsPropertyId,
        int $clientId
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO global_units (pms_unit_id, pms_property_id, client_id)
             VALUES (:uid, :pid, :cid)'
        );
        $stmt->execute([
            ':uid' => $pmsUnitId,
            ':pid' => $pmsPropertyId,
            ':cid' => $clientId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function createLocal(
        PDO $clientPdo,
        string $pmsUnitId,
        string $pmsPropertyId,
        string $unitNumber,
        string $floor,
        float $rentAmount
    ): int {
        $stmt = $clientPdo->prepare(
            'INSERT INTO units (pms_unit_id, pms_property_id, unit_number, floor, rent_amount, status)
             VALUES (:uid, :pid, :num, :floor, :rent, "vacant")'
        );
        $stmt->execute([
            ':uid'   => $pmsUnitId,
            ':pid'   => $pmsPropertyId,
            ':num'   => $unitNumber,
            ':floor' => $floor,
            ':rent'  => round($rentAmount, 2),
        ]);
        return (int) $clientPdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLocal(PDO $clientPdo, string $pmsUnitId): ?array
    {
        $stmt = $clientPdo->prepare('SELECT * FROM units WHERE pms_unit_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $pmsUnitId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Units of a property, with current tenant names.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByProperty(PDO $clientPdo, string $pmsPropertyId): array
    {
        $sql = 'SELECT u.pms_unit_id, u.unit_number, u.floor, u.rent_amount, u.status,
                       t.full_name AS tenant_name
                FROM units u
                LEFT JOIN tenants t ON t.pms_unit_id = u.pms_unit_id AND t.is_active = 1
                WHERE u.pms_property_id = :pid
                ORDER BY u.unit_number';
        $stmt = $clientPdo->prepare($sql);
        $stmt->execute([':pid' => $pmsPropertyId]);
        return $stmt->fetchAll();
    }

    public function setStatus(PDO $clientPdo, string $pmsUnitId, string $status): void
    {
        $stmt = $clientPdo->prepare('UPDATE units SET status = :status WHERE pms_unit_id = :uid');
        $stmt->execute([':status' => $status, ':uid' => $pmsUnitId]);
    }

    /**
     * Dashboard occupancy counters.
     *
     * @return array{total:int, occupied:int, vacant:int}
     */
    public function occupancyStats(PDO $clientPdo): array
    {
        $row = $clientPdo->query(
            'SELECT COUNT(*) AS total,
                    SUM(status = "occupied") AS occupied,
                    SUM(status = "vacant") AS vacant
             FROM units'
        )->fetch();

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'occupied' => (int) ($row['occupied'] ?? 0),
            'vacant'   => (int) ($row['vacant'] ?? 0),
        ];
    }
}
