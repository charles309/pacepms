<?php

declare(strict_types=1);

/**
 * Cron: daily rent reminders. For each active client DB, find occupied units
 * whose tenant has not paid this month, and email + notify the tenant.
 * Logs each reminder into the messages table.
 *   0 8 * * * php /var/www/html/api/cron/send_reminders.php
 *
 * Note: "rent due in <= 5 days" is approximated using the move-in day-of-month
 * as the billing day; if today is within 5 days of that day and no successful
 * payment exists this month, a reminder is sent.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI only.\n");
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/ValidationHelper.php';
require_once __DIR__ . '/../helpers/EmailHelper.php';
require_once __DIR__ . '/../models/Unit.php';
require_once __DIR__ . '/../models/Tenant.php';
require_once __DIR__ . '/../models/Payment.php';
require_once __DIR__ . '/../models/Message.php';
require_once __DIR__ . '/../models/Notification.php';

$sent = 0;
$errors = 0;

try {
    $global = Database::global();
    $clients = $global->query('SELECT id, db_name FROM clients WHERE is_active = 1')->fetchAll();
} catch (\Throwable $e) {
    Logger::error('send_reminders: client list failed: ' . $e->getMessage());
    fwrite(STDERR, "Failed to list clients.\n");
    exit(1);
}

$paymentModel = new Payment($global);
$messageModel = new Message($global);
$notificationModel = new Notification($global);

$today = new DateTimeImmutable('today');

foreach ($clients as $client) {
    $dbName = (string) $client['db_name'];
    try {
        $clientPdo = Database::client($dbName);
    } catch (\Throwable $e) {
        Logger::error("send_reminders: cannot connect to {$dbName}: " . $e->getMessage());
        $errors++;
        continue;
    }

    try {
        $units = $clientPdo->query(
            'SELECT u.pms_unit_id, u.rent_amount, t.full_name, t.email, t.move_in_date, t.id AS tenant_id
             FROM units u
             JOIN tenants t ON t.pms_unit_id = u.pms_unit_id AND t.is_active = 1
             WHERE u.status = "occupied"'
        )->fetchAll();
    } catch (\Throwable $e) {
        Logger::error("send_reminders: unit query failed for {$dbName}: " . $e->getMessage());
        $errors++;
        continue;
    }

    foreach ($units as $u) {
        try {
            // Skip if already paid this month.
            if ($paymentModel->hasPaidThisMonth($clientPdo, (string) $u['pms_unit_id'])) {
                continue;
            }

            // Determine billing day from move-in date (fallback: 5th).
            $billingDay = 5;
            if (!empty($u['move_in_date'])) {
                $billingDay = (int) (new DateTimeImmutable((string) $u['move_in_date']))->format('j');
            }
            $billingDay = min($billingDay, (int) $today->format('t'));
            $dueDate = $today->setDate(
                (int) $today->format('Y'),
                (int) $today->format('n'),
                $billingDay
            );
            $daysUntilDue = (int) $today->diff($dueDate)->format('%r%a');

            // Send when due within the next 5 days (or overdue this month).
            if ($daysUntilDue > 5) {
                continue;
            }

            $amount = (float) $u['rent_amount'];
            $unitId = (string) $u['pms_unit_id'];
            $dueInfo = $daysUntilDue < 0
                ? 'and is now overdue'
                : 'on ' . $dueDate->format('jS M Y');

            if (!empty($u['email'])) {
                EmailHelper::rentReminder((string) $u['email'], (string) $u['full_name'], $amount, $unitId, $dueInfo);
            }

            $messageModel->create(
                $clientPdo,
                $unitId,
                (int) $u['tenant_id'],
                'landlord',
                'both',
                'Rent Reminder',
                'Your rent of KES ' . number_format($amount, 2) . ' for unit ' . $unitId . ' is due ' . $dueInfo . '.'
            );
            $notificationModel->create(
                $clientPdo,
                (int) $u['tenant_id'],
                'tenant',
                'rent_reminder',
                'Rent Reminder',
                'Your rent of KES ' . number_format($amount, 2) . ' is due ' . $dueInfo . '.'
            );

            $sent++;
        } catch (\Throwable $e) {
            Logger::error('send_reminders: reminder failed for unit ' . ($u['pms_unit_id'] ?? '?') . ': ' . $e->getMessage());
            $errors++;
        }
    }
}

Logger::info("send_reminders: sent={$sent} errors={$errors}");
fwrite(STDOUT, "Reminders sent: {$sent}; errors: {$errors}\n");
exit(0);
