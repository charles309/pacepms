<?php

declare(strict_types=1);

/**
 * Landlord / Property Manager operations. All data scoped to the authenticated
 * client's isolated DB; ownership enforced via IdorGuard.
 */
final class AdminController
{
    private PDO $global;
    private PDO $client;
    private int $clientId;
    private string $role;

    /** @param array{user_id:int, role:string, db_name:?string, client_id:?int, token:string} $ctx */
    public function __construct(private readonly array $ctx)
    {
        $this->global = Database::global();
        if (empty($ctx['db_name']) || $ctx['client_id'] === null) {
            ResponseHelper::forbidden('No client database bound to this session.');
        }
        $this->clientId = (int) $ctx['client_id'];
        $this->role = (string) $ctx['role'];
        try {
            $this->client = Database::client((string) $ctx['db_name']);
        } catch (\PDOException $e) {
            Logger::error('Admin DB connect failed: ' . $e->getMessage());
            ResponseHelper::serverError();
        }
    }

    // -----------------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------------
    public function dashboard(): never
    {
        try {
            $occ = (new Unit($this->global))->occupancyStats($this->client);
            $occupancyRate = $occ['total'] > 0 ? round(($occ['occupied'] / $occ['total']) * 100, 1) : 0.0;

            $rentThisMonth = (float) $this->client->query(
                'SELECT COALESCE(SUM(amount),0) FROM payment_history
                 WHERE status = "success"
                   AND YEAR(paid_at) = YEAR(CURRENT_DATE) AND MONTH(paid_at) = MONTH(CURRENT_DATE)'
            )->fetchColumn();

            $pendingPayments = (int) $this->client->query(
                'SELECT COUNT(*) FROM payment_history WHERE status = "pending"'
            )->fetchColumn();

            $openComplaints = (new Complaint($this->global))->countOpen($this->client);

            $client = (new Client($this->global))->findById($this->clientId);
            $balance = $client !== null ? (float) $client['balance'] : 0.0;

            $recent = $this->client->query(
                'SELECT amount, payment_method AS method, mpesa_receipt AS receipt, status, paid_at
                 FROM payment_history ORDER BY paid_at DESC LIMIT 5'
            )->fetchAll();

            ResponseHelper::success([
                'total_units'               => $occ['total'],
                'occupied'                  => $occ['occupied'],
                'vacant'                    => $occ['vacant'],
                'occupancy_rate'            => $occupancyRate,
                'rent_collected_this_month' => round($rentThisMonth, 2),
                'pending_payments'          => $pendingPayments,
                'open_complaints'           => $openComplaints,
                'balance'                   => round($balance, 2),
                'recent_payments'           => $recent,
            ], 'Dashboard loaded.');
        } catch (\PDOException $e) {
            $this->fail('Admin dashboard failed', $e);
        }
    }

    // -----------------------------------------------------------------------
    // Properties
    // -----------------------------------------------------------------------
    public function getProperties(): never
    {
        try {
            $rows = (new Property($this->global))->listLocalWithStats($this->client);
            ResponseHelper::raw(['success' => true, 'data' => $rows], 200);
        } catch (\PDOException $e) {
            $this->fail('Property list failed', $e);
        }
    }

    public function getProperty(array $params): never
    {
        $pmsPropertyId = ValidationHelper::sanitizeString($params[0] ?? '', 20);
        IdorGuard::assertPropertyOwnership($this->global, $pmsPropertyId, $this->clientId);

        try {
            $property = (new Property($this->global))->findLocal($this->client, $pmsPropertyId);
            if ($property === null) {
                ResponseHelper::notFound('Property not found.');
            }
            $units = (new Unit($this->global))->listByProperty($this->client, $pmsPropertyId);
            ResponseHelper::raw([
                'success'  => true,
                'property' => [
                    'pms_property_id' => $property['pms_property_id'],
                    'name'            => $property['name'],
                    'address'         => $property['address'],
                    'description'     => $property['description'],
                    'units'           => $units,
                ],
            ], 200);
        } catch (\PDOException $e) {
            $this->fail('Property fetch failed', $e);
        }
    }

