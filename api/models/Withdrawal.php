<?php

declare(strict_types=1);

/**
 * Withdrawal requests (global DB).
 */
final class Withdrawal
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(
        int $clientId,
        float $amountRequested,
        float $serviceFee,
        float $amountDisbursed,
        string $bankDetails,
        string $notes
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO withdrawals
                (client_id, amount_requested, service_fee, amount_disbursed, status, bank_details, notes)
             VALUES (:cid, :req, :fee, :disb, "pending", :bank, :notes)'
        );
        $stmt->execute([
            ':cid'   => $clientId,
            ':req'   => round($amountRequested, 2),
            ':fee'   => round($serviceFee, 2),
            ':disb'  => round($amountDisbursed, 2),
            ':bank'  => $bankDetails,
            ':notes' => $notes,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM withdrawals WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * List by client (admin view).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByClient(int $clientId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, amount_requested, service_fee, amount_disbursed, status, notes, requested_at, verified_at
             FROM withdrawals WHERE client_id = :cid ORDER BY id DESC'
        );
        $stmt->execute([':cid' => $clientId]);
        return $stmt->fetchAll();
    }

    /**
     * List with optional filters joined to client name (super admin view).
     *
     * @return array{total:int, rows:array<int, array<string, mixed>>}
     */
    public function paginate(?string $status, ?int $clientId, int $page, int $limit): array
    {
        $where = [];
        $params = [];
        if ($status !== null) {
            $where[] = 'w.status = :status';
            $params[':status'] = $status;
        }
        if ($clientId !== null) {
            $where[] = 'w.client_id = :cid';
            $params[':cid'] = $clientId;
        }
        $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM withdrawals w {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $sql = "SELECT w.id, w.client_id, c.name AS client_name, w.amount_requested, w.service_fee,
                       w.amount_disbursed, w.status, w.notes, w.requested_at, w.verified_at
                FROM withdrawals w
                JOIN clients c ON c.id = w.client_id
                {$whereSql}
                ORDER BY w.id DESC
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

    public function setVerified(int $id, string $status, int $verifiedBy, string $notes): void
    {
        $stmt = $this->db->prepare(
            'UPDATE withdrawals
             SET status = :status, verified_by = :vby, verified_at = NOW(), notes = :notes
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':vby'    => $verifiedBy,
            ':notes'  => $notes,
            ':id'     => $id,
        ]);
    }
}
