<?php

declare(strict_types=1);

namespace App\Support;

final class Http
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $path = rawurldecode($path);
        if (strlen($path) > 512) {
            $path = substr($path, 0, 512);
        }
        return $path;
    }

    /**
     * Resolve the client IP honouring TRUSTED_PROXIES only.
     */
    public static function clientIp(Config $config): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $proxies = array_filter(array_map('trim', explode(',', $config->string('TRUSTED_PROXIES'))));
        if ($remote !== '' && in_array($remote, $proxies, true)) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }
        return $remote;
    }

    public static function hmac(string $value, Config $config): string
    {
        return hash_hmac('sha256', $value, $config->string('RATE_LIMIT_SECRET'));
    }

    /**
     * HTML-escape for templates.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function header(string $name, string $value): void
    {
        header($name . ': ' . $value);
    }

    public static function securityHeaders(Config $config): void
    {
        self::header('X-Content-Type-Options', 'nosniff');
        self::header('Referrer-Policy', 'strict-origin-when-cross-origin');
        self::header('X-Frame-Options', 'DENY');
        self::header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        self::header('Content-Security-Policy', $config->csp());
    }

    public static function apiCorsHeaders(): void
    {
        self::header('Access-Control-Allow-Origin', '*');
        self::header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        self::header('Access-Control-Allow-Headers', 'Content-Type, Idempotency-Key');
        self::header('Access-Control-Expose-Headers', 'Location, Retry-After, Idempotency-Replayed');
    }

    /**
     * Emit JSON response.
     *
     * @param array<string,mixed> $data
     */
    public static function json(array $data, int $status = 200, array $headers = []): void
    {
        // Status must be set AFTER a Location header: PHP rewrites an
        // explicit 200 to 302 when header('Location: absolute') is seen and
        // no explicit status has been registered at that point.
        foreach ($headers as $name => $value) {
            self::header($name, $value);
        }
        http_response_code($status);
        self::header('Content-Type', 'application/json; charset=utf-8');
        if (!array_key_exists('Cache-Control', $headers)) {
            self::header('Cache-Control', 'no-store');
        }
        self::apiCorsHeaders();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{"error":{"code":"internal_error","message":"The request could not be processed."}}';
        }
        echo $json;
    }

    /**
     * API error envelope.
     *
     * @param array<string,string>|null $fields
     */
    public static function apiError(int $status, string $code, string $message, ?array $fields = null, array $headers = []): void
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }
        self::json(['error' => $error], $status, $headers);
    }

    public static function redirect(string $path, int $status = 303): void
    {
        http_response_code($status);
        header('Location: ' . $path);
    }

    /**
     * Stateless signed form token: expiry.timestamp '.' nonce '.' hmac.
     */
    public static function issueFormToken(Config $config): string
    {
        $issued = time();
        $nonce = bin2hex(random_bytes(8));
        $payload = $issued . '.' . $nonce;
        return $payload . '.' . hash_hmac('sha256', $payload, $config->string('RATE_LIMIT_SECRET'));
    }

    /**
     * Verify a form token; returns the issued timestamp or null.
     */
    public static function verifyFormToken(string $token, Config $config, int $maxAge = 7200): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$issued, $nonce, $mac] = $parts;
        if (!ctype_digit($issued) || $nonce === '' || !ctype_xdigit($nonce) || strlen($nonce) !== 16) {
            return null;
        }
        $expected = hash_hmac('sha256', $issued . '.' . $nonce, $config->string('RATE_LIMIT_SECRET'));
        if (!hash_equals($expected, strtolower((string) $mac))) {
            return null;
        }
        $issuedTs = (int) $issued;
        if (time() - $issuedTs > $maxAge || time() < $issuedTs - 60) {
            return null;
        }
        return $issuedTs;
    }

    /**
     * Clean a string for safe UTF-8 storage; null when invalid beyond repair.
     */
    public static function cleanUtf8(string $value): ?string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            if (!mb_check_encoding($value, 'UTF-8')) {
                return null;
            }
        }
        // Strip control characters except tab, LF, CR.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        return $clean === null ? null : $clean;
    }

    public static function isoUtc(?string $datetime): ?string
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        $ts = strtotime($datetime . ' UTC');
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