    // -----------------------------------------------------------------------
    // Units
    // -----------------------------------------------------------------------
    public function getUnit(array $params): never
    {
        $pmsUnitId = ValidationHelper::sanitizeString($params[0] ?? '', 20);
        IdorGuard::assertUnitOwnership($this->global, $pmsUnitId, $this->clientId);

        try {
            $unit = (new Unit($this->global))->findLocal($this->client, $pmsUnitId);
            if ($unit === null) {
                ResponseHelper::notFound('Unit not found.');
            }
            $tenant = (new Tenant($this->global))->findActiveByUnit($this->client, $pmsUnitId);
            $latest = (new Payment($this->global))->latestForUnit($this->client, $pmsUnitId);

            ResponseHelper::raw([
                'success' => true,
                'unit'    => [
                    'pms_unit_id'  => $unit['pms_unit_id'],
                    'unit_number'  => $unit['unit_number'],
                    'floor'        => $unit['floor'],
                    'rent_amount'  => (float) $unit['rent_amount'],
                    'status'       => $unit['status'],
                    'tenant'       => $tenant === null ? null : [
                        'id'        => (int) $tenant['id'],
                        'full_name' => $tenant['full_name'],
                        'phone'     => $tenant['phone'],
                        'email'     => $tenant['email'],
                    ],
                    'last_payment' => $latest,
                ],
            ], 200);
        } catch (\PDOException $e) {
            $this->fail('Unit fetch failed', $e);
        }
    }

    /**
     * PUT /admin/units/{id}/status — includes defaulter flow.
     */
    public function setUnitStatus(array $params): never
    {
        $pmsUnitId = ValidationHelper::sanitizeString($params[0] ?? '', 20);
        IdorGuard::assertUnitOwnership($this->global, $pmsUnitId, $this->clientId);

        $body = ValidationHelper::jsonBody();
        $status = ValidationHelper::requireEnum($body, 'status', ['vacant', 'occupied']);

        $unitModel = new Unit($this->global);
        $unit = $unitModel->findLocal($this->client, $pmsUnitId);
        if ($unit === null) {
            ResponseHelper::notFound('Unit not found.');
        }

        // Simple occupancy toggle (occupied) — no report needed.
        if ($status === 'occupied') {
            try {
                $unitModel->setStatus($this->client, $pmsUnitId, 'occupied');
            } catch (\PDOException $e) {
                $this->fail('Unit status update failed', $e);
            }
            AuditHelper::logAction(
                $this->client,
                $this->ctx['user_id'],
                $this->role,
                'unit.status_changed',
                'unit',
                $pmsUnitId,
                ['status' => $unit['status']],
                ['status' => 'occupied']
            );
            ResponseHelper::raw([
                'success'           => true,
                'message'           => 'Unit marked occupied.',
                'defaulter_flagged' => false,
            ], 200);
        }

        // Vacating requires a vacate report.
        $vacateReason = ValidationHelper::requireString($body, 'vacate_reason', 2000);
        $vacateDate = ValidationHelper::requireDate($body, 'vacate_date');
        $outstanding = isset($body['outstanding_balance'])
            ? round((float) $body['outstanding_balance'], 2)
            : 0.0;
        if ($outstanding < 0) {
            ResponseHelper::badRequest('outstanding_balance cannot be negative.');
        }

        $tenant = (new Tenant($this->global))->findActiveByUnit($this->client, $pmsUnitId);
        $isDefaulter = $outstanding > 0;

        try {
            $this->client->beginTransaction();

            if ($tenant !== null) {
                // Vacate report
                $vr = $this->client->prepare(
                    'INSERT INTO vacate_reports
                        (pms_unit_id, tenant_id, reported_by, reason, outstanding_amt, report_date)
                     VALUES (:uid, :tid, :role, :reason, :amt, :date)'
                );
                $vr->execute([
                    ':uid'    => $pmsUnitId,
                    ':tid'    => (int) $tenant['id'],
                    ':role'   => $this->role,
                    ':reason' => $vacateReason,
                    ':amt'    => $outstanding,
                    ':date'   => $vacateDate,
                ]);

                // Archive tenant -> former_tenants
                (new Tenant($this->global))->archiveToFormer(
                    $this->client,
                    $tenant,
                    $vacateDate,
                    $vacateReason,
                    $outstanding,
                    $isDefaulter
                );
            }

            $unitModel->setStatus($this->client, $pmsUnitId, 'vacant');
            $this->client->commit();
        } catch (\PDOException $e) {
            if ($this->client->inTransaction()) {
                $this->client->rollBack();
            }
            $this->fail('Vacate flow failed', $e);
        }

        // Blacklist + broadcast (only if defaulter). Outside the client TX since
        // it touches the global DB; failures here are logged but non-fatal.
        if ($isDefaulter && $tenant !== null) {
            $this->flagDefaulter($tenant, $pmsUnitId, $outstanding, $vacateReason);
        }

        AuditHelper::logAction(
            $this->client,
            $this->ctx['user_id'],
            $this->role,
            'unit.vacated',
            'unit',
            $pmsUnitId,
            ['status' => $unit['status']],
            ['status' => 'vacant', 'outstanding' => $outstanding, 'defaulter' => $isDefaulter]
        );

        ResponseHelper::raw([
            'success'           => true,
            'message'           => 'Unit status updated. Tenant record archived.',
            'defaulter_flagged' => $isDefaulter,
        ], 200);
    }

