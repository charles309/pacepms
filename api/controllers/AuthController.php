<?php

declare(strict_types=1);

/**
 * Authentication: login (multi-table lookup + rate limiting) and logout.
 */
final class AuthController
{
    /**
     * POST /auth/login
     */
    public function login(): never
    {
        $globalPdo = Database::global();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Rate limit by IP before doing any work.
        RateLimiter::check($globalPdo, $ip, LOGIN_RATE_LIMIT, LOGIN_RATE_WINDOW);

        $body = ValidationHelper::jsonBody();
        $email = ValidationHelper::sanitizeString($body['email'] ?? '', 150);
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        if (!ValidationHelper::validateEmail($email) || $password === '') {
            ResponseHelper::raw([
                'success' => false,
                'error'   => 'invalid_credentials',
                'message' => 'Invalid email or password.',
            ], 401);
        }

        // 1) Super admin
        $superAdmin = (new SuperAdmin($globalPdo))->findByEmail($email);
        if ($superAdmin !== null && password_verify($password, $superAdmin['password'])) {
            $this->issueToken($globalPdo, (int) $superAdmin['id'], 'superadmin', null, null, [
                'id'    => (int) $superAdmin['id'],
                'name'  => $superAdmin['name'],
                'email' => $superAdmin['email'],
            ], $ip);
        }

        // 2) Client (landlord / manager)
        $client = (new Client($globalPdo))->findByEmail($email);
        if ($client !== null) {
            if ((int) $client['is_active'] !== 1) {
                ResponseHelper::raw([
                    'success' => false,
                    'error'   => 'account_disabled',
                    'message' => 'Your account has been deactivated. Contact the administrator.',
                ], 403);
            }
            if (password_verify($password, $client['password'])) {
                $this->issueToken(
                    $globalPdo,
                    (int) $client['id'],
                    (string) $client['role'],
                    (string) $client['db_name'],
                    (int) $client['id'],
                    [
                        'id'    => (int) $client['id'],
                        'name'  => $client['name'],
                        'email' => $client['email'],
                    ],
                    $ip
                );
            }
        }

        // 3) Tenant — scan active client DBs.
        $tenantResult = $this->findTenant($globalPdo, $email, $password);
        if ($tenantResult !== null) {
            [$tenant, $dbName, $clientId] = $tenantResult;
            $this->issueToken(
                $globalPdo,
                (int) $tenant['id'],
                'tenant',
                $dbName,
                $clientId,
                [
                    'id'    => (int) $tenant['id'],
                    'name'  => $tenant['full_name'],
                    'email' => $tenant['email'],
                ],
                $ip
            );
        }

        // Nothing matched.
        ResponseHelper::raw([
            'success' => false,
            'error'   => 'invalid_credentials',
            'message' => 'Invalid email or password.',
        ], 401);
    }

    /**
     * Iterate active client DBs to find a matching tenant.
     *
     * @return array{0:array<string,mixed>,1:string,2:int}|null
     */
    private function findTenant(PDO $globalPdo, string $email, string $password): ?array
    {
        try {
            $clients = $globalPdo->query(
                'SELECT id, db_name FROM clients WHERE is_active = 1'
            )->fetchAll();
        } catch (\PDOException $e) {
            Logger::error('Tenant lookup: client list failed: ' . $e->getMessage());
            return null;
        }

        $tenantModel = new Tenant($globalPdo);
        foreach ($clients as $c) {
            try {
                $clientPdo = Database::client((string) $c['db_name']);
                $tenant = $tenantModel->findByEmail($clientPdo, $email);
                if ($tenant !== null
                    && $tenant['password'] !== null
                    && password_verify($password, (string) $tenant['password'])
                ) {
                    return [$tenant, (string) $c['db_name'], (int) $c['id']];
                }
            } catch (\PDOException $e) {
                Logger::error('Tenant lookup in ' . $c['db_name'] . ' failed: ' . $e->getMessage());
                continue;
            }
        }

        return null;
    }

    /**
     * Issue and return a token. Terminates the request.
     *
     * @param array<string, mixed> $user
     */
    private function issueToken(
        PDO $globalPdo,
        int $userId,
        string $role,
        ?string $dbName,
        ?int $clientId,
        array $user,
        string $ip
    ): never {
        $raw = TokenHelper::generate();
        try {
            TokenHelper::store($globalPdo, $userId, $role, $raw, $dbName, $clientId);
        } catch (\PDOException $e) {
            Logger::error('Token store failed: ' . $e->getMessage());
            ResponseHelper::serverError();
        }

        RateLimiter::clear($globalPdo, $ip);

        ResponseHelper::raw([
            'success'    => true,
            'token'      => $raw,
            'role'       => $role,
            'expires_in' => TOKEN_TTL_SECONDS,
            'db_name'    => $dbName,
            'user'       => $user,
        ], 200);
    }

    /**
     * POST /auth/logout
     */
    public function logout(): never
    {
        $raw = TokenHelper::fromAuthorizationHeader();
        if ($raw === null) {
            ResponseHelper::unauthorized('Missing or malformed Authorization header.');
        }
        try {
            TokenHelper::revoke(Database::global(), $raw);
        } catch (\PDOException $e) {
            Logger::error('Logout revoke failed: ' . $e->getMessage());
            ResponseHelper::serverError();
        }
        ResponseHelper::raw(['success' => true, 'message' => 'Logged out successfully.'], 200);
    }
}
