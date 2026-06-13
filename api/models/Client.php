<?php

declare(strict_types=1);

/**
 * Client (landlord / manager) accounts in the global DB.
 */
final class Client
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Insert a new client. Returns the new client id.
     */
    public function create(
        string $name,
        string $email,
        string $phone,
        string $role,
        string $passwordHash,
        string $dbName,
        float $serviceFeePct
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO clients (name, email, phone, role, password, db_name, service_fee_pct)
             VALUES (:name, :email, :phone, :role, :pass, :db, :fee)'
        );
        $stmt->execute([
            ':name'  => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':role'  => $role,
            ':pass'  => $passwordHash,
            ':db'    => $dbName,
            ':fee'   => $serviceFeePct,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update the db_name once the padded client id is known.
     */
    public function setDbName(int $clientId, string $dbName): void
    {
        $stmt = $this->db->prepare('UPDATE clients SET db_name = :db WHERE id = :id');
        $stmt->execute([':db' => $dbName, ':id' => $clientId]);
    }

    /**
     * Paginated list with optional filters.
     *
     * @return array{total:int, rows:array<int, array<string, mixed>>}
     */
    public function paginate(?string $role, ?int $isActive, int $page, int $limit): array
    {
        $where = [];
        $params = [];
        if ($role !== null) {
            $where[] = 'role = :role';
            $params[':role'] = $role;
        }
        if ($isActive !== null) {
            $where[] = 'is_active = :active';
            $params[':active'] = $isActive;
        }
        $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM clients {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $sql = "SELECT id, name, email, phone, role, db_name, service_fee_pct, balance, is_active, created_at
                FROM clients {$whereSql}
                ORDER BY id DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['total' => $total, 'rows' => $stmt->fetchAll()];
    }

    /**
     * Update mutable client fields.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int $clientId, array $fields): void
    {
        $allowed = ['name', 'phone', 'is_active', 'service_fee_pct'];
        $set = [];
        $params = [':id' => $clientId];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $set[] = "{$col} = :{$col}";
                $params[":{$col}"] = $fields[$col];
            }
        }
        if ($set === []) {
            return;
        }
        $sql = 'UPDATE clients SET ' . implode(', ', $set) . ' WHERE id = :id';
        $this->db->prepare($sql)->execute($params);
    }

    /**
     * Adjust a client's balance by a (possibly negative) delta.
     */
    public function adjustBalance(int $clientId, float $delta): void
    {
        $stmt = $this->db->prepare('UPDATE clients SET balance = balance + :delta WHERE id = :id');
        $stmt->execute([':delta' => round($delta, 2), ':id' => $clientId]);
    }

    /**
     * All active clients (used for defaulter broadcast).
     *
     * @return array<int, array<string, mixed>>
     */
    public function allActive(): array
    {
        $stmt = $this->db->query('SELECT id, name, email FROM clients WHERE is_active = 1');
        return $stmt->fetchAll();
    }
}