    /**
     * Insert into global blacklist and notify all other active clients.
     *
     * @param array<string, mixed> $tenant
     */
    private function flagDefaulter(array $tenant, string $pmsUnitId, float $owed, string $report): void
    {
        try {
            (new Blacklist($this->global))->add(
                (string) $tenant['national_id'],
                (string) $tenant['full_name'],
                (string) ($tenant['phone'] ?? ''),
                (string) ($tenant['email'] ?? ''),
                $pmsUnitId,
                $this->clientId,
                $owed,
                $report
            );
        } catch (\PDOException $e) {
            Logger::error('Blacklist insert failed: ' . $e->getMessage());
            return;
        }

        try {
            $clients = (new Client($this->global))->allActive();
            foreach ($clients as $c) {
                if ((int) $c['id'] === $this->clientId || empty($c['email'])) {
                    continue;
                }
                try {
                    EmailHelper::defaulterAlert(
                        (string) $c['email'],
                        (string) $c['name'],
                        (string) $tenant['full_name'],
                        (string) $tenant['national_id'],
                        $owed
                    );
                } catch (\Throwable $e) {
                    Logger::error('Defaulter alert email failed for client ' . $c['id'] . ': ' . $e->getMessage());
                }
            }
        } catch (\PDOException $e) {
            Logger::error('Defaulter broadcast failed: ' . $e->getMessage());
        }
    }

    public function getUnitHistory(array $params): never
    {
        $pmsUnitId = ValidationHelper::sanitizeString($params[0] ?? '', 20);
        IdorGuard::assertUnitOwnership($this->global, $pmsUnitId, $this->clientId);
        try {
            $history = (new Payment($this->global))->historyByUnit($this->client, $pmsUnitId);
            ResponseHelper::raw(['success' => true, 'unit' => $pmsUnitId, 'history' => $history], 200);
        } catch (\PDOException $e) {
            $this->fail('Unit history failed', $e);
        }
    }

