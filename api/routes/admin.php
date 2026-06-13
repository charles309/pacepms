<?php

declare(strict_types=1);

/**
 * Admin (landlord / manager) routes.
 *
 * @var string             $method
 * @var array<int, string> $segments
 */

require_once __DIR__ . '/../helpers/ValidationHelper.php';
require_once __DIR__ . '/../helpers/AuditHelper.php';
require_once __DIR__ . '/../helpers/EmailHelper.php';
require_once __DIR__ . '/../helpers/PayHeroHelper.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/IdorGuard.php';
require_once __DIR__ . '/../models/Client.php';
require_once __DIR__ . '/../models/Property.php';
require_once __DIR__ . '/../models/Unit.php';
require_once __DIR__ . '/../models/Tenant.php';
require_once __DIR__ . '/../models/Payment.php';
require_once __DIR__ . '/../models/Withdrawal.php';
require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/Message.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../models/Blacklist.php';
require_once __DIR__ . '/../controllers/AdminController.php';

$ctx = AuthMiddleware::requireRole(['landlord', 'manager']);

$resource = $segments[1] ?? '';
$arg1 = $segments[2] ?? null;     // id or sub-resource
$sub = $segments[3] ?? null;      // sub-action (status, history, qr, reply, read)

$controller = new AdminController($ctx);

match (true) {
    $resource === 'dashboard' && $method === 'GET' => $controller->dashboard(),

    // Properties
    $resource === 'properties' && $arg1 === null && $method === 'GET' => $controller->getProperties(),
    $resource === 'properties' && $arg1 !== null && $method === 'GET' => $controller->getProperty([$arg1]),

    // Units
    $resource === 'units' && $arg1 !== null && $sub === null && $method === 'GET'
        => $controller->getUnit([$arg1]),
    $resource === 'units' && $arg1 !== null && $sub === 'status' && $method === 'PUT'
        => $controller->setUnitStatus([$arg1]),
    $resource === 'units' && $arg1 !== null && $sub === 'history' && $method === 'GET'
        => $controller->getUnitHistory([$arg1]),
    $resource === 'units' && $arg1 !== null && $sub === 'qr' && $method === 'GET'
        => $controller->getUnitQR([$arg1]),

    // Tenants
    $resource === 'tenants' && $arg1 === null && $method === 'POST' => $controller->registerTenant(),
    $resource === 'tenants' && $arg1 === null && $method === 'GET'  => $controller->getTenants(),
    $resource === 'tenants' && $arg1 !== null && $method === 'GET'  => $controller->getTenant([$arg1]),
    $resource === 'tenants' && $arg1 !== null && $method === 'PUT'  => $controller->updateTenant([$arg1]),

    // Complaints
    $resource === 'complaints' && $arg1 === null && $method === 'GET' => $controller->getComplaints(),
    $resource === 'complaints' && $arg1 !== null && $sub === null && $method === 'GET'
        => $controller->getComplaint([$arg1]),
    $resource === 'complaints' && $arg1 !== null && $sub === 'reply' && $method === 'PUT'
        => $controller->replyComplaint([$arg1]),

    // Messages
    $resource === 'messages' && $arg1 === 'send' && $method === 'POST' => $controller->sendMessage(),
    $resource === 'messages' && $arg1 === null && $method === 'GET'    => $controller->getMessages(),

    // Withdrawals
    $resource === 'withdrawals' && $arg1 === 'request' && $method === 'POST' => $controller->requestWithdrawal(),
    $resource === 'withdrawals' && $arg1 === null && $method === 'GET'       => $controller->getWithdrawals(),

    // Notifications
    $resource === 'notifications' && $arg1 === null && $method === 'GET' => $controller->getNotifications(),
    $resource === 'notifications' && $arg1 !== null && $sub === 'read' && $method === 'PUT'
        => $controller->markNotificationRead([$arg1]),

    default => ResponseHelper::notFound('Admin endpoint not found.'),
};
