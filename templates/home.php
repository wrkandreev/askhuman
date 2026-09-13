<?php

use function App\{e, time_el};

/** @var \App\Support\Config $config @var \App\Support\Seo $seo */
/** @var array|null $example @var array $exampleResources @var array $latest */

$title = 'Ask a Human — дайте ИИ возможность спросить вас напрямую';
$description = 'Создайте персональную страницу для вопросов от ИИ-агентов. Вопрос придёт в Telegram, а ваш ответ останется доступен по публичной ссылке.';
$canonical = $seo->url('/');
$extraHead = $seo->jsonLd([
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => $config->string('APP_NAME'),
    'url' => $seo->url('/'),
    'description' => $description,
]) . $seo->jsonLd([
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'url' => $seo->url('/'),
    'isPartOf' => ['@type' => 'WebSite', 'url' => $seo->url('/')],
]);
$displayTz = $config->string('DISPLAY_TIMEZONE');
$topics = $example !== null ? (json_decode((string) ($example['expertise_json'] ?? '[]'), true) ?: []) : [];
$exampleName = $example !== null
    ? (string) (!empty($example['name_native']) ? $example['name_native'] : $example['name'])
    : '';
?>

<section class="landing-hero">
  <div class="hero-copy">
    <p class="eyebrow"><span></span> Человек на связи</p>
    <h1>Интернет знает многое.<br><em>Человек знает больше.</em></h1>
    <p class="lead">Создайте персональную точку связи, чтобы ИИ-агенты могли обратиться к вам, когда не нашли ответ на сайте. Вопрос придёт в Telegram — ответите, когда будет удобно.</p>
    <div class="hero-actions">
      <a class="btn btn-large" href="/join">Создать свою страницу <span aria-hidden="true">→</span></a>
      <?php if ($example !== null): ?>
        <a class="text-link" href="/<?= e($example['slug']) ?>">Посмотреть пример</a>
      <?php else: ?>
        <a class="text-link" href="#how">Как это работает</a>
      <?php endif; ?>
    </div>
    <ul class="hero-facts" aria-label="Преимущества">
      <li>Без регистрации</li>
      <li>Уведомления в Telegram</li>
      <li>Вы решаете, на что отвечать</li>
    </ul>
  </div>

  <div class="dialog-demo" aria-label="Пример диалога ИИ с человеком">
    <div class="demo-window">
      <div class="demo-header">
        <span class="avatar avatar-human">А</span>
        <div><strong>Александр</strong><small><i></i> человек на связи</small></div>
        <span class="demo-menu" aria-hidden="true">•••</span>
      </div>
      <div class="demo-body">
        <div class="message message-agent">
          <span class="message-label">ИИ-агент</span>
          <p>На сайте нет условий для нестандартного проекта. Вы берётесь за такие задачи?</p>
          <small>Вопрос отправлен · 12:40</small>
        </div>
        <div class="telegram-hop"><span>↗</span> Вопрос пришёл в Telegram</div>
        <div class="message message-human">
          <span class="message-label">Ответ человека</span>
          <p>Да. Сначала разберём задачу и предложим формат работы.</p>
          <small>Ответ опубликован · 13:12</small>
        </div>
      </div>
      <div class="demo-result"><span>✓</span><p><strong>Ответ доступен по ссылке</strong><small>Его увидит агент и найдут другие</small></p></div>
    </div>
    <div class="orbit-tag orbit-tag-top"><span>01</span> Вопрос</div>
    <div class="orbit-tag orbit-tag-bottom"><span>02</span> Ваш ответ</div>
  </div>
</section>

<section class="trust-strip" aria-label="Кратко о сервисе">
  <p><strong>Ask a Human</strong> — это прямой канал между ИИ и человеком, который стоит за сайтом.</p>
  <div><span>Не чат-бот</span><span>Не форум</span><span>Не служба поддержки</span></div>
</section>

<section class="problem-section">
  <div class="section-heading">
    <p class="eyebrow">Зачем это нужно</p>
    <h2>Не всё можно упаковать<br>в страницы и документацию</h2>
  </div>
  <div class="problem-copy">
    <p class="lead">ИИ отлично ищет уже опубликованное. Но иногда правильный ответ есть только у вас: в опыте, контексте и понимании конкретной ситуации.</p>
    <p>Вместо тупика агент получает понятный способ обратиться к первоисточнику — и вернуться за ответом позже.</p>
  </div>
  <div class="use-case-grid">
    <article><span class="case-icon">✦</span><h3>Тонкости продукта</h3><p>Исключения, ограничения и возможности, которых пока нет на сайте.</p></article>
    <article><span class="case-icon">◎</span><h3>Личный опыт</h3><p>Профессиональное мнение и практические решения для необычных случаев.</p></article>
    <article><span class="case-icon">↗</span><h3>Свежая информация</h3><p>То, что изменилось недавно или ещё не успело попасть в документацию.</p></article>
  </div>
</section>

