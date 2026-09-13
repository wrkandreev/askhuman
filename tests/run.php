<?php

declare(strict_types=1);

/**
 * Dependency-free test harness for locally executable acceptance checks.
 * Run: php tests/run.php
 *
 * Covers pure logic: validation, slug derivation, form tokens, IP resolution,
 * router behaviour, escaping/JSON-LD safety, and config validation.
 * Database-backed and live-Telegram scenarios require a MySQL host; see
 * docs/DEPLOYMENT.md for manual steps.
 */

require __DIR__ . '/../app/Support/Config.php';
require __DIR__ . '/../app/Support/Http.php';
require __DIR__ . '/../app/Support/Router.php';
require __DIR__ . '/../app/Support/Seo.php';
require __DIR__ . '/../app/Support/Translit.php';
require __DIR__ . '/../app/Services/QuestionService.php';

use App\Services\QuestionService;
use App\Support\Config;
use App\Support\Http;
use App\Support\Router;
use App\Support\Seo;

$pass = 0;
$fail = 0;

function check(string $name, bool $condition): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ok  $name\n";
    } else {
        $fail++;
        echo "FAIL  $name\n";
    }
}

function configWith(array $overrides = []): Config
{
    $dir = sys_get_temp_dir() . '/askahuman-test-' . bin2hex(random_bytes(4));
    mkdir($dir . '/config', 0777, true);
    file_put_contents($dir . '/config/local.php', '<?php return ' . var_export($overrides, true) . ';');
    return new Config($dir);
}

$secret = str_repeat('s', 48);
$config = configWith(['RATE_LIMIT_SECRET' => $secret, 'APP_URL' => 'https://example.com']);

echo "== Validation ==\n";
$ref = new ReflectionClass(QuestionService::class);
$svc = $ref->newInstanceWithoutConstructor();
$v = Closure::bind(fn (mixed $q, mixed $c) => $this->validateInput($q, $c), $svc, QuestionService::class);
$slug = Closure::bind(fn (string $q) => $this->makeSlug($q), $svc, QuestionService::class);

try {
    $v('short', null);
    check('19-char question rejected', false);
} catch (\App\Services\DomainException $e) {
    check('19-char question rejected with 422', $e->httpStatus === 422);
    check('question field error present', isset($e->fields['question']));
}
$out = $v(str_repeat('a', 20), null);
check('20-char question accepted', $out['question'] === str_repeat('a', 20));
try {
    $v(str_repeat('a', 4001), null);
    check('4001-char question rejected', false);
} catch (\App\Services\DomainException $e) {
    check('4001-char question rejected', str_contains($e->fields['question'] ?? '', '4,000'));
}
try {
    $v('valid question here ok........', str_repeat('c', 8001));
    check('8001-char context rejected', false);
} catch (\App\Services\DomainException $e) {
    check('8001-char context rejected', isset($e->fields['context']));
}
$out = $v('  valid question with whitespace ', '  ');
check('whitespace-only context becomes null', $out['context'] === null);
check('question trimmed', $out['question'] === 'valid question with whitespace');
try {
    $v(42, null);
    check('non-string question rejected', false);
} catch (\App\Services\DomainException $e) {
    check('non-string question rejected', true);
}
try {
    $v('long enough question here........', ['array']);
    check('non-string context rejected', false);
} catch (\App\Services\DomainException $e) {
    check('non-string context rejected', isset($e->fields['context']));
}
$bad = "aaaaaaaaaaaaaaaaaaaaaa\xB1\x31"; // invalid UTF-8 tail
try {
    $cleaned = $v($bad, null);
    // Safe cleaning is acceptable per spec: no invalid bytes may survive.
    check('invalid UTF-8 question rejected or safely cleaned', !mb_check_encoding($bad, 'UTF-8')
        && mb_check_encoding($cleaned['question'], 'UTF-8'));
} catch (\App\Services\DomainException $e) {
    check('invalid UTF-8 question rejected or safely cleaned', isset($e->fields['question']));
}

echo "== Slug derivation ==\n";
check('basic slug', $slug('What is the best PHP framework?') === 'what-is-the-best-php-framework');
check('punctuation runs collapsed', $slug('Foo...  ---   bar!!!') === 'foo-bar');
check('cyrillic transliterated', $slug('Что такое Nizhny Novgorod?') === 'chto-takoe-nizhny-novgorod');
check('empty fallback', $slug('???') === 'question');
check('length capped', mb_strlen($slug(str_repeat('слово ', 100))) <= 100);
check('leading/trailing dashes trimmed', $slug('---hello world---') === 'hello-world');

