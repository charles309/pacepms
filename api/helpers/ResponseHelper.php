<?php

declare(strict_types=1);

/**
 * Standardised JSON response output. Every method terminates the request with
 * exit() after echoing the JSON body, so no code runs after a response.
 */
final class ResponseHelper
{
    private function __construct()
    {
    }

    /**
     * Emit a JSON body with the given HTTP status and stop execution.
     *
     * @param array<string, mixed> $payload
     */
    private static function emit(array $payload, int $code): never
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @param mixed $data
     */
    public static function success(mixed $data = null, string $message = 'Success', int $code = 200): never
    {
        self::emit([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    /**
     * Send a raw, fully-formed payload (used where the contract is fixed by the
     * external spec, e.g. login or PayHero responses).
     *
     * @param array<string, mixed> $payload
     */
    public static function raw(array $payload, int $code = 200): never
    {
        self::emit($payload, $code);
    }

    public static function created(mixed $data = null, string $message = 'Created'): never
    {
        self::success($data, $message, 201);
    }

    public static function error(string $message, int $code = 400, string $errorSlug = 'error'): never
    {
        self::emit([
            'success' => false,
            'error'   => $errorSlug,
            'message' => $message,
        ], $code);
    }

    public static function badRequest(string $msg = 'Bad request.'): never
    {
        self::error($msg, 400, 'bad_request');
    }

    public static function unauthorized(string $msg = 'Unauthorized.'): never
    {
        self::error($msg, 401, 'unauthorized');
    }

    public static function forbidden(string $msg = 'Forbidden.'): never
    {
        self::error($msg, 403, 'forbidden');
    }

    public static function notFound(string $msg = 'Not found.'): never
    {
        self::error($msg, 404, 'not_found');
    }

    public static function conflict(string $msg = 'Conflict.'): never
    {
        self::error($msg, 409, 'conflict');
    }

    public static function unprocessable(string $msg = 'Unprocessable entity.'): never
    {
        self::error($msg, 422, 'unprocessable');
    }

    public static function tooManyRequests(string $msg = 'Too many requests.', int $retryAfter = 60): never
    {
        if (!headers_sent()) {
            header('Retry-After: ' . $retryAfter);
        }
        self::error($msg, 429, 'too_many_requests');
    }

    public static function maintenance(string $msg = 'System is under maintenance. Try again later.'): never
    {
        self::error($msg, 503, 'maintenance_mode');
    }

    public static function serverError(string $msg = 'An internal error occurred.'): never
    {
        self::error($msg, 500, 'server_error');
    }
}
