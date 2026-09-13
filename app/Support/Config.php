<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Configuration loader.
 *
 * Priority: real environment variable, then config/local.php, then default.
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $values;

    public function __construct(string $basePath)
    {
        $local = [];
        $localFile = $basePath . '/config/local.php';
        if (is_file($localFile)) {
            /** @psalm-suppress UnresolvableInclude */
            $local = require $localFile;
            if (!is_array($local)) {
                $local = [];
            }
        }
        $this->values = [];
        foreach ($this->defaults() as $key => $default) {
            $env = getenv($key);
            if ($env !== false && $env !== '') {
                $this->values[$key] = $env;
            } elseif (array_key_exists($key, $local) && $local[$key] !== null && $local[$key] !== '') {
                $this->values[$key] = $local[$key];
            } else {
                $this->values[$key] = $default;
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function defaults(): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_NAME' => 'Ask a Human',
            'APP_URL' => '',
            'APP_BASE_PATH' => '',
            'DISPLAY_TIMEZONE' => 'Europe/Moscow',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_NAME' => 'ask_a_human',
            'DB_USER' => 'ask_a_human',
            'DB_PASSWORD' => '',
            'ADMIN_PASSWORD_HASH' => '',
            'RATE_LIMIT_SECRET' => '',
            'CONTACT_EMAIL' => '',
            'TELEGRAM_API_BASE' => '',
            'TELEGRAM_BOT_USERNAME' => '',
            'TELEGRAM_BOT_TOKEN' => '',
            'TELEGRAM_CHAT_ID' => '',
            'TRUSTED_PROXIES' => '',
        ];
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function string(string $key): string
    {
        return (string) $this->values[$key];
    }

    public function bool(string $key): bool
    {
        return in_array(strtolower((string) $this->values[$key]), ['1', 'true', 'yes', 'on'], true);
    }

    public function baseUrl(): string
    {
        return rtrim($this->string('APP_URL'), '/');
    }

    private ?string $cspNonce = null;

    /**
     * Per-response nonce for server-rendered ld+json only.
     */
    public function cspNonce(): string
    {
        if ($this->cspNonce === null) {
            $this->cspNonce = bin2hex(random_bytes(16));
        }
        return $this->cspNonce;
    }

    public function csp(): string
    {
        return "default-src 'self'; style-src 'self'; img-src 'self' data:; "
            . "script-src 'self' 'nonce-" . $this->cspNonce() . "'; base-uri 'none'; "
            . "frame-ancestors 'none'; form-action 'self'";
    }

    /**
     * Validate production-critical settings. Returns a list of problems.
     *
     * @return list<string>
     */
    public function validateProduction(): array
    {
        $problems = [];
        if ($this->string('APP_ENV') === 'production') {
            if ($this->string('APP_URL') === '') {
                $problems[] = 'APP_URL is required';
            }
            foreach (['DB_NAME', 'DB_USER', 'ADMIN_PASSWORD_HASH', 'RATE_LIMIT_SECRET', 'CONTACT_EMAIL'] as $key) {
                $value = $this->string($key);
                if ($value === '' || str_starts_with($value, 'replace')) {
                    $problems[] = $key . ' is required in production';
                }
            }
            if ($this->string('APP_URL') !== '' && !str_starts_with($this->string('APP_URL'), 'https://')) {
                $problems[] = 'APP_URL must use https in production';
            }
            if (strlen($this->string('RATE_LIMIT_SECRET')) < 32) {
                $problems[] = 'RATE_LIMIT_SECRET must be at least 32 characters';
            }
            if ($this->bool('APP_DEBUG')) {
                $problems[] = 'APP_DEBUG=true is forbidden in production';
            }
        }
        return $problems;
    }
}
