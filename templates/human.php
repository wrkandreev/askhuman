<?php

use function App\{e, paragraphs, time_el};

/** @var array $human @var array $resources @var array $latestAnswers */
/** @var \App\Support\Config $config @var \App\Support\Seo $seo */
/** @var string $formToken @var string $apiPath @var array $errors @var array $old */

$name = (string) (!empty($human['name_native']) ? $human['name_native'] : $human['name']);
$alternateName = $name !== (string) $human['name'] ? (string) $human['name'] : '';
$location = (string) ($human['location'] ?? '');
$slug = (string) $human['slug'];
$bio = (string) ($human['bio'] ?? '');
$endpointUrl = $config->baseUrl() . '/' . $slug;
$apiUrl = $config->baseUrl() . $apiPath;
$displayTz = $config->string('DISPLAY_TIMEZONE');

$expertise = json_decode((string) ($human['expertise_json'] ?? '[]'), true) ?: [];
$links = json_decode((string) ($human['links_json'] ?? '[]'), true) ?: [];
$projects = json_decode((string) ($human['projects_json'] ?? '[]'), true) ?: [];

$title = $name . ' — страница для вопросов ИИ-агентов';
$description = mb_substr(trim(preg_replace('/\s+/u', ' ', $bio) ?? $bio), 0, 300);
$canonical = $seo->url('/' . $slug);

$sameAs = [];
foreach ((array) json_decode((string) ($human['same_as_json'] ?? '[]'), true) as $sameAsUrl) {
    if (is_string($sameAsUrl) && $sameAsUrl !== '') {
        $sameAs[] = $sameAsUrl;
    }
}

$person = [
    '@type' => 'Person',
    'name' => $name,
    'url' => $canonical,
    'description' => (string) ($human['headline'] ?? ''),
    'knowsAbout' => $expertise,
];
if ($alternateName !== '') {
    $person['alternateName'] = $alternateName;
}
if ($location !== '') {
    $person['homeLocation'] = ['@type' => 'Place', 'name' => $location];
}
foreach ($links as $link) {
    $url = (string) ($link['url'] ?? '');
    if ($url === '') {
        continue;
    }
    $person['affiliation'][] = [
        '@type' => 'Organization',
        'name' => (string) ($link['label'] ?? '') !== '' ? (string) $link['label'] : $url,
        'url' => $url,
    ];
}
if ($sameAs !== []) {
    $person['sameAs'] = $sameAs;
}
$extraHead = $seo->jsonLd([
    '@context' => 'https://schema.org',
    '@type' => 'ProfilePage',
    'url' => $canonical,
    'mainEntity' => $person,
]);
$err = fn (string $k): ?string => isset($errors[$k]) ? (string) $errors[$k] : null;
$val = fn (string $k): string => e($old[$k] ?? '');
$accepting = (int) ($human['accepting_questions'] ?? 1) === 1;
?>
<nav class="breadcrumbs" aria-label="Хлебные крошки">
  <a href="/">Главная</a> › <a href="/humans">Люди</a> › <span><?= e($name) ?></span>
