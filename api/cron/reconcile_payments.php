<?php

declare(strict_types=1);

/**
 * Cron: daily payment reconciliation report, written to logs/reconcile.log.
 *   0 0 * * * php /var/www/html/api/cron/reconcile_payments.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI only.\n");
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../config/database.php';

$reportFile = LOG_DIR . '/reconcile.log';

try {
    $global = Database::global();

    // Yesterday's window (full day).
    $from = (new DateTimeImmutable('yesterday'))->format('Y-m-d') . ' 00:00:00';
    $to = (new DateTimeImmutable('today'))->format('Y-m-d') . ' 00:00:00';

    $stmt = $global->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN payment_method="mpesa" AND status="success" THEN amount END),0) AS mpesa_total,
            COALESCE(SUM(CASE WHEN payment_method="cash"  AND status="success" THEN amount END),0) AS cash_total,
            COALESCE(SUM(CASE WHEN status="success" THEN service_fee END),0) AS service_fees,
            SUM(status="success") AS success_count,
            SUM(status="pending") AS pending_count,
            SUM(status="failed")  AS failed_count,
            COUNT(*) AS total_count
         FROM payments
         WHERE created_at >= :from AND created_at < :to'
    );
    $stmt->execute([':from' => $from, ':to' => $to]);
    $row = $stmt->fetch();

    $platformRevenue = $global->query(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'platform_revenue' LIMIT 1"
    )->fetchColumn();

    $report = sprintf(
        "[%s] RECONCILE %s -> %s | mpesa=%.2f cash=%.2f fees=%.2f success=%d pending=%d failed=%d total=%d cumulative_revenue=%s\n",
        date('Y-m-d H:i:s'),
        $from,
        $to,
        (float) $row['mpesa_total'],
        (float) $row['cash_total'],
        (float) $row['service_fees'],
        (int) $row['success_count'],
        (int) $row['pending_count'],
        (int) $row['failed_count'],
        (int) $row['total_count'],
        $platformRevenue === false ? '0.00' : (string) $platformRevenue
    );

    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0750, true);
    }
    file_put_contents($reportFile, $report, FILE_APPEND | LOCK_EX);
    Logger::info('reconcile_payments: report written.');
    fwrite(STDOUT, $report);
    exit(0);
} catch (\Throwable $e) {
    Logger::error('reconcile_payments failed: ' . $e->getMessage());
    fwrite(STDERR, 'reconcile_payments failed: ' . $e->getMessage() . "\n");
    exit(1);
}
