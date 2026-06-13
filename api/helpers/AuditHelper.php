<?php

declare(strict_types=1);

/**
 * Writes audit_log records into a client database. Audit failures must never
 * break the primary request, so all errors are caught and logged.
 */
final class AuditHelper
{
    private function __construct()
    {
    }

    /**
     * Record a create/update/delete action.
     *
     * @param mixed $oldValue Serialisable old state (or null).
     * @param mixed $newValue Serialisable new state (or null).
     */
    public static function logAction(
        PDO $clientPdo,
        ?int $actorId,
        string $actorRole,
        string $action,
        string $targetType,
        string $targetId,
        mixed $oldValue = null,
        mixed $newValue = null
    ): void {
        try {
            $stmt = $clientPdo->prepare(
                'INSERT INTO audit_log
                    (actor_id, actor_role, action, target_type, target_id, old_value, new_value, ip_address)
                 VALUES
                    (:actor_id, :actor_role, :action, :target_type, :target_id, :old_value, :new_value, :ip)'
            );
            $stmt->execute([
                ':actor_id'    => $actorId,
                ':actor_role'  => mb_substr($actorRole, 0, 50),
                ':action'      => mb_substr($action, 0, 100),
                ':target_type' => mb_substr($targetType, 0, 100),
                ':target_id'   => mb_substr($targetId, 0, 50),
                ':old_value'   => $oldValue === null ? null : json_encode($oldValue, JSON_UNESCAPED_UNICODE),
                ':new_value'   => $newValue === null ? null : json_encode($newValue, JSON_UNESCAPED_UNICODE),
                ':ip'          => self::clientIp(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Audit log failed: ' . $e->getMessage());
        }
    }

    public static function clientIp(): string
    {
        return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }
}
