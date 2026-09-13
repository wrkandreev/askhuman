<?php

use function App\{e, time_el};

/** @var array $counts @var float|null $median @var array $items @var string|null $filter @var string $csrfToken */

$robots = 'noindex,nofollow';
$title = 'Admin — ' . $config->string('APP_NAME');
$description = 'Administration area.';
$canonical = '/admin';
$displayTz = $config->string('DISPLAY_TIMEZONE');

$medianText = $median === null ? 'n/a' : ($median < 90
    ? round($median) . ' s'
    : ($median < 5400 ? round($median / 60) . ' min' : round($median / 3600, 1) . ' h'));
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
  <h1>Admin</h1>
  <section>
    <h2>Summary</h2>
    <ul>
      <li>Total questions: <?= (int) $counts['total'] ?></li>
      <li>Waiting: <?= (int) $counts['waiting'] ?></li>
      <li>Answered: <?= (int) $counts['answered'] ?></li>
      <li>Median response time: <?= e($medianText) ?></li>
    </ul>
  </section>
  <section>
    <h2>Questions</h2>
    <nav aria-label="Filters">
      <ul class="inline-list">
        <li><a href="/admin"<?= $filter === null ? ' aria-current="true"' : '' ?>>All</a></li>
        <li><a href="/admin?status=waiting"<?= $filter === 'waiting_for_human' ? ' aria-current="true"' : '' ?>>Waiting</a></li>
        <li><a href="/admin?status=answered"<?= $filter === 'answered' ? ' aria-current="true"' : '' ?>>Answered</a></li>
        <li><a href="/admin?status=declined"<?= $filter === 'declined' ? ' aria-current="true"' : '' ?>>Declined</a></li>
        <li><a href="/admin?status=hidden"<?= $filter === 'hidden' ? ' aria-current="true"' : '' ?>>Hidden</a></li>
      </ul>
    </nav>
    <?php if ($items === []): ?>
      <p>No questions in this view.</p>
    <?php else: ?>
      <table class="admin-table">
        <thead>
          <tr><th>ID</th><th>Question</th><th>Status</th><th>Source</th><th>Created</th></tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td><a href="/admin/questions/<?= (int) $item['id'] ?>">#<?= (int) $item['id'] ?></a></td>
              <td><a href="/admin/questions/<?= (int) $item['id'] ?>"><?= e(mb_substr((string) $item['question'], 0, 120)) ?></a></td>
              <td><?= e($item['status']) ?></td>
              <td><?= e($item['source']) ?></td>
              <td><?= time_el($item['created_at'], 'UTC', 'Y-m-d H:i') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
  <p><a href="/admin/humans">→ Human catalog and applications</a></p>
  <form method="post" action="/admin/logout">
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <button type="submit" class="btn btn-secondary">Log out</button>
  </form>
</main>
</body>
</html>
