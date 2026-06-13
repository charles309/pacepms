<?php

declare(strict_types=1);

/**
 * Front controller. Bootstraps config, applies global middleware, dispatches to
 * route modules, and converts any uncaught error into a safe JSON response.
 */

require_once __DIR__ . '/config/constants.php';

// Hide internal errors from clients in production; always log them.
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/helpers/Logger.php';
require_once __DIR__ . '/config/cors.php';            // sets headers + handles OPTIONS
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/ResponseHelper.php';
require_once __DIR__ . '/helpers/TokenHelper.php';
require_once __DIR__ . '/middleware/MaintenanceMiddleware.php';

header('Content-Type: application/json; charset=utf-8');

// Convert fatal errors / uncaught exceptions into JSON.
set_exception_handler(static function (\Throwable $e): void {
    Logger::error('Uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'error'   => 'server_error',
        'message' => APP_DEBUG ? $e->getMessage() : 'An internal error occurred.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Logger::error('Fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'server_error',
                'message' => 'An internal error occurred.',
            ], JSON_UNESCAPED_SLASHES);
        }
    }
});

// --- Parse request ---------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Normalise: strip a leading /api prefix, collapse to "/segment/segment".
$uriPath = preg_replace('#^/api#', '', $uriPath) ?? $uriPath;
$uri = '/' . trim((string) $uriPath, '/');

$segments = $uri === '/' ? [] : explode('/', trim($uri, '/'));
$segments = array_map(static fn ($s) => rawurldecode($s), $segments);
$module = $segments[0] ?? '';

// --- Global middleware -----------------------------------------------------
MaintenanceMiddleware::check($uri);

// --- Dispatch --------------------------------------------------------------
match ($module) {
    'auth'       => require __DIR__ . '/routes/auth.php',
    'superadmin' => require __DIR__ . '/routes/superadmin.php',
    'admin'      => require __DIR__ . '/routes/admin.php',
    'tenant'     => require __DIR__ . '/routes/tenant.php',
    'webhook'    => require __DIR__ . '/routes/webhook.php',
    default      => ResponseHelper::notFound('Endpoint not found.'),
};
