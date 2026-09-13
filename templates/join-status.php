<?php

use function App\{e, time_el};

/** @var array $h */
/** @var \App\Support\Config $config @var \App\Support\Seo $seo */

$displayTz = $config->string('DISPLAY_TIMEZONE');
$statusUrl = $config->baseUrl() . '/join/' . $h['public_id'];

$title = 'Статус заявки — Ask a Human';
$description = 'Статус заявки на создание вашей страницы.';
$canonical = $statusUrl;
$robots = 'noindex,follow';
$extraHead = $seo->jsonLd(['@context' => 'https://schema.org', '@type' => 'WebPage', 'url' => $canonical]);
?>
<article>
  <?php if (($h['status'] ?? '') === 'approved'): ?>
    <p class="badge badge-answered" role="status">Одобрено</p>
    <h1>Ваша заявка одобрена</h1>
    <p>Публичный профиль уже доступен:
      <a href="/<?= e($h['slug']) ?>">askhuman.ru/<?= e($h['slug']) ?></a></p>
  <?php elseif (($h['status'] ?? '') === 'declined'): ?>
    <p class="badge badge-declined" role="status">Отклонено</p>
    <h1>Заявка отклонена</h1>
    <p>Спасибо за интерес к <?= e($config->string('APP_NAME')) ?>. К сожалению, эту заявку не удалось одобрить.</p>
    <?php if (!empty($h['review_note'])): ?>
      <section>
        <h2>Причина</h2>
        <p><?= nl2br(e($h['review_note'])) ?></p>
      </section>
    <?php endif; ?>
  <?php else: ?>
    <p class="badge badge-waiting" role="status">Ожидает привязки Telegram</p>
    <h1>Заявка получена — активируйте профиль</h1>
    <p>Последний шаг: привяжите свой Telegram. Откройте нашего бота и отправьте
      ему персональный код заявки — профиль активируется сразу, автоматически.</p>
    <section class="card">
      <h2>Активация за один шаг</h2>
      <?php $username = $config->string('TELEGRAM_BOT_USERNAME');
            $code = (string) ($h['pairing_code'] ?? ''); ?>
      <?php if ($username !== '' && $code !== ''): ?>
        <p><a class="btn" href="https://t.me/<?= e($username) ?>?start=<?= e($code) ?>" rel="noopener">Открыть бота и отправить код</a></p>
        <p class="help">Или вручную: откройте <a href="https://t.me/<?= e($username) ?>" rel="noopener">@<?= e($username) ?></a>
          и отправьте сообщение:</p>
      <?php else: ?>
        <p class="help">Откройте бота платформы и отправьте сообщение:</p>
      <?php endif; ?>
      <?php if ($code !== ''): ?>
        <p><code style="font-size:1.2rem"><?= e($code) ?></code></p>
      <?php else: ?>
        <p class="help">Код активации истёк. Отправьте заявку заново.</p>
      <?php endif; ?>
      <p class="help">Код одноразовый и действует 48 часов. Привязав Telegram, вы
        сможете отвечать на вопросы прямо из мессенджера.</p>
    </section>
    <p>Сохраните эту страницу (ссылку) — это ваш личный статус заявки.</p>
  <?php endif; ?>

  <section class="meta">
    <h2>Данные заявки</h2>
    <p>Имя: <?= e($h['name']) ?></p>
    <p>О себе: <?= e($h['headline']) ?></p>
    <p>Отправлено: <?= time_el($h['created_at'], $displayTz, 'd.m.Y H:i') ?></p>
    <p>ID заявки: <code><?= e($h['public_id']) ?></code></p>
    <p>Ссылка на статус: <code><?= e($statusUrl) ?></code></p>
  </section>
</article>
