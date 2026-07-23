<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/** @var string $resetMsg  @var string $resetErr  @var bool $tokenOk
 *  @var string $rUser  @var int $rExp  @var string $rTok
 *  @var string $csrfField  @var string $siteTitle  @var string $adminBase  @var string $cspNonce */
?>
<!DOCTYPE html>
<html lang="<?= e($adminLang ?? 'ru') ?>">
<head>
  <meta charset="UTF-8">
  <!-- Панель браузера в цвет темы: --canvas из admin.css (светлая #f9fafb,
       тёмная #0F1117). Стоит ДО скрипта — тому нужно что-то найти. -->
  <meta name="theme-color" content="#f9fafb">
  <script nonce="<?= e($cspNonce) ?>">
    try {
      var t = localStorage.getItem('deeno-theme') || 'light';
      document.documentElement.dataset.theme = t;
      var m = document.querySelector('meta[name="theme-color"]');
      if (m && t === 'dark') m.setAttribute('content', '#0F1117');
    } catch (e) {}
  </script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e(t('Восстановление пароля')) ?> — <?= e($siteTitle) ?></title>
  <link rel="stylesheet" href="<?= e($adminBase) ?>assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/admin.css') ?>">
</head>
<body class="login-page">

<div class="login-card">
  <div class="login-card__brand">
    <?php if (($siteLogo ?? '') !== ''): ?>
      <img class="login-card__logo" src="<?= e($siteLogo) ?>" alt="">
    <?php endif; ?>
    <h1 class="login-card__title"><?= e($siteTitle) ?></h1>
  </div>
  <p class="login-card__subtitle"><?= e(t('Восстановление пароля')) ?></p>

  <?php if ($resetErr !== ''): ?>
    <div class="alert alert--danger"><?= e($resetErr) ?></div>
  <?php endif; ?>
  <?php if ($resetMsg !== ''): ?>
    <div class="alert alert--success"><?= e($resetMsg) ?></div>
  <?php endif; ?>

  <?php if ($tokenOk): ?>
    <!-- Токен из письма верен: задаём новый пароль -->
    <form method="post">
      <?= $csrfField ?>
      <input type="hidden" name="do" value="set">
      <input type="hidden" name="u" value="<?= e($rUser) ?>">
      <input type="hidden" name="exp" value="<?= (int)$rExp ?>">
      <input type="hidden" name="t" value="<?= e($rTok) ?>">
      <label class="field">
        <span class="field__label"><?= e(t('Новый пароль')) ?> (<?= (int)UserManager::MIN_PASSWORD ?>+)</span>
        <input type="password" name="password" required minlength="<?= (int)UserManager::MIN_PASSWORD ?>" autofocus autocomplete="new-password">
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Пароль ещё раз')) ?></span>
        <input type="password" name="password2" required minlength="<?= (int)UserManager::MIN_PASSWORD ?>" autocomplete="new-password">
      </label>
      <button type="submit" class="btn btn--primary btn--block"><?= e(t('Сохранить пароль')) ?></button>
    </form>
  <?php elseif ($resetMsg === ''): ?>
    <form method="post">
      <?= $csrfField ?>
      <input type="hidden" name="do" value="request">
      <label class="field">
        <span class="field__label"><?= e(t('Имя пользователя')) ?></span>
        <input type="text" name="username" required autofocus>
      </label>
      <button type="submit" class="btn btn--primary btn--block"><?= e(t('Отправить ссылку на email')) ?></button>
    </form>
  <?php endif; ?>

  <p class="muted" style="text-align:center;margin:16px 0 0">
    <a href="<?= e($adminBase) ?>"><?= e(t('Вернуться ко входу')) ?></a>
  </p>
</div>

</body>
</html>