    public function getUnitQR(array $params): never
    {
        $pmsUnitId = ValidationHelper::sanitizeString($params[0] ?? '', 20);
        IdorGuard::assertUnitOwnership($this->global, $pmsUnitId, $this->clientId);

        $payUrl = PAYMENT_PAGE_URL . '?unit=' . rawurlencode($pmsUnitId);
        $dataUri = $this->makeQrDataUri($payUrl);

        ResponseHelper::raw([
            'success'     => true,
            'pms_unit_id' => $pmsUnitId,
            'payment_url' => $payUrl,
            'qr_code'     => $dataUri,
        ], 200);
    }

    /**
     * Generate a base64 PNG data URI for the QR code via endroid/qr-code, with a
     * graceful fallback if the library is unavailable.
     */
    private function makeQrDataUri(string $text): string
    {
        if (class_exists(\Endroid\QrCode\QrCode::class)) {
            try {
                // endroid/qr-code v4/v5 builder API.
                if (class_exists(\Endroid\QrCode\Builder\Builder::class)) {
                    $result = \Endroid\QrCode\Builder\Builder::create()
                        ->writer(new \Endroid\QrCode\Writer\PngWriter())
                        ->data($text)
                        ->size(300)
                        ->margin(10)
                        ->build();
                    return $result->getDataUri();
                }
            } catch (\Throwable $e) {
                Logger::error('QR generation failed: ' . $e->getMessage());
            }
        }

        Logger::warning('endroid/qr-code unavailable; returning text payload only.');
        return 'data:text/plain;base64,' . base64_encode($text);
    }

    // -----------------------------------------------------------------------
    // Tenants
    // -----------------------------------------------------------------------
    public function registerTenant(): never
    {
        $body = ValidationHelper::jsonBody();
        $pmsUnitId = ValidationHelper::sanitizeString($body['pms_unit_id'] ?? '', 20);
        IdorGuard::assertUnitOwnership($this->global, $pmsUnitId, $this->clientId);

        $nationalId = ValidationHelper::requireNationalId($body);
        $fullName = ValidationHelper::requireString($body, 'full_name', 200);
        $phone = ValidationHelper::requirePhone($body);
        $email = ValidationHelper::requireEmail($body);
        $moveInDate = ValidationHelper::requireDate($body, 'move_in_date');

        $tenantModel = new Tenant($this->global);

        // Duplicate guard (national id unique per client DB).
        if ($tenantModel->findByNationalId($this->client, $nationalId) !== null) {
            ResponseHelper::conflict('A tenant with this national ID already exists.');
        }

        // Blacklist check (warn but allow).
        $blacklistWarning = false;
        $blacklistDetails = null;
        try {
            $bl = (new Blacklist($this->global))->findActiveByNationalId($nationalId);
            if ($bl !== null) {
                $blacklistWarning = true;
                $blacklistDetails = [
                    'amount_owed'   => (float) $bl['amount_owed'],
                    'previous_unit' => $bl['last_pms_unit_id'],
                    'flagged_at'    => $bl['flagged_at'],
                ];
            }
        } catch (\PDOException $e) {
            Logger::error('Blacklist check failed: ' . $e->getMessage());
        }

        // Auto-generate a tenant portal password.
        $tempPassword = bin2hex(random_bytes(4));
        $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

        try {
            $this->client->beginTransaction();
            $tenantId = $tenantModel->create(
                $this->client,
                $pmsUnitId,
                $nationalId,
                $fullName,
                $phone,
                $email,
                $moveInDate,
                $passwordHash
            );
            (new Unit($this->global))->setStatus($this->client, $pmsUnitId, 'occupied');
            $this->client->commit();
        } catch (\PDOException $e) {
            if ($this->client->inTransaction()) {
                $this->client->rollBack();
            }
            $this->fail('Tenant registration failed', $e);
        }

        AuditHelper::logAction(
            $this->client,
            $this->ctx['user_id'],
            $this->role,
            'tenant.register',
            'tenant',
            (string) $tenantId,
            null,
            ['national_id' => $nationalId, 'pms_unit_id' => $pmsUnitId, 'blacklisted' => $blacklistWarning]
        );

        try {
            EmailHelper::welcome($email, $fullName, $tempPassword, 'tenant');
        } catch (\Throwable $e) {
            Logger::error('Tenant welcome email failed: ' . $e->getMessage());
        }

        $payload = [
            'success'           => true,
            'message'           => $blacklistWarning
                ? 'Tenant registered with blacklist warning.'
                : 'Tenant registered.',
            'tenant_id'         => $tenantId,
            'blacklist_warning' => $blacklistWarning,
        ];
        if ($blacklistDetails !== null) {
            $payload['blacklist_details'] = $blacklistDetails;
        }
        ResponseHelper::raw($payload, 201);
    }