echo "== Form tokens ==\n";
$token = Http::issueFormToken($config);
check('valid token verifies', Http::verifyFormToken($token, $config) !== null);
check('tampered token rejected', Http::verifyFormToken(substr_replace($token, 'deadbeef', 0, 8), $config) === null);
check('foreign-secret token rejected', Http::verifyFormToken($token, configWith(['RATE_LIMIT_SECRET' => str_repeat('x', 48)])) === null);
$expired = (time() - 7201) . '.' . bin2hex(random_bytes(8));
$expired .= '.' . hash_hmac('sha256', $expired, $secret);
check('expired token rejected', Http::verifyFormToken($expired, $config) === null);

echo "== IP resolution ==\n";
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
$noTrust = configWith(['RATE_LIMIT_SECRET' => $secret, 'TRUSTED_PROXIES' => '']);
check('untrusted proxy XFF ignored', Http::clientIp($noTrust) === '198.51.100.7');
$trust = configWith(['RATE_LIMIT_SECRET' => $secret, 'TRUSTED_PROXIES' => '198.51.100.7']);
check('trusted proxy XFF honoured', Http::clientIp($trust) === '203.0.113.9');
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, 203.0.113.9';
check('invalid XFF first value ignored', Http::clientIp($trust) === '198.51.100.7');

echo "== Router ==\n";
$router = new Router();
$router->get('/q/{public_id:[0-9a-f]{32}}/{slug}', fn ($p) => 'show:' . $p['public_id']);
$router->post('/questions', fn () => 'store');
$m = $router->dispatch('GET', '/q/' . str_repeat('a', 32) . '/my-slug');
check('route with params matches', ($m['handler'])($m['params']) === 'show:' . str_repeat('a', 32));
$m = $router->dispatch('POST', '/q/' . str_repeat('a', 32) . '/my-slug');
check('method mismatch detected with Allow', isset($m['method_not_allowed']) && $m['allow'] === 'GET');
$m = $router->dispatch('GET', '/q/' . str_repeat('a', 32) . '/my-slug');
$m = $router->dispatch('GET', '/q/notahexid/x');
check('unknown path 404', isset($m['not_found']));
check('non-hex id does not match', $router->dispatch('GET', '/q/zz/x')['not_found'] ?? false);

echo "== Output safety ==\n";
$hostile = '</script><img src=x onerror=alert(1)>"\'&<>';
$esc = Http::e($hostile);
check('htmlspecialchars escapes angle quotes amp', !str_contains($esc, '<') && !str_contains($esc, '"') && str_contains($esc, '&amp;'));
$seo = new Seo($config);
$ld = $seo->jsonLd(['x' => $hostile]);
check('jsonLd neutralizes </script>', str_contains($ld, '<\/script') || !str_contains($ld, '</script'));
check('jsonLd carries csp nonce', str_contains($ld, 'nonce='));
check('xmlEscape escapes', !str_contains($seo->xmlEscape('<a>&"'), '<a>'));

echo "== Config validation ==\n";
$bad = configWith(['APP_ENV' => 'production', 'APP_URL' => '']);
$problems = $bad->validateProduction();
check('missing production config detected', $problems !== []);
check('contact email flagged', count(array_filter($problems, fn ($p) => str_starts_with($p, 'CONTACT_EMAIL'))) === 1);
$good = configWith([
    'APP_ENV' => 'production',
    'APP_URL' => 'https://ask.example',
    'DB_NAME' => 'x', 'DB_USER' => 'x',
    'ADMIN_PASSWORD_HASH' => password_hash('secret', PASSWORD_DEFAULT),
    'RATE_LIMIT_SECRET' => $secret,
    'CONTACT_EMAIL' => 'real@example.com',
]);
check('full production config passes', $good->validateProduction() === []);
$debug = configWith(['APP_ENV' => 'production', 'APP_DEBUG' => 'true']);
check('debug in production forbidden', count(array_filter($debug->validateProduction(), fn ($p) => str_starts_with($p, 'APP_DEBUG'))) === 1);

echo "== Time formatting ==\n";
check('isoUtc format', Http::isoUtc('2026-09-12 15:20:30') === '2026-09-12T15:20:30Z');
check('isoUtc null', Http::isoUtc(null) === null);

