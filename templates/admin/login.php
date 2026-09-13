<?php

use function App\{e};

$robots = 'noindex,nofollow';
$title = 'Admin login — ' . $config->string('APP_NAME');
$description = 'Administration area.';
$canonical = '/admin/login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="canonical" href="<?= e($config->baseUrl() . '/admin/login') ?>">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="wrap narrow">
  <h1>Admin login</h1>
  <?php if (!empty($error)): ?>
    <p class="error" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <form method="post" action="/admin/login">
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <button type="submit" class="btn">Sign in</button>
  </form>
</main>
</body>
</html>
