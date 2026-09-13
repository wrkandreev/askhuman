<?php

use function App\{e, paragraphs};

/** @var string $mode 'form'|'invalid'|'done'
 * @var array<string,mixed>|null $human
 * @var string $formToken @var array<string,string> $errors @var array<string,string> $old
 * @var \App\Support\Config $config @var \App\Support\Seo $seo @var string $base
 */

$name = $human !== null ? (string) $human['name'] : '';
$profileUrl = $human !== null ? $base . '/' . $human['slug'] : '';
$title = 'Редактирование профиля — Ask a Human';
$description = 'Одноразовая форма редактирования профиля эксперта.';
$val = fn (string $k): string => e($old[$k] ?? '');
$err = fn (string $k): ?string => isset($errors[$k]) ? (string) $errors[$k] : null;
?>
<article>
<?php if ($mode === 'invalid'): ?>
  <h1>Ссылка недействительна</h1>
  <p>Эта ссылка для редактирования не найдена, уже была использована или истекла (30 минут).</p>
  <p>Запросите новую в Telegram-боте командой <code>/edit</code>.</p>
<?php elseif ($mode === 'done'): ?>
  <h1>Профиль обновлён</h1>
  <p>Изменения сохранены и уже видны на публичной странице:
    <a href="<?= e($profileUrl) ?>"><?= e($profileUrl) ?></a></p>
  <p>Ссылка для редактирования стала недействительной. Для новой правки запросите ссылку командой <code>/edit</code> в боте.</p>
<?php else: ?>
  <p class="eyebrow">Одноразовая ссылка · действует 30 минут</p>
  <h1>Редактирование профиля</h1>
  <p class="help">Вы редактируете профиль <strong><?= e($name) ?></strong>. После сохранения ссылка станет недействительной.</p>
  <?php if (!empty($errors['form'])): ?>
    <p class="error" role="alert"><?= e((string) $errors['form']) ?></p>
  <?php endif; ?>
  <form method="post" action="" novalidate>
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
      <label for="location">Город, страна <span class="help">(необязательно)</span></label>
      <input type="text" id="location" name="location" maxlength="100" value="<?= $val('location') ?>">
    </div>
    <div class="field">
      <label for="headline">Короткое описание</label>
      <input type="text" id="headline" name="headline" maxlength="120" required value="<?= $val('headline') ?>"
        <?= $err('headline') ? 'aria-invalid="true" aria-describedby="headline-error"' : '' ?>>
      <?php if ($err('headline')): ?><p class="error" id="headline-error"><?= e($errors['headline']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label for="bio">О себе: на какие вопросы вы готовы отвечать</label>
      <textarea id="bio" name="bio" rows="5" required aria-describedby="bio-help
        <?= $err('bio') ? 'bio-error' : '' ?>"><?= $val('bio') ?></textarea>
      <p class="help" id="bio-help">50–2 000 знаков.</p>
      <?php if ($err('bio')): ?><p class="error" id="bio-error"><?= e($errors['bio']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label for="expertise">Темы <span class="help">(по одной в строке, 2–60 знаков, до 15)</span></label>
      <textarea id="expertise" name="expertise" rows="5"><?= $val('expertise') ?></textarea>
      <?php if ($err('expertise')): ?><p class="error"><?= e($errors['expertise']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label for="links">Ссылки <span class="help">(по одной в строке, формат «Название | URL», до 5)</span></label>
      <textarea id="links" name="links" rows="4" placeholder="Мой сайт | https://example.com"><?= $val('links') ?></textarea>
      <?php if ($err('links')): ?><p class="error"><?= e($errors['links']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label for="resources">Сайты и проекты <span class="help">(по одному в строке, формат «Название | URL | Описание»)</span></label>
      <textarea id="resources" name="resources" rows="4"><?= $val('resources') ?></textarea>
    </div>

    <button type="submit" class="btn">Сохранить изменения →</button>
  </form>
<?php endif; ?>
</article>
