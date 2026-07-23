<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/** @var array $list  @var bool $saved  @var bool $deleted
 *  @var string $usersErr  @var string $selfName  @var string $adminBase  @var Security $security */
?>

<?php if ($saved): ?>
  <div class="alert alert--success" data-toast><?= e(t('Сохранено.')) ?></div>
<?php elseif ($deleted): ?>
  <div class="alert alert--success" data-toast><?= e(t('Пользователь удалён.')) ?></div>
<?php elseif ($usersErr !== ''): ?>
  <div class="alert alert--danger" data-toast><?= e($usersErr) ?></div>
<?php endif; ?>

<div class="filters">
  <button type="button" class="btn btn--primary js-user-new"><?= icon('plus') ?><?= e(t('Пользователь')) ?></button>
</div>

<div class="card">
  <table class="table">
    <tr>
      <th><?= e(t('Логин')) ?></th>
      <th><?= e(t('Имя')) ?></th>
      <th><?= e(t('Роль')) ?></th>
      <th><?= e(t('Последний вход')) ?></th>
      <th class="table__actions-head"><?= e(t('Действия')) ?></th>
    </tr>
    <?php foreach ($list as $item): $login = strtolower((string)($item['username'] ?? '')); ?>
      <tr>
        <td class="table__title">
          <?= e($login) ?>
          <?php if (empty($item['active'])): ?><span class="badge badge--draft" style="margin-left:6px"><?= e(t('отключён')) ?></span><?php endif; ?>
        </td>
        <td class="muted"><?= e((string)($item['display_name'] ?? '')) ?></td>
        <td><span class="badge badge--<?= e((string)($item['role'] ?? '')) ?>"><?= e((string)($item['role'] ?? '')) ?></span></td>
        <td class="muted"><?= !empty($item['last_login']) ? e(date('d.m.Y H:i', strtotime((string)$item['last_login']))) : '—' ?></td>
        <td class="table__actions">
          <button type="button" class="btn btn--small btn--secondary js-user-edit"
                  data-username="<?= e($login) ?>"
                  data-display-name="<?= e((string)($item['display_name'] ?? '')) ?>"
                  data-email="<?= e((string)($item['email'] ?? '')) ?>"
                  data-role="<?= e((string)($item['role'] ?? 'author')) ?>"
                  data-active="<?= !empty($item['active']) ? '1' : '0' ?>"
                  data-self="<?= $login === $selfName ? '1' : '0' ?>"><?= icon('pen') ?></button>
          <?php if ($login !== $selfName): ?>
            <button type="button" class="btn btn--small btn--danger js-user-delete"
                    data-name="<?= e($login) ?>" data-title="<?= e($login) ?>"><?= icon('trash') ?></button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<!-- Модальное окно создания/редактирования пользователя -->
<div class="modal" id="user-edit-modal" hidden>
  <div class="modal__box">
    <h3 class="modal__title" id="user-edit-heading"><?= e(t('Новый пользователь')) ?></h3>
    <form method="post" action="<?= e($adminBase) ?>users/save/" id="user-edit-form">
      <?= $security->csrfField() ?>
      <label class="field">
        <span class="field__label"><?= e(t('Логин (латиница)')) ?></span>
        <input type="text" name="username" id="user-edit-username" required pattern="[a-zA-Z0-9_-]{1,64}">
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Отображаемое имя')) ?></span>
        <input type="text" name="display_name" id="user-edit-display-name">
      </label>
      <label class="field">
        <span class="field__label">Email (<?= e(t('для восстановления пароля')) ?>)</span>
        <input type="email" name="email" id="user-edit-email">
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Роль')) ?></span>
        <select name="role" id="user-edit-role">
          <?php foreach (UserManager::ROLES as $r): ?>
            <option value="<?= e($r) ?>"><?= e($r) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field__label" id="user-edit-password-label"><?= e(t('Пароль')) ?></span>
        <input type="password" name="password" id="user-edit-password" autocomplete="new-password"
               minlength="<?= (int)UserManager::MIN_PASSWORD ?>">
      </label>
      <div class="modal__actions">
        <label class="field--check">
          <input type="checkbox" name="active" value="1" id="user-edit-active">
          <span><?= e(t('Активен')) ?></span>
        </label>
        <button type="button" class="btn btn--secondary btn--icon js-modal-close" title="<?= e(t('Отмена')) ?>" aria-label="<?= e(t('Отмена')) ?>"><?= icon('x') ?></button>
        <button type="submit" class="btn btn--primary btn--icon" title="<?= e(t('Сохранить')) ?>" aria-label="<?= e(t('Сохранить')) ?>"><?= icon('tick') ?></button>
      </div>
    </form>
  </div>
</div>

<!-- Подтверждение удаления пользователя -->
<div class="modal" id="user-delete-modal" hidden>
  <div class="modal__box">
    <h3 class="modal__title"><?= e(t('Удалить пользователя?')) ?></h3>
    <p class="modal__text">«<span id="user-delete-title"></span>» <?= e(t('будет удалён. Его посты останутся на сайте.')) ?></p>
    <form method="post" action="<?= e($adminBase) ?>users/delete/">
      <?= $security->csrfField() ?>
      <input type="hidden" name="name" id="user-delete-name" value="">
      <div class="modal__actions">
        <button type="button" class="btn btn--secondary btn--icon js-modal-close" title="<?= e(t('Отмена')) ?>" aria-label="<?= e(t('Отмена')) ?>"><?= icon('x') ?></button>
        <button type="submit" class="btn btn--danger btn--icon" title="<?= e(t('Удалить')) ?>" aria-label="<?= e(t('Удалить')) ?>"><?= icon('trash') ?></button>
      </div>
    </form>
  </div>
</div>
