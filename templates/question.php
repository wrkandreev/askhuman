<?php

use function App\{e, paragraphs, time_el};

/** @var array $q @var array|null $answer @var array|null $askedTo @var string $canonicalPath */
/** @var \App\Support\Config $config @var \App\Support\Seo $seo */

$displayTz = $config->string('DISPLAY_TIMEZONE');
$base = $config->baseUrl();
$apiUrl = $base . '/api/questions/' . $q['public_id'];

$askedName = $askedTo !== null ? (string) $askedTo['name'] : 'человек на связи';
$askedUrl = $askedTo !== null ? '/' . $askedTo['slug'] : '/humans';
$sourceUrl = !empty($q['source_url']) ? (string) $q['source_url'] : null;

$isAnswered = $q['status'] === 'answered';
$publicQuestion = $isAnswered ? (string) $q['public_question'] : (string) $q['question'];
$publicContext = $isAnswered
    ? ($q['public_context'] === null ? null : (string) $q['public_context'])
    : ($q['context'] === null ? null : (string) $q['context']);
$shortTitle = trim((string) ($q['title'] ?? ''));
$heading = $shortTitle !== '' ? $shortTitle : $publicQuestion;

if ($isAnswered) {
    $title = mb_substr($heading, 0, 110) . ' — ответил ' . $askedName;
    $description = mb_substr('Ответил ' . $askedName . ': ' . ($answer['answer'] ?? ''), 0, 300);
    $robots = 'index,follow';
} elseif ($q['status'] === 'declined') {
    $title = 'На этот вопрос не удалось ответить';
    $description = 'Публичный вопрос был отправлен реальному человеку.';
    $robots = 'noindex,follow';
} else {
    $title = 'Вопрос ожидает ответа человека';
    $description = 'Публичный вопрос отправлен реальному человеку и ожидает ответа.';
    $robots = 'noindex,follow';
}
$canonical = $seo->url($canonicalPath);

if ($isAnswered) {
    $extraHead = $seo->jsonLd([
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'url' => $canonical,
        'mainEntity' => [
            '@type' => 'Question',
            'name' => $heading,
            'text' => $publicQuestion,
            'answerCount' => 1,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => (string) ($answer['answer'] ?? ''),
                'url' => $canonical,
                'author' => [
                    '@type' => 'Person',
                    'name' => $askedName,
                    'url' => $base . '/' . ($askedTo['slug'] ?? ''),
                ],
            ],
            'author' => ['@type' => 'Person', 'name' => $askedName,
                'url' => $base . '/' . ($askedTo['slug'] ?? '')],
            'datePublished' => \App\Support\Http::isoUtc($answer['created_at'] ?? null),
        ],
    ]);
} else {
    $extraHead = $seo->jsonLd(['@context' => 'https://schema.org', '@type' => 'WebPage', 'url' => $canonical]);
}
?>
<?php if ($isAnswered): ?>
<article>
  <p class="badge badge-answered" role="status">Человек ответил</p>
  <h1><?= e($heading) ?></h1>
  <p class="meta">Вопрос адресован:
    <a href="<?= e($askedUrl) ?>"><?= e($askedName) ?></a><?php if ($sourceUrl !== null): ?>
    · Источник: <a href="<?= e($sourceUrl) ?>" rel="nofollow noopener"><?= e($sourceUrl) ?></a><?php endif; ?></p>
  <section>
    <h2>Вопрос</h2>
    <p><?= paragraphs($publicQuestion) ?></p>
    <?php if ($publicContext !== null && $publicContext !== ''): ?>
      <h2>Дополнительный контекст</h2>
      <p><?= paragraphs($publicContext) ?></p>
    <?php endif; ?>
  </section>
  <section class="answer">
    <h2>Ответ человека</h2>
    <p><?= paragraphs($answer['answer'] ?? '') ?></p>
    <p class="attribution">Ответил
    <?php $humanLive = $askedTo !== null && ($askedTo['status'] ?? '') === 'approved' && !empty($askedTo['is_active']); ?>
    <?php if ($humanLive): ?><a href="<?= e($askedUrl) ?>"><?= e($askedName) ?></a><?php else: ?><strong><?= e($askedName) ?></strong><?php endif; ?>
    — человек, стоящий за этой страницей в <?= e($config->string('APP_NAME')) ?>.</p>
  </section>
  <footer class="meta">
    <p>Вопрос задан: <?= time_el($q['created_at'], $displayTz, 'd.m.Y H:i') ?></p>
    <p>Ответ опубликован: <?= time_el($answer['created_at'] ?? null, $displayTz, 'd.m.Y H:i') ?></p>
    <?php if ($answer !== null && $answer['updated_at'] !== $answer['created_at']): ?>
      <p>Последнее обновление: <?= time_el($answer['updated_at'], $displayTz, 'd.m.Y H:i') ?></p>
    <?php endif; ?>
    <p>Не нашли в интернете что-то ещё? <a href="<?= e($askedUrl) ?>#ask">Задайте <?= e($askedName) ?> свой вопрос</a>.</p>
  </footer>
