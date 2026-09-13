<?php

declare(strict_types=1);

/**
 * Application bootstrap: configuration, error handling, database, routing.
 * Called from public/index.php.
 */

use App\Controllers\AdminController;
use App\Controllers\ApiHumanController;
use App\Controllers\ApiQuestionController;
use App\Controllers\EditProfileController;
use App\Controllers\JoinController;
use App\Controllers\PageController;
use App\Controllers\QuestionController;
use App\Controllers\TelegramWebhookController;
use App\Repositories\HumanRepository;
use App\Repositories\QuestionRepository;
use App\Services\HumanService;
use App\Services\QuestionService;
use App\Services\RateLimiter;
use App\Services\TelegramNotifier;
use App\Support\Config;
use App\Support\Database;
use App\Support\Http;
use App\Support\Router;
use App\Support\Seo;

$basePath = getenv('APP_BASE_PATH');
if ($basePath === false || $basePath === '') {
    $basePath = dirname(__DIR__);
}

// Minimal PSR-4 autoloader for the App\ namespace (no Composer required).
spl_autoload_register(function (string $class) use ($basePath): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $basePath . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set('UTC');

$config = new Config($basePath);

$debug = $config->bool('APP_DEBUG');
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

// Top-level exception handler: log a correlation ID, emit a generic error.
set_exception_handler(function (Throwable $e) use ($config, $debug, $basePath): void {
    $correlationId = bin2hex(random_bytes(8));
    error_log(sprintf(
        'askahuman error %s: %s in %s:%d',
        $correlationId,
        $e->getMessage(),
        $debug ? $e->getFile() : '[redacted]',
        $debug ? $e->getLine() : 0
    ));
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "fatal: {$e->getMessage()}\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        Http::securityHeaders($config);
    }
    $isApi = str_starts_with(Http::path(), '/api/');
    if ($isApi) {
        Http::apiError(500, 'internal_error', 'The request could not be processed. Reference: ' . $correlationId . '.');
    } else {
        $title = 'Ошибка сервера';
        $body = 'Сервис не смог обработать запрос. Попробуйте ещё раз позже.'
            . ($debug ? '' : ' Код ошибки: ' . Http::e($correlationId) . '.');
        include $basePath . '/templates/errors/500.php';
    }
    exit;
});

$productionProblems = $config->validateProduction();
if ($productionProblems !== [] && !$debug) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Service is not configured correctly.\n";
    foreach ($productionProblems as $problem) {
        // Problem names contain no secrets.
        echo '- ' . $problem . "\n";
    }
    error_log('askahuman config: ' . implode('; ', $productionProblems));
    exit;
}

$pdo = Database::connect($config);
$pdo->exec("SET time_zone = '+00:00'");

$humans = new HumanRepository($pdo);
$questions = new QuestionRepository($pdo);
$rateLimiter = new RateLimiter($pdo);
$telegram = new TelegramNotifier($config);
$questionService = new QuestionService($pdo, $questions, $humans, $rateLimiter, $telegram, $config);
$humanService = new HumanService($humans, $rateLimiter, $telegram, $config);
$seo = new Seo($config);

$pages = new PageController($config, $seo, $humans, $questions, $basePath);
$htmlQuestions = new QuestionController($config, $seo, $questionService, $questions, $humans, $basePath);
$api = new ApiQuestionController($config, $questionService, $questions, $humans, $basePath);
$join = new JoinController($config, $seo, $humanService, $humans, $basePath);
$apiHumans = new ApiHumanController($config, $humanService, $basePath);
$editProfile = new EditProfileController($config, $seo, $humanService, $humans, $rateLimiter, $basePath);
$admin = new AdminController($config, $seo, $questions, $humans, $rateLimiter, $questionService, $humanService, $basePath);

$router = new Router();

