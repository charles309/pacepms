<?php

declare(strict_types=1);

/**
 * Webhook routes (no auth token — secured by IP allowlist in the controller).
 *   POST /webhook/payhero/callback
 *
 * @var string             $method
 * @var array<int, string> $segments
 */

require_once __DIR__ . '/../helpers/ValidationHelper.php';
require_once __DIR__ . '/../helpers/PayHeroHelper.php';
require_once __DIR__ . '/../helpers/EmailHelper.php';
require_once __DIR__ . '/../models/Client.php';
require_once __DIR__ . '/../models/Payment.php';
require_once __DIR__ . '/../models/Tenant.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../controllers/PaymentController.php';

$provider = $segments[1] ?? '';
$action = $segments[2] ?? '';

$controller = new PaymentController();

match (true) {
    $provider === 'payhero' && $action === 'callback' && $method === 'POST'
        => $controller->handlePayHeroCallback(),
    default => ResponseHelper::notFound('Webhook endpoint not found.'),
};
