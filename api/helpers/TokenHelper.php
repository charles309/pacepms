<?php

declare(strict_types=1);

/**
 * Opaque token lifecycle: generate, hash, store, validate, revoke, purge.
 *
 * The raw token (64 hex chars) is returned to the client exactly once. Only
 * SHA-256(raw) is ever persisted.
 */
final class TokenHelper
{
    private function __construct()
    {
    }

    /**
     * Generate a cryptographically-random opaque token (64 hex chars).
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(TOKEN_BYTES));
    }

    /**
     * SHA-256 of the raw token — the value stored server-side.
     */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Persist a token hash for a user. Returns the row id.
     */
    public static function store(
        PDO $globalPdo,
        int $userId,
        string $role,
        string $rawToken,
        ?string $dbName = null,
        ?int $clientId = null
    ): int {
        $expires = date('Y-m-d H:i:s', time() + TOKEN_TTL_SECONDS);

        $stmt = $globalPdo->prepare(
            'INSERT INTO auth_tokens (user_id, role, token_hash, db_name, client_id, expires_at)
             VALUES (:uid, :role, :hash, :db, :cid, :exp)'
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':role' => $role,
            ':hash' => self::hash($rawToken),
            ':db'   => $dbName,
            ':cid'  => $clientId,
            ':exp'  => $expires,
        ]);

        return (int) $globalPdo->lastInsertId();
    }

    /**
     * Look up a non-expired token by its raw value.
     *
     * @return array<string, mixed>|null
     */
    public static function validate(PDO $globalPdo, string $rawToken): ?array
    {
        $stmt = $globalPdo->prepare(
            'SELECT * FROM auth_tokens
             WHERE token_hash = :hash AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':hash' => self::hash($rawToken)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Delete a token (logout).
     */
    public static function revoke(PDO $globalPdo, string $rawToken): void
    {
        $stmt = $globalPdo->prepare('DELETE FROM auth_tokens WHERE token_hash = :hash');
        $stmt->execute([':hash' => self::hash($rawToken)]);
    }

    /**
     * Remove all expired tokens. Returns the number deleted.
     */
    public static function purgeExpired(PDO $globalPdo): int
    {
        return (int) $globalPdo->exec('DELETE FROM auth_tokens WHERE expires_at < NOW()');
    }

    /**
     * Extract the bearer token from the Authorization header, or null.
     */
    public static function fromAuthorizationHeader(): ?string
    {
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', trim($header), $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
