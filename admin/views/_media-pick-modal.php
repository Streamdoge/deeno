<?php declare(strict_types=1); defined('FFC_ADMIN') or exit; ?>
<?php /* Модалка выбора изображения из медиатеки. Требует $mediaList; поле-приёмник —
        через кнопку .js-pick-media с data-target="<id инпута>" (см. admin.js).
        Возможности повторяют страницу «Медиа» (имя, вес, дата, сетка/список,
        копирование URL, загрузка перетаскиванием) — КРОМЕ удаления:
        снести файл из-под открытого редактора слишком легко по ошибке, а
        ссылки на него в постах после этого перестают работать. */ ?>
<div class="modal" id="media-pick-modal" hidden>
  <div class="modal__box modal__box--wide media-lib">
    <div class="media-lib__head">
      <h3 class="modal__title"><?= e(t('Медиатека')) ?></h3>
      <div class="segmented segmented--push" role="group" aria-label="<?= e(t('Вид')) ?>">
        <button type="button" class="segmented__btn js-media-pick-view" data-view="grid" aria-pressed="true" title="<?= e(t('Сетка')) ?>"><?= icon('grid') ?></button>
        <button type="button" class="segmented__btn js-media-pick-view" data-view="list" aria-pressed="false" title="<?= e(t('Список')) ?>"><?= icon('list') ?></button>
      </div>
      <button type="button" class="media-lib__close js-modal-close" title="<?= e(t('Закрыть')) ?>" aria-label="<?= e(t('Закрыть')) ?>"><?= icon('x') ?></button>
    </div>
    <div class="media-lib__body" id="media-pick-dropzone">
      <p class="muted media-lib__empty" id="media-pick-empty" <?= empty($mediaList) ? '' : 'hidden' ?>><?= e(t('Медиатека пуста.')) ?></p>
      <div class="media-pick-grid" id="media-pick-grid">
        <?php foreach (($mediaList ?? []) as $mi): if (empty($mi['isImage'])) continue; ?>
          <div class="media-pick-item">
            <button type="button" class="media-pick-item__btn" data-url="<?= e($mi['url']) ?>" data-name="<?= e($mi['name']) ?>" title="<?= e($mi['name']) ?>">
              <span class="media-pick-item__preview"><img src="<?= e($mi['thumb']) ?>" alt="<?= e($mi['name']) ?>" loading="lazy"></span>
              <span class="media-pick-item__meta">
                <span class="media-pick-item__name"><?= e($mi['name']) ?></span>
                <span class="media-pick-item__size muted"><?= e(number_format((float)($mi['size'] ?? 0) / 1024, 0, '.', ' ')) ?> <?= e(t('КБ')) ?><?php if (!empty($mi['mtime'])): ?> · <?= e(date('d.m.Y', (int)$mi['mtime'])) ?><?php endif; ?></span>
              </span>
            </button>
            <button type="button" class="btn btn--small btn--secondary media-pick-item__copy js-copy-url"
                    data-url="<?= e($mi['url']) ?>" title="<?= e(t('Копировать URL')) ?>"><?= icon('link') ?></button>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="media-lib__foot">
      <label class="btn btn--primary">
        <?= e(t('Загрузить файл')) ?>
        <input type="file" id="media-pick-input" accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.ico" hidden>
      </label>
      <span class="muted"><?= e(t('или перетащите файл в окно')) ?></span>
      <span class="muted" id="media-pick-status"></span>
    </div>
  </div>
</div>
