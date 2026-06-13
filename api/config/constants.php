<?php

declare(strict_types=1);

/**
 * Application constants and environment loading.
 *
 * Loads the .env file (if vlucas/phpdotenv is available) and defines every
 * constant the application relies on. Secrets are NEVER hard-coded here; they
 * are read from the environment. Safe, non-secret defaults are provided so the
 * application degrades gracefully when an optional variable is missing.
 */

// ---------------------------------------------------------------------------
// Composer autoload + .env loading
// ---------------------------------------------------------------------------
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$envDir = dirname(__DIR__);
if (is_file($envDir . '/.env') && class_exists(\Dotenv\Dotenv::class)) {
    try {
        $dotenv = \Dotenv\Dotenv::createImmutable($envDir);
        $dotenv->safeLoad();
    } catch (\Throwable $e) {
        // Never fatally crash on env parsing; fall through to getenv() defaults.
        error_log('[constants] .env load failed: ' . $e->getMessage());
    }
}

/**
 * Read an environment variable from $_ENV, $_SERVER or getenv() with a default.
 */
function env_get(string $key, ?string $default = null): ?string
{
    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER)) {
        return (string) $_SERVER[$key];
    }
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    return $default;
}

// ---------------------------------------------------------------------------
// Environment / runtime
// ---------------------------------------------------------------------------
define('APP_ENV', env_get('APP_ENV', 'production'));
define('APP_DEBUG', filter_var(env_get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));
define('APP_BASE_PATH', dirname(__DIR__));
define('LOG_DIR', APP_BASE_PATH . '/logs');
define('ERROR_LOG_FILE', LOG_DIR . '/error.log');
define('ACCESS_LOG_FILE', LOG_DIR . '/access.log');

// ---------------------------------------------------------------------------
// Database — API (least-privilege) connection
// ---------------------------------------------------------------------------
define('DB_HOST', env_get('DB_HOST', 'localhost'));
define('DB_PORT', (int) env_get('DB_PORT', '3306'));
define('DB_USER', env_get('DB_USER', 'pms_user'));
define('DB_PASS', env_get('DB_PASS', ''));
define('DB_GLOBAL_NAME', env_get('DB_GLOBAL_NAME', 'pms_global'));

// ---------------------------------------------------------------------------
// Database — privileged (provisioning) connection used only by createClient()
// ---------------------------------------------------------------------------
define('DB_ROOT_USER', env_get('DB_ROOT_USER', 'pms_provisioner'));
define('DB_ROOT_PASS', env_get('DB_ROOT_PASS', ''));

// ---------------------------------------------------------------------------
// Client database naming
// ---------------------------------------------------------------------------
define('CLIENT_DB_PREFIX', env_get('CLIENT_DB_PREFIX', 'pms_client_'));
define('CLIENT_TEMPLATE_SQL', APP_BASE_PATH . '/setup/client_template.sql');

// ---------------------------------------------------------------------------
// Tokens
// ---------------------------------------------------------------------------
define('TOKEN_TTL_SECONDS', (int) env_get('TOKEN_TTL_SECONDS', '3600'));
define('TOKEN_BYTES', 32); // 32 bytes => 64 hex chars

// ---------------------------------------------------------------------------
// Passwords
// ---------------------------------------------------------------------------
define('BCRYPT_COST', (int) env_get('BCRYPT_COST', '12'));

// ---------------------------------------------------------------------------
// Service fee / business
// ---------------------------------------------------------------------------
define('DEFAULT_SERVICE_FEE', (float) env_get('DEFAULT_SERVICE_FEE', '5.0')); // percent

// ---------------------------------------------------------------------------
// Rate limiting (login)
// ---------------------------------------------------------------------------
define('LOGIN_RATE_LIMIT', (int) env_get('LOGIN_RATE_LIMIT', '5'));
define('LOGIN_RATE_WINDOW', (int) env_get('LOGIN_RATE_WINDOW', '60')); // seconds

// ---------------------------------------------------------------------------
// PayHero
// ---------------------------------------------------------------------------
define('PAYHERO_USERNAME', env_get('PAYHERO_USERNAME', ''));
define('PAYHERO_PASSWORD', env_get('PAYHERO_PASSWORD', ''));
define('PAYHERO_CHANNEL_ID', (int) env_get('PAYHERO_CHANNEL_ID', '0'));
define('PAYHERO_PROVIDER', env_get('PAYHERO_PROVIDER', 'm-pesa'));
define('PAYHERO_STK_URL', env_get('PAYHERO_STK_URL', 'https://backend.payhero.co.ke/api/v2/payments?is_active=true'));
define('PAYHERO_CALLBACK_URL', env_get('PAYHERO_CALLBACK_URL', 'https://yourdomain.com/api/webhook/payhero/callback'));

/**
 * Comma-separated list of IPs/CIDRs allowed to call the PayHero callback.
 * Empty string disables the allowlist (NOT recommended in production).
 */
define('PAYHERO_ALLOWED_IPS', env_get('PAYHERO_ALLOWED_IPS', ''));

// ---------------------------------------------------------------------------
// SMTP / mail
// ---------------------------------------------------------------------------
define('SMTP_HOST', env_get('SMTP_HOST', 'smtp.example.com'));
define('SMTP_PORT', (int) env_get('SMTP_PORT', '587'));
define('SMTP_USER', env_get('SMTP_USER', ''));
define('SMTP_PASS', env_get('SMTP_PASS', ''));
define('SMTP_SECURE', env_get('SMTP_SECURE', 'tls'));
define('MAIL_FROM', env_get('MAIL_FROM', 'noreply@pms.co.ke'));
define('MAIL_FROM_NAME', env_get('MAIL_FROM_NAME', 'PMS Notifications'));

// ---------------------------------------------------------------------------
// CORS / public URLs
// ---------------------------------------------------------------------------
define('CORS_ALLOWED_ORIGIN', env_get('CORS_ALLOWED_ORIGIN', 'https://yourdomain.com'));
define('PUBLIC_BASE_URL', env_get('PUBLIC_BASE_URL', 'https://yourdomain.com'));
define('PAYMENT_PAGE_URL', env_get('PAYMENT_PAGE_URL', PUBLIC_BASE_URL . '/pay'));

// ---------------------------------------------------------------------------
// Ensure log directory exists
// ---------------------------------------------------------------------------
if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0750, true);
}
