<?php

declare(strict_types=1);

/**
 * Super admin routes (role = superadmin).
 *
 * @var string             $method
 * @var array<int, string> $segments
 */

require_once __DIR__ . '/../helpers/ValidationHelper.php';
require_once __DIR__ . '/../helpers/AuditHelper.php';
require_once __DIR__ . '/../helpers/EmailHelper.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/IdorGuard.php';
require_once __DIR__ . '/../models/SuperAdmin.php';
require_once __DIR__ . '/../models/Client.php';
require_once __DIR__ . '/../models/Property.php';
require_once __DIR__ . '/../models/Unit.php';
require_once __DIR__ . '/../models/Payment.php';
require_once __DIR__ . '/../models/Withdrawal.php';
require_once __DIR__ . '/../models/Blacklist.php';
require_once __DIR__ . '/../controllers/SuperAdminController.php';
require_once __DIR__ . '/../controllers/MaintenanceController.php';

$ctx = AuthMiddleware::requireRole(['superadmin']);

$resource = $segments[1] ?? '';
$arg1 = $segments[2] ?? null;     // e.g. {client_id} or {withdrawal_id}
$sub = $segments[3] ?? null;      // e.g. "verify"

$controller = new SuperAdminController($ctx);
$maintenance = new MaintenanceController($ctx);

/** Build the trailing params array for controller methods. */
$params = array_values(array_filter([$arg1], static fn ($v) => $v !== null));

match (true) {
    $resource === 'dashboard' && $method === 'GET' => $controller->dashboard(),

    // Clients
    $resource === 'clients' && $arg1 === null && $method === 'GET'  => $controller->getClients(),
    $resource === 'clients' && $arg1 === null && $method === 'POST' => $controller->createClient(),
    $resource === 'clients' && $arg1 !== null && $method === 'GET'  => $controller->getClient($params),
    $resource === 'clients' && $arg1 !== null && $method === 'PUT'  => $controller->updateClient($params),

    // Properties / units assignment
    $resource === 'properties' && $arg1 === 'assign' && $method === 'POST' => $controller->assignProperty(),
    $resource === 'units' && $arg1 === 'assign' && $method === 'POST'      => $controller->assignUnits(),

    // Withdrawals
    $resource === 'withdrawals' && $arg1 === null && $method === 'GET' => $controller->getWithdrawals(),
    $resource === 'withdrawals' && $arg1 !== null && $sub === 'verify' && $method === 'PUT'
        => $controller->verifyWithdrawal([$arg1]),

    // Payments
    $resource === 'payments' && $arg1 === 'cash' && $method === 'POST'      => $controller->registerCashPayment(),
    $resource === 'payments' && $arg1 === 'reconcile' && $method === 'GET'  => $controller->reconcile(),

    // Blacklist
    $resource === 'blacklist' && $arg1 === null && $method === 'GET'    => $controller->getBlacklist(),
    $resource === 'blacklist' && $arg1 !== null && $method === 'DELETE' => $controller->removeBlacklist($params),

    // Maintenance
    $resource === 'maintenance' && $method === 'GET' => $maintenance->getStatus(),
    $resource === 'maintenance' && $method === 'PUT' => $maintenance->toggle(),

    default => ResponseHelper::notFound('Super admin endpoint not found.'),
};
