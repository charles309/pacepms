<?php

declare(strict_types=1);

/**
 * PayHero (M-Pesa) integration: STK push, callback validation, reference parsing.
 */
final class PayHeroHelper
{
    private function __construct()
    {
    }

    private static function authHeader(): string
    {
        return 'Basic ' . base64_encode(PAYHERO_USERNAME . ':' . PAYHERO_PASSWORD);
    }

    /**
     * Build the external_reference for a payment: PMS-{pms_unit_id}-{timestamp}.
     * Since pms_unit_id is already e.g. "PMS-UNIT-00198", the result is
     * "PMS-UNIT-00198-1717200000", matching the documented format.
     */
    public static function buildExternalRef(string $pmsUnitId, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        return $pmsUnitId . '-' . $timestamp;
    }

    /**
     * Recover the pms_unit_id from an external reference of the form
     * "PMS-UNIT-00198-1717200000". Returns null if it doesn't match.
     */
    public static function parseExternalRef(string $externalRef): ?string
    {
        if (preg_match('/^(PMS-UNIT-[A-Za-z0-9]+)-\d+$/', $externalRef, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * Initiate an STK push.
     *
     * @return array<string, mixed> Decoded PayHero response merged with http_code.
     */
    public static function stkPush(
        float $amount,
        string $phone,
        string $externalRef,
        string $customerName = '',
        ?string $callbackUrl = null
    ): array {
        $payload = [
            'amount'             => (int) round($amount),
            'phone_number'       => $phone,
            'channel_id'         => PAYHERO_CHANNEL_ID,
            'provider'           => PAYHERO_PROVIDER,
            'external_reference' => $externalRef,
            'customer_name'      => $customerName,
            'callback_url'       => $callbackUrl ?? PAYHERO_CALLBACK_URL,
        ];

        $ch = curl_init(PAYHERO_STK_URL);
        if ($ch === false) {
            return ['success' => false, 'error' => 'curl_init_failed', 'http_code' => 0];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . self::authHeader(),
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::error('PayHero STK push cURL error: ' . $curlErr);
            return ['success' => false, 'error' => 'gateway_unreachable', 'http_code' => $httpCode];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            Logger::error('PayHero STK push returned non-JSON: ' . substr((string) $response, 0, 500));
            return ['success' => false, 'error' => 'invalid_gateway_response', 'http_code' => $httpCode];
        }

        $data['http_code'] = $httpCode;
        return $data;
    }

    /**
     * Validate that the inbound request originates from an allowed PayHero IP.
     * If no allowlist is configured, the check is skipped (logged as a warning).
     */
    public static function validateCallbackSource(string $remoteIp): bool
    {
        $allow = trim((string) PAYHERO_ALLOWED_IPS);
        if ($allow === '') {
            Logger::warning('PayHero callback IP allowlist not configured; accepting ' . $remoteIp);
            return true;
        }

        foreach (array_map('trim', explode(',', $allow)) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                if (self::ipInCidr($remoteIp, $entry)) {
                    return true;
                }
            } elseif ($entry === $remoteIp) {
                return true;
            }
        }

        return false;
    }

    /**
     * IPv4 CIDR match.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $bits = (int) $bits;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        $mask = -1 << (32 - $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
