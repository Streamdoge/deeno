<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/**
 * @var array $categories  массив slug => ['title'=>, 'description'=>, 'count'=>]
 * @var bool $saved  @var bool $deleted  @var bool $error
 * @var string $adminBase  @var Security $security
 */
?>

<?php if ($saved): ?>
  <div class="alert alert--success" data-toast><?= e(t('Категория сохранена.')) ?></div>
<?php elseif ($deleted): ?>
  <div class="alert alert--success" data-toast><?= e(t('Категория удалена.')) ?></div>
<?php elseif ($error): ?>
  <div class="alert alert--danger" data-toast><?= e(t('Не удалось сохранить изменения.')) ?></div>
<?php endif; ?>

<div class="filters">
  <button type="button" class="btn btn--primary js-category-new"><?= icon('plus') ?><?= e(t('Категория')) ?></button>
</div>

<?php
// «Без категории» (Post::DEFAULT_CATEGORY) — виртуальное ведёрко постов без рубрики,
// в списке категорий не показываем: удалять/править нечего, а счётчик виден в постах.
$visibleCategories = array_filter(
    $categories,
    fn($slug) => (string)$slug !== Post::DEFAULT_CATEGORY,
    ARRAY_FILTER_USE_KEY
);
?>
<div class="card">
<?php if (empty($visibleCategories)): ?>
  <p class="muted" style="padding:1rem"><?= e(t('Категорий пока нет.')) ?></p>
<?php else: ?>
  <table class="table table--striped">
    <thead>
      <tr>
        <th><?= e(t('Название')) ?></th>
        <th><?= e(t('Ссылка')) ?></th>
        <th><?= e(t('Описание')) ?></th>
        <th><?= e(t('Постов')) ?></th>
        <th class="table__actions-head"><?= e(t('Действия')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($visibleCategories as $slug => $cat): ?>
        <tr>
          <td class="table__title">
            <a href="<?= e($adminBase) ?>posts/?category=<?= e(urlencode((string)$slug)) ?>"><?= e($cat['title']) ?></a>
          </td>
          <td class="muted"><?= e((string)$slug) ?></td>
          <td class="muted"><?= $cat['description'] !== '' ? e(mb_strimwidth($cat['description'], 0, 80, '…')) : '—' ?></td>
          <td class="muted"><?= (int)$cat['count'] ?></td>
          <td class="table__actions">
            <button class="btn btn--small btn--secondary js-category-edit"
                    data-slug="<?= e((string)$slug) ?>"
                    data-title="<?= e($cat['title']) ?>"
                    data-description="<?= e($cat['description']) ?>"
                    data-position="<?= (int)($cat['position'] ?? 0) ?>"
                    data-icon="<?= e((string)($cat['icon'] ?? '')) ?>"><?= icon('pen') ?></button>
            <button class="btn btn--small btn--danger js-category-delete"
                    data-slug="<?= e((string)$slug) ?>"
                    data-title="<?= e($cat['title']) ?>"><?= icon('trash') ?></button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<!-- Модальное окно создания/редактирования категории -->
<div class="modal" id="category-edit-modal" hidden>
  <div class="modal__box">
    <h3 class="modal__title" id="category-edit-heading"><?= e(t('Редактировать категорию')) ?></h3>
    <p class="modal__text muted" id="category-edit-hint"><?= e(t('Если ссылка совпадает с существующей категорией — они объединятся.')) ?></p>
    <form method="post" action="<?= e($adminBase) ?>categories/save/" id="category-edit-form"
          data-save-url="<?= e($adminBase) ?>categories/save/" data-create-url="<?= e($adminBase) ?>categories/create/">
      <?= $security->csrfField() ?>
      <input type="hidden" name="from" id="category-edit-from" value="">
      <label class="field">
        <span class="field__label"><?= e(t('Название')) ?></span>
        <input type="text" name="title" id="category-edit-title" required>
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Ссылка')) ?></span>
        <input type="text" name="slug" id="category-edit-slug" required>
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Описание')) ?></span>
        <textarea name="description" id="category-edit-description" rows="3"></textarea>
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Порядок')) ?></span>
        <input type="number" name="position" id="category-edit-position" value="0" step="1">
        <span class="field__hint"><?= e(t('Влияет, когда в Настройках выбрана сортировка разделов «Вручную».')) ?></span>
      </label>
      <div class="field">
        <span class="field__label"><?= e(t('Иконка')) ?></span>
        <div class="cover-picker">
          <input type="text" name="icon" id="category-edit-icon" placeholder="/media/2026/07/icon.svg">
          <button type="button" class="btn btn--icon btn--secondary js-pick-media" data-target="category-edit-icon" title="<?= e(t('Выбрать из медиатеки')) ?>" aria-label="<?= e(t('Выбрать из медиатеки')) ?>"><?= icon('image') ?></button>
        </div>
        <img id="category-edit-icon-preview" class="cover-preview cover-preview--icon" alt="" hidden>
        <span class="field__hint"><?= e(t('Картинка/эмодзи рядом с разделом в теме документации.')) ?></span>
      </div>
      <div class="modal__actions">
        <button type="button" class="btn btn--secondary btn--icon js-modal-close" title="<?= e(t('Отмена')) ?>" aria-label="<?= e(t('Отмена')) ?>"><?= icon('x') ?></button>
        <button type="submit" class="btn btn--primary btn--icon" title="<?= e(t('Сохранить')) ?>" aria-label="<?= e(t('Сохранить')) ?>"><?= icon('tick') ?></button>
      </div>
    </form>
  </div>
</div>

<!-- Модальное окно удаления категории -->
<div class="modal" id="category-delete-modal" hidden>
  <div class="modal__box">
    <h3 class="modal__title"><?= e(t('Удалить категорию?')) ?></h3>
    <p class="modal__text">«<span id="category-delete-title"></span>» <?= e(t('— посты перейдут в категорию по умолчанию.')) ?></p>
    <form method="post" action="<?= e($adminBase) ?>categories/delete/">
      <?= $security->csrfField() ?>
      <input type="hidden" name="from" id="category-delete-from" value="">
      <div class="modal__actions">
        <button type="button" class="btn btn--secondary btn--icon js-modal-close" title="<?= e(t('Отмена')) ?>" aria-label="<?= e(t('Отмена')) ?>"><?= icon('x') ?></button>
        <button type="submit" class="btn btn--danger btn--icon" title="<?= e(t('Удалить')) ?>" aria-label="<?= e(t('Удалить')) ?>"><?= icon('trash') ?></button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/_media-pick-modal.php'; ?>
