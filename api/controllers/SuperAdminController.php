<?php

declare(strict_types=1);

/**
 * Super admin operations: client provisioning, ID assignment, withdrawals,
 * cash payments, reconciliation, blacklist, dashboard.
 */
final class SuperAdminController
{
    private PDO $global;

    /** @param array{user_id:int, role:string, db_name:?string, client_id:?int, token:string} $ctx */
    public function __construct(private readonly array $ctx)
    {
        $this->global = Database::global();
    }

    // -----------------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------------
    public function dashboard(): never
    {
        try {
            $totalClients = (int) $this->global->query('SELECT COUNT(*) FROM clients')->fetchColumn();
            $totalProps = (int) $this->global->query('SELECT COUNT(*) FROM global_properties')->fetchColumn();
            $totalUnits = (int) $this->global->query('SELECT COUNT(*) FROM global_units')->fetchColumn();

            $allTime = (float) $this->global->query(
                'SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = "success"'
            )->fetchColumn();
            $thisMonth = (float) $this->global->query(
                'SELECT COALESCE(SUM(amount),0) FROM payments
                 WHERE status = "success"
                   AND YEAR(created_at) = YEAR(CURRENT_DATE) AND MONTH(created_at) = MONTH(CURRENT_DATE)'
            )->fetchColumn();

            $platformRevenue = $this->getSetting('platform_revenue', '0.00');
            $pendingWithdrawals = (int) $this->global->query(
                'SELECT COUNT(*) FROM withdrawals WHERE status = "pending"'
            )->fetchColumn();
            $activeBlacklist = (new Blacklist($this->global))->countActive();

            ResponseHelper::success([
                'total_clients'              => $totalClients,
                'total_properties'          => $totalProps,
                'total_units'               => $totalUnits,
                'rent_collected_all_time'   => round($allTime, 2),
                'rent_collected_this_month' => round($thisMonth, 2),
                'service_fees_earned'       => round((float) $platformRevenue, 2),
                'pending_withdrawals'       => $pendingWithdrawals,
                'active_blacklist_entries'  => $activeBlacklist,
            ], 'Dashboard loaded.');
        } catch (\PDOException $e) {
            $this->fail('Dashboard query failed', $e);
        }
    }

    // -----------------------------------------------------------------------
    // Clients
    // -----------------------------------------------------------------------
    public function getClients(): never
    {
        $role = isset($_GET['role']) ? ValidationHelper::sanitizeString($_GET['role'], 20) : null;
        $isActive = isset($_GET['is_active']) ? (int) $_GET['is_active'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));

        if ($role !== null && !ValidationHelper::validateEnum($role, ['landlord', 'manager'])) {
            ResponseHelper::badRequest('Invalid role filter.');
        }

