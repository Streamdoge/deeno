<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/** @var array $items  @var bool $deleted  @var bool $error  @var string $adminBase  @var Security $security  @var array $user */
$canDelete = in_array($user['role'] ?? '', ['admin', 'editor'], true);
?>

<?php if ($deleted): ?>
  <div class="alert alert--success" data-toast><?= e(t('Файл удалён.')) ?></div>
<?php elseif ($error): ?>
  <div class="alert alert--danger" data-toast><?= e(t('Не удалось удалить файл.')) ?></div>
<?php endif; ?>

<div class="card media-upload" id="media-dropzone">
  <p class="media-upload__hint"><?= e(t('Перетащите файлы сюда или')) ?></p>
  <label class="btn btn--primary media-pick" id="media-pick">
    <span class="media-pick__text"><?= e(t('Выбрать файлы')) ?></span>
    <span class="spinner media-pick__spin" aria-hidden="true"></span>
    <input type="file" id="media-input" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.svg,.ico" hidden>
  </label>
  <p class="muted" style="margin-bottom:0">jpg, png, gif, webp, pdf · <?= e(t('до 10 МБ')) ?></p>
</div>

<?php if (empty($items)): ?>
  <div class="card"><p class="muted" style="margin:0"><?= e(t('Медиатека пуста.')) ?></p></div>
<?php else: ?>
  <div class="media-toolbar">
    <div class="segmented" role="group" aria-label="<?= e(t('Вид')) ?>">
      <button type="button" class="segmented__btn js-media-view" data-view="grid" aria-pressed="true" title="<?= e(t('Сетка')) ?>"><?= icon('grid') ?></button>
      <button type="button" class="segmented__btn js-media-view" data-view="list" aria-pressed="false" title="<?= e(t('Список')) ?>"><?= icon('list') ?></button>
    </div>
  </div>
  <div class="media-grid" id="media-grid">
    <?php foreach ($items as $item): ?>
      <div class="media-item">
        <?php if ($item['isImage']): ?>
          <a class="media-item__preview" href="<?= e($item['url']) ?>" target="_blank">
            <img src="<?= e($item['thumb']) ?>" alt="<?= e($item['name']) ?>" loading="lazy">
          </a>
        <?php else: ?>
          <a class="media-item__preview media-item__preview--doc" href="<?= e($item['url']) ?>" target="_blank"><?= icon('file') ?></a>
        <?php endif; ?>
        <div class="media-item__meta">
          <span class="media-item__name" title="<?= e($item['name']) ?>"><?= e($item['name']) ?></span>
          <span class="media-item__size muted"><?= e(number_format($item['size'] / 1024, 0, '.', ' ')) ?> КБ</span>
          <span class="media-item__date muted"><?= e(date('d.m.Y', $item['mtime'])) ?></span>
        </div>
        <div class="media-item__actions">
          <button type="button" class="btn btn--small btn--secondary js-copy-url" data-url="<?= e($item['url']) ?>" title="<?= e(t('Копировать URL')) ?>"><?= icon('link') ?></button>
          <?php if ($canDelete): ?>
            <button type="button" class="btn btn--small btn--danger js-media-delete"
                    data-url="<?= e($item['url']) ?>" data-title="<?= e($item['name']) ?>"><?= icon('trash') ?></button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Модальное окно подтверждения удаления файла -->
<div class="modal" id="media-delete-modal" hidden>
  <div class="modal__box">
    <h3 class="modal__title"><?= e(t('Удалить файл?')) ?></h3>
    <p class="modal__text">«<span id="media-delete-title"></span>» <?= e(t('будет удалён. Ссылки на него в постах перестанут работать.')) ?></p>
    <form method="post" action="<?= e($adminBase) ?>media/delete/">
      <?= $security->csrfField() ?>
      <input type="hidden" name="url" id="media-delete-url" value="">
      <div class="modal__actions">
        <button type="button" class="btn btn--secondary btn--icon js-modal-close" title="<?= e(t('Отмена')) ?>" aria-label="<?= e(t('Отмена')) ?>"><?= icon('x') ?></button>
        <button type="submit" class="btn btn--danger btn--icon" title="<?= e(t('Удалить')) ?>" aria-label="<?= e(t('Удалить')) ?>"><?= icon('trash') ?></button>
      </div>
    </form>
  </div>
</div>
