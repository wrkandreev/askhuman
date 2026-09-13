<?php

declare(strict_types=1);

namespace App\Support;

/**
 * SEO/metadata helpers: JSON-LD, sitemap entries, canonical URLs.
 */
final class Seo
{
    public function __construct(private Config $config)
    {
    }

    public function url(string $path = '/'): string
    {
        return $this->config->baseUrl() . $path;
    }

    /**
     * Build a <script type="application/ld+json"> tag safely via json_encode.
     *
     * @param array<string,mixed> $data
     */
    public function jsonLd(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        if ($json === false) {
            return '';
        }
        // Defensive: json_encode already escapes "</script" as "<\/script"
        // via the solidus escape; belt-and-braces replace any residual.
        $json = str_replace('</script', '<\/script', $json);
        return '<script type="application/ld+json" nonce="' . Http::e($this->config->cspNonce()) . '">' . $json . '</script>';
    }

    public function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
