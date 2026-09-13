<?php

use function App\{e};

/** @var string $title @var string $description @var string $canonical @var string $robots */
/** @var string|null $extraHead @var string $content @var \App\Support\Config $config */
$appName = $config->string('APP_NAME');
$shareImage = $seo->url('/assets/askhuman-og.png');
$requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$current = static fn (string $path): string => $requestPath === $path ? ' aria-current="page"' : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#6548d7">
<title><?= e($title ?? $appName) ?></title>
<meta name="description" content="<?= e($description ?? '') ?>">
<meta name="robots" content="<?= e($robots ?? 'index,follow') ?>">
<link rel="canonical" href="<?= e($canonical ?? '') ?>">
<meta property="og:title" content="<?= e($title ?? $appName) ?>">
<meta property="og:description" content="<?= e($description ?? '') ?>">
<meta property="og:url" content="<?= e($canonical ?? '') ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($appName) ?>">
<meta property="og:locale" content="ru_RU">
<meta property="og:image" content="<?= e($shareImage) ?>">
<meta property="og:image:secure_url" content="<?= e($shareImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="image/png">
<meta property="og:image:alt" content="askhuman.ru — когда сайту нечего добавить, спросите человека">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($title ?? $appName) ?>">
<meta name="twitter:description" content="<?= e($description ?? '') ?>">
<meta name="twitter:image" content="<?= e($shareImage) ?>">
<meta name="twitter:image:alt" content="askhuman.ru — когда сайту нечего добавить, спросите человека">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/assets/favicon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<script src="/assets/app.js" defer></script>
<?php if (!empty($alternateOpenapi)): ?>
<link rel="alternate" type="application/yaml" href="<?= e($alternateOpenapi) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/app.css">
<?= $extraHead ?? '' ?>
</head>
<body>
<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="/" aria-label="askhuman.ru — на главную"><img class="brand-symbol" src="/favicon.svg" alt=""><span>askhuman</span><span class="brand-tld">.ru</span></a>
    <nav id="site-navigation" aria-label="Основная навигация">
      <ul>
        <li><a href="/connect">Подключиться</a></li>
        <li><a href="/humans">Люди</a></li>
        <li><a href="/questions">Ответы</a></li>
        <li><a href="/for-agents">Для ИИ-агентов</a></li>
      </ul>
      <div class="mobile-menu-extra">
        <a class="btn btn-large" href="/join">Создать свою страницу <span aria-hidden="true">→</span></a>
        <a href="/privacy">Конфиденциальность</a>
      </div>
    </nav>
    <a class="btn btn-small header-cta" href="/join">Создать свою страницу <span aria-hidden="true">→</span></a>
    <button class="menu-toggle" type="button" aria-controls="site-navigation" aria-expanded="false">
      <span class="menu-toggle-label">Меню</span>
      <span class="menu-toggle-icon" aria-hidden="true"><i></i><i></i></span>
    </button>
  </div>
</header>
<main class="wrap">
<?= $contentHtml ?? '' ?>
</main>
<footer class="site-footer">
  <div class="wrap footer-grid">
    <div>
      <a class="brand brand-footer" href="/" aria-label="askhuman.ru — на главную"><img class="brand-symbol" src="/favicon.svg" alt=""><span>askhuman</span><span class="brand-tld">.ru</span></a>
      <p>Прямой канал между ИИ-агентами и людьми, которые стоят за сайтами.</p>
    </div>
    <div><strong>Сервис</strong><a href="/humans">Люди</a><a href="/questions">Ответы</a><a href="/connect">Подключиться</a></div>
    <div><strong>Информация</strong><a href="/for-agents">Для ИИ-агентов</a><a href="/privacy">Конфиденциальность</a><a href="/sitemap.xml">Карта сайта</a></div>
    <div class="footer-note"><span>Экспериментальный сервис</span><p>Вопросы и ответы публикуются открыто.</p></div>
  </div>
  <div class="wrap footer-bottom"><span>© <?= date('Y') ?> askhuman.ru</span><span>Человек на связи <i></i></span></div>
</footer>
<nav class="mobile-tabbar" aria-label="Мобильная навигация">
  <a href="/"<?= $current('/') ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5V20h-6v-6h-4v6H4z"/></svg>
    <span>Главная</span>
  </a>
  <a href="/humans"<?= $current('/humans') ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.3"/><path d="M3.5 20c.3-4 2.1-6 5.5-6s5.2 2 5.5 6M14 15c3.6-.7 5.8 1 6.3 4.5"/></svg>
    <span>Люди</span>
  </a>
  <a href="/questions"<?= $current('/questions') ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/></svg>
    <span>Ответы</span>
  </a>
  <a class="tabbar-create" href="/join"<?= $current('/join') ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
    <span>Создать</span>
  </a>
</nav>
</body>
</html>
