<?php

declare(strict_types=1);

/**
 * Auth routes. Expects $method (string) and $segments (array) from index.php.
 *   POST /auth/login
 *   POST /auth/logout
 *
 * @var string             $method
 * @var array<int, string> $segments
 */

require_once __DIR__ . '/../helpers/ValidationHelper.php';
require_once __DIR__ . '/../middleware/RateLimiter.php';
require_once __DIR__ . '/../models/SuperAdmin.php';
require_once __DIR__ . '/../models/Client.php';
require_once __DIR__ . '/../models/Tenant.php';
require_once __DIR__ . '/../controllers/AuthController.php';

$action = $segments[1] ?? '';
$controller = new AuthController();

match (true) {
    $method === 'POST' && $action === 'login'  => $controller->login(),
    $method === 'POST' && $action === 'logout' => $controller->logout(),
    default => ResponseHelper::notFound('Auth endpoint not found.'),
};
