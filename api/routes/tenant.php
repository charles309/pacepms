<?php

declare(strict_types=1);

/**
 * Tenant routes (role = tenant).
 *
 * @var string             $method
 * @var array<int, string> $segments
 */

require_once __DIR__ . '/../helpers/ValidationHelper.php';
require_once __DIR__ . '/../helpers/EmailHelper.php';
require_once __DIR__ . '/../helpers/PayHeroHelper.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../models/Client.php';
require_once __DIR__ . '/../models/Unit.php';
require_once __DIR__ . '/../models/Property.php';
require_once __DIR__ . '/../models/Tenant.php';
require_once __DIR__ . '/../models/Payment.php';
require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/Message.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../controllers/TenantController.php';

$ctx = AuthMiddleware::requireRole(['tenant']);

$resource = $segments[1] ?? '';
$arg1 = $segments[2] ?? null;

$controller = new TenantController($ctx);

match (true) {
    $resource === 'unit' && $method === 'GET' => $controller->getUnit(),

    $resource === 'payments' && $arg1 === 'history' && $method === 'GET' => $controller->getPaymentHistory(),
    $resource === 'payments' && $arg1 === 'pay' && $method === 'POST'    => $controller->initiatePayment(),

    $resource === 'complaints' && $method === 'POST' => $controller->submitComplaint(),
    $resource === 'complaints' && $method === 'GET'  => $controller->getComplaints(),

    $resource === 'messages' && $method === 'GET'      => $controller->getMessages(),
    $resource === 'notifications' && $method === 'GET' => $controller->getNotifications(),

    default => ResponseHelper::notFound('Tenant endpoint not found.'),
};