    public function getTenants(): never
    {
        try {
            $rows = (new Tenant($this->global))->listActive($this->client);
            ResponseHelper::raw(['success' => true, 'data' => $rows], 200);
        } catch (\PDOException $e) {
            $this->fail('Tenant list failed', $e);
        }
    }

    public function getTenant(array $params): never
    {
        $tenantId = (int) ($params[0] ?? 0);
        $tenant = IdorGuard::assertTenantOwnership($this->client, $tenantId);
        try {
            $history = (new Payment($this->global))->historyByNationalId(
                $this->client,
                (string) $tenant['national_id']
            );
            $ccStmt = $this->client->prepare('SELECT COUNT(*) FROM complaints WHERE tenant_id = :tid');
            $ccStmt->execute([':tid' => $tenantId]);
            $complaintCount = (int) $ccStmt->fetchColumn();

            unset($tenant['password']);
            ResponseHelper::success([
                'tenant'          => $tenant,
                'payment_history' => $history,
                'complaint_count' => $complaintCount,
            ], 'Tenant detail.');
        } catch (\PDOException $e) {
            $this->fail('Tenant fetch failed', $e);
        }
    }

    public function updateTenant(array $params): never
    {
        $tenantId = (int) ($params[0] ?? 0);
        $existing = IdorGuard::assertTenantOwnership($this->client, $tenantId);

        $body = ValidationHelper::jsonBody();
        $fields = [];
        if (isset($body['full_name'])) {
            $fields['full_name'] = ValidationHelper::sanitizeString($body['full_name'], 200);
        }
        if (isset($body['phone'])) {
            $phone = ValidationHelper::sanitizeString($body['phone'], 20);
            if (!ValidationHelper::validatePhone($phone)) {
                ResponseHelper::badRequest('Invalid phone number.');
            }
            $fields['phone'] = $phone;
        }
        if (isset($body['email'])) {
            $emailVal = ValidationHelper::sanitizeString($body['email'], 150);
            if (!ValidationHelper::validateEmail($emailVal)) {
                ResponseHelper::badRequest('Invalid email.');
            }
            $fields['email'] = $emailVal;
        }
        if ($fields === []) {
            ResponseHelper::badRequest('No updatable fields supplied.');
        }

        try {
            (new Tenant($this->global))->update($this->client, $tenantId, $fields);
        } catch (\PDOException $e) {
            $this->fail('Tenant update failed', $e);
        }

        AuditHelper::logAction(
            $this->client,
            $this->ctx['user_id'],
            $this->role,
            'tenant.update',
            'tenant',
            (string) $tenantId,
            ['full_name' => $existing['full_name'], 'phone' => $existing['phone'], 'email' => $existing['email']],
            $fields
        );

        ResponseHelper::success(null, 'Tenant updated.');
    }

    // -----------------------------------------------------------------------
    // Complaints
    // -----------------------------------------------------------------------
    public function getComplaints(): never
    {
        $status = isset($_GET['status']) ? ValidationHelper::sanitizeString($_GET['status'], 20) : null;
        $unit = isset($_GET['pms_unit_id']) ? ValidationHelper::sanitizeString($_GET['pms_unit_id'], 20) : null;
        if ($status !== null && !ValidationHelper::validateEnum($status, ['open', 'in_progress', 'resolved'])) {
            ResponseHelper::badRequest('Invalid status filter.');
        }
        try {
            $rows = (new Complaint($this->global))->listForAdmin($this->client, $status, $unit);
            ResponseHelper::raw(['success' => true, 'data' => $rows], 200);
        } catch (\PDOException $e) {
            $this->fail('Complaint list failed', $e);
        }
    }

