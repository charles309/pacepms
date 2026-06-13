<?php

declare(strict_types=1);

/**
 * DB-backed sliding-window rate limiter. Records each attempt and counts the
 * number of attempts from an IP within the trailing window. On breach it emits
 * a 429 with a Retry-After header.
 *
 * Relies on the `login_attempts` table in pms_global.
 */
final class RateLimiter
{
    private function __construct()
    {
    }

    /**
     * Enforce the rate limit for $ip. Records the attempt, then checks the count
     * within the window. Terminates with 429 on breach.
     */
    public static function check(
        PDO $globalPdo,
        string $ip,
        int $maxReq = LOGIN_RATE_LIMIT,
        int $window = LOGIN_RATE_WINDOW
    ): void {
        try {
            // Record this attempt.
            $insert = $globalPdo->prepare(
                'INSERT INTO login_attempts (ip_address, attempted_at) VALUES (:ip, NOW())'
            );
            $insert->execute([':ip' => mb_substr($ip, 0, 45)]);

            // Count attempts in the trailing window. The window is an integer
            // cast from a constant — inlined because MySQL prepared statements
            // do not reliably accept a placeholder inside INTERVAL.
            $windowSec = (int) $window;
            $count = $globalPdo->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE ip_address = :ip
                   AND attempted_at > (NOW() - INTERVAL {$windowSec} SECOND)"
            );
            $count->bindValue(':ip', mb_substr($ip, 0, 45));
            $count->execute();
            $attempts = (int) $count->fetchColumn();

            // Opportunistic cleanup of old rows.
            $cleanupSec = (int) max($window * 10, 600);
            $globalPdo->exec(
                "DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL {$cleanupSec} SECOND)"
            );

            if ($attempts > $maxReq) {
                ResponseHelper::tooManyRequests(
                    'Too many login attempts. Please wait before trying again.',
                    $window
                );
            }
        } catch (\PDOException $e) {
            Logger::error('Rate limiter failed: ' . $e->getMessage());
            // Fail open on infrastructure error to avoid locking out all users.
        }
    }

    /**
     * Clear recorded attempts for an IP (e.g. after a successful login).
     */
    public static function clear(PDO $globalPdo, string $ip): void
    {
        try {
            $globalPdo->prepare('DELETE FROM login_attempts WHERE ip_address = :ip')
                ->execute([':ip' => mb_substr($ip, 0, 45)]);
        } catch (\PDOException $e) {
            Logger::error('Rate limiter clear failed: ' . $e->getMessage());
        }
    }
}
