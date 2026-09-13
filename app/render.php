<?php

declare(strict_types=1);

namespace App;

use App\Support\Http;

/**
 * Render a template inside the public layout.
 *
 * The content template runs first (in output buffering): it may set
 * $title/$description/$canonical/$robots/$extraHead for the layout head,
 * and anything it echoes becomes the page body.
 *
 * @param array<string,mixed> $vars
 */
function render(string $basePath, string $template, array $vars = [], string $layout = 'layouts/layout.php'): void
{
    $vars['basePath'] = $basePath;
    extract($vars, EXTR_SKIP);
    ob_start();
    try {
        include $basePath . '/templates/' . $template;
        $contentHtml = ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    include $basePath . '/templates/' . $layout;
}

/**
 * Escape helper available inside templates.
 */
function e(mixed $value): string
{
    return Http::e($value);
}

/**
 * Render plain-text paragraphs: escaped, then nl2br.
 */
function paragraphs(mixed $value): string
{
    return nl2br(Http::e($value));
}

/**
 * <time> element with display timezone and ISO datetime.
 */
function time_el(?string $datetimeUtc, string $displayTimezone, string $dateFormat = 'M j, Y, H:i'): string
{
    $iso = Http::isoUtc($datetimeUtc);
    if ($iso === null) {
        return '';
    }
    $ts = strtotime($iso);
    $display = gmdate($dateFormat, (int) $ts);
    if ($displayTimezone !== 'UTC') {
        $local = (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone($displayTimezone));
        $display = $local->format($dateFormat) . ' (' . $displayTimezone . ')';
    }
    return '<time datetime="' . e($iso) . '">' . e($display) . '</time>';
}
