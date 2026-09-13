<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#6548d7">
<title>Ошибка сервера — Ask a Human</title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="site-header"><div class="wrap header-inner"><a class="brand" href="/" aria-label="askhuman.ru — на главную"><img class="brand-symbol" src="/favicon.svg" alt=""><span>askhuman</span><span class="brand-tld">.ru</span></a></div></header>
<main class="wrap">
  <h1>Ошибка сервера</h1>
  <p><?= htmlspecialchars((string) ($body ?? 'Сервис не смог обработать запрос. Попробуйте ещё раз позже.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
  <p><a href="/">На главную</a></p>
</main>
</body>
</html>