echo "== cleanUtf8 ==\n";
check('control chars stripped', Http::cleanUtf8("a\x00b\x1Fc") === 'abc');
check('valid text unchanged', Http::cleanUtf8('привет 😀') === 'привет 😀');

echo "== Human application validation ==\n";
require __DIR__ . '/../app/Repositories/HumanRepository.php';
require __DIR__ . '/../app/Services/RateLimiter.php';
require __DIR__ . '/../app/Services/TelegramNotifier.php';
require __DIR__ . '/../app/Services/HumanService.php';
require __DIR__ . '/../app/Controllers/TelegramWebhookController.php';

$hv = new ReflectionClass(\App\Services\HumanService::class);
$hsvc = $hv->newInstanceWithoutConstructor();
$hvFn = Closure::bind(
    fn (mixed $n, mixed $nn, mixed $l, mixed $h, mixed $b, mixed $e, mixed $lk) => $this->validateApplication($n, $nn, $l, $h, $b, $e, $lk),
    $hsvc,
    \App\Services\HumanService::class
);
$out = $hvFn('  Ivan Petrov ', null, 'Tver, Russia', 'Accountant in Tver', str_repeat('x', 50), "gardening\nplumbing", "Blog | https://example.com\nhttps://a.ru");
check('valid application accepted', $out['name'] === 'Ivan Petrov');
check('links parsed with label', $out['links'][0] === ['label' => 'Blog', 'url' => 'https://example.com']);
check('bare url parsed', $out['links'][1]['url'] === 'https://a.ru');
check('topics split', $out['expertise'] === ['gardening', 'plumbing']);
try {
    $hvFn(str_repeat('n', 101), null, null, 'Valid headline here', str_repeat('x', 50), null, null);
    check('long name rejected', false);
} catch (\App\Services\DomainException $e) {
    check('long name rejected', isset($e->fields['name']));
}
try {
    $hvFn('Jo', null, null, 'short', str_repeat('x', 50), null, null);
    check('short headline rejected', false);
} catch (\App\Services\DomainException $e) {
    check('short headline rejected', isset($e->fields['headline']));
}
try {
    $hvFn('Jane Doe', null, null, 'Valid headline here', str_repeat('x', 49), null, null);
    check('short bio rejected', false);
} catch (\App\Services\DomainException $e) {
    check('short bio rejected', isset($e->fields['bio']));
}
try {
    $hvFn('Jane Doe', null, null, 'Valid headline here', str_repeat('x', 50), "ok\nx", null);
    check('short topic rejected', false);
} catch (\App\Services\DomainException $e) {
    check('short topic rejected', isset($e->fields['expertise']));
}
try {
    $hvFn('Jane Doe', null, null, 'Valid headline here', str_repeat('x', 50), null, "ftp://bad.example");
    check('non-http link rejected', false);
} catch (\App\Services\DomainException $e) {
    check('non-http link rejected', isset($e->fields['links']));
}
echo "== source_url validation ==\n";
$vs = Closure::bind(fn (mixed $q, mixed $c, mixed $s) => $this->validateInput($q, $c, $s), $svc, QuestionService::class);
$out = $vs('Long enough question for the source test', null, '  https://example.com/page  ');
check('source_url trimmed', $out['source_url'] === 'https://example.com/page');
$out = $vs('Long enough question for the source test', null, '   ');
check('blank source_url -> null', $out['source_url'] === null);
try {
    $vs('Long enough question for the source test', null, 'javascript:alert(1)');
    check('non-http source_url rejected', false);
} catch (\App\Services\DomainException $e) {
    check('non-http source_url rejected', isset($e->fields['source_url']));
}
try {
    $vs('Long enough question for the source test', null, 42);
    check('non-string source_url rejected', false);
} catch (\App\Services\DomainException $e) {
    check('non-string source_url rejected', isset($e->fields['source_url']));
}

