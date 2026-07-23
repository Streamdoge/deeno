<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/** @var ?array $me  @var bool $saved  @var string $profileErr  @var string $adminBase  @var Security $security */
?>

<?php if ($saved): ?>
  <div class="alert alert--success" data-toast><?= e(t('Сохранено.')) ?></div>
<?php endif; ?>
<?php if ($profileErr !== ''): ?>
  <div class="alert alert--danger"><?= e($profileErr) ?></div>
<?php endif; ?>

<div class="grid-2">
  <div class="card">
    <h2 class="card__title"><?= e(t('Мой профиль')) ?></h2>
    <form method="post" action="<?= e($adminBase) ?>profile/">
      <?= $security->csrfField() ?>
      <label class="field">
        <span class="field__label"><?= e(t('Логин')) ?></span>
        <input type="text" value="<?= e((string)($me['username'] ?? '')) ?>" readonly>
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Отображаемое имя')) ?></span>
        <input type="text" name="display_name" value="<?= e((string)($me['display_name'] ?? '')) ?>">
      </label>
      <label class="field">
        <span class="field__label">Email (<?= e(t('для восстановления пароля')) ?>)</span>
        <input type="email" name="email" value="<?= e((string)($me['email'] ?? '')) ?>">
      </label>
      <hr class="sep">
      <label class="field">
        <span class="field__label"><?= e(t('Новый пароль')) ?> (<?= e(t('пусто — не менять')) ?>)</span>
        <input type="password" name="password" minlength="<?= (int)UserManager::MIN_PASSWORD ?>" autocomplete="new-password">
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Пароль ещё раз')) ?></span>
        <input type="password" name="password2" minlength="<?= (int)UserManager::MIN_PASSWORD ?>" autocomplete="new-password">
      </label>
      <hr class="sep">
      <label class="field">
        <span class="field__label"><?= e(t('Текущий пароль (для подтверждения изменений)')) ?></span>
        <input type="password" name="current_password" required autocomplete="current-password">
      </label>
      <button type="submit" class="btn btn--primary"><?= e(t('Сохранить')) ?></button>
    </form>
  </div>

  <div class="card">
    <h2 class="card__title"><?= e(t('Учётная запись')) ?></h2>
    <dl class="sysinfo">
      <div class="sysinfo__row"><dt><?= e(t('Роль')) ?></dt><dd><span class="badge badge--<?= e((string)($me['role'] ?? '')) ?>"><?= e((string)($me['role'] ?? '')) ?></span></dd></div>
      <div class="sysinfo__row"><dt><?= e(t('Создан')) ?></dt><dd><?= !empty($me['created']) ? e(date('d.m.Y', strtotime((string)$me['created']))) : '—' ?></dd></div>
      <div class="sysinfo__row"><dt><?= e(t('Последний вход')) ?></dt><dd><?= !empty($me['last_login']) ? e(date('d.m.Y H:i', strtotime((string)$me['last_login']))) : '—' ?></dd></div>
    </dl>
    <p class="muted" style="margin-bottom:0">
      <?= e(t('Сессия действует 2 часа без активности и не дольше 24 часов с момента входа.')) ?>
    </p>
  </div>
</div>
