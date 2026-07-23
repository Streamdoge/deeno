<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/**
 * Редактор поста — простая форма с полями (раздел 10.2 ТЗ).
 * @var string $filename  @var array $meta  @var string $body  @var bool $isNew
 * @var string $previewToken  @var string $author  @var bool $saved  @var string $editErr
 * @var string $adminBase  @var string $siteUrl  @var Security $security  @var array $categoryList
 */
$m = fn(string $key, string $default = '') => e((string)($meta[$key] ?? $default));
$isPage = ($type ?? 'post') === 'page';
$statuses = statusLabels($isPage);
$curStatus = (string)($meta['status'] ?? 'draft');
$tags = implode(', ', (array)($meta['tags'] ?? []));
$customFields = (array)($meta['custom_fields'] ?? []);
$saveAction = ($isPage ? 'pages' : 'posts') . '/save/';

// Категория — выпадающий список из уже созданных категорий (раздел «Категории»),
// свободный текст больше не пускаем. «Без категории» — дефолт, всегда в списке.
$categoryOptions = $categoryList ?? [];
if (!isset($categoryOptions[Post::DEFAULT_CATEGORY])) {
    $categoryOptions = [Post::DEFAULT_CATEGORY => ['title' => t('Без категории')]] + $categoryOptions;
}
$curCategory = (string)($meta['category'] ?? '');
if ($curCategory !== '' && !isset($categoryOptions[$curCategory])) {
    $categoryOptions[$curCategory] = ['title' => $curCategory];
}
?>

<?php if ($saved): ?>
  <div class="alert alert--success" data-toast><?= e(t('Сохранено.')) ?></div>
<?php endif; ?>
<?php if ($editErr !== ''): ?>
  <div class="alert alert--danger"><?= e($editErr) ?></div>
<?php endif; ?>