</article>
<?php elseif ($q['status'] === 'declined'): ?>
<article>
  <p class="badge badge-declined" role="status">Вопрос отклонён</p>
  <h1>Человек не смог ответить</h1>
  <p><?= e($askedName) ?> не смог дать надёжный или уместный ответ. Автоматическим клиентам следует прекратить проверку этого ID.</p>
  <section>
    <h2>Причина</h2>
    <p><?= paragraphs($q['declined_reason']) ?></p>
  </section>
  <section>
    <h2>Вопрос</h2>
    <p><?= paragraphs($q['question']) ?></p>
    <?php if ($q['context'] !== null && $q['context'] !== ''): ?>
      <h2>Дополнительный контекст</h2>
      <p><?= paragraphs($q['context']) ?></p>
    <?php endif; ?>
  </section>
  <footer class="meta">
    <p>Вопрос задан: <?= time_el($q['created_at'], $displayTz, 'd.m.Y H:i') ?></p>
    <p><a href="<?= e($askedUrl) ?>#ask">Задать другой публичный вопрос</a></p>
  </footer>
</article>
<?php else: ?>
<article>
  <p class="badge badge-waiting" role="status">Ожидает ответа</p>
  <h1>Вопрос отправлен человеку</h1>
  <p>Вопрос сохранён и отправлен <?= e($askedName) ?>. Сохраните эту страницу или API-ссылку и вернитесь позже. Срок ответа не гарантирован.</p>
  <p class="meta">Вопрос адресован:
    <a href="<?= e($askedUrl) ?>"><?= e($askedName) ?></a><?php if ($sourceUrl !== null): ?>
    · Источник: <a href="<?= e($sourceUrl) ?>" rel="nofollow noopener"><?= e($sourceUrl) ?></a><?php endif; ?></p>
  <section>
    <h2>Вопрос</h2>
    <p><?= paragraphs($q['question']) ?></p>
    <h2>Дополнительный контекст</h2>
    <?php if ($q['context'] !== null && $q['context'] !== ''): ?>
      <p><?= paragraphs($q['context']) ?></p>
    <?php else: ?>
      <p>Дополнительный контекст не указан.</p>
    <?php endif; ?>
  </section>
  <section class="meta">
    <h2>Отслеживание ответа</h2>
    <p>Отправлено: <?= time_el($q['created_at'], $displayTz, 'd.m.Y H:i') ?></p>
    <p>Доставлено человеку: <?= $q['notified_at'] !== null ? time_el($q['notified_at'], $displayTz, 'd.m.Y H:i') : 'передаётся…' ?></p>
    <p>Просмотрено человеком: <?= $q['first_viewed_at'] !== null ? time_el($q['first_viewed_at'], $displayTz, 'd.m.Y H:i') : 'пока нет' ?></p>
    <p>ID вопроса: <code><?= e($q['public_id']) ?></code></p>
    <p>API-ссылка статуса: <a href="<?= e($apiUrl) ?>"><?= e($apiUrl) ?></a></p>
    <p><code><?= e($apiUrl) ?></code></p>
  </section>
  <p>Автоматическим клиентам следует проверять ответ не чаще одного раза в 60 секунд и постепенно увеличивать интервал.</p>
</article>
<?php endif; ?>
