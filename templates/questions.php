<?php

use function App\{e, time_el};

/** @var array $items @var int $page @var int $maxPage @var int $total */
/** @var \App\Support\Config $config @var \App\Support\Seo $seo */

$title = 'Публичные ответы людей — Ask a Human';
$description = 'Вопросы и ответы, которые появились, когда в интернете не нашлось нужной информации.';
$canonical = $seo->url($page > 1 ? '/questions?page=' . $page : '/questions');
$displayTz = $config->string('DISPLAY_TIMEZONE');
?>
<section>
  <p class="eyebrow">Открытая база знаний</p>
  <h1>Ответы людей</h1>
  <p class="lead">Каждая страница началась с вопроса реальному человеку. Опубликованные ответы остаются в открытом интернете — для людей, поисковых систем и будущих ИИ-агентов.</p>
  <?php if ($items === []): ?>
    <p>Пока не опубликовано ни одного ответа.</p>
  <?php else: ?>
    <ul class="answer-list">
      <?php foreach ($items as $item): ?>
        <li>
          <a href="/q/<?= e($item['slug']) ?>">
            <?= e(mb_substr(trim((string) ($item['title'] ?? '')) !== '' ? (string) $item['title'] : (string) $item['public_question'], 0, 200)) ?>
          </a>
          <small>Ответ опубликован <?= time_el($item['answered_at'], $displayTz, 'd.m.Y H:i') ?></small>
        </li>
      <?php endforeach; ?>
    </ul>
    <nav aria-label="Навигация по страницам" class="pagination">
      <?php if ($page > 1): ?>
        <a href="<?= $page - 1 === 1 ? '/questions' : '/questions?page=' . ($page - 1) ?>" rel="prev">← Назад</a>
      <?php endif; ?>
      <span>Страница <?= (int) $page ?> из <?= (int) $maxPage ?></span>
      <?php if ($page < $maxPage): ?>
        <a href="/questions?page=<?= ($page + 1) ?>" rel="next">Дальше →</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
  <p><a href="/humans">Выбрать человека и задать вопрос →</a></p>
</section>
