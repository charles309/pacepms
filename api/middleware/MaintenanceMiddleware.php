<?php

declare(strict_types=1);

/**
 * Blocks all endpoints when maintenance mode is enabled, except a small set of
 * critical routes.
 */
final class MaintenanceMiddleware
{
    /** @var array<int, string> Routes that bypass maintenance mode. */
    private static array $excluded = [
        '/auth/login',
        '/superadmin/maintenance',
        '/webhook/payhero/callback',
    ];

    private function __construct()
    {
    }

    public static function check(string $uri): void
    {
        foreach (self::$excluded as $path) {
            if (str_starts_with($uri, $path)) {
                return;
            }
        }

        try {
            $pdo = Database::global();
            $stmt = $pdo->prepare(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode' LIMIT 1"
            );
            $stmt->execute();
            $enabled = $stmt->fetchColumn();

            if ($enabled === '1') {
                $msgStmt = $pdo->prepare(
                    "SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_message' LIMIT 1"
                );
                $msgStmt->execute();
                $message = $msgStmt->fetchColumn();
                $message = ($message === false || $message === '')
                    ? 'System is under maintenance. Try again later.'
                    : (string) $message;

                ResponseHelper::maintenance($message);
            }
        } catch (\PDOException $e) {
            Logger::error('Maintenance check failed: ' . $e->getMessage());
            // Fail open: do not block traffic if the settings lookup errors.
        }
    }
}
