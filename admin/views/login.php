<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/** @var string $error  @var bool $blocked  @var string $csrfField  @var string $siteTitle  @var string $adminBase  @var string $siteLogo */
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
  <title><?= e(t('Вход')) ?> / <?= e($siteTitle) ?></title>
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
  <p class="login-card__subtitle"><?= e(t('Панель управления')) ?></p>

  <?php if (!empty($resetDone)): ?>
    <div class="alert alert--success"><?= e(t('Пароль обновлён — войдите с новым паролем.')) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert--danger"><?= e($error) ?></div>
  <?php endif; ?>

  <!-- action не указан: POST уходит на текущий URL — вход не зависит от ЧПУ -->
  <form method="post" <?= $blocked ? 'class="is-disabled"' : '' ?>>
    <?= $csrfField ?>
    <label class="field">
      <span class="field__label"><?= e(t('Имя пользователя')) ?></span>
      <input type="text" name="username" required autofocus autocomplete="username" <?= $blocked ? 'disabled' : '' ?>>
    </label>
    <label class="field">
      <span class="field__label"><?= e(t('Пароль')) ?></span>
      <input type="password" name="password" required autocomplete="current-password" <?= $blocked ? 'disabled' : '' ?>>
    </label>
    <button type="submit" class="btn btn--primary btn--block" <?= $blocked ? 'disabled' : '' ?>><?= e(t('Войти')) ?></button>
  </form>

  <p class="muted" style="text-align:center;margin:16px 0 0">
    <a href="<?= e($adminBase) ?>reset/"><?= e(t('Забыли пароль?')) ?></a>
  </p>
</div>

</body>
</html>
