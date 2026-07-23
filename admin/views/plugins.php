<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/** @var array $plugins  @var string[] $enabled  @var bool $saved  @var bool $installed  @var bool $deleted
 *  @var string $pluginsErr  @var string $adminBase  @var Security $security */
?>

<?php if ($saved): ?>
  <div class="alert alert--success" data-toast><?= e(t('Сохранено.')) ?></div>
<?php elseif ($installed): ?>
  <div class="alert alert--success" data-toast><?= e(t('Плагин установлен.')) ?></div>
<?php elseif ($deleted): ?>
  <div class="alert alert--success" data-toast><?= e(t('Плагин удалён.')) ?></div>
<?php elseif ($pluginsErr !== ''): ?>
  <div class="alert alert--danger" data-toast><?= e($pluginsErr) ?></div>
<?php endif; ?>

<div class="filters">
  <form method="post" action="<?= e($adminBase) ?>plugins/install/" enctype="multipart/form-data" id="plugin-install-form">
    <?= $security->csrfField() ?>
    <label class="btn btn--primary">
      <?= icon('plus') ?><?= e(t('Плагин')) ?>
      <input type="file" name="plugin_zip" accept=".zip" required id="plugin-zip-input" hidden>
    </label>
  </form>
  <?php if (!empty($plugins)): ?>
    <div class="segmented" role="group" aria-label="<?= e(t('Вид')) ?>">
      <button type="button" class="segmented__btn js-plugins-view is-active" data-view="list" aria-pressed="true" title="<?= e(t('Список')) ?>"><?= icon('list') ?></button>
      <button type="button" class="segmented__btn js-plugins-view" data-view="cards" aria-pressed="false" title="<?= e(t('Карточки')) ?>"><?= icon('grid') ?></button>
    </div>
  <?php endif; ?>
</div>

<?php if (empty($plugins)): ?>
  <div class="card"><p class="muted" style="margin:0"><?= e(t('Плагинов пока нет.')) ?></p></div>
<?php else: ?>
  <div class="plugin-grid" id="plugin-grid">
    <?php foreach ($plugins as $p): $on = in_array($p['dir'], $enabled, true); ?>
      <div class="plugin-item">
        <div class="plugin-item__body">
          <div class="plugin-item__head">
            <span class="plugin-item__name"><?= e($p['name']) ?></span>
            <?php if ($p['version'] !== ''): ?>
              <span class="plugin-item__version">v<?= e($p['version']) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($p['description'] !== ''): ?>
            <p class="plugin-item__desc"><?= e($p['description']) ?></p>
          <?php endif; ?>
          <?php if ($p['author'] !== ''): ?>
            <p class="plugin-item__author"><?= e(t('Автор')) ?>: <?= e($p['author']) ?></p>
          <?php endif; ?>
        </div>
        <div class="plugin-item__actions">
          <form method="post" action="<?= e($adminBase) ?>plugins/toggle/">
            <?= $security->csrfField() ?>
            <input type="hidden" name="name" value="<?= e($p['dir']) ?>">
            <button type="submit" class="switch <?= $on ? 'switch--on' : '' ?>" role="switch"
                    aria-checked="<?= $on ? 'true' : 'false' ?>"
                    title="<?= e($on ? t('Выключить') : t('Включить')) ?>"><span class="switch__knob"></span></button>
          </form>
          <button type="button" class="btn btn--small btn--danger js-plugin-delete" title="<?= e(t('Удалить')) ?>"
                  data-name="<?= e($p['dir']) ?>" data-title="<?= e($p['name']) ?>"><?= icon('trash') ?></button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Модальное окно подтверждения удаления плагина -->
<div class="modal" id="plugin-delete-modal" hidden>
  <div class="modal__box">
    <h3 class="modal__title"><?= e(t('Удалить плагин?')) ?></h3>
    <p class="modal__text">«<span id="plugin-delete-title"></span>» <?= e(t('будет удалён. Файлы плагина не восстановить.')) ?></p>
    <form method="post" action="<?= e($adminBase) ?>plugins/delete/">
      <?= $security->csrfField() ?>
      <input type="hidden" name="name" id="plugin-delete-name" value="">
      <div class="modal__actions">
        <button type="button" class="btn btn--secondary btn--icon js-modal-close" title="<?= e(t('Отмена')) ?>" aria-label="<?= e(t('Отмена')) ?>"><?= icon('x') ?></button>
        <button type="submit" class="btn btn--danger btn--icon" title="<?= e(t('Удалить')) ?>" aria-label="<?= e(t('Удалить')) ?>"><?= icon('trash') ?></button>
      </div>
    </form>
  </div>
</div>