        try {
            $result = (new Client($this->global))->paginate($role, $isActive, $page, $limit);
            ResponseHelper::raw([
                'success' => true,
                'total'   => $result['total'],
                'page'    => $page,
                'data'    => $result['rows'],
            ], 200);
        } catch (\PDOException $e) {
            $this->fail('Client list failed', $e);
        }
    }

    public function getClient(array $params): never
    {
        $clientId = (int) ($params[0] ?? 0);
        try {
            $client = (new Client($this->global))->findById($clientId);
            if ($client === null) {
                ResponseHelper::notFound('Client not found.');
            }
            unset($client['password']);
            ResponseHelper::success($client, 'Client detail.');
        } catch (\PDOException $e) {
            $this->fail('Client fetch failed', $e);
        }
    }

    /**
     * POST /superadmin/clients — create client + provision DB.
     */
    public function createClient(): never
    {
        $body = ValidationHelper::jsonBody();
        $name = ValidationHelper::requireString($body, 'name', 150);
        $email = ValidationHelper::requireEmail($body);
        $phone = ValidationHelper::requirePhone($body);
        $role = ValidationHelper::requireEnum($body, 'role', ['landlord', 'manager']);
        $serviceFee = isset($body['service_fee_pct'])
            ? round((float) $body['service_fee_pct'], 2)
            : (float) DEFAULT_SERVICE_FEE;
        if ($serviceFee < 0 || $serviceFee > 100) {
            ResponseHelper::badRequest('service_fee_pct must be between 0 and 100.');
        }

        $clientModel = new Client($this->global);
        if ($clientModel->findByEmail($email) !== null) {
            ResponseHelper::conflict('A client with that email already exists.');
        }

        $tempPassword = $this->generateTempPassword();
        $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

        try {
            // Insert client with a placeholder db_name, then derive real name from id.
            $this->global->beginTransaction();
            $placeholder = 'pending_' . bin2hex(random_bytes(8));
            $clientId = $clientModel->create(
                $name,
                $email,
                $phone,
                $role,
                $passwordHash,
                $placeholder,
                $serviceFee
            );
            $dbName = CLIENT_DB_PREFIX . str_pad((string) $clientId, 3, '0', STR_PAD_LEFT);
            $clientModel->setDbName($clientId, $dbName);
            $this->global->commit();
        } catch (\PDOException $e) {
            if ($this->global->inTransaction()) {
                $this->global->rollBack();
            }
            $this->fail('Client insert failed', $e);
        }

        // Provision the isolated database (privileged connection).
        try {
            $this->provisionClientDatabase($dbName);
        } catch (\Throwable $e) {
            Logger::error('Provisioning failed for ' . $dbName . ': ' . $e->getMessage());
            ResponseHelper::serverError('Client created but database provisioning failed. Contact support.');
        }

        AuditHelper::logAction(
            $this->global,
            $this->ctx['user_id'],
            'superadmin',
            'client.create',
            'client',
            (string) $clientId,
            null,
            ['name' => $name, 'email' => $email, 'role' => $role, 'db_name' => $dbName]
        );

        try {
            EmailHelper::welcome($email, $name, $tempPassword, $role);
        } catch (\Throwable $e) {
            Logger::error('Welcome email failed: ' . $e->getMessage());
        }

        ResponseHelper::raw([
            'success'       => true,
            'message'       => 'Client account created and database provisioned.',
            'client_id'     => $clientId,
            'db_name'       => $dbName,
            'temp_password' => $tempPassword,
        ], 201);
    }

    /**
     * CREATE DATABASE + run client_template.sql using the privileged connection.
     */
    private function provisionClientDatabase(string $dbName): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new RuntimeException('Unsafe database name.');
        }

        $priv = Database::privileged();
        $priv->exec(
            "CREATE DATABASE IF NOT EXISTS `{$dbName}`
             CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $priv->exec("USE `{$dbName}`");

        $sql = @file_get_contents(CLIENT_TEMPLATE_SQL);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Client template SQL missing or empty.');
        }

        foreach ($this->splitSqlStatements($sql) as $statement) {
            $priv->exec($statement);
        }
    }

    /**
     * Split a SQL script into individual statements (semicolon-terminated,
     * comment-stripped). Sufficient for the controlled template file.
     *
     * @return array<int, string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $lines = preg_split('/\r?\n/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--') || $trimmed === '') {
                continue;
            }
            $clean[] = $line;
        }
        $joined = implode("\n", $clean);

        $statements = [];
        foreach (explode(';', $joined) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
        }
        return $statements;
    }

    public function updateClient(array $params): never
    {
        $clientId = (int) ($params[0] ?? 0);
        $clientModel = new Client($this->global);
        $existing = $clientModel->findById($clientId);
        if ($existing === null) {
            ResponseHelper::notFound('Client not found.');
        }

        $body = ValidationHelper::jsonBody();
        $fields = [];
        if (isset($body['name'])) {
            $fields['name'] = ValidationHelper::sanitizeString($body['name'], 150);
        }
        if (isset($body['phone'])) {
            $phone = ValidationHelper::sanitizeString($body['phone'], 20);
            if (!ValidationHelper::validatePhone($phone)) {
                ResponseHelper::badRequest('Invalid phone number.');
            }
            $fields['phone'] = $phone;
        }
        if (isset($body['is_active'])) {
            $fields['is_active'] = ((int) $body['is_active']) === 1 ? 1 : 0;
        }
        if (isset($body['service_fee_pct'])) {
            $fee = round((float) $body['service_fee_pct'], 2);
            if ($fee < 0 || $fee > 100) {
                ResponseHelper::badRequest('service_fee_pct must be between 0 and 100.');
            }
            $fields['service_fee_pct'] = $fee;
        }

        if ($fields === []) {
            ResponseHelper::badRequest('No updatable fields supplied.');
        }

        try {
            $clientModel->update($clientId, $fields);
        } catch (\PDOException $e) {
            $this->fail('Client update failed', $e);
        }

        AuditHelper::logAction(
            $this->global,
            $this->ctx['user_id'],
            'superadmin',
            'client.update',
            'client',
            (string) $clientId,
            ['name' => $existing['name'], 'phone' => $existing['phone'],
             'is_active' => $existing['is_active'], 'service_fee_pct' => $existing['service_fee_pct']],
            $fields
        );

        ResponseHelper::success(null, 'Client updated.');
    }

    // -----------------------------------------------------------------------
    // Property / Unit assignment
    // -----------------------------------------------------------------------
    public function assignProperty(): never
    {
        $body = ValidationHelper::jsonBody();
        $clientId = (int) ($body['client_id'] ?? 0);
        $propertyName = ValidationHelper::requireString($body, 'property_name', 200);
        $location = ValidationHelper::sanitizeString($body['location'] ?? '', 1000);
        $description = ValidationHelper::sanitizeString($body['description'] ?? '', 2000);

        $client = (new Client($this->global))->findById($clientId);
        if ($client === null) {
            ResponseHelper::notFound('Client not found.');
        }
        $dbName = (string) $client['db_name'];

        $propertyModel = new Property($this->global);
        try {
            $this->global->beginTransaction();
            $pmsPropertyId = $this->nextPmsId('pms_property_counter', 'PMS-PROP-');
            $propertyModel->createGlobal($pmsPropertyId, $clientId, $dbName, $propertyName, $location, $description);
            $this->global->commit();
        } catch (\PDOException $e) {
            if ($this->global->inTransaction()) {
                $this->global->rollBack();
            }
            $this->fail('Property assignment (global) failed', $e);
        }

        // Mirror into the client DB.
        try {
            $clientPdo = Database::client($dbName);
            $propertyModel->createLocal($clientPdo, $pmsPropertyId, $propertyName, $location, $description);
            AuditHelper::logAction(
                $clientPdo,
                $this->ctx['user_id'],
                'superadmin',
                'property.assign',
                'property',
                $pmsPropertyId,
                null,
                ['property_name' => $propertyName, 'location' => $location]
            );
        } catch (\PDOException $e) {
            Logger::error('Property local mirror failed: ' . $e->getMessage());
        }

        ResponseHelper::raw([
            'success'         => true,
            'pms_property_id' => $pmsPropertyId,
            'message'         => 'Property registered and global ID assigned.',
        ], 201);
    }

    public function assignUnits(): never
    {
        $body = ValidationHelper::jsonBody();
        $clientId = (int) ($body['client_id'] ?? 0);
        $pmsPropertyId = ValidationHelper::sanitizeString($body['pms_property_id'] ?? '', 20);

        $client = (new Client($this->global))->findById($clientId);
        if ($client === null) {
            ResponseHelper::notFound('Client not found.');
        }
        $dbName = (string) $client['db_name'];

        // Verify the property belongs to this client.
        IdorGuard::assertPropertyOwnership($this->global, $pmsPropertyId, $clientId);

        // Build the unit list — supports explicit "units" array or count+prefix.
        $units = $this->resolveUnitInput($body);
        if ($units === []) {
            ResponseHelper::badRequest('Provide a non-empty "units" array or "unit_count".');
        }

        $unitModel = new Unit($this->global);
        $assignedIds = [];
        try {
            $clientPdo = Database::client($dbName);
            $this->global->beginTransaction();
            foreach ($units as $u) {
                $pmsUnitId = $this->nextPmsId('pms_unit_counter', 'PMS-UNIT-');
                $unitModel->createGlobal($pmsUnitId, $pmsPropertyId, $clientId);
                $unitModel->createLocal(
                    $clientPdo,
                    $pmsUnitId,
                    $pmsPropertyId,
                    $u['unit_number'],
                    $u['floor'],
                    $u['rent_amount']
                );
                $assignedIds[] = $pmsUnitId;
            }
            $this->global->commit();
        } catch (\PDOException $e) {
            if ($this->global->inTransaction()) {
                $this->global->rollBack();
            }
            $this->fail('Unit assignment failed', $e);
        }

        AuditHelper::logAction(
            $this->global,
            $this->ctx['user_id'],
            'superadmin',
            'units.assign',
            'property',
            $pmsPropertyId,
            null,
            ['unit_ids' => $assignedIds]
        );

        ResponseHelper::raw([
            'success'        => true,
            'units_assigned' => count($assignedIds),
            'unit_ids'       => $assignedIds,
        ], 201);
    }

    /**
     * Normalise the two supported unit input shapes into a uniform list.
     *
     * @param array<string, mixed> $body
     * @return array<int, array{unit_number:string, floor:string, rent_amount:float}>
     */
    private function resolveUnitInput(array $body): array
    {
        $out = [];
        if (isset($body['units']) && is_array($body['units'])) {
            foreach ($body['units'] as $u) {
                if (!is_array($u)) {
                    continue;
                }
                if (!ValidationHelper::validateAmount($u['rent_amount'] ?? null)) {
                    ResponseHelper::badRequest('Each unit requires a positive rent_amount.');
                }
                $out[] = [
                    'unit_number' => ValidationHelper::sanitizeString($u['unit_number'] ?? '', 50),
                    'floor'       => ValidationHelper::sanitizeString($u['floor'] ?? '', 20),
                    'rent_amount' => round((float) $u['rent_amount'], 2),
                ];
            }
            return $out;
        }

        // count + prefix form
        $count = (int) ($body['unit_count'] ?? 0);
        if ($count > 0) {
            $prefix = ValidationHelper::sanitizeString($body['unit_prefix'] ?? 'U', 10);
            $rent = ValidationHelper::validateAmount($body['rent_amount'] ?? null)
                ? round((float) $body['rent_amount'], 2)
                : 0.0;
            if ($rent <= 0) {
                ResponseHelper::badRequest('rent_amount is required when using unit_count.');
            }
            for ($i = 1; $i <= $count; $i++) {
                $out[] = [
                    'unit_number' => $prefix . $i,
                    'floor'       => ValidationHelper::sanitizeString($body['floor'] ?? '', 20),
                    'rent_amount' => $rent,
                ];
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Withdrawals
    // -----------------------------------------------------------------------
    public function getWithdrawals(): never
    {
        $status = isset($_GET['status']) ? ValidationHelper::sanitizeString($_GET['status'], 20) : null;
        $clientId = isset($_GET['client_id']) ? (int) $_GET['client_id'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));

        if ($status !== null && !ValidationHelper::validateEnum($status, ['pending', 'approved', 'rejected'])) {
            ResponseHelper::badRequest('Invalid status filter.');
        }

        try {
            $result = (new Withdrawal($this->global))->paginate($status, $clientId, $page, $limit);
            ResponseHelper::raw([
                'success' => true,
                'total'   => $result['total'],
                'page'    => $page,
                'data'    => $result['rows'],
            ], 200);
        } catch (\PDOException $e) {
            $this->fail('Withdrawal list failed', $e);
        }
    }

    public function verifyWithdrawal(array $params): never
    {
        $withdrawalId = (int) ($params[0] ?? 0);
        $body = ValidationHelper::jsonBody();
        $action = ValidationHelper::requireEnum($body, 'action', ['approved', 'rejected']);
        $notes = ValidationHelper::sanitizeString($body['notes'] ?? '', 2000);

        $withdrawalModel = new Withdrawal($this->global);
        $clientModel = new Client($this->global);

        $withdrawal = $withdrawalModel->findById($withdrawalId);
        if ($withdrawal === null) {
            ResponseHelper::notFound('Withdrawal not found.');
        }
        if ($withdrawal['status'] !== 'pending') {
            ResponseHelper::conflict('This withdrawal has already been processed.');
        }

        $clientId = (int) $withdrawal['client_id'];
        $amountRequested = (float) $withdrawal['amount_requested'];
        $serviceFee = (float) $withdrawal['service_fee'];
        $amountDisbursed = (float) $withdrawal['amount_disbursed'];

        try {
            $this->global->beginTransaction();

            if ($action === 'approved') {
                $client = $clientModel->findById($clientId);
                if ($client === null) {
                    $this->global->rollBack();
                    ResponseHelper::notFound('Client not found.');
                }
                if ((float) $client['balance'] < $amountRequested) {
                    $this->global->rollBack();
                    ResponseHelper::unprocessable('Client balance is insufficient for this withdrawal.');
                }
                // Debit the full requested amount from the client balance.
                $clientModel->adjustBalance($clientId, -$amountRequested);
                // Retain the service fee as platform revenue.
                $this->incrementPlatformRevenue($serviceFee);
            }

            $withdrawalModel->setVerified($withdrawalId, $action, $this->ctx['user_id'], $notes);
            $this->global->commit();
        } catch (\PDOException $e) {
            if ($this->global->inTransaction()) {
                $this->global->rollBack();
            }
            $this->fail('Withdrawal verification failed', $e);
        }

        AuditHelper::logAction(
            $this->global,
            $this->ctx['user_id'],
            'superadmin',
            'withdrawal.' . $action,
            'withdrawal',
            (string) $withdrawalId,
            ['status' => 'pending'],
            ['status' => $action, 'notes' => $notes]
        );

        if ($action === 'approved') {
            $client = $clientModel->findById($clientId);
            if ($client !== null && !empty($client['email'])) {
                try {
                    EmailHelper::withdrawalApproved(
                        (string) $client['email'],
                        (string) $client['name'],
                        $amountDisbursed,
                        $serviceFee
                    );
                } catch (\Throwable $e) {
                    Logger::error('Withdrawal email failed: ' . $e->getMessage());
                }
            }
        }

        ResponseHelper::raw([
            'success'              => true,
            'message'              => 'Withdrawal ' . $action . '.',
            'amount_disbursed'     => round($amountDisbursed, 2),
            'service_fee_retained' => round($serviceFee, 2),
        ], 200);
    }

    // -----------------------------------------------------------------------
    // Cash payments
    // -----------------------------------------------------------------------
    public function registerCashPayment(): never
    {
        $body = ValidationHelper::jsonBody();
        $pmsUnitId = ValidationHelper::sanitizeString($body['pms_unit_id'] ?? '', 20);
        $nationalId = ValidationHelper::requireNationalId($body, 'tenant_national_id');
        $amount = ValidationHelper::requireAmount($body);
        $notes = ValidationHelper::sanitizeString($body['notes'] ?? '', 1000);

        // Resolve the unit's owning client from the global registry.
        $unitRow = $this->findGlobalUnit($pmsUnitId);
        if ($unitRow === null) {
            ResponseHelper::notFound('Unit not found in the global registry.');
        }
        $clientId = (int) $unitRow['client_id'];

        $client = (new Client($this->global))->findById($clientId);
        if ($client === null) {
            ResponseHelper::notFound('Owning client not found.');
        }
        $dbName = (string) $client['db_name'];
        $serviceFeePct = (float) $client['service_fee_pct'];
        $serviceFee = round($amount * ($serviceFeePct / 100), 2);
        $clientReceives = round($amount - $serviceFee, 2);

        $paymentModel = new Payment($this->global);
        $externalRef = 'CASH-' . $pmsUnitId . '-' . time() . '-' . random_int(1000, 9999);

        try {
            $clientPdo = Database::client($dbName);
            $this->global->beginTransaction();

            $globalPaymentId = $paymentModel->createSettledCash(
                $pmsUnitId,
                $clientId,
                $nationalId,
                $amount,
                $serviceFee,
                $externalRef
            );

            (new Client($this->global))->adjustBalance($clientId, $clientReceives);
            $this->incrementPlatformRevenue($serviceFee);

            $this->global->commit();
        } catch (\PDOException $e) {
            if ($this->global->inTransaction()) {
                $this->global->rollBack();
            }
            $this->fail('Cash payment failed', $e);
        }

        // Mirror to client history (non-fatal).
        try {
            $paymentModel->insertLocalHistory(
                $clientPdo,
                $globalPaymentId,
                $pmsUnitId,
                $nationalId,
                $amount,
                $serviceFee,
                'cash',
                '',
                'success'
            );
            AuditHelper::logAction(
                $clientPdo,
                $this->ctx['user_id'],
                'superadmin',
                'payment.cash',
                'payment',
                (string) $globalPaymentId,
                null,
                ['amount' => $amount, 'notes' => $notes]
            );
        } catch (\PDOException $e) {
            Logger::error('Cash payment local mirror failed: ' . $e->getMessage());
        }

        ResponseHelper::raw([
            'success'    => true,
            'message'    => 'Cash payment recorded.',
            'payment_id' => $globalPaymentId,
        ], 201);
    }

    // -----------------------------------------------------------------------
    // Reconciliation
    // -----------------------------------------------------------------------
    public function reconcile(): never
    {
        $from = isset($_GET['from']) && ValidationHelper::validateDate($_GET['from'])
            ? $_GET['from'] : date('Y-m-01');
        $to = isset($_GET['to']) && ValidationHelper::validateDate($_GET['to'])
            ? $_GET['to'] : date('Y-m-d');
        $clientId = isset($_GET['client_id']) ? (int) $_GET['client_id'] : null;

        $where = 'WHERE created_at >= :from AND created_at < (DATE_ADD(:to, INTERVAL 1 DAY))';
        $params = [':from' => $from . ' 00:00:00', ':to' => $to];
        if ($clientId !== null) {
            $where .= ' AND client_id = :cid';
            $params[':cid'] = $clientId;
        }

        try {
            $sql = "SELECT
                        COALESCE(SUM(CASE WHEN payment_method='mpesa' AND status='success' THEN amount END),0) AS mpesa_total,
                        COALESCE(SUM(CASE WHEN payment_method='cash'  AND status='success' THEN amount END),0) AS cash_total,
                        COALESCE(SUM(CASE WHEN status='success' THEN service_fee END),0) AS service_fees,
                        SUM(status='pending') AS pending_count,
                        SUM(status='failed')  AS failed_count
                    FROM payments {$where}";
            $stmt = $this->global->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();

            ResponseHelper::success([
                'from'                 => $from,
                'to'                   => $to,
                'mpesa_collected'      => round((float) $row['mpesa_total'], 2),
                'cash_collected'       => round((float) $row['cash_total'], 2),
                'service_fees_earned'  => round((float) $row['service_fees'], 2),
                'pending_transactions' => (int) $row['pending_count'],
                'failed_transactions'  => (int) $row['failed_count'],
            ], 'Reconciliation report.');
        } catch (\PDOException $e) {
            $this->fail('Reconciliation failed', $e);
        }
    }

    // -----------------------------------------------------------------------
    // Blacklist
    // -----------------------------------------------------------------------
    public function getBlacklist(): never
    {
        try {
            $rows = (new Blacklist($this->global))->all();
            ResponseHelper::raw(['success' => true, 'data' => $rows], 200);
        } catch (\PDOException $e) {
            $this->fail('Blacklist fetch failed', $e);
        }
    }

    public function removeBlacklist(array $params): never
    {
        $id = (int) ($params[0] ?? 0);
        try {
            $ok = (new Blacklist($this->global))->resolve($id);
        } catch (\PDOException $e) {
            $this->fail('Blacklist removal failed', $e);
        }
        if (!$ok) {
            ResponseHelper::notFound('Blacklist entry not found.');
        }

        AuditHelper::logAction(
            $this->global,
            $this->ctx['user_id'],
            'superadmin',
            'blacklist.resolve',
            'blacklist',
            (string) $id
        );

        ResponseHelper::success(null, 'Blacklist entry resolved.');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------
    private function getSetting(string $key, string $default): string
    {
        $stmt = $this->global->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    /**
     * Atomically increment and return the next counter, formatting as PREFIX#####.
     * Must be called inside a transaction for safety.
     */
    private function nextPmsId(string $counterKey, string $prefix): string
    {
        // Lock the counter row, increment, return.
        $sel = $this->global->prepare(
            'SELECT setting_value FROM system_settings WHERE setting_key = :k FOR UPDATE'
        );
        $sel->execute([':k' => $counterKey]);
        $current = (int) ($sel->fetchColumn() ?: 0);
        $next = $current + 1;

        $upd = $this->global->prepare(
            'UPDATE system_settings SET setting_value = :v WHERE setting_key = :k'
        );
        $upd->execute([':v' => (string) $next, ':k' => $counterKey]);

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function incrementPlatformRevenue(float $delta): void
    {
        $current = (float) $this->getSetting('platform_revenue', '0.00');
        $new = round($current + $delta, 2);
        $stmt = $this->global->prepare(
            'UPDATE system_settings SET setting_value = :v WHERE setting_key = :k'
        );
        $stmt->execute([':v' => number_format($new, 2, '.', ''), ':k' => 'platform_revenue']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findGlobalUnit(string $pmsUnitId): ?array
    {
        $stmt = $this->global->prepare('SELECT * FROM global_units WHERE pms_unit_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $pmsUnitId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function generateTempPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $special = '!@#$%&*';
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $out .= $special[random_int(0, strlen($special) - 1)];
        return $out;
    }

    private function fail(string $context, \Throwable $e): never
    {
        Logger::error($context . ': ' . $e->getMessage());
        ResponseHelper::serverError();
    }
}
