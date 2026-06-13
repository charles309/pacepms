<?php

declare(strict_types=1);

/**
 * Minimal file logger using the mandated format:
 *   [YYYY-MM-DD HH:MM:SS] [LEVEL] [FILE:LINE] Message
 */
final class Logger
{
    private function __construct()
    {
    }

    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    public static function warning(string $message): void
    {
        self::write('WARN', $message);
    }

    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    private static function write(string $level, string $message): void
    {
        $caller = self::caller();
        $line = sprintf(
            "[%s] [%s] [%s] %s%s",
            date('Y-m-d H:i:s'),
            $level,
            $caller,
            $message,
            PHP_EOL
        );

        $file = defined('ERROR_LOG_FILE') ? ERROR_LOG_FILE : (sys_get_temp_dir() . '/pms_error.log');
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function caller(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        // Index 2 is the original caller (0=caller(),1=write(),2=public method,3=app code).
        $frame = $trace[3] ?? $trace[2] ?? null;
        if ($frame === null || !isset($frame['file'])) {
            return 'unknown:0';
        }
        return basename($frame['file']) . ':' . ($frame['line'] ?? 0);
    }
}