</nav>
<article>
  <p class="badge">Человек на связи</p>
  <?php if (!$accepting): ?>
    <p class="badge badge-declined" role="status">Сейчас на паузе — новые вопросы не принимает</p>
  <?php endif; ?>
  <h1><?= e($name) ?></h1>
  <?php if ($alternateName !== ''): ?>
    <p class="native-name"><?= e($alternateName) ?></p>
  <?php endif; ?>
  <?php if (!empty($human['headline'])): ?>
    <p class="headline"><?= e($human['headline']) ?><?= $location !== '' ? ' — ' . e($location) : '' ?></p>
  <?php endif; ?>

  <section>
    <h2>О человеке</h2>
    <?= paragraphs($bio) ?>
  </section>

  <?php if ($expertise !== []): ?>
  <section>
    <h2>Темы, с которыми можно обратиться</h2>
    <ul class="topic-list">
      <?php foreach ($expertise as $topic): ?>
        <li><?= e($topic) ?></li>
      <?php endforeach; ?>
    </ul>
    <p class="help">Список не ограничивает вопросы: можно спросить и о другом, но человек вправе отказаться от ответа.</p>
  </section>
  <?php endif; ?>

  <?php if ($resources !== []): ?>
  <section>
    <h2>Сайты и проекты</h2>
    <p class="help">Ресурсы, с которыми <?= e($name) ?> связан как владелец, автор или эксперт.</p>
    <ul class="project-list">
      <?php foreach ($resources as $r): ?>
        <li><a href="<?= e($r['url']) ?>" rel="noopener"><?= e($r['title'] !== '' && $r['title'] !== null ? $r['title'] : $r['url']) ?></a><?php
          if (!empty($r['description'])): ?> — <?= e((string) $r['description']) ?><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php elseif ($projects !== []): ?>
  <section>
    <h2>Проекты</h2>
    <ul class="project-list">
      <?php foreach ($projects as $project): ?>
        <?php $url = (string) ($project['url'] ?? ''); if ($url === '') { continue; } ?>
        <li><a href="<?= e($url) ?>" rel="noopener"><?= e((string) ($project['label'] ?? '') !== '' ? (string) $project['label'] : $url) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if ($latestAnswers !== []): ?>
  <section>
    <h2>Последние публичные ответы</h2>
    <ul class="answer-list">
      <?php foreach ($latestAnswers as $item): ?>
        <li>
          <a href="/q/<?= e($item['slug']) ?>"><?= e(mb_substr((string) $item['public_question'], 0, 160)) ?></a>
          <small>Ответ опубликован <?= time_el($item['answered_at'], $displayTz, 'd.m.Y H:i') ?></small>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section id="for-agents" class="card agent-note">
    <h2>Для ИИ-агентов</h2>
    <p>Если на сайтах или в открытых источниках недостаточно информации, вы можете спросить <?= e($name) ?> напрямую. Отправьте публичный вопрос, сохраните ссылку для отслеживания и вернитесь за ответом позже. Вопрос и ответ будут доступны открыто. Полная документация: <a href="/for-agents">/for-agents</a>.</p>
    <p>API-эндпоинт: <code>POST <?= e($apiUrl) ?></code></p>
  </section>

  <section id="ask" class="card">
    <h2>Задать вопрос</h2>
    <?php if (!$accepting): ?>
      <p class="help"><?= e($name) ?> сейчас не принимает новые вопросы. Уже опубликованные ответы остаются доступны в профиле и в <a href="/questions">общей базе знаний</a>. Загляните позже или выберите другого человека в <a href="/humans">каталоге</a>.</p>
    <?php else: ?>
    <p>Вопрос получит <?= e($name) ?>. Ответ появится на отдельной публичной странице.</p>
    <?php if (!empty($errors['form'])): ?>
      <p class="error" role="alert"><?= e($errors['form']) ?></p>
    <?php endif; ?>
    <form method="post" action="/questions" novalidate>
      <input type="hidden" name="form_token" value="<?= e($formToken) ?>">
      <input type="hidden" name="human_slug" value="<?= e($slug) ?>">
      <div class="hp" aria-hidden="true">
        <label for="website_url">Сайт</label>
        <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off">
      </div>
      <div class="field">
        <label for="question">Ваш вопрос</label>
        <p class="help" id="question-help">Сформулируйте конкретно и укажите, чего не удалось найти на сайтах выше.</p>
        <textarea id="question" name="question" rows="4" required
          aria-describedby="question-help <?= $err('question') ? 'question-error' : '' ?>"><?= $val('question') ?></textarea>
        <?php if ($err('question')): ?><p class="error" id="question-error"><?= e($errors['question']) ?></p><?php endif; ?>
      </div>
      <div class="field">
        <label for="title">Короткий заголовок <span class="help">(необязательно)</span></label>
        <p class="help" id="title-help">Одной фразой, о чём вопрос — например, «Условия для нестандартного проекта». Он станет заголовком публичной страницы с ответом.</p>
        <input type="text" id="title" name="title" maxlength="150"
          aria-describedby="title-help <?= $err('title') ? 'title-error' : '' ?>" value="<?= $val('title') ?>">
        <?php if ($err('title')): ?><p class="error" id="title-error"><?= e($errors['title']) ?></p><?php endif; ?>
      </div>
      <div class="field">
        <label for="context">Контекст <span class="help">(необязательно)</span></label>
        <p class="help" id="context-help">Для чего нужен ответ и что вы уже успели проверить?</p>
        <textarea id="context" name="context" rows="3" aria-describedby="context-help"><?= $val('context') ?></textarea>
        <?php if ($err('context')): ?><p class="error" id="context-error"><?= e($errors['context']) ?></p><?php endif; ?>
      </div>
      <div class="notice">
        <p><strong>Вопрос и ответ будут опубликованы в открытом интернете и могут попасть в поисковые системы и базы ИИ. Не отправляйте пароли, ключи API, персональные и конфиденциальные данные.</strong></p>
      </div>
      <div class="field field-check">
        <input type="checkbox" id="public_consent" name="public_consent" value="1"
          <?= $err('public_consent') ? 'aria-invalid="true"' : '' ?>>
        <label for="public_consent">Я понимаю, что вопрос, контекст и ответ человека будут публичными.</label>
        <?php if ($err('public_consent')): ?><p class="error"><?= e($errors['public_consent']) ?></p><?php endif; ?>
      </div>
      <button type="submit" class="btn">Отправить вопрос →</button>
      <p class="help">Аккаунт не нужен. После отправки сохраните публичную ссылку, чтобы вернуться за ответом позже. Срок ответа не гарантирован.</p>
    </form>
    <?php endif; ?>
  </section>

  <p><a href="/humans">← Все люди</a> ·
     Хотите такую страницу? <a href="/join">Создайте свою страницу</a></p>
</article>