    public function getComplaint(array $params): never
    {
        $complaintId = (int) ($params[0] ?? 0);
        $complaint = IdorGuard::assertComplaintOwnership($this->client, $complaintId);
        ResponseHelper::success($complaint, 'Complaint detail.');
    }

    public function replyComplaint(array $params): never
    {
        $complaintId = (int) ($params[0] ?? 0);
        $complaint = IdorGuard::assertComplaintOwnership($this->client, $complaintId);

        $body = ValidationHelper::jsonBody();
        $reply = ValidationHelper::requireString($body, 'admin_reply', 5000);
        $status = isset($body['status'])
            ? ValidationHelper::requireEnum($body, 'status', ['open', 'in_progress', 'resolved'])
            : 'in_progress';

        try {
            (new Complaint($this->global))->reply($this->client, $complaintId, $reply, $status);
        } catch (\PDOException $e) {
            $this->fail('Complaint reply failed', $e);
        }

        // Notify tenant (best effort).
        $tenant = (new Tenant($this->global))->findById($this->client, (int) $complaint['tenant_id']);
        if ($tenant !== null) {
            try {
                if (!empty($tenant['email'])) {
                    EmailHelper::complaintReply(
                        (string) $tenant['email'],
                        (string) $tenant['full_name'],
                        (string) ($complaint['subject'] ?? 'Your complaint'),
                        $reply
                    );
                }
                (new Notification($this->global))->create(
                    $this->client,
                    (int) $tenant['id'],
                    'tenant',
                    'complaint_reply',
                    'Reply to your complaint',
                    $reply
                );
            } catch (\Throwable $e) {
                Logger::error('Complaint reply notification failed: ' . $e->getMessage());
            }
        }

        AuditHelper::logAction(
            $this->client,
            $this->ctx['user_id'],
            $this->role,
            'complaint.reply',
            'complaint',
            (string) $complaintId,
            ['status' => $complaint['status']],
            ['status' => $status]
        );

        ResponseHelper::success(null, 'Reply sent to tenant.');
    }

    // -----------------------------------------------------------------------
    // Messages
    // -----------------------------------------------------------------------
    public function sendMessage(): never
    {
        $body = ValidationHelper::jsonBody();
        $pmsUnitId = ValidationHelper::sanitizeString($body['pms_unit_id'] ?? '', 20);
        IdorGuard::assertUnitOwnership($this->global, $pmsUnitId, $this->clientId);

        $channel = ValidationHelper::requireEnum($body, 'channel', ['app', 'email', 'both']);
        $subject = ValidationHelper::requireString($body, 'subject', 255);
        $messageBody = ValidationHelper::requireString($body, 'body', 5000);

        $tenant = (new Tenant($this->global))->findActiveByUnit($this->client, $pmsUnitId);
        $tenantId = $tenant !== null ? (int) $tenant['id'] : null;

        try {
            (new Message($this->global))->create(
                $this->client,
                $pmsUnitId,
                $tenantId,
                $this->role,
                $channel,
                $subject,
                $messageBody
            );
        } catch (\PDOException $e) {
            $this->fail('Message send failed', $e);
        }

        // Email delivery (best effort).
        if (($channel === 'email' || $channel === 'both') && $tenant !== null && !empty($tenant['email'])) {
            try {
                EmailHelper::genericMessage(
                    (string) $tenant['email'],
                    (string) $tenant['full_name'],
                    $subject,
                    $messageBody
                );
            } catch (\Throwable $e) {
                Logger::error('Message email failed: ' . $e->getMessage());
            }
        }

        // In-app notification.
        if (($channel === 'app' || $channel === 'both') && $tenantId !== null) {
            try {
                (new Notification($this->global))->create(
                    $this->client,
                    $tenantId,
                    'tenant',
                    'message',
                    $subject,
                    $messageBody
                );
            } catch (\Throwable $e) {
                Logger::error('Message notification failed: ' . $e->getMessage());
            }
        }

        ResponseHelper::raw(['success' => true, 'message' => 'Message sent via ' . $channel . '.'], 200);
    }