// Public HTML
$router->get('/', [$pages, 'home']);
$router->post('/questions', [$htmlQuestions, 'store']);
$router->get('/questions', [$pages, 'archive']);
$router->get('/q/{public_id:[0-9a-f]{32}}/{slug}', [$htmlQuestions, 'show']); // legacy → 301 /q/{slug}
$router->get('/q/{public_id:[0-9a-f]{32}}', [$htmlQuestions, 'show']); // tracking → 301 /q/{slug}
$router->get('/q/{slug:[^/]+}', [$htmlQuestions, 'show']); // canonical
$router->get('/humans', [$pages, 'humans']);
$router->get('/humans/{slug}', [$pages, 'human']); // legacy → 301 /{slug}
$router->get('/connect', [$pages, 'connect']);
$router->get('/join', [$join, 'form']);
$router->post('/join', [$join, 'store']);
$router->get('/join/{public_id:[0-9a-f]{32}}', [$join, 'status']);
$router->get('/edit/{token:[0-9a-f]{32}}', [$editProfile, 'form']);
$router->post('/edit/{token:[0-9a-f]{32}}', [$editProfile, 'save']);
$router->get('/for-agents', [$pages, 'forAgents']);
$router->get('/privacy', [$pages, 'privacy']);
$router->get('/openapi.yaml', [$pages, 'openapi']);
$router->get('/llms.txt', [$pages, 'llmsTxt']);
$router->get('/sitemap.xml', [$pages, 'sitemap']);
$router->get('/robots.txt', [$pages, 'robots']);

// JSON API
$router->add('OPTIONS', '/api/questions', [$api, 'options']);
$router->add('OPTIONS', '/api/questions/{public_id:[0-9a-f]{32}}', [$api, 'options']);
$router->post('/api/questions', [$api, 'store']);
$router->get('/api/questions/{public_id:[0-9a-f]{32}}', [$api, 'show']);
$router->add('OPTIONS', '/api/humans', [$apiHumans, 'options']);
$router->post('/api/humans', [$apiHumans, 'store']);
$router->add('OPTIONS', '/api/humans/{slug}/questions', [$api, 'options']);
$router->post('/api/humans/{slug}/questions', [$api, 'store']);

// Telegram webhook (answers directly from Telegram replies)
$telegramWebhook = new TelegramWebhookController($config, $questions, $questionService, $telegram, $humanService, $rateLimiter);
$router->post('/telegram/webhook', [$telegramWebhook, 'handle']);

// Admin
$router->get('/admin/login', [$admin, 'loginForm']);
$router->post('/admin/login', [$admin, 'login']);
$router->post('/admin/logout', [$admin, 'logout']);
$router->get('/admin', [$admin, 'dashboard']);
$router->get('/admin/questions/{id:\d+}', [$admin, 'review']);
$router->post('/admin/questions/{id:\d+}/answer', [$admin, 'answer']);
$router->post('/admin/questions/{id:\d+}/decline', [$admin, 'decline']);
$router->post('/admin/questions/{id:\d+}/hide', [$admin, 'hide']);
$router->get('/admin/humans', [$admin, 'humansList']);
$router->get('/admin/humans/{id:\d+}', [$admin, 'humanReview']);
$router->post('/admin/humans/{id:\d+}/update', [$admin, 'humanUpdate']);
$router->post('/admin/humans/{id:\d+}/approve', [$admin, 'humanApprove']);
$router->post('/admin/humans/{id:\d+}/decline', [$admin, 'humanDecline']);
$router->post('/admin/humans/{id:\d+}/hide', [$admin, 'humanHide']);

// Human endpoints: root-level /{slug} — MUST stay registered last so exact
// routes above always win. Unknown slugs produce a real 404 inside.
$router->get('/{slug}', [$pages, 'humanEndpoint']);

// Shared view rendering helper for controllers/templates.
require_once $basePath . '/app/render.php';

return static function (string $method, string $path) use ($router, $config, $basePath): void {
    Http::securityHeaders($config);
    $match = $router->dispatch($method, $path);

    if (isset($match['not_found'])) {
        $isApi = str_starts_with($path, '/api/');
        http_response_code(404);
        if ($isApi) {
            Http::header('Cache-Control', 'no-store');
            Http::apiError(404, 'not_found', 'No public question is available for that tracking ID.');
        } else {
            include $basePath . '/templates/errors/404.php';
        }
        return;
    }
    if (isset($match['method_not_allowed'])) {
        header('Allow: ' . $match['allow']);
        http_response_code(405);
        if (str_starts_with($path, '/api/')) {
            Http::apiError(405, 'method_not_allowed', 'This method is not supported for the requested resource.');
        } else {
            echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Метод не поддерживается</title></head>'
                . '<body><h1>405 — метод не поддерживается</h1></body></html>';
        }
        return;
    }

    ($match['handler'])($match['params']);
};
