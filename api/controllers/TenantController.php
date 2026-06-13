<?php

declare(strict_types=1);

/**
 * Tenant portal operations, scoped to the authenticated tenant's unit + client DB.
 */
final class TenantController
{
    private PDO $global;
    private PDO $client;
    private int $tenantId;
    private int $clientId;

    /** @param array{user_id:int, role:string, db_name:?string, client_id:?int, token:string} $ctx */
    public function __construct(private readonly array $ctx)
    {
        $this->global = Database::global();
        if (empty($ctx['db_name']) || $ctx['client_id'] === null) {
            ResponseHelper::forbidden('No client database bound to this session.');
        }
        $this->tenantId = (int) $ctx['user_id'];
        $this->clientId = (int) $ctx['client_id'];
        try {
            $this->client = Database::client((string) $ctx['db_name']);
        } catch (\PDOException $e) {
            Logger::error('Tenant DB connect failed: ' . $e->getMessage());
            ResponseHelper::serverError();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadTenant(): array
    {
        $tenant = (new Tenant($this->global))->findById($this->client, $this->tenantId);
        if ($tenant === null || (int) $tenant['is_active'] !== 1) {
            ResponseHelper::unauthorized('Tenant account is no longer active.');
        }
        return $tenant;
    }

    public function getUnit(): never
    {
        $tenant = $this->loadTenant();
        $pmsUnitId = (string) $tenant['pms_unit_id'];

        try {
            $unit = (new Unit($this->global))->findLocal($this->client, $pmsUnitId);
            if ($unit === null) {
                ResponseHelper::notFound('Unit not found.');
            }
            $property = (new Property($this->global))->findLocal($this->client, (string) $unit['pms_property_id']);
            $latest = (new Payment($this->global))->latestForUnit($this->client, $pmsUnitId);

            ResponseHelper::raw([
                'success' => true,
                'unit'    => [
                    'pms_unit_id'    => $unit['pms_unit_id'],
                    'unit_number'    => $unit['unit_number'],
                    'property_name'  => $property['name'] ?? null,
                    'rent_amount'    => (float) $unit['rent_amount'],
                    'status'         => $unit['status'],
                    'latest_payment' => $latest,
                ],
            ], 200);
        } catch (\PDOException $e) {
            $this->fail('Tenant unit fetch failed', $e);
        }
    }

    public function getPaymentHistory(): never
    {
        $tenant = $this->loadTenant();
        try {
            $history = (new Payment($this->global))->historyByNationalId(
                $this->client,
                (string) $tenant['national_id']
            );
            ResponseHelper::raw(['success' => true, 'data' => $history], 200);
        } catch (\PDOException $e) {
            $this->fail('Tenant history failed', $e);
        }
    }

    /**
     * POST /tenant/payments/pay — initiate STK push.
     */
    public function initiatePayment(): never
    {
        $tenant = $this->loadTenant();
        $pmsUnitId = (string) $tenant['pms_unit_id'];

        $body = ValidationHelper::jsonBody();
        $amount = ValidationHelper::requireAmount($body);
        $phone = ValidationHelper::sanitizeString($body['phone_number'] ?? '', 20);
        if (!ValidationHelper::validatePhone($phone)) {
            ResponseHelper::badRequest('A valid phone_number is required.');
        }
        $phone = ValidationHelper::normalizePhone($phone);

        $unit = (new Unit($this->global))->findLocal($this->client, $pmsUnitId);
        if ($unit === null) {
            ResponseHelper::notFound('Unit not found.');
        }
        $rent = (float) $unit['rent_amount'];
        if (abs($amount - $rent) > 0.001) {
            ResponseHelper::unprocessable('Amount must match the unit rent of KES ' . number_format($rent, 2) . '.');
        }

        $externalRef = PayHeroHelper::buildExternalRef($pmsUnitId);

        // Call PayHero first; only persist a pending record if it queued.
        $response = PayHeroHelper::stkPush(
            $amount,
            $phone,
            $externalRef,
            (string) $tenant['full_name']
        );

        $queued = ($response['success'] ?? false) === true
            || (isset($response['status']) && strtoupper((string) $response['status']) === 'QUEUED');

        if (!$queued) {
            Logger::error('PayHero STK push not queued: ' . json_encode($response));
            ResponseHelper::error('Payment could not be initiated. Please try again.', 502, 'gateway_error');
        }

        $checkoutId = $response['CheckoutRequestID'] ?? null;

        try {
            (new Payment($this->global))->createPending(
                $pmsUnitId,
                $this->clientId,
                (string) $tenant['national_id'],
                $amount,
                'mpesa',
                $externalRef,
                $checkoutId !== null ? (string) $checkoutId : null,
                'system'
            );
            // Local pending mirror so admin/tenant dashboards reflect it.
            (new Payment($this->global))->insertLocalHistory(
                $this->client,
                0,
                $pmsUnitId,
                (string) $tenant['national_id'],
                $amount,
                0.0,
                'mpesa',
                '',
                'pending'
            );
        } catch (\PDOException $e) {
            // Payment was queued at gateway but local persistence failed; log loudly.
            Logger::error('Pending payment persist failed after STK queue: ' . $e->getMessage());
        }

        ResponseHelper::raw([
            'success'           => true,
            'status'            => 'QUEUED',
            'reference'         => $response['reference'] ?? null,
            'CheckoutRequestID' => $checkoutId,
            'message'           => 'STK push sent. Complete payment on your phone.',
        ], 201);
    }

    public function submitComplaint(): never
    {
        $tenant = $this->loadTenant();
        $body = ValidationHelper::jsonBody();
        $subject = ValidationHelper::requireString($body, 'subject', 255);
        $message = ValidationHelper::requireString($body, 'message', 5000);

        try {
            $complaintId = (new Complaint($this->global))->create(
                $this->client,
                (string) $tenant['pms_unit_id'],
                $this->tenantId,
                $subject,
                $message
            );
        } catch (\PDOException $e) {
            $this->fail('Complaint submission failed', $e);
        }

        // Notify the owning admin (best effort).
        try {
            $client = (new Client($this->global))->findById($this->clientId);
            if ($client !== null && !empty($client['email'])) {
                EmailHelper::complaintReceived((string) $client['email'], (string) $client['name'], $subject);
            }
            (new Notification($this->global))->create(
                $this->client,
                $this->clientId,
                'admin',
                'complaint',
                'New complaint: ' . $subject,
                $message
            );
        } catch (\Throwable $e) {
            Logger::error('Complaint notification failed: ' . $e->getMessage());
        }

        ResponseHelper::raw([
            'success'      => true,
            'message'      => 'Complaint submitted.',
            'complaint_id' => $complaintId,
        ], 201);
    }

    public function getComplaints(): never
    {
        $this->loadTenant();
        try {
            $rows = (new Complaint($this->global))->listForTenant($this->client, $this->tenantId);
            ResponseHelper::raw(['success' => true, 'data' => $rows], 200);
        } catch (\PDOException $e) {
            $this->fail('Tenant complaint list failed', $e);
        }
    }

    public function getMessages(): never
    {
        $this->loadTenant();
        try {
            $rows = (new Message($this->global))->listForTenant($this->client, $this->tenantId);
            ResponseHelper::raw(['success' => true, 'data' => $rows], 200);
        } catch (\PDOException $e) {
            $this->fail('Tenant message list failed', $e);
        }
    }

    public function getNotifications(): never
    {
        $this->loadTenant();
        try {
            $rows = (new Notification($this->global))->listAll($this->client, $this->tenantId, 'tenant');
            ResponseHelper::raw(['success' => true, 'data' => $rows], 200);
        } catch (\PDOException $e) {
            $this->fail('Tenant notification list failed', $e);
        }
    }

    private function fail(string $context, \Throwable $e): never
    {
        Logger::error($context . ': ' . $e->getMessage());
        ResponseHelper::serverError();
    }
}
