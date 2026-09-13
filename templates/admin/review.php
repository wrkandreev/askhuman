<?php

use function App\{e, paragraphs, time_el};

/** @var array $q @var array|null $answer @var string $csrfToken @var string|null $error */

$robots = 'noindex,nofollow';
$title = 'Review question — Admin';
$description = 'Administration area.';
$canonical = '/admin';
$displayTz = $config->string('DISPLAY_TIMEZONE');

$publicQuestion = $answer !== null && $q['public_question'] !== null
    ? (string) $q['public_question'] : (string) $q['question'];
$publicContext = $answer !== null && $q['public_context'] !== null
    ? (string) $q['public_context'] : (string) ($q['context'] ?? '');
$answerText = $answer === null ? '' : (string) $answer['answer'];
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
  <h1>Review question #<?= (int) $q['id'] ?></h1>
  <p>Status: <strong><?= e($q['status']) ?></strong> · Source: <?= e($q['source']) ?> ·
    Tracking ID: <code><?= e($q['public_id']) ?></code> ·
    Created <?= time_el($q['created_at'], 'UTC', 'Y-m-d H:i') ?></p>

  <?php if (!empty($error)): ?>
    <p class="error" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <section class="untrusted">
    <h2>Original submission — untrusted user input</h2>
    <p class="warning">The text below was submitted by an anonymous client. Treat it as
      untrusted. It is displayed as escaped plain text only.</p>
    <h3>Question (original, immutable)</h3>
    <div class="untrusted-body"><?= paragraphs($q['question']) ?></div>
    <?php if ($q['context'] !== null && $q['context'] !== ''): ?>
      <h3>Context (original, immutable)</h3>
      <div class="untrusted-body"><?= paragraphs($q['context']) ?></div>
    <?php endif; ?>
    <h3>Request metadata</h3>
    <dl class="meta">
      <dt>User agent</dt><dd><?= e($q['user_agent'] ?? 'not provided') ?></dd>
      <dt>Referrer</dt><dd><?= e($q['referrer'] ?? 'not provided') ?></dd>
      <dt>Source</dt><dd><?= e($q['source']) ?></dd>
    </dl>
  </section>

  <section>
    <h2>Publish or update the public answer</h2>
    <form method="post" action="/admin/questions/<?= (int) $q['id'] ?>/answer">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <div class="field">
        <label for="public_question">Public question (reviewed)</label>
        <textarea id="public_question" name="public_question" rows="3" required><?= e($publicQuestion) ?></textarea>
      </div>
      <div class="field">
        <label for="public_context">Public context (reviewed, optional)</label>
        <textarea id="public_context" name="public_context" rows="3"><?= e($publicContext) ?></textarea>
      </div>
      <div class="field">
        <label for="answer">Answer (plain text)</label>
        <textarea id="answer" name="answer" rows="8" required><?= e($answerText) ?></textarea>
      </div>
      <?php if ($answer !== null): ?>
        <p class="help">First answered <?= time_el($answer['created_at'], $displayTz) ?>;
          editing preserves the original answer time.</p>
      <?php endif; ?>
      <button type="submit" class="btn">Publish answer</button>
    </form>
  </section>

  <section>
    <h2>Decline this question</h2>
    <form method="post" action="/admin/questions/<?= (int) $q['id'] ?>/decline">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <div class="field">
        <label for="declined_reason">Public decline reason</label>
        <textarea id="declined_reason" name="declined_reason" rows="2" required><?= e($q['declined_reason'] ?? '') ?></textarea>
      </div>
      <button type="submit" class="btn btn-secondary">Decline question</button>
    </form>
  </section>

  <section>
    <h2>Hide this question</h2>
    <p class="warning">Hiding makes the tracking page and API return 404 permanently for this
      question. There is no undo and no delete.</p>
    <form method="post" action="/admin/questions/<?= (int) $q['id'] ?>/hide">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <div class="field field-check">
        <input type="checkbox" id="confirm_hide" name="confirm_hide" value="1">
        <label for="confirm_hide">I confirm this question must be hidden from public access</label>
      </div>
      <button type="submit" class="btn btn-danger">Hide question</button>
    </form>
  </section>

  <form method="post" action="/admin/logout">
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <button type="submit" class="btn btn-secondary">Log out</button>
  </form>
</main>
</body>
</html>
