<?php

declare(strict_types=1);

/**
 * Token authentication + role enforcement.
 *
 * authenticate() returns the auth context (user_id, role, db_name, client_id,
 * raw token) and terminates with 401 if the token is missing/invalid/expired.
 * requireRole() additionally enforces an allowlist of roles.
 */
final class AuthMiddleware
{
    private function __construct()
    {
    }

    /**
     * Validate the bearer token and return the auth context.
     *
     * @return array{user_id:int, role:string, db_name:?string, client_id:?int, token:string}
     */
    public static function authenticate(): array
    {
        $raw = TokenHelper::fromAuthorizationHeader();
        if ($raw === null) {
            ResponseHelper::unauthorized('Missing or malformed Authorization header.');
        }

        try {
            $row = TokenHelper::validate(Database::global(), $raw);
        } catch (\PDOException $e) {
            Logger::error('Token validation query failed: ' . $e->getMessage());
            ResponseHelper::serverError();
        }

        if ($row === null) {
            ResponseHelper::unauthorized('Invalid or expired token.');
        }

        return [
            'user_id'   => (int) $row['user_id'],
            'role'      => (string) $row['role'],
            'db_name'   => $row['db_name'] !== null ? (string) $row['db_name'] : null,
            'client_id' => $row['client_id'] !== null ? (int) $row['client_id'] : null,
            'token'     => $raw,
        ];
    }

    /**
     * Authenticate and require the role to be in $allowedRoles.
     *
     * @param array<int, string> $allowedRoles
     * @return array{user_id:int, role:string, db_name:?string, client_id:?int, token:string}
     */
    public static function requireRole(array $allowedRoles): array
    {
        $ctx = self::authenticate();
        if (!in_array($ctx['role'], $allowedRoles, true)) {
            ResponseHelper::forbidden('Your role does not have access to this resource.');
        }
        return $ctx;
    }
}