<form method="post" action="<?= e($adminBase . $saveAction) ?>" id="editor-form" class="editor">
  <?= $security->csrfField() ?>
  <input type="hidden" name="file" value="<?= e($filename) ?>">
  <input type="hidden" name="type" value="<?= $isPage ? 'page' : 'post' ?>">
  <input type="hidden" name="author" value="<?= e($author) ?>">
  <input type="hidden" name="date" value="<?= $m('date') ?>">

  <div class="editor__main">
    <label class="field">
      <input type="text" name="title" id="post-title" required value="<?= $m('title') ?>" placeholder="<?= e(t('Заголовок поста')) ?>">
    </label>

    <div class="md-toolbar" id="md-toolbar">
      <select class="md-toolbar__heading" id="md-heading" title="<?= e(t('Уровень заголовка')) ?>" aria-label="<?= e(t('Уровень заголовка')) ?>">
        <option value=""><?= e(t('Стиль')) ?></option>
        <option value="1">H1</option>
        <option value="2">H2</option>
        <option value="3">H3</option>
        <option value="4">H4</option>
        <option value="0"><?= e(t('Абзац')) ?></option>
      </select>
      <span class="md-toolbar__sep"></span>
      <button type="button" data-md="bold" title="<?= e(t('Жирный (Ctrl+B)')) ?>"><b>B</b></button>
      <button type="button" data-md="italic" title="<?= e(t('Курсив (Ctrl+I)')) ?>"><i>I</i></button>
      <button type="button" data-md="strike" title="<?= e(t('Зачёркнутый')) ?>"><s>S</s></button>
      <button type="button" data-md="mark" title="<?= e(t('Выделить маркером')) ?>"><?= icon('highlighter') ?></button>
      <div class="md-color" id="md-color">
        <button type="button" class="md-color__trigger" title="<?= e(t('Цвет текста')) ?>"><?= icon('paint-bucket') ?></button>
        <div class="md-color__pop" hidden>
          <?php foreach (['red','orange','yellow','green','blue','purple','gray'] as $c): ?>
            <button type="button" class="md-color__sw md-color__sw--<?= $c ?>" data-color="<?= $c ?>" aria-label="<?= e($c) ?>"></button>
          <?php endforeach; ?>
        </div>
      </div>
      <button type="button" data-md="clear" title="<?= e(t('Очистить формат')) ?>"><?= icon('eraser') ?></button>
      <span class="md-toolbar__sep"></span>
      <button type="button" data-md="code" title="<?= e(t('Код в строке')) ?>">&lt;/&gt;</button>
      <button type="button" data-md="codeblock" title="<?= e(t('Блок кода')) ?>">```</button>
      <span class="md-toolbar__sep"></span>
      <button type="button" data-md="link" title="<?= e(t('Ссылка (Ctrl+K)')) ?>"><?= icon('link') ?></button>
      <button type="button" data-md="image" class="js-pick-media" data-target="" title="<?= e(t('Изображение из медиатеки')) ?>"><?= icon('image') ?></button>
      <button type="button" data-md="video" title="<?= e(t('Видео (YouTube/Vimeo)')) ?>"><?= icon('video') ?></button>
      <span class="md-toolbar__sep"></span>
      <button type="button" data-md="align-left" title="<?= e(t('По левому краю')) ?>"><?= icon('align-left') ?></button>
      <button type="button" data-md="align-center" title="<?= e(t('По центру')) ?>"><?= icon('align-center') ?></button>
      <button type="button" data-md="align-right" title="<?= e(t('По правому краю')) ?>"><?= icon('align-right') ?></button>
      <button type="button" data-md="align-justify" title="<?= e(t('По ширине')) ?>"><?= icon('align-justify') ?></button>
      <span class="md-toolbar__sep"></span>
      <button type="button" data-md="ul" title="<?= e(t('Маркированный список')) ?>"><?= icon('list') ?></button>
      <button type="button" data-md="ol" title="<?= e(t('Нумерованный список')) ?>">1.</button>
      <button type="button" data-md="quote" title="<?= e(t('Цитата')) ?>"><?= icon('quote') ?></button>
      <button type="button" data-md="table" title="<?= e(t('Таблица')) ?>"><?= icon('table') ?></button>
      <button type="button" data-md="hr" title="<?= e(t('Линия-разделитель')) ?>"><?= icon('minus') ?></button>
    </div>

    <div class="editor__content-wrap" id="dropzone">
      <textarea name="content" id="post-content" class="editor__content"
                placeholder="<?= e(t('Текст поста в Markdown… Перетащите сюда изображение, чтобы вставить его.')) ?>"><?= e($body) ?></textarea>
      <div class="dropzone-hint" id="dropzone-hint"><?= e(t('Отпустите, чтобы загрузить изображение')) ?></div>
    </div>

    <div class="editor__toolbar">
      <button type="button" class="btn btn--secondary btn--small" id="insert-more" title="<?= e(t('Всё, что ниже разрыва, видно только на странице поста')) ?>"><?= e(t('+ Разрыв <!--more-->')) ?></button>
      <span class="muted" id="autosave-status"></span>
      <span class="editor__count" id="editor-count" aria-live="polite"></span>
    </div>
  </div>

  <aside class="editor__side">

    <div class="card">
      <div class="editor__actions">
        <?php
        // Пост «уже публичный», если он не новый и его сохранённый статус — публичный
        // (или запланирован). Тогда любое сохранение — просто «Сохранить».
        $wasLive = !$isNew && in_array($curStatus, ['published', 'sticky', 'unlisted', 'scheduled'], true);
        $saveLabel = ($wasLive || $curStatus === 'draft') ? t('Сохранить')
                   : ($curStatus === 'scheduled' ? t('Запланировать') : t('Опубликовать'));
        ?>
        <?php // Слева — живой предпросмотр (POST текущего содержимого в новую вкладку),
              // справа — основная кнопка сохранения. formnovalidate: превью не требует заголовок. ?>
        <button type="submit" class="btn btn--secondary"
                formaction="<?= e($siteUrl) ?>/preview" formmethod="post"
                formtarget="_blank" formnovalidate><?= e(t('Предпросмотр')) ?></button>
        <button type="submit" class="btn btn--primary" id="save-btn"
                data-was-live="<?= $wasLive ? '1' : '0' ?>"
                data-label-save="<?= e(t('Сохранить')) ?>"
                data-label-publish="<?= e(t('Опубликовать')) ?>"
                data-label-schedule="<?= e(t('Запланировать')) ?>"><?= e($saveLabel) ?></button>
      </div>
    </div>

    <div class="card">
      <div class="tabs">
        <button type="button" class="tabs__btn active" data-tab="params"><?= e(t('Параметры')) ?></button>
        <button type="button" class="tabs__btn" data-tab="seo">SEO</button>
        <button type="button" class="tabs__btn" data-tab="custom"><?= e(t('Поля')) ?></button>
      </div>

      <div class="tab-pane" id="tab-params">
        <label class="field">
          <span class="field__label"><?= e(t('Статус')) ?></span>
          <select name="status" id="post-status">
            <?php foreach ($statuses as $val => $label): ?>
              <option value="<?= e($val) ?>" <?= $curStatus === $val ? 'selected' : '' ?>><?= e(t($label)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if (!$isPage): ?>
        <div class="field" id="field-scheduled" <?= $curStatus !== 'scheduled' ? 'hidden' : '' ?>>
          <span class="field__label"><?= e(t('Дата отложенной публикации')) ?></span>
          <div class="datepicker js-datepicker">
            <input type="hidden" name="scheduled_date" value="<?= e((string)($meta['scheduled_date'] ?? '')) ?>">
          </div>
        </div>
        <?php endif; ?>
        <label class="field">
          <span class="field__label"><?= e(t('Ссылка')) ?></span>
          <input type="text" name="slug" id="post-slug" value="<?= $m('slug') ?>" placeholder="avtomaticheski-iz-zagolovka">
        </label>
        <?php if ($isPage): ?>
        <label class="field field--check">
          <input type="checkbox" name="show_in_menu" value="1" <?= ($meta['show_in_menu'] ?? true) !== false ? 'checked' : '' ?>>
          <span><?= e(t('Показывать в меню сайта')) ?></span>
        </label>
        <label class="field">
          <span class="field__label"><?= e(t('Позиция в меню')) ?></span>
          <input type="number" name="position" value="<?= e((string)($meta['position'] ?? '')) ?>">
        </label>
        <div class="field">
          <span class="field__label"><?= e(t('Иконка')) ?></span>
          <div class="cover-picker">
            <input type="text" name="icon" id="post-icon" value="<?= $m('icon') ?>" placeholder="/media/2026/07/icon.svg">
            <button type="button" class="btn btn--icon btn--secondary js-pick-media" data-target="post-icon" title="<?= e(t('Выбрать из медиатеки')) ?>" aria-label="<?= e(t('Выбрать из медиатеки')) ?>"><?= icon('image') ?></button>
          </div>
          <img id="post-icon-preview" class="cover-preview cover-preview--icon" alt=""
               src="<?= $m('icon') ?>" <?= (string)($meta['icon'] ?? '') === '' ? 'hidden' : '' ?>>
        </div>
        <?php else: ?>
        <label class="field">
          <span class="field__label"><?= e(t('Категория')) ?></span>
          <select name="category">
            <?php foreach ($categoryOptions as $slug => $cat): ?>
              <option value="<?= e((string)$slug) ?>" <?= ($curCategory === (string)$slug || ($curCategory === '' && $slug === Post::DEFAULT_CATEGORY)) ? 'selected' : '' ?>><?= e((string)($cat['title'] ?? $slug)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span class="field__label"><?= e(t('Теги (через запятую)')) ?></span>
          <input type="text" name="tags" value="<?= e($tags) ?>" placeholder="php, cms">
        </label>
        <div class="field">
          <span class="field__label"><?= e(t('Обложка')) ?></span>
          <div class="cover-picker">
            <input type="text" name="cover" id="post-cover" value="<?= $m('cover') ?>" placeholder="/media/2026/07/cover.jpg">
            <button type="button" class="btn btn--icon btn--secondary js-pick-media" data-target="post-cover" title="<?= e(t('Выбрать из медиатеки')) ?>" aria-label="<?= e(t('Выбрать из медиатеки')) ?>"><?= icon('image') ?></button>
          </div>
          <img id="post-cover-preview" class="cover-preview" alt=""
               src="<?= $m('cover') ?>" <?= (string)($meta['cover'] ?? '') === '' ? 'hidden' : '' ?>>
        </div>
        <div class="field">
          <span class="field__label"><?= e(t('Иконка')) ?></span>
          <div class="cover-picker">
            <input type="text" name="icon" id="post-icon" value="<?= $m('icon') ?>" placeholder="/media/2026/07/icon.svg">
            <button type="button" class="btn btn--icon btn--secondary js-pick-media" data-target="post-icon" title="<?= e(t('Выбрать из медиатеки')) ?>" aria-label="<?= e(t('Выбрать из медиатеки')) ?>"><?= icon('image') ?></button>
          </div>
          <img id="post-icon-preview" class="cover-preview cover-preview--icon" alt=""
               src="<?= $m('icon') ?>" <?= (string)($meta['icon'] ?? '') === '' ? 'hidden' : '' ?>>
          <span class="field__hint"><?= e(t('Небольшая картинка рядом с пунктом в теме документации. Пусто — без иконки.')) ?></span>
        </div>
        <label class="field">
          <span class="field__label"><?= e(t('Анонс')) ?></span>
          <textarea name="excerpt" rows="3"><?= $m('excerpt') ?></textarea>
        </label>
        <?php endif; ?>
        <details class="adv">
          <summary><?= e(t('Дополнительно')) ?></summary>
          <div class="adv__body">
            <label class="field">
              <span class="field__label"><?= e(t('Шаблон темы')) ?></span>
              <input type="text" name="template" value="<?= $m('template') ?>" placeholder="<?= $isPage ? 'page' : 'post' ?>">
            </label>
            <?php if (!$isPage): ?>
            <label class="field">
              <span class="field__label"><?= e(t('Позиция (сортировка)')) ?></span>
              <input type="number" name="position" value="<?= e((string)($meta['position'] ?? '')) ?>">
            </label>
            <?php endif; ?>
          </div>
        </details>
      </div>

      <div class="tab-pane" id="tab-seo" hidden>
        <label class="field">
          <span class="field__label">SEO title</span>
          <input type="text" name="seo_title" value="<?= $m('seo_title') ?>">
        </label>
        <label class="field">
          <span class="field__label">SEO description</span>
          <textarea name="seo_description" rows="3" maxlength="200"><?= $m('seo_description') ?></textarea>
        </label>
        <label class="field">
          <span class="field__label">Canonical URL</span>
          <input type="text" name="canonical" value="<?= $m('canonical') ?>">
        </label>
        <label class="field field--check">
          <input type="checkbox" name="seo_noindex" value="1" <?= !empty($meta['seo_noindex']) ? 'checked' : '' ?>>
          <span><?= e(t('Запретить индексацию (noindex)')) ?></span>
        </label>
        <hr class="sep">
        <label class="field">
          <span class="field__label">OG title (<?= e(t('соцсети')) ?>)</span>
          <input type="text" name="og_title" value="<?= $m('og_title') ?>">
        </label>
        <div class="field">
          <span class="field__label">OG image</span>
          <div class="cover-picker">
            <input type="text" name="og_image" id="post-og-image" value="<?= $m('og_image') ?>" placeholder="/media/2026/07/og.jpg">
            <button type="button" class="btn btn--icon btn--secondary js-pick-media" data-target="post-og-image" title="<?= e(t('Выбрать из медиатеки')) ?>" aria-label="<?= e(t('Выбрать из медиатеки')) ?>"><?= icon('image') ?></button>
          </div>
        </div>
        <label class="field">
          <span class="field__label">OG description</span>
          <textarea name="og_description" rows="2"><?= $m('og_description') ?></textarea>
        </label>
      </div>

      <div class="tab-pane" id="tab-custom" hidden>
        <p class="muted" style="margin-top:0"><?= e(t('Кастомные поля — доступны в теме через')) ?> <code>$post->custom('key')</code>.</p>
        <div id="custom-fields">
          <?php foreach ($customFields as $ck => $cv): ?>
            <div class="cf-row">
              <input type="text" name="cf_key[]" value="<?= e((string)$ck) ?>" placeholder="key">
              <input type="text" name="cf_value[]" value="<?= e((string)$cv) ?>" placeholder="<?= e(t('значение')) ?>">
              <button type="button" class="btn btn--small btn--secondary js-cf-remove">✕</button>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn--small btn--secondary" id="cf-add"><?= e(t('+ Добавить поле')) ?></button>
      </div>
    </div>

  </aside>
</form>

<!-- Модалка выбора изображения из медиатеки (обложка, OG image, вставка в текст) -->
<?php include __DIR__ . '/_media-pick-modal.php'; ?>
