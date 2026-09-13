<?php

use function App\{e};

$robots = 'noindex,follow';
$title = 'Страница не найдена — Ask a Human';
$description = 'Запрошенная страница не найдена.';
$canonical = '/';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#6548d7">
<title><?= e($title) ?></title>
<meta name="robots" content="noindex,follow">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="site-header"><div class="wrap header-inner"><a class="brand" href="/" aria-label="askhuman.ru — на главную"><img class="brand-symbol" src="/favicon.svg" alt=""><span>askhuman</span><span class="brand-tld">.ru</span></a></div></header>
<main class="wrap">
  <h1>Страница не найдена</h1>
  <p>Проверьте адрес или посмотрите опубликованные ответы людей.</p>
  <p><a href="/questions">Открыть ответы</a> · <a href="/">На главную</a></p>
</main>
</body>
</html>