    public function getMessages(): never
    {
        try {
            $rows = (new Message($this->global))->listByAdmin($this->client);
            ResponseHelper::raw(['success' => true, 'data' => $rows], 200);
        } catch (\PDOException $e) {
            $this->fail('Message list failed', $e);
        }
    }

    // -----------------------------------------------------------------------
    // Withdrawals
    // -----------------------------------------------------------------------
    public function requestWithdrawal(): never
    {
        $body = ValidationHelper::jsonBody();
        $amount = ValidationHelper::requireAmount($body);
        $bankDetails = ValidationHelper::sanitizeString($body['bank_account'] ?? ($body['bank_details'] ?? ''), 500);
        $notes = ValidationHelper::sanitizeString($body['notes'] ?? '', 1000);

        $client = (new Client($this->global))->findById($this->clientId);
        if ($client === null) {
            ResponseHelper::notFound('Client not found.');
        }
        $balance = (float) $client['balance'];
        if ($amount > $balance) {
            ResponseHelper::unprocessable('Requested amount exceeds your current balance.');
        }

        $serviceFeePct = (float) $client['service_fee_pct'];
        $serviceFee = round($amount * ($serviceFeePct / 100), 2);
        $amountDisbursed = round($amount - $serviceFee, 2);

        try {
            $withdrawalId = (new Withdrawal($this->global))->create(
                $this->clientId,
                $amount,
                $serviceFee,
                $amountDisbursed,
                $bankDetails,
                $notes
            );
        } catch (\PDOException $e) {
            $this->fail('Withdrawal request failed', $e);
        }

        AuditHelper::logAction(
            $this->client,
            $this->ctx['user_id'],
            $this->role,
            'withdrawal.request',
            'withdrawal',
            (string) $withdrawalId,
            null,
            ['amount' => $amount, 'service_fee' => $serviceFee]
        );

        ResponseHelper::raw([
            'success'          => true,
            'message'          => 'Withdrawal request submitted for super admin review.',
            'withdrawal_id'    => $withdrawalId,
            'service_fee'      => $serviceFee,
            'amount_disbursed' => $amountDisbursed,
        ], 201);
    }

    public function getWithdrawals(): never
    {
        try {
            $rows = (new Withdrawal($this->global))->listByClient($this->clientId);
            ResponseHelper::raw(['success' => true, 'data' => $rows], 200);
        } catch (\PDOException $e) {
            $this->fail('Withdrawal list failed', $e);
        }
    }

    // -----------------------------------------------------------------------
    // Notifications
    // -----------------------------------------------------------------------
    public function getNotifications(): never
    {
        try {
            $rows = (new Notification($this->global))->listUnread($this->client, $this->clientId, 'admin');
            ResponseHelper::raw(['success' => true, 'data' => $rows], 200);
        } catch (\PDOException $e) {
            $this->fail('Notification list failed', $e);
        }
    }

    public function markNotificationRead(array $params): never
    {
        $id = (int) ($params[0] ?? 0);
        try {
            $ok = (new Notification($this->global))->markRead($this->client, $id, $this->clientId, 'admin');
        } catch (\PDOException $e) {
            $this->fail('Notification update failed', $e);
        }
        if (!$ok) {
            ResponseHelper::notFound('Notification not found.');
        }
        ResponseHelper::success(null, 'Notification marked as read.');
    }

    private function fail(string $context, \Throwable $e): never
    {
        Logger::error($context . ': ' . $e->getMessage());
        ResponseHelper::serverError();
    }
}
