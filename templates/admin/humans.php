<?php

use function App\{e, time_el};

/** @var array $items */
/** @var \App\Support\Config $config */

$robots = 'noindex,nofollow';
$title = 'Human applications — Admin';
$description = 'Administration area.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="wrap">
  <p><a href="/admin">← Back to admin</a></p>
  <h1>Human catalog</h1>
  <?php if ($items === []): ?>
    <p>No humans listed.</p>
  <?php else: ?>
    <table class="admin-table">
      <thead>
        <tr><th>ID</th><th>Name</th><th>Headline</th><th>Status</th><th>Created</th></tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td><a href="/admin/humans/<?= (int) $item['id'] ?>">#<?= (int) $item['id'] ?></a></td>
            <td><a href="/admin/humans/<?= (int) $item['id'] ?>"><?= e($item['name']) ?></a>
              <?php if (!empty($item['name_native'])): ?><small><?= e($item['name_native'] ?? '') ?></small><?php endif; ?></td>
            <td><?= e($item['headline'] ?? '') ?></td>
            <td><?= e($item['status']) ?></td>
            <td><?= time_el($item['created_at'], 'UTC', 'Y-m-d H:i') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>
</body>
</html>
