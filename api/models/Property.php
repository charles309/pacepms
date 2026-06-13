<?php

declare(strict_types=1);

/**
 * Property registry. Global rows live in pms_global.global_properties; local
 * rows live in the client's properties table.
 */
final class Property
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Insert into the global registry.
     */
    public function createGlobal(
        string $pmsPropertyId,
        int $clientId,
        string $dbName,
        string $propertyName,
        string $location,
        string $description
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO global_properties
                (pms_property_id, client_id, db_name, property_name, location, description)
             VALUES (:pid, :cid, :db, :name, :loc, :desc)'
        );
        $stmt->execute([
            ':pid'  => $pmsPropertyId,
            ':cid'  => $clientId,
            ':db'   => $dbName,
            ':name' => $propertyName,
            ':loc'  => $location,
            ':desc' => $description,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert into the client's local properties table.
     */
    public function createLocal(
        PDO $clientPdo,
        string $pmsPropertyId,
        string $name,
        string $address,
        string $description
    ): int {
        $stmt = $clientPdo->prepare(
            'INSERT INTO properties (pms_property_id, name, address, description)
             VALUES (:pid, :name, :addr, :desc)'
        );
        $stmt->execute([
            ':pid'  => $pmsPropertyId,
            ':name' => $name,
            ':addr' => $address,
            ':desc' => $description,
        ]);
        return (int) $clientPdo->lastInsertId();
    }

    /**
     * List local properties with occupancy stats.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLocalWithStats(PDO $clientPdo): array
    {
        $sql = 'SELECT p.pms_property_id, p.name, p.address, p.description,
                       COUNT(u.id) AS total_units,
                       SUM(CASE WHEN u.status = "occupied" THEN 1 ELSE 0 END) AS occupied,
                       SUM(CASE WHEN u.status = "vacant" THEN 1 ELSE 0 END) AS vacant
                FROM properties p
                LEFT JOIN units u ON u.pms_property_id = p.pms_property_id
                GROUP BY p.id, p.pms_property_id, p.name, p.address, p.description
                ORDER BY p.id DESC';
        return $clientPdo->query($sql)->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLocal(PDO $clientPdo, string $pmsPropertyId): ?array
    {
        $stmt = $clientPdo->prepare('SELECT * FROM properties WHERE pms_property_id = :pid LIMIT 1');
        $stmt->execute([':pid' => $pmsPropertyId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
