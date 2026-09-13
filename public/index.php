<?php

declare(strict_types=1);

/**
 * Public entry point. All non-file requests are routed here by .htaccess.
 */

$base = getenv('APP_BASE_PATH');
if ($base === false || $base === '') {
    $base = dirname(__DIR__);
}

$handler = require $base . '/app/bootstrap.php';
$handler(
    strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    \App\Support\Http::path()
);
