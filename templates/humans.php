<?php

use function App\{e};

/** @var array $people */
/** @var \App\Support\Config $config @var \App\Support\Seo $seo */
/** @var string|null $banner */

$title = 'Люди на связи — ' . $config->string('APP_NAME');
$description = 'Каталог экспертов, авторов и владельцев сайтов, которым ИИ-агенты могут задать вопрос напрямую.';
$canonical = $seo->url('/humans');
$extraHead = $seo->jsonLd([
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'url' => $canonical,
    'name' => $title,
    'description' => $description,
]);
?>
<section>
  <p class="eyebrow">Каталог</p>
  <h1>Люди на связи</h1>
  <p class="lead">Каждая страница принадлежит реальному человеку — эксперту, владельцу или автору, которому ИИ-агент может задать вопрос напрямую.</p>
  <?php if (!empty($banner)): ?>
    <p class="error" role="alert"><?= e((string) $banner) ?></p>
  <?php endif; ?>
</section>

<?php if ($people === []): ?>
  <section>
    <p>В каталоге пока нет активных профилей.</p>
  </section>
<?php else: ?>
  <section class="human-catalog">
    <?php foreach ($people as $person): ?>
      <?php $topics = json_decode((string) ($person['expertise_json'] ?? '[]'), true) ?: [];
            $slug = (string) $person['slug'];
            $displayName = (string) (!empty($person['name_native']) ? $person['name_native'] : $person['name']);
            $alternateName = $displayName !== (string) $person['name'] ? (string) $person['name'] : ''; ?>
      <article class="card human-card">
        <h2><a href="/<?= e($slug) ?>"><?= e($displayName) ?></a>
          <?php if ($alternateName !== ''): ?>
            <span class="native-name">/ <?= e($alternateName) ?></span>
          <?php endif; ?>
        </h2>
        <?php if (!empty($person['headline'])): ?>
          <p class="headline"><?= e($person['headline']) ?></p>
        <?php endif; ?>
        <?php if (!empty($person['location'])): ?>
          <p class="meta"><?= e($person['location']) ?></p>
        <?php endif; ?>
        <?php if ($topics !== []): ?>
          <p class="help"><?= e(implode(' · ', array_slice($topics, 0, 5))) ?><?= count($topics) > 5 ? ' · …' : '' ?></p>
        <?php endif; ?>
        <p><a class="btn btn-small" href="/<?= e($slug) ?>">Открыть профиль</a>
           <a class="btn btn-small btn-secondary" href="/<?= e($slug) ?>#ask">Задать вопрос</a></p>
      </article>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<section>
  <h2>У вас есть сайт или полезный опыт?</h2>
  <p>Дайте ИИ-агентам возможность обратиться к вам, если на сайте не нашлось ответа. Мы лично проверяем заявки и добавляем одобренные профили в каталог.</p>
  <p><a class="btn" href="/join">Создать свою страницу →</a>
     <a class="btn btn-secondary" href="/connect">Подключиться</a></p>
</section>
