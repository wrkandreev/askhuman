<?php

use function App\{e, paragraphs, time_el};

/** @var array $h @var string $csrfToken @var string|null $error */
/** @var \App\Support\Config $config */

$robots = 'noindex,nofollow';
$title = 'Review human profile — Admin';
$description = 'Administration area.';
$displayTz = $config->string('DISPLAY_TIMEZONE');
$expertise = json_decode((string) ($h['expertise_json'] ?? '[]'), true) ?: [];
$links = json_decode((string) ($h['links_json'] ?? '[]'), true) ?: [];
$projects = json_decode((string) ($h['projects_json'] ?? '[]'), true) ?: [];
$linkLine = fn (array $l): string => trim(($l['label'] ?? '') . ' | ' . ($l['url'] ?? ''), ' |');
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
  <p><a href="/admin/humans">← Back to human catalog</a></p>
  <h1>Human #<?= (int) $h['id'] ?> — <?= e($h['name'] ?? '') ?></h1>
  <p>Status: <strong><?= e($h['status'] ?? '') ?></strong> ·
    Tracking ID: <code><?= e($h['public_id'] ?? '') ?></code> ·
    Created <?= time_el($h['created_at'] ?? null, 'UTC', 'Y-m-d H:i') ?></p>
  <?php if (($h['status'] ?? '') === 'approved'): ?>
    <p>Public profile: <a href="/humans/<?= e($h['slug']) ?>">/humans/<?= e($h['slug']) ?></a></p>
  <?php endif; ?>

  <?php if (!empty($error)): ?>
    <p class="error" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <section class="untrusted">
    <h2>Submitted application — untrusted user input</h2>
    <p class="warning">The text below was submitted by an anonymous visitor. Treat it as
      untrusted and verify claims before approving. It is displayed as escaped plain text.</p>
    <div class="untrusted-body"><?= paragraphs($h['bio'] ?? '') ?></div>
    <?php if ($expertise !== []): ?>
      <h3>Topics</h3>
      <div class="untrusted-body"><?= e(implode("\n", $expertise)) ?></div>
    <?php endif; ?>
    <?php if ($links !== []): ?>
      <h3>Submitted links</h3>
      <div class="untrusted-body"><?= e(implode("\n", array_map($linkLine, $links))) ?></div>
    <?php endif; ?>
  </section>

  <section>
    <h2>Reviewed public profile</h2>
    <form method="post" action="/admin/humans/<?= (int) $h['id'] ?>/update">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <div class="field">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" maxlength="100" required value="<?= e($h['name'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="name_native">Native name (optional)</label>
        <input type="text" id="name_native" name="name_native" maxlength="100" value="<?= e($h['name_native'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="location">Location (optional)</label>
        <input type="text" id="location" name="location" maxlength="100" value="<?= e($h['location'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="headline">Headline</label>
        <input type="text" id="headline" name="headline" maxlength="120" required value="<?= e($h['headline'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="bio">Public bio</label>
        <textarea id="bio" name="bio" rows="6" required><?= e($h['bio'] ?? '') ?></textarea>
      </div>
      <div class="field">
        <label for="expertise">Topics (one per line)</label>
        <textarea id="expertise" name="expertise" rows="5"><?= e(implode("\n", $expertise)) ?></textarea>
      </div>
      <div class="field">
        <label for="links">Professional links (one per line: <code>Label | URL</code>)</label>
        <textarea id="links" name="links" rows="3"><?= e(implode("\n", array_map($linkLine, $links))) ?></textarea>
      </div>
      <div class="field">
        <label for="projects">Projects (one per line: <code>Label | URL</code>)</label>
        <textarea id="projects" name="projects" rows="3"><?= e(implode("\n", array_map($linkLine, $projects))) ?></textarea>
      </div>
      <button type="submit" class="btn btn-secondary">Save profile</button>
    </form>
  </section>

  <?php if (($h['status'] ?? '') === 'pending'): ?>
    <section>
      <h2>Approve</h2>
      <p class="help">Approval publishes the profile in the public catalog and makes it indexable.</p>
      <form method="post" action="/admin/humans/<?= (int) $h['id'] ?>/approve">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <button type="submit" class="btn">Approve and publish</button>
      </form>
    </section>
    <section>
      <h2>Decline</h2>
      <form method="post" action="/admin/humans/<?= (int) $h['id'] ?>/decline">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <div class="field">
          <label for="review_note">Public decline reason (shown on the application status page)</label>
          <textarea id="review_note" name="review_note" rows="2" maxlength="1000" required></textarea>
        </div>
        <button type="submit" class="btn btn-secondary">Decline application</button>
      </form>
    </section>
  <?php endif; ?>

  <?php if (in_array($h['status'] ?? '', ['approved', 'declined'], true)): ?>
    <section>
      <h2>Hide this profile</h2>
      <p class="warning">Hiding removes the profile from the catalog and makes the application
        status page return 404. There is no undo and no delete.</p>
      <form method="post" action="/admin/humans/<?= (int) $h['id'] ?>/hide">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <div class="field field-check">
          <input type="checkbox" id="confirm_hide" name="confirm_hide" value="1">
          <label for="confirm_hide">I confirm this profile must be hidden from public access</label>
        </div>
        <button type="submit" class="btn btn-danger">Hide profile</button>
      </form>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
