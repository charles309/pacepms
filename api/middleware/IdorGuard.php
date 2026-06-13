<?php

declare(strict_types=1);

/**
 * Ownership checks (IDOR prevention). Every assertion verifies the requesting
 * client owns the resource, terminating with 403/404 otherwise. Global-registry
 * checks (units/properties) use the global DB; client-scoped checks
 * (tenants/complaints/withdrawals) use the client DB or global ledger.
 */
final class IdorGuard
{
    private function __construct()
    {
    }

    /**
     * Verify a pms_unit_id belongs to $clientId in the global registry.
     */
    public static function assertUnitOwnership(PDO $globalPdo, string $pmsUnitId, int $clientId): void
    {
        $stmt = $globalPdo->prepare(
            'SELECT id FROM global_units WHERE pms_unit_id = :uid AND client_id = :cid LIMIT 1'
        );
        $stmt->execute([':uid' => $pmsUnitId, ':cid' => $clientId]);
        if ($stmt->fetchColumn() === false) {
            ResponseHelper::forbidden('Access denied: this unit does not belong to your account.');
        }
    }

    /**
     * Verify a pms_property_id belongs to $clientId in the global registry.
     */
    public static function assertPropertyOwnership(PDO $globalPdo, string $pmsPropId, int $clientId): void
    {
        $stmt = $globalPdo->prepare(
            'SELECT id FROM global_properties WHERE pms_property_id = :pid AND client_id = :cid LIMIT 1'
        );
        $stmt->execute([':pid' => $pmsPropId, ':cid' => $clientId]);
        if ($stmt->fetchColumn() === false) {
            ResponseHelper::forbidden('Access denied: this property does not belong to your account.');
        }
    }

    /**
     * Verify a tenant exists in the client's own database. Returns the tenant row.
     *
     * @return array<string, mixed>
     */
    public static function assertTenantOwnership(PDO $clientPdo, int $tenantId): array
    {
        $stmt = $clientPdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $tenantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            ResponseHelper::notFound('Tenant not found in your account.');
        }
        return $row;
    }

    /**
     * Verify a complaint exists in the client's own database. Returns the row.
     *
     * @return array<string, mixed>
     */
    public static function assertComplaintOwnership(PDO $clientPdo, int $complaintId): array
    {
        $stmt = $clientPdo->prepare('SELECT * FROM complaints WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $complaintId]);
        $row = $stmt->fetch();
        if ($row === false) {
            ResponseHelper::notFound('Complaint not found in your account.');
        }
        return $row;
    }

    /**
     * Verify a withdrawal belongs to $clientId in the global ledger. Returns the row.
     *
     * @return array<string, mixed>
     */
    public static function assertWithdrawalOwnership(PDO $globalPdo, int $withdrawalId, int $clientId): array
    {
        $stmt = $globalPdo->prepare(
            'SELECT * FROM withdrawals WHERE id = :id AND client_id = :cid LIMIT 1'
        );
        $stmt->execute([':id' => $withdrawalId, ':cid' => $clientId]);
        $row = $stmt->fetch();
        if ($row === false) {
            ResponseHelper::forbidden('Access denied: this withdrawal does not belong to your account.');
        }
        return $row;
    }
}
