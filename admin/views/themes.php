<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/** @var array $themes  @var bool $activated  @var bool $installed  @var bool $deleted  @var string $themesErr
 *  @var string $adminBase  @var Security $security */
?>

<?php if ($activated): ?>
  <div class="alert alert--success" data-toast><?= e(t('Тема активирована.')) ?></div>
<?php elseif ($installed): ?>
  <div class="alert alert--success" data-toast><?= e(t('Тема установлена.')) ?></div>
<?php elseif ($deleted): ?>
  <div class="alert alert--success" data-toast><?= e(t('Тема удалена.')) ?></div>
<?php elseif ($themesErr !== ''): ?>
  <div class="alert alert--danger" data-toast><?= e($themesErr) ?></div>
<?php endif; ?>

<div class="filters">
  <form method="post" action="<?= e($adminBase) ?>themes/install/" enctype="multipart/form-data" id="theme-install-form">
    <?= $security->csrfField() ?>
    <label class="btn btn--primary">
      <?= icon('plus') ?><?= e(t('Тема')) ?>
      <input type="file" name="theme_zip" accept=".zip" required id="theme-zip-input" hidden>
    </label>
  </form>
</div>

<div class="theme-grid">
  <?php foreach ($themes as $t): ?>
    <div class="theme-card <?= $t['active'] ? 'theme-card--active' : '' ?>">
      <div class="theme-card__shot">
        <?php if ($t['screenshot'] !== ''): ?>
          <img src="<?= e($t['screenshot']) ?>" alt="<?= e($t['name']) ?>">
        <?php else: ?>
          <?= icon('layers') ?>
        <?php endif; ?>
      </div>
      <div class="theme-card__body">
        <div class="theme-card__head">
          <span class="theme-card__name"><?= e($t['name']) ?></span>
          <?php if ($t['version'] !== ''): ?>
            <span class="theme-card__version">v<?= e($t['version']) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($t['description'] !== ''): ?>
          <p class="theme-card__desc"><?= e($t['description']) ?></p>
        <?php endif; ?>
        <?php if ($t['author'] !== ''): ?>
          <p class="theme-card__author"><?= e(t('Автор')) ?>: <?= e($t['author']) ?></p>
        <?php endif; ?>
      </div>
      <div class="theme-card__actions">
        <?php if ($t['active']): ?>
          <span class="btn btn--small theme-card__active"><?= e(t('Активная')) ?></span>
        <?php else: ?>
          <form method="post" action="<?= e($adminBase) ?>themes/activate/" class="theme-card__activate">
            <?= $security->csrfField() ?>
            <input type="hidden" name="name" value="<?= e($t['dir']) ?>">
            <button type="submit" class="btn btn--small btn--secondary"><?= e(t('Активировать')) ?></button>
          </form>
          <?php if (!$t['isDefault']): ?>
            <button type="button" class="btn btn--small btn--danger js-theme-delete" title="<?= e(t('Удалить')) ?>"
                    data-name="<?= e($t['dir']) ?>" data-title="<?= e($t['name']) ?>"><?= icon('trash') ?></button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Модальное окно подтверждения удаления темы -->
<div class="modal" id="theme-delete-modal" hidden>
  <div class="modal__box">
    <h3 class="modal__title"><?= e(t('Удалить тему?')) ?></h3>
    <p class="modal__text">«<span id="theme-delete-title"></span>» <?= e(t('будет удалена. Файлы темы не восстановить.')) ?></p>
    <form method="post" action="<?= e($adminBase) ?>themes/delete/">
      <?= $security->csrfField() ?>
      <input type="hidden" name="name" id="theme-delete-name" value="">
      <div class="modal__actions">
        <button type="button" class="btn btn--secondary btn--icon js-modal-close" title="<?= e(t('Отмена')) ?>" aria-label="<?= e(t('Отмена')) ?>"><?= icon('x') ?></button>
        <button type="submit" class="btn btn--danger btn--icon" title="<?= e(t('Удалить')) ?>" aria-label="<?= e(t('Удалить')) ?>"><?= icon('trash') ?></button>
      </div>
    </form>
  </div>
</div>
