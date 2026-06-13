<?php

declare(strict_types=1);

/**
 * Payments master ledger (global) + per-client payment_history.
 */
final class Payment
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Create a pending payment in the global ledger.
     */
    public function createPending(
        string $pmsUnitId,
        int $clientId,
        string $tenantNationalId,
        float $amount,
        string $method,
        string $externalReference,
        ?string $checkoutRequestId,
        string $recordedBy = 'system'
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO payments
                (pms_unit_id, client_id, tenant_national_id, amount, payment_method,
                 external_reference, checkout_request_id, status, recorded_by)
             VALUES
                (:uid, :cid, :nid, :amount, :method, :ref, :crid, "pending", :rb)'
        );
        $stmt->execute([
            ':uid'    => $pmsUnitId,
            ':cid'    => $clientId,
            ':nid'    => $tenantNationalId,
            ':amount' => round($amount, 2),
            ':method' => $method,
            ':ref'    => $externalReference,
            ':crid'   => $checkoutRequestId,
            ':rb'     => $recordedBy,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Create an already-settled payment (cash) in the global ledger.
     */
    public function createSettledCash(
        string $pmsUnitId,
        int $clientId,
        string $tenantNationalId,
        float $amount,
        float $serviceFee,
        string $externalReference
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO payments
                (pms_unit_id, client_id, tenant_national_id, amount, service_fee, payment_method,
                 external_reference, status, recorded_by)
             VALUES
                (:uid, :cid, :nid, :amount, :fee, "cash", :ref, "success", "superadmin")'
        );
        $stmt->execute([
            ':uid'    => $pmsUnitId,
            ':cid'    => $clientId,
            ':nid'    => $tenantNationalId,
            ':amount' => round($amount, 2),
            ':fee'    => round($serviceFee, 2),
            ':ref'    => $externalReference,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByExternalReference(string $externalReference): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payments WHERE external_reference = :ref LIMIT 1');
        $stmt->execute([':ref' => $externalReference]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Mark a payment success/failed and store the receipt + computed fee.
     */
    public function markResult(int $paymentId, string $status, string $receipt, float $serviceFee): void
    {
        $stmt = $this->db->prepare(
            'UPDATE payments
             SET status = :status, mpesa_receipt = :receipt, service_fee = :fee
             WHERE id = :id'
        );
        $stmt->execute([
            ':status'  => $status,
            ':receipt' => $receipt,
            ':fee'     => round($serviceFee, 2),
            ':id'      => $paymentId,
        ]);
    }

    /**
     * Insert a record into the client's local payment_history.
     */
    public function insertLocalHistory(
        PDO $clientPdo,
        int $globalPaymentId,
        string $pmsUnitId,
        string $tenantNationalId,
        float $amount,
        float $serviceFee,
        string $method,
        string $receipt,
        string $status
    ): int {
        $stmt = $clientPdo->prepare(
            'INSERT INTO payment_history
                (global_payment_id, pms_unit_id, tenant_national_id, amount, service_fee,
                 payment_method, mpesa_receipt, status)
             VALUES (:gid, :uid, :nid, :amount, :fee, :method, :receipt, :status)'
        );
        $stmt->execute([
            ':gid'     => $globalPaymentId,
            ':uid'     => $pmsUnitId,
            ':nid'     => $tenantNationalId,
            ':amount'  => round($amount, 2),
            ':fee'     => round($serviceFee, 2),
            ':method'  => $method,
            ':receipt' => $receipt,
            ':status'  => $status,
        ]);
        return (int) $clientPdo->lastInsertId();
    }

    /**
     * Local payment history for a unit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function historyByUnit(PDO $clientPdo, string $pmsUnitId): array
    {
        $stmt = $clientPdo->prepare(
            'SELECT amount, service_fee, payment_method AS method, mpesa_receipt AS receipt,
                    status, paid_at
             FROM payment_history WHERE pms_unit_id = :uid ORDER BY paid_at DESC'
        );
        $stmt->execute([':uid' => $pmsUnitId]);
        return $stmt->fetchAll();
    }

    /**
     * Local payment history for a tenant (by national id).
     *
     * @return array<int, array<string, mixed>>
     */
    public function historyByNationalId(PDO $clientPdo, string $nationalId): array
    {
        $stmt = $clientPdo->prepare(
            'SELECT amount, service_fee, payment_method AS method, mpesa_receipt AS receipt,
                    status, paid_at
             FROM payment_history WHERE tenant_national_id = :nid ORDER BY paid_at DESC'
        );
        $stmt->execute([':nid' => $nationalId]);
        return $stmt->fetchAll();
    }

    /**
     * Latest successful local payment for a unit (or null).
     *
     * @return array<string, mixed>|null
     */
    public function latestForUnit(PDO $clientPdo, string $pmsUnitId): ?array
    {
        $stmt = $clientPdo->prepare(
            'SELECT amount, mpesa_receipt AS receipt, status, paid_at
             FROM payment_history
             WHERE pms_unit_id = :uid AND status = "success"
             ORDER BY paid_at DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $pmsUnitId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Has the unit a successful payment in the current calendar month? (local)
     */
    public function hasPaidThisMonth(PDO $clientPdo, string $pmsUnitId): bool
    {
        $stmt = $clientPdo->prepare(
            'SELECT 1 FROM payment_history
             WHERE pms_unit_id = :uid AND status = "success"
               AND YEAR(paid_at) = YEAR(CURRENT_DATE) AND MONTH(paid_at) = MONTH(CURRENT_DATE)
             LIMIT 1'
        );
        $stmt->execute([':uid' => $pmsUnitId]);
        return $stmt->fetchColumn() !== false;
    }
}
