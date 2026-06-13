<?php

declare(strict_types=1);

/**
 * Input validation + sanitization. Validation failures call ResponseHelper and
 * terminate the request with a 400, so controllers can rely on clean values.
 */
final class ValidationHelper
{
    private function __construct()
    {
    }

    /**
     * trim + strip_tags + length clamp.
     */
    public static function sanitizeString(mixed $input, int $maxLen = 255): string
    {
        $value = is_scalar($input) ? (string) $input : '';
        $value = trim($value);
        $value = strip_tags($value);
        return mb_substr($value, 0, $maxLen);
    }

    public static function validatePhone(string $phone): bool
    {
        return (bool) preg_match('/^(?:\+254|0)7[0-9]{8}$/', $phone);
    }

    /**
     * Normalise a Kenyan MSISDN to the 07XXXXXXXX local format.
     */
    public static function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if (str_starts_with($phone, '+254')) {
            return '0' . substr($phone, 4);
        }
        if (str_starts_with($phone, '254')) {
            return '0' . substr($phone, 3);
        }
        return $phone;
    }

    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validateAmount(mixed $amount): bool
    {
        return is_numeric($amount) && (float) $amount > 0;
    }

    public static function validateEnum(mixed $value, array $allowed): bool
    {
        return is_scalar($value) && in_array((string) $value, $allowed, true);
    }

    /**
     * Validate a Y-m-d date string.
     */
    public static function validateDate(string $date, string $format = 'Y-m-d'): bool
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d !== false && $d->format($format) === $date;
    }

    public static function validateNationalId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9\-]{4,50}$/', $id);
    }

    // -----------------------------------------------------------------------
    // Strict "require" helpers — emit a 400 and exit on failure.
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    public static function requireString(array $body, string $key, int $maxLen = 255, int $minLen = 1): string
    {
        if (!isset($body[$key]) || !is_scalar($body[$key])) {
            ResponseHelper::badRequest("Missing required field: {$key}.");
        }
        $value = self::sanitizeString($body[$key], $maxLen);
        if (mb_strlen($value) < $minLen) {
            ResponseHelper::badRequest("Field '{$key}' must be at least {$minLen} character(s).");
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function requireEmail(array $body, string $key = 'email'): string
    {
        $value = self::sanitizeString($body[$key] ?? '', 150);
        if (!self::validateEmail($value)) {
            ResponseHelper::badRequest("Field '{$key}' must be a valid email address.");
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function requirePhone(array $body, string $key = 'phone'): string
    {
        $value = self::sanitizeString($body[$key] ?? '', 20);
        if (!self::validatePhone($value)) {
            ResponseHelper::badRequest("Field '{$key}' must be a valid Kenyan phone number.");
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function requireAmount(array $body, string $key = 'amount'): float
    {
        if (!isset($body[$key]) || !self::validateAmount($body[$key])) {
            ResponseHelper::badRequest("Field '{$key}' must be a positive number.");
        }
        return round((float) $body[$key], 2);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<int, string>   $allowed
     */
    public static function requireEnum(array $body, string $key, array $allowed): string
    {
        $value = self::sanitizeString($body[$key] ?? '', 50);
        if (!self::validateEnum($value, $allowed)) {
            ResponseHelper::badRequest("Field '{$key}' must be one of: " . implode(', ', $allowed) . '.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function requireDate(array $body, string $key): string
    {
        $value = self::sanitizeString($body[$key] ?? '', 10);
        if (!self::validateDate($value)) {
            ResponseHelper::badRequest("Field '{$key}' must be a valid date (YYYY-MM-DD).");
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function requireNationalId(array $body, string $key = 'national_id'): string
    {
        $value = self::sanitizeString($body[$key] ?? '', 50);
        if (!self::validateNationalId($value)) {
            ResponseHelper::badRequest("Field '{$key}' must be a valid national ID.");
        }
        return $value;
    }

    /**
     * Decode the JSON request body into an array (or empty array).
     *
     * @return array<string, mixed>
     */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            ResponseHelper::badRequest('Request body must be valid JSON.');
        }
        return $decoded;
    }
}
