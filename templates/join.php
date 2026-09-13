<?php

use function App\{e};

/** @var \App\Support\Config $config @var \App\Support\Seo $seo */
/** @var string $formToken @var array $errors @var array $old */

$title = 'Создать свою страницу — ' . $config->string('APP_NAME');
$description = 'Создайте публичную страницу, через которую ИИ-агенты смогут задавать вам вопросы.';
$canonical = $seo->url('/join');
$extraHead = $seo->jsonLd(['@context' => 'https://schema.org', '@type' => 'WebPage', 'url' => $canonical]);
$old = $old ?? [];
$err = fn (string $k): ?string => isset($errors[$k]) ? (string) $errors[$k] : null;
$val = fn (string $k): string => e($old[$k] ?? '');
?>
<section>
  <p class="eyebrow">Стать доступнее для ИИ</p>
  <h1>Создайте свою<br><em>страницу для ИИ-агентов</em></h1>
  <p class="lead">Это публичная страница, через которую ИИ-агенты смогут задать вам вопрос, если не найдут ответ на вашем сайте.</p>
  <p>Заполните короткую анкету: кто вы и в каких темах можете помочь. После проверки профиль появится в <a href="/humans">каталоге людей</a>. Мы лично согласуем, как вопросы будут приходить вам в Telegram.</p>
</section>

<section class="card">
  <h2>Расскажите о себе</h2>
  <?php if (!empty($errors['form'])): ?>
    <p class="error" role="alert"><?= e($errors['form']) ?></p>
  <?php endif; ?>
  <form method="post" action="/join" novalidate>
    <input type="hidden" name="form_token" value="<?= e($formToken) ?>">
    <div class="hp" aria-hidden="true">
      <label for="website_url">Сайт</label>
      <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off">
    </div>

    <div class="field">
      <label for="name">Имя и фамилия</label>
      <input type="text" id="name" name="name" maxlength="100" required value="<?= $val('name') ?>"
        <?= $err('name') ? 'aria-invalid="true" aria-describedby="name-error"' : '' ?>>
      <?php if ($err('name')): ?><p class="error" id="name-error"><?= e($errors['name']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="name_native">Имя на другом языке <span class="help">(необязательно)</span></label>
      <input type="text" id="name_native" name="name_native" maxlength="100" value="<?= $val('name_native') ?>">
    </div>

    <div class="field">
      <label for="location">Город и страна <span class="help">(необязательно)</span></label>
      <input type="text" id="location" name="location" maxlength="100" placeholder="Например, Москва, Россия" value="<?= $val('location') ?>">
    </div>

    <div class="field">
      <label for="headline">Коротко о вас</label>
      <p class="help">Например: «Бухгалтер из Твери» или «Основатель студии продуктового дизайна».</p>
      <input type="text" id="headline" name="headline" maxlength="120" required value="<?= $val('headline') ?>"
        <?= $err('headline') ? 'aria-invalid="true" aria-describedby="headline-error"' : '' ?>>
      <?php if ($err('headline')): ?><p class="error" id="headline-error"><?= e($errors['headline']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="bio">На какие вопросы вы можете ответить?</label>
      <p class="help">Опишите опыт, знания или проекты, с которыми вы связаны. От 50 до 2 000 знаков.</p>
      <textarea id="bio" name="bio" rows="5" required
        <?= $err('bio') ? 'aria-invalid="true" aria-describedby="bio-error"' : '' ?>><?= $val('bio') ?></textarea>
      <?php if ($err('bio')): ?><p class="error" id="bio-error"><?= e($errors['bio']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="expertise">Темы <span class="help">(необязательно)</span></label>
      <p class="help">По одной теме в строке, не больше 15. Например: налоги, веб-разработка, Нижний Новгород.</p>
      <textarea id="expertise" name="expertise" rows="4"><?= $val('expertise') ?></textarea>
      <?php if ($err('expertise')): ?><p class="error"><?= e($errors['expertise']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="links">Сайты и проекты <span class="help">(необязательно)</span></label>
      <p class="help">По одной ссылке в строке, не больше 5. Формат: <code>Название | https://example.com</code> или просто URL.</p>
      <textarea id="links" name="links" rows="3" placeholder="Мой блог | https://example.com"><?= $val('links') ?></textarea>
      <?php if ($err('links')): ?><p class="error"><?= e($errors['links']) ?></p><?php endif; ?>
    </div>

    <div class="notice">
      <p><strong>После одобрения профиль будет опубликован в открытом интернете и может попасть в поисковые системы и базы ИИ. Не указывайте пароли, ключи API, чужие персональные данные и другую закрытую информацию.</strong></p>
    </div>

    <div class="field field-check">
      <input type="checkbox" id="public_consent" name="public_consent" value="1"
        <?= $err('public_consent') ? 'aria-invalid="true"' : '' ?>>
      <label for="public_consent">Я понимаю, что после одобрения мой профиль будет публичным.</label>
      <?php if ($err('public_consent')): ?><p class="error"><?= e($errors['public_consent']) ?></p><?php endif; ?>
    </div>

    <button type="submit" class="btn">Отправить заявку →</button>
    <p class="help">Аккаунт не нужен. После отправки вы получите личную ссылку статуса заявки и код активации через Telegram — с ним профиль заработает сразу.</p>
  </form>
</section>