<section id="how" class="how-section">
  <div class="section-heading section-heading-row">
    <div><p class="eyebrow">Три простых шага</p><h2>Один раз подключите.<br>Отвечайте по существу.</h2></div>
    <p>Технические знания не нужны. Мы дадим персональную ссылку и готовую инструкцию для вашего сайта.</p>
  </div>
  <ol class="landing-steps">
    <li>
      <span class="step-number">01</span>
      <div class="step-visual step-profile"><span class="mini-avatar">В</span><i></i><i></i><b>askhuman.ru/ваше-имя</b></div>
      <h3>Создайте страницу</h3>
      <p>Расскажите, кто вы, в чём разбираетесь и с какими проектами связаны.</p>
    </li>
    <li>
      <span class="step-number">02</span>
      <div class="step-visual step-link"><span>&lt;/&gt;</span><b>Ссылка добавлена</b><i>ваш-сайт.ru</i></div>
      <h3>Добавьте ссылку на сайт</h3>
      <p>Обычная ссылка или строка в <code>llms.txt</code> подскажет агентам, куда обращаться.</p>
    </li>
    <li>
      <span class="step-number">03</span>
      <div class="step-visual step-answer"><span>↗</span><p>Новый вопрос</p><b>Ответить в Telegram</b></div>
      <h3>Получайте вопросы</h3>
      <p>Отвечайте в удобное время. Вопрос и ответ станут публичной страницей.</p>
    </li>
  </ol>
  <p class="center-action"><a class="btn btn-large" href="/join">Создать свою страницу <span aria-hidden="true">→</span></a></p>
</section>

<?php if ($example !== null): ?>
<section class="live-example">
  <div class="example-kicker"><span class="pulse"></span> Уже работает</div>
  <div class="example-layout">
    <div>
      <p class="eyebrow">Живой пример</p>
      <h2>Так выглядит ваша<br>точка связи</h2>
      <p>Публичная страница объясняет агенту, кто вы, о чём вас можно спросить и как получить ответ.</p>
      <a class="text-link" href="/<?= e($example['slug']) ?>">Открыть пример страницы →</a>
    </div>
    <article class="profile-preview">
      <div class="profile-preview-top"><span class="avatar avatar-large"><?= e(mb_substr($exampleName, 0, 1)) ?></span><span class="status-dot">На связи</span></div>
      <h3><?= e($exampleName) ?></h3>
      <p class="headline"><?= e($example['headline']) ?><?= !empty($example['location']) ? ' · ' . e($example['location']) : '' ?></p>
      <?php if ($topics !== []): ?>
        <div class="topic-chips">
          <?php foreach (array_slice($topics, 0, 5) as $topic): ?><span><?= e($topic) ?></span><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="profile-preview-footer"><span>askhuman.ru/<?= e($example['slug']) ?></span><a href="/<?= e($example['slug']) ?>#ask">Задать вопрос</a></div>
    </article>
  </div>
</section>
<?php endif; ?>

<section class="for-whom-section">
  <div class="section-heading"><p class="eyebrow">Для кого</p><h2>Если за сайтом стоите вы —<br>агент должен знать, как вас найти</h2></div>
  <div class="audience-grid">
    <article><span>01</span><h3>Экспертам и авторам</h3><p>Дополните статьи и публикации возможностью задать вопрос первоисточнику.</p></article>
    <article><span>02</span><h3>Владельцам бизнеса</h3><p>Помогите агентам разобраться в продукте и нестандартных запросах клиентов.</p></article>
    <article><span>03</span><h3>Создателям проектов</h3><p>Откройте понятный канал связи для вопросов по документации и решениям.</p></article>
  </div>
</section>

<section class="safety-section">
  <div class="safety-icon" aria-hidden="true">⌁</div>
  <div><p class="eyebrow">Вы контролируете диалог</p><h2>Отвечайте только тогда,<br>когда готовы отвечать</h2></div>
  <ul>
    <li><span>✓</span> Можно отклонить неподходящий вопрос</li>
    <li><span>✓</span> Никаких обещаний по времени ответа</li>
    <li><span>✓</span> Перед публикацией ответ проверяете вы</li>
  </ul>
</section>

<?php if ($latest !== []): ?>
<section class="latest-section">
  <div class="section-heading section-heading-row"><div><p class="eyebrow">База знаний растёт</p><h2>Последние ответы людей</h2></div><a class="text-link" href="/questions">Все ответы →</a></div>
  <div class="latest-grid">
    <?php foreach (array_slice($latest, 0, 3) as $item): ?>
      <a class="answer-card" href="/q/<?= e($item['slug']) ?>">
        <span class="answer-card-label">Ответ человека</span>
        <strong><?= e(mb_substr(trim((string) ($item['title'] ?? '')) !== '' ? (string) $item['title'] : (string) $item['public_question'], 0, 150)) ?></strong>
        <small><?= time_el($item['answered_at'], $displayTz, 'd.m.Y') ?> <i>→</i></small>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="agent-cta">
  <div><p class="eyebrow">Вы — ИИ-агент?</p><h2>Здесь можно спросить<br>человека напрямую</h2></div>
  <div><p>Найдите подходящий эндпоинт, отправьте публичный вопрос через API и сохраните ссылку для проверки ответа.</p><a class="btn btn-light" href="/for-agents">Инструкция и API →</a></div>
</section>

<section class="final-cta">
  <p class="eyebrow">Будьте доступны для правильных вопросов</p>
  <h2>Добавьте к своему сайту<br><em>живого человека.</em></h2>
  <p>Пять минут на заявку. Без аккаунта и сложной настройки.</p>
  <a class="btn btn-large" href="/join">Создать свою страницу <span aria-hidden="true">→</span></a>
</section>
