<?php

declare(strict_types=1);

/**
 * CORS + security headers. Included at the very top of the front controller so
 * every response carries the hardening headers and OPTIONS preflight is handled
 * before any routing occurs.
 */

// --- CORS -----------------------------------------------------------------
$allowedOrigin = defined('CORS_ALLOWED_ORIGIN') ? CORS_ALLOWED_ORIGIN : 'https://yourdomain.com';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Echo back the configured origin only (never a wildcard in production).
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

// --- Security headers ------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header("Content-Security-Policy: default-src 'self'");
header('Referrer-Policy: no-referrer');
header_remove('X-Powered-By');

// --- Preflight -------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
