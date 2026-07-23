<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/** @var array $backupsList  @var bool $backupOk  @var bool $backupDeleted  @var string $backupErr
 *  @var string $adminBase  @var Security $security */
?>

<?php if (!empty($backupOk)): ?>
  <div class="alert alert--success" data-toast><?= e(t('Бэкап создан.')) ?></div>
<?php elseif (!empty($backupDeleted)): ?>
  <div class="alert alert--success" data-toast><?= e(t('Бэкап удалён.')) ?></div>
<?php elseif ($backupErr !== ''): ?>
  <div class="alert alert--danger" data-toast><?= e(t('Не удалось создать бэкап:')) ?> <?= e($backupErr) ?></div>
<?php endif; ?>

<div class="filters">
  <form method="post" action="<?= e($adminBase) ?>backups/create/">
    <?= $security->csrfField() ?>
    <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('Бэкап')) ?></button>
  </form>
</div>

<div class="card">
<?php if (empty($backupsList)): ?>
  <p class="muted" style="padding:1rem"><?= e(t('Бэкапов пока нет.')) ?></p>
<?php else: ?>
  <table class="table table--striped">
    <thead>
      <tr>
        <th><?= e(t('Файл')) ?></th>
        <th><?= e(t('Размер')) ?></th>
        <th><?= e(t('Дата')) ?></th>
        <th class="table__actions-head"><?= e(t('Действия')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($backupsList as $b): ?>
        <tr>
          <td class="table__title"><?= e($b['name']) ?></td>
          <td class="muted"><?= e(number_format($b['size'] / 1048576, 1, '.', ' ')) ?> <?= e(t('МБ')) ?></td>
          <td class="muted"><?= e(date('d.m.Y H:i', $b['mtime'])) ?></td>
          <td class="table__actions">
            <a class="btn btn--small btn--secondary" title="<?= e(t('Скачать')) ?>"
               href="<?= e($adminBase) ?>backups/download/?file=<?= e(urlencode($b['name'])) ?>"><?= icon('download') ?></a>
            <button type="button" class="btn btn--small btn--danger js-backup-delete"
                    data-file="<?= e($b['name']) ?>" data-title="<?= e($b['name']) ?>"
                    title="<?= e(t('Удалить')) ?>"><?= icon('trash') ?></button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<!-- Модальное окно подтверждения удаления бэкапа -->
<div class="modal" id="backup-delete-modal" hidden>
  <div class="modal__box">
    <h3 class="modal__title"><?= e(t('Удалить бэкап?')) ?></h3>
    <p class="modal__text">«<span id="backup-delete-title"></span>» <?= e(t('будет удалён безвозвратно.')) ?></p>
    <form method="post" action="<?= e($adminBase) ?>backups/delete/">
      <?= $security->csrfField() ?>
      <input type="hidden" name="file" id="backup-delete-file" value="">
      <div class="modal__actions">
        <button type="button" class="btn btn--secondary btn--icon js-modal-close" title="<?= e(t('Отмена')) ?>" aria-label="<?= e(t('Отмена')) ?>"><?= icon('x') ?></button>
        <button type="submit" class="btn btn--danger btn--icon" title="<?= e(t('Удалить')) ?>" aria-label="<?= e(t('Удалить')) ?>"><?= icon('trash') ?></button>
      </div>
    </form>
  </div>
</div>
