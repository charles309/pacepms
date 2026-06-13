<?php

declare(strict_types=1);

/**
 * Cron: purge expired auth tokens. Runs every 15 minutes.
 *   *\/15 * * * * php /var/www/html/api/cron/purge_tokens.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI only.\n");
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/TokenHelper.php';

try {
    $deleted = TokenHelper::purgeExpired(Database::global());
    Logger::info("purge_tokens: removed {$deleted} expired token(s).");
    fwrite(STDOUT, "Removed {$deleted} expired token(s).\n");
    exit(0);
} catch (\Throwable $e) {
    Logger::error('purge_tokens failed: ' . $e->getMessage());
    fwrite(STDERR, 'purge_tokens failed: ' . $e->getMessage() . "\n");
    exit(1);
}
