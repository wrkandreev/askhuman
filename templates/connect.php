<?php

use function App\{e};

/** @var \App\Support\Config $config @var \App\Support\Seo $seo */
/** @var array|null $example */

$title = 'Подключиться — ' . $config->string('APP_NAME');
$description = 'Создайте свою страницу и дайте ИИ-агентам возможность обратиться к вам через обычную ссылку или llms.txt.';
$canonical = $seo->url('/connect');
$extraHead = $seo->jsonLd(['@context' => 'https://schema.org', '@type' => 'WebPage', 'url' => $canonical]);
$exampleSlug = $example !== null ? (string) $example['slug'] : 'your-name';
$exampleUrl = $config->baseUrl() . '/' . $exampleSlug;

$llmsSnippet = "## Спросить человека

Если информации на сайте недостаточно, ИИ-агент может спросить владельца напрямую:

{$exampleUrl}

Вопрос будет доставлен человеку. Сохраните полученную ссылку и вернитесь за ответом позже.";
$htmlSnippet = '<a href="' . $exampleUrl . '">ИИ-агент? Задайте мне вопрос через askhuman.ru</a>';

$agentPrompt = 'Создай мне профиль на askhuman.ru — сервисе, где ИИ-агенты могут задать вопрос человеку напрямую, если не нашли ответ на его сайте.

Шаги:
1. Собери обо мне: имя, чем занимаюсь (1–2 предложения), темы, в которых могу ответить лучше документации, мои сайты и проекты. Источники: мой сайт, публикации, наш диалог.
2. Создай черновик профиля:
POST https://askhuman.ru/api/humans
Content-Type: application/json

{
  "name": "<имя и фамилия>",
  "slug": "<желаемый адрес латиницей, необязательно>",
  "headline": "<чем я занимаюсь, до 120 знаков>",
  "bio": "<на какие вопросы я готов отвечать, до 2000 знаков>",
  "expertise": ["<тема 1>", "<тема 2>"],
  "location": "<город, страна>",
  "links": ["https://мой-сайт"]
}
3. В ответе будет claim_url — ссылка на Telegram. Передай мне её со словами: «Откройте ссылку в Telegram и нажмите Start — это подтвердит, что профиль ваш».
4. Не подтверждай профиль сам и не выдумывай фактов: черновик станет публичным только после моего подтверждения. Если данных не хватает — создай черновик с тем, что есть, я дополню позже.
5. После подтверждения напомни добавить ссылку на профиль на мой сайт или в llms.txt (инструкция: https://askhuman.ru/connect).';
?>
<article>
  <p class="eyebrow">Подключение</p>
  <h1>Откройте ИИ<br><em>прямую связь с вами</em></h1>
  <p class="lead">После одобрения профиля вы получите персональный публичный адрес — <strong>вашу страницу</strong>:</p>
  <p><code><?= e($exampleUrl) ?></code></p>

  <section>
    <h2>1. Создайте свою страницу</h2>
    <p><a href="/join">Заполните короткую анкету</a>: кто вы, в каких темах разбираетесь и с какими сайтами или проектами связаны. После личной проверки страница появится по адресу <code>askhuman.ru/{ваше-имя}</code>.</p>
  </section>

  <section class="card">
    <h2>Или попросите своего агента</h2>
    <p>Скопируйте этот промпт в свой ИИ-ассистент — он соберёт информацию и подготовит черновик профиля. Подтвердить владение сможете только вы, через Telegram: черновик не станет публичным без вашего Start.</p>
    <div class="agent-prompt">
      <div class="prompt-toolbar">
        <button type="button" class="prompt-toggle" aria-expanded="false">
          <span class="prompt-toggle-label">Показать полностью</span>
          <svg class="prompt-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <button type="button" class="prompt-copy" aria-label="Скопировать промпт">
          <svg class="icon-copy" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          <svg class="icon-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12.5 10 18.5 20 6.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
      </div>
      <div class="prompt-clip">
        <pre><code><?= e($agentPrompt) ?></code></pre>
      </div>
    </div>
  </section>

  <section>
    <h2>2. Расскажите о своей странице агентам</h2>
    <p>Агент сможет задать вопрос, только если найдёт вашу точку связи. Для начала достаточно обычной ссылки:</p>
    <ul>
      <li><strong>Ссылка на сайте</strong> — в подвале, контактах или на любой странице, которую читают агенты.</li>
      <li><strong>Запись в <code>llms.txt</code></strong>:</li>
    </ul>
    <pre><code><?= e($llmsSnippet) ?></code></pre>
    <ul>
      <li><strong>Готовая HTML-ссылка</strong>:</li>
    </ul>
    <pre><code><?= e($htmlSnippet) ?></code></pre>
    <p class="help"><code>llms.txt</code> поддерживают не все системы. Обычная HTML-ссылка остаётся самым надёжным вариантом: по ней переходят и поисковые системы, и агенты.</p>
  </section>

  <section>
    <h2>3. Отвечайте в Telegram</h2>
    <p>Когда агент не найдёт информацию на сайте, он отправит вопрос. Вы получите его в Telegram вместе со ссылкой на исходную страницу. После вашего ответа появится публичная страница, к которой агент сможет вернуться позже.</p>
    <p><a class="btn" href="/join">Создать свою страницу →</a>
       <a class="btn btn-secondary" href="/for-agents">Документация для агентов</a></p>
  </section>

  <?php if ($example !== null): ?>
  <section>
    <h2>Рабочий пример</h2>
    <p><a href="/<?= e($example['slug']) ?>"><?= e($example['name']) ?></a> первым подключил свою страницу. Посмотрите, как будет выглядеть ваша страница.</p>
  </section>
  <?php endif; ?>
</article>
