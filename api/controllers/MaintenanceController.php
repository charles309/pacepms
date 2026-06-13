<?php

declare(strict_types=1);

/**
 * Maintenance mode status + toggle (super admin only).
 */
final class MaintenanceController
{
    private PDO $global;

    /** @param array{user_id:int, role:string, db_name:?string, client_id:?int, token:string} $ctx */
    public function __construct(private readonly array $ctx)
    {
        $this->global = Database::global();
    }

    public function getStatus(): never
    {
        try {
            $enabled = $this->getSetting('maintenance_mode', '0');
            $message = $this->getSetting('maintenance_message', '');
            ResponseHelper::raw([
                'success'          => true,
                'maintenance_mode' => $enabled === '1',
                'message'          => $message,
            ], 200);
        } catch (\PDOException $e) {
            Logger::error('Maintenance status failed: ' . $e->getMessage());
            ResponseHelper::serverError();
        }
    }

    public function toggle(): never
    {
        $body = ValidationHelper::jsonBody();
        if (!isset($body['enabled'])) {
            ResponseHelper::badRequest('Field "enabled" is required.');
        }
        $enabled = filter_var($body['enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $message = ValidationHelper::sanitizeString($body['message'] ?? '', 1000);

        try {
            $this->setSetting('maintenance_mode', $enabled);
            $this->setSetting('maintenance_message', $message);
        } catch (\PDOException $e) {
            Logger::error('Maintenance toggle failed: ' . $e->getMessage());
            ResponseHelper::serverError();
        }

        AuditHelper::logAction(
            $this->global,
            $this->ctx['user_id'],
            'superadmin',
            'maintenance.toggle',
            'system',
            'maintenance_mode',
            null,
            ['enabled' => $enabled === '1', 'message' => $message]
        );

        ResponseHelper::raw([
            'success'          => true,
            'maintenance_mode' => $enabled === '1',
        ], 200);
    }

    private function getSetting(string $key, string $default): string
    {
        $stmt = $this->global->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    private function setSetting(string $key, string $value): void
    {
        $stmt = $this->global->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, updated_by)
             VALUES (:k, :v, :by)
             ON DUPLICATE KEY UPDATE setting_value = :v2, updated_by = :by2'
        );
        $stmt->execute([
            ':k'   => $key,
            ':v'   => $value,
            ':by'  => $this->ctx['user_id'],
            ':v2'  => $value,
            ':by2' => $this->ctx['user_id'],
        ]);
    }
}