echo "== title validation ==\n";
$vt = Closure::bind(fn (mixed $q, mixed $c, mixed $s, mixed $t) => $this->validateInput($q, $c, $s, $t), $svc, QuestionService::class);
$qx = 'Long enough question for the title test';
$out = $vt($qx, null, null, null);
check('absent title -> null', $out['title'] === null);
$out = $vt($qx, null, null, '   ');
check('blank title -> null', $out['title'] === null);
$out = $vt($qx, null, null, '  Unusual project terms  ');
check('title trimmed', $out['title'] === 'Unusual project terms');
try {
    $vt($qx, null, null, 'ab');
    check('2-char title rejected', false);
} catch (\App\Services\DomainException $e) {
    check('2-char title rejected', isset($e->fields['title']));
}
try {
    $vt($qx, null, null, str_repeat('x', 151));
    check('151-char title rejected', false);
} catch (\App\Services\DomainException $e) {
    check('151-char title rejected', isset($e->fields['title']));
}
try {
    $vt($qx, null, null, 42);
    check('non-string title rejected', false);
} catch (\App\Services\DomainException $e) {
    check('non-string title rejected', isset($e->fields['title']));
}
$out = $vt($qx, null, null, "\xB1\x31 bad utf8");
check('invalid utf8 title sanitized', mb_check_encoding((string) $out['title'], 'UTF-8'));
$out = $vt($qx, null, null, str_repeat('x', 150));
check('150-char title accepted', $out['title'] === str_repeat('x', 150));

$slugFn = Closure::bind(fn (string $n) => $this->makeSlug($n), $hsvc, \App\Services\HumanService::class);
check('human slug cyrillic', $slugFn('Мария Иванова') === 'mariya-ivanova');
check('human slug fallback', $slugFn('!!!') === 'human');

echo "== question slug guard ==\n";
$hex = 'abcdef0123456789abcdef0123456789';
check('bare 32-hex slug gets -q suffix', $slug($hex) === $hex . '-q');
check('regular slug untouched', $slug('Повторный заголовок') === 'povtornyy-zagolovok');

echo "== expert lifecycle: drafts, tokens, states ==\n";
use App\Services\HumanService;
$vDraft = Closure::bind(fn (array $b) => $this->validateDraftInput($b), $hsvc, HumanService::class);
$out = $vDraft(['name' => '  Jane Doe  ', 'expertise' => ['Product', 'Dev tools'], 'links' => ['https://x.example', 'Site | https://y.example']]);
check('draft name trimmed', $out['name'] === 'Jane Doe');
check('draft expertise array accepted', $out['expertise'] === ['Product', 'Dev tools']);
check('draft link label parsed', $out['links'][1] === ['label' => 'Site', 'url' => 'https://y.example']);
check('draft optional fields empty', $out['headline'] === '' && $out['bio'] === '' && $out['slug'] === null);
try { $vDraft([]); check('draft requires name', false); } catch (\App\Services\DomainException $e) { check('draft requires name', isset($e->fields['name'])); }
try { $vDraft(['name' => 'Jane', 'expertise' => str_repeat('a', 16)]); check('draft expertise over 15 rejected', false); } catch (\App\Services\DomainException $e) { check('draft expertise over 15 rejected', isset($e->fields['expertise'])); }
try { $vDraft(['name' => 'Jane', 'slug' => 'Join']); check('reserved slug rejected', false); } catch (\App\Services\DomainException $e) { check('reserved slug rejected', isset($e->fields['slug'])); }
try { $vDraft(['name' => 'Jane', 'links' => ['notaurl']]); check('bad draft link rejected', false); } catch (\App\Services\DomainException $e) { check('bad draft link rejected', isset($e->fields['links'])); }
$out = $vDraft(['name' => 'Jane Doe', 'slug' => 'Jane-Doe']);
check('draft slug lowercased', $out['slug'] === 'jane-doe');

check('claim token format enforced', \App\Controllers\TelegramWebhookController::extractClaimToken('/start claim_' . str_repeat('a', 32)) === str_repeat('a', 32));
check('claim token lowercase normalized', \App\Controllers\TelegramWebhookController::extractClaimToken('/start claim_' . strtoupper(str_repeat('a', 32))) === str_repeat('a', 32));
check('claim token with bot username accepted', \App\Controllers\TelegramWebhookController::extractClaimToken('/start@askhumanrubot claim_' . str_repeat('0', 32)) === str_repeat('0', 32));
check('pairing code is not a claim token', \App\Controllers\TelegramWebhookController::extractClaimToken('/start ' . str_repeat('a', 12)) === null);

check('edit token rejects bad format', $hsvc->findHumanByEditToken('zz') === null);
check('delete confirm window empty', !HumanService::deleteConfirmOpen(null));
check('delete confirm fresh request open', HumanService::deleteConfirmOpen(gmdate('Y-m-d H:i:s', time() - 60)));
check('delete confirm expired window closed', !HumanService::deleteConfirmOpen(gmdate('Y-m-d H:i:s', time() - 3600)));

echo "\nPassed: $pass, failed: $fail\n";
exit($fail === 0 ? 0 : 1);
