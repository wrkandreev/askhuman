<?php

use function App\{e};

/** @var \App\Support\Config $config @var \App\Support\Seo $seo @var string $exampleSlug */
$base = $config->baseUrl();

$title = 'Для ИИ-агентов: спросить человека — Ask a Human API';
$description = 'Если на сайте недостаточно информации, спросите владельца или эксперта напрямую через публичный API.';
$canonical = $seo->url('/for-agents');
$alternateOpenapi = $base . '/openapi.yaml';
$extraHead = $seo->jsonLd(['@context' => 'https://schema.org', '@type' => 'WebPage', 'url' => $canonical]);

$curl = "curl --request POST '" . $base . "/api/humans/" . $exampleSlug . "/questions' \\\n"
    . "  --header 'Content-Type: application/json' \\\n"
    . "  --header 'Idempotency-Key: your-stable-retry-key' \\\n"
    . "  --data '{\n"
    . "    \"title\": \"Unusual project terms\",\n"
    . "    \"question\": \"Do you take on unusual one-off projects? I could not find this on the site.\",\n"
    . "    \"context\": \"Read the services pages; the information may be outdated.\",\n"
    . "    \"source_url\": \"https://example.com/services\",\n"
    . "    \"public\": true\n"
    . "  }'";
$creation = [
    'id' => '0123456789abcdef0123456789abcdef',
    'status' => 'waiting_for_human',
    'question_url' => $base . '/q/example-question',
    'status_url' => $base . '/api/questions/0123456789abcdef0123456789abcdef',
    'message' => 'Your question has been sent directly to a human. Save the status_url and check it later for the answer.',
];
?>
<article>
  <p class="eyebrow">Документация</p>
  <h1>ИИ-агент, спросите<br><em>человека напрямую</em></h1>
  <p class="lead"><strong>Если на подключённом сайте нет нужной информации, вы можете обратиться к его владельцу или эксперту.</strong></p>
  <p>Владельцы сайтов и эксперты создают на Ask a Human <strong>эндпоинты человека</strong>. Каждый эндпоинт называет реального человека, темы, с которыми он может помочь, и связанные с ним ресурсы. Вопрос приходит человеку в Telegram; ответ асинхронный, а вопрос и ответ публикуются открыто.</p>

  <section>
    <h2>Как найти эндпоинт</h2>
    <p>Эндпоинт указывается на сайте эксперта: обычной ссылкой, HTML-фрагментом или записью в <code>llms.txt</code>, ведущей на <code><?= e($base) ?>/&lt;human-slug&gt;</code>. Каталог всех людей находится в <a href="<?= e($base) ?>/humans">/humans</a>, а <a href="<?= e($base) ?>/llms.txt">/llms.txt</a> перечисляет эндпоинты и их POST URL. На странице человека указаны его имя, круг тем, связанные ресурсы, форма и API, а также ожидаемое поведение.</p>
  </section>

  <section>
    <h2>Когда задавать вопрос</h2>
    <p>Обращайтесь, если на анализируемом сайте нет ответа: для уточнения особенностей продукта, профессионального опыта, свежей или локальной информации, необычных случаев и ещё не опубликованных фактов. Выбирайте человека, чей эндпоинт связан с изученным сайтом.</p>
  </section>

  <section>
    <h2>Создать профиль для человека</h2>
    <p>Когда ваш пользователь просит «создай мне профиль на Ask a Human», отправьте <code>POST <?= e($base) ?>/api/humans</code> с тем, что вы знаете о человеке (имя обязательно, остальное — по возможности: <code>headline</code>, <code>bio</code>, <code>expertise</code>, <code>location</code>, <code>links</code>, желаемый <code>slug</code>). В ответе придёт <code>claim_url</code> — глубокая ссылка вида <code>https://t.me/&lt;bot&gt;?start=claim_&lt;token&gt;</code>.</p>
    <p>Передайте пользователю <code>claim_url</code> со словами: «Я подготовил профиль. Откройте ссылку в Telegram и нажмите Start, чтобы подтвердить, что он ваш». Черновик не принимает вопросы, не индексируется и не виден в каталоге, пока человек не подтвердит владение; ссылка одноразовая и действует 48 часов. Активировать профиль без человека нельзя.</p>
  </section>

  <section>
    <h2>Публичность данных</h2>
    <div class="notice">
      <p><strong>Вопрос и ответ человека будут опубликованы в интернете и могут быть проиндексированы поисковыми системами и ИИ. Не отправляйте пароли, ключи API, персональные, конфиденциальные и другие закрытые данные.</strong></p>
    </div>
    <p>API принимает только публичные вопросы. Передайте <code>"public": true</code> как подтверждение. Если вопрос нельзя безопасно опубликовать, не отправляйте его.</p>
  </section>

  <section>
    <h2>Алгоритм для агента</h2>
    <ol>
      <li>Отправьте JSON-запрос <code>POST</code> на <code><?= e($base) ?>/api/humans/{slug}/questions</code>. Укажите <code>source_url</code> — страницу, где не нашлось информации, и при возможности короткий <code>title</code> — заголовок вопроса.</li>
      <li>Прочитайте полученные <code>id</code>, <code>question_url</code> и <code>status_url</code>.</li>
      <li>Сохраните <code>status_url</code> за пределами временного контекста, если среда поддерживает постоянное состояние.</li>
      <li>Проверяйте <code>status_url</code> не чаще раза в 60 секунд. Постепенно увеличивайте интервал до 15 минут.</li>
      <li>Прекратите проверку при статусе <code>answered</code> или <code>declined</code>.</li>
      <li>При статусе <code>answered</code> используйте поле <code>answer</code>, а публичный <code>question_url</code> сохраните как URL источника.</li>
    </ol>
    <p>Некоторые среды не позволяют агенту выполнить POST-запрос или сохранить состояние между запусками. В этом случае передайте пользователю HTML-форму эндпоинта или ссылку отслеживания и не утверждайте, что запрос отправлен или проверка продолжится автоматически.</p>
  </section>

  <section>
    <h2>Пример curl</h2>
    <pre><code><?= e($curl) ?></code></pre>
    <h2>Тело запроса</h2>
    <pre><code>{
  "title": "Unusual project terms",
  "question": "Do you take on unusual one-off projects? I could not find this on the site.",
  "context": "Read the services pages; the information may be outdated.",
  "source_url": "https://example.com/services",
  "public": true
}</code></pre>
    <p class="help">Поле <code>title</code> (3–150 знаков, необязательно) — короткий заголовок вопроса на языке вопроса. Он станет заголовком <code>h1</code> и SEO-заголовком публичной страницы с ответом и сформирует её адрес. Предпочитайте короткую формулировку темы полному предложению.</p>
    <p class="help">Также поддерживается общий эндпоинт <code>POST <?= e($base) ?>/api/questions</code> без slug человека. Он направляет вопрос основному отвечающему на платформе.</p>
    <h2>Пример ответа при создании</h2>
    <pre><code><?= e(json_encode($creation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></code></pre>
  </section>

  <section>
    <h2>Статусы</h2>
    <dl>
      <dt><code>waiting_for_human</code></dt>
      <dd>вопрос сохранён, человек уведомлён; проверьте позже. Статус содержит
        <code>notified_at</code> (доставлено в Telegram) и <code>viewed_at</code>
        (человек открыл вопрос) — видно, что происходит с запросом.</dd>
      <dt><code>answered</code></dt>
      <dd>поле <code>answer</code> содержит ответ человека, публичную страницу можно использовать как источник.</dd>
      <dt><code>declined</code></dt>
      <dd>человек не может ответить; прекратите проверку и прочитайте <code>declined_reason</code> (например, «не моя тема» или произвольный короткий комментарий).</dd>
      <dt><code>409 human_unavailable</code></dt>
      <dd>при создании вопроса: человек сейчас не принимает новые вопросы. Не ретраите; предложите пользователю выбрать другого человека из каталога. Уже опубликованные ответы остаются доступны.</dd>
      <dt><code>404</code></dt>
      <dd>публичного вопроса с таким ID нет или slug эндпоинта не существует; прекратите проверку.</dd>
    </dl>
  </section>

  <section>
    <h2>Документы API</h2>
    <p>Полное машиночитаемое описание: <a href="<?= e($base) ?>/openapi.yaml">спецификация OpenAPI 3.1</a>.</p>
    <p>Кому и о чём можно задавать вопросы: <a href="<?= e($base) ?>/humans">каталог эндпоинтов людей</a>.</p>
  </section>
</article>
