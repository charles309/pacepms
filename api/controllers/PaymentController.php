<?php

declare(strict_types=1);

/**
 * PayHero webhook callback processing. Idempotent: a second callback for an
 * already-settled reference does nothing.
 */
final class PaymentController
{
    public function handlePayHeroCallback(): never
    {
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!PayHeroHelper::validateCallbackSource($remoteIp)) {
            Logger::warning('Rejected PayHero callback from unauthorized IP: ' . $remoteIp);
            ResponseHelper::forbidden('Callback source not allowed.');
        }

        $raw = file_get_contents('php://input');
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || !isset($data['response']) || !is_array($data['response'])) {
            Logger::warning('Invalid PayHero callback payload: ' . substr((string) $raw, 0, 500));
            ResponseHelper::raw(['success' => false, 'error' => 'invalid_payload'], 400);
        }

        $response = $data['response'];
        $resultCode = (int) ($response['ResultCode'] ?? -1);
        $extRef = ValidationHelper::sanitizeString($response['ExternalReference'] ?? '', 100);
        $receipt = ValidationHelper::sanitizeString($response['MpesaReceiptNumber'] ?? '', 50);
        $callbackAmount = isset($response['Amount']) ? (float) $response['Amount'] : 0.0;
        $status = ($resultCode === 0) ? 'success' : 'failed';

        if ($extRef === '') {
            ResponseHelper::raw(['success' => false, 'error' => 'missing_reference'], 400);
        }

        $globalPdo = Database::global();
        $paymentModel = new Payment($globalPdo);

        $payment = $paymentModel->findByExternalReference($extRef);
        if ($payment === null) {
            Logger::warning('PayHero callback for unknown reference: ' . $extRef);
            // Acknowledge with 200 to stop retries for an unknown ref.
            ResponseHelper::raw(['success' => true, 'message' => 'No matching record.'], 200);
        }

        // Idempotency: already processed.
        if (in_array($payment['status'], ['success', 'failed'], true)) {
            ResponseHelper::raw(['success' => true, 'message' => 'Already processed.'], 200);
        }

        $clientId = (int) $payment['client_id'];
        $client = (new Client($globalPdo))->findById($clientId);
        if ($client === null) {
            Logger::error('PayHero callback: client not found for payment ' . $payment['id']);
            ResponseHelper::raw(['success' => false, 'error' => 'client_not_found'], 200);
        }

        $serviceFeePct = (float) $client['service_fee_pct'];
        $amount = (float) $payment['amount'];
        $serviceFee = $status === 'success' ? round($amount * ($serviceFeePct / 100), 2) : 0.0;
        $clientReceives = round($amount - $serviceFee, 2);

        try {
            $globalPdo->beginTransaction();

            $paymentModel->markResult((int) $payment['id'], $status, $receipt, $serviceFee);

            if ($status === 'success') {
                (new Client($globalPdo))->adjustBalance($clientId, $clientReceives);
                $this->incrementPlatformRevenue($globalPdo, $serviceFee);
            }

            $globalPdo->commit();
        } catch (\PDOException $e) {
            if ($globalPdo->inTransaction()) {
                $globalPdo->rollBack();
            }
            Logger::error('PayHero callback processing failed: ' . $e->getMessage());
            ResponseHelper::raw(['success' => false, 'error' => 'processing_failed'], 500);
        }

        // Mirror into client DB + receipt email (best effort).
        try {
            $clientPdo = Database::client((string) $client['db_name']);

            // Update the pending local mirror if present, else insert.
            $upd = $clientPdo->prepare(
                'UPDATE payment_history
                 SET status = :status, mpesa_receipt = :receipt, service_fee = :fee, global_payment_id = :gid
                 WHERE pms_unit_id = :uid AND status = "pending" AND payment_method = "mpesa"
                 ORDER BY id DESC LIMIT 1'
            );
            $upd->execute([
                ':status'  => $status,
                ':receipt' => $receipt,
                ':fee'     => $serviceFee,
                ':gid'     => (int) $payment['id'],
                ':uid'     => $payment['pms_unit_id'],
            ]);

            if ($upd->rowCount() === 0) {
                $paymentModel->insertLocalHistory(
                    $clientPdo,
                    (int) $payment['id'],
                    (string) $payment['pms_unit_id'],
                    (string) $payment['tenant_national_id'],
                    $amount,
                    $serviceFee,
                    'mpesa',
                    $receipt,
                    $status
                );
            }

            if ($status === 'success') {
                $tenant = (new Tenant($globalPdo))->findByNationalId(
                    $clientPdo,
                    (string) $payment['tenant_national_id']
                );
                if ($tenant !== null && !empty($tenant['email'])) {
                    EmailHelper::rentReceipt(
                        (string) $tenant['email'],
                        (string) $tenant['full_name'],
                        $amount,
                        $receipt,
                        (string) $payment['pms_unit_id'],
                        date('Y-m-d H:i:s')
                    );
                }
                if ($tenant !== null) {
                    (new Notification($globalPdo))->create(
                        $clientPdo,
                        (int) $tenant['id'],
                        'tenant',
                        'payment',
                        'Payment received',
                        'Your rent payment of KES ' . number_format($amount, 2) . ' was received.'
                    );
                }
            }
        } catch (\Throwable $e) {
            Logger::error('PayHero callback mirror/email failed: ' . $e->getMessage());
        }

        ResponseHelper::raw(['success' => true], 200);
    }

    private function incrementPlatformRevenue(PDO $globalPdo, float $delta): void
    {
        $stmt = $globalPdo->prepare(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'platform_revenue' LIMIT 1"
        );
        $stmt->execute();
        $current = (float) ($stmt->fetchColumn() ?: 0);
        $new = round($current + $delta, 2);
        $globalPdo->prepare(
            "UPDATE system_settings SET setting_value = :v WHERE setting_key = 'platform_revenue'"
        )->execute([':v' => number_format($new, 2, '.', '')]);
    }
}
