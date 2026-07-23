<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/** @var array $config  @var array $themes  @var Post[] $pagesList  @var bool $saved  @var string $settingsErr
 *  @var string $adminBase  @var Security $security  @var bool $isSiteAdmin  @var string $adminLang  @var string $adminTheme */
$c = fn(string $key, string $default = '') => e((string)($config[$key] ?? $default));

// Не-админ попадает в этот раздел только ради личных настроек панели —
// всё, что меняет сайт, ему не показываем и на сервере не принимаем.
$canEditSite = !empty($isSiteAdmin);
?>

<?php if ($saved): ?>
  <div class="alert alert--success" data-toast><?= e(t('Настройки сохранены.')) ?></div>
<?php endif; ?>
<?php if ($settingsErr !== ''): ?>
  <div class="alert alert--danger"><?= e($settingsErr) ?></div>
<?php endif; ?>

<form method="post" action="<?= e($adminBase) ?>settings/">
  <?= $security->csrfField() ?>

  <div class="filters">
    <button type="submit" class="btn btn--primary"><?= e(t('Сохранить настройки')) ?></button>
    <?php if (defined('DEENO_VERSION')): ?>
      <span class="settings__version">deeno <?= e(DEENO_VERSION) ?></span>
    <?php endif; ?>
  </div>

  <?php
  /* Личные настройки панели — переехали из подвала сайдбара (2026-07-20).
     Хранятся в профиле пользователя, поэтому переживают выход и одинаковы
     на всех его устройствах.

     Карточка объявлена замыканием: администратор видит её ВНИЗУ левой колонки
     (под «Сайтом»), а редактор и автор — единственной на странице. Разметка
     при этом одна. */
  $panelCard = function () use ($adminTheme, $adminLang): void { ?>
    <div class="card">
      <h2 class="card__title"><?= e(t('Панель управления')) ?></h2>
      <label class="field">
        <span class="field__label"><?= e(t('Тема оформления')) ?></span>
        <select name="admin_theme">
          <option value="light" <?= ($adminTheme ?? 'light') !== 'dark' ? 'selected' : '' ?>><?= e(t('Светлая')) ?></option>
          <option value="dark" <?= ($adminTheme ?? '') === 'dark' ? 'selected' : '' ?>><?= e(t('Тёмная')) ?></option>
        </select>
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Язык панели')) ?></span>
        <select name="admin_lang">
          <option value="ru" <?= ($adminLang ?? 'ru') === 'ru' ? 'selected' : '' ?>>Русский</option>
          <option value="en" <?= ($adminLang ?? '') === 'en' ? 'selected' : '' ?>>English</option>
        </select>
      </label>
    </div>
  <?php }; ?>

  <?php if (!$canEditSite): ?>
    <div class="grid-2"><?php $panelCard(); ?></div>
    </form>
    <?php return; ?>
  <?php endif; ?>

  <div class="grid-2">
    <div>
    <div class="card">
      <h2 class="card__title"><?= e(t('Сайт')) ?></h2>
      <label class="field">
        <span class="field__label"><?= e(t('Название сайта')) ?></span>
        <input type="text" name="site_title" required value="<?= $c('site_title') ?>">
      </label>
      <div class="field">
        <span class="field__label"><?= e(t('Логотип')) ?></span>
        <div class="cover-picker">
          <input type="text" name="logo" id="set-logo" value="<?= $c('logo') ?>" placeholder="/media/2026/07/logo.svg">
          <button type="button" class="btn btn--icon btn--secondary js-pick-media" data-target="set-logo" title="<?= e(t('Выбрать из медиатеки')) ?>" aria-label="<?= e(t('Выбрать из медиатеки')) ?>"><?= icon('image') ?></button>
        </div>
      </div>
      <label class="field">
        <span class="field__label"><?= e(t('Слоган')) ?></span>
        <input type="text" name="site_tagline" value="<?= $c('site_tagline') ?>">
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Описание')) ?></span>
        <textarea name="site_description" rows="2"><?= $c('site_description') ?></textarea>
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Текст в подвале')) ?></span>
        <input type="text" name="footer_text" value="<?= $c('footer_text') ?>" placeholder="<?= e(t('Например: © 2026 Мой сайт')) ?>">
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('URL сайта')) ?></span>
        <input type="text" name="site_url" value="<?= $c('site_url') ?>" placeholder="https://example.com">
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Язык')) ?></span>
        <select name="language">
          <option value="ru" <?= ($config['language'] ?? '') === 'ru' ? 'selected' : '' ?>>Русский</option>
          <option value="en" <?= ($config['language'] ?? '') === 'en' ? 'selected' : '' ?>>English</option>
        </select>
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Временная зона')) ?></span>
        <input type="text" name="timezone" value="<?= $c('timezone', 'Europe/Moscow') ?>">
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Тема')) ?></span>
        <select name="theme">
          <?php foreach ($themes as $t): ?>
            <option value="<?= e($t) ?>" <?= ($config['theme'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Формат даты')) ?></span>
        <select name="date_format">
          <?php foreach (['d.m.Y', 'd.m.Y H:i', 'j.n.Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'] as $fmt): ?>
            <option value="<?= e($fmt) ?>" <?= ($config['date_format'] ?? 'd.m.Y') === $fmt ? 'selected' : '' ?>><?= e(date($fmt)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Главная страница')) ?></span>
        <select name="homepage">
          <?php /* Не «Лента постов»: у темы-документации главная — обзор разделов,
                   а не хронолента. Нейтральная формулировка подходит любой теме. */ ?>
          <option value=""><?= e(t('Главная страница темы')) ?></option>
          <?php foreach ($pagesList as $pg): ?>
            <option value="<?= e($pg->slug) ?>" <?= ($config['homepage'] ?? '') === $pg->slug ? 'selected' : '' ?>><?= e($pg->title ?: $pg->slug) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Постов на страницу')) ?></span>
        <input type="number" name="posts_per_page" min="1" max="100" value="<?= $c('posts_per_page', '10') ?>">
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Сортировка постов')) ?></span>
        <select name="order_by">
          <option value="date" <?= ($config['order_by'] ?? '') !== 'position' ? 'selected' : '' ?>><?= e(t('По дате')) ?></option>
          <option value="position" <?= ($config['order_by'] ?? '') === 'position' ? 'selected' : '' ?>><?= e(t('По позиции')) ?></option>
        </select>
      </label>
      <?php $docSortOptions = ['manual' => t('Вручную (номера)'), 'alpha' => t('Алфавит'), 'created' => t('Дата создания'), 'modified' => t('Дата изменения')]; ?>
      <label class="field">
        <span class="field__label"><?= e(t('Порядок разделов')) ?></span>
        <select name="category_order">
          <?php $cur = (string)($config['category_order'] ?? 'alpha'); foreach ($docSortOptions as $v => $lbl): ?>
            <option value="<?= e($v) ?>" <?= $cur === $v ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field__label"><?= e(t('Порядок статей в разделе')) ?></span>
        <select name="article_order">
          <?php $cur = (string)($config['article_order'] ?? 'manual'); foreach ($docSortOptions as $v => $lbl): ?>
            <option value="<?= e($v) ?>" <?= $cur === $v ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <?php /* Личные настройки — под «Сайтом», в той же левой колонке */ ?>
    <?php $panelCard(); ?>
    </div>

    <div>
      <div class="card">
        <h2 class="card__title"><?= e(t('Режим обслуживания')) ?></h2>
        <label class="field field--check">
          <input type="checkbox" name="maintenance_mode" value="1" <?= !empty($config['maintenance_mode']) ? 'checked' : '' ?>>
          <span><?= e(t('Включить (гости увидят 503)')) ?></span>
        </label>
        <label class="field">
          <span class="field__label"><?= e(t('Сообщение')) ?></span>
          <input type="text" name="maintenance_message" value="<?= $c('maintenance_message') ?>">
        </label>
      </div>

      <div class="card">
        <h2 class="card__title"><?= e(t('RSS и Sitemap')) ?></h2>
        <label class="field field--check">
          <input type="checkbox" name="rss_enabled" value="1" <?= !empty($config['rss_enabled']) ? 'checked' : '' ?>>
          <span><?= e(t('RSS-лента (/rss.xml)')) ?></span>
        </label>
        <label class="field">
          <span class="field__label"><?= e(t('Постов в RSS')) ?></span>
          <input type="number" name="rss_items" min="1" max="100" value="<?= $c('rss_items', '20') ?>">
        </label>
        <label class="field field--check">
          <input type="checkbox" name="sitemap_enabled" value="1" <?= !empty($config['sitemap_enabled']) ? 'checked' : '' ?>>
          <span><?= e(t('Sitemap (/sitemap.xml)')) ?></span>
        </label>
      </div>

      <div class="card">
        <h2 class="card__title"><?= e(t('Система')) ?></h2>
        <label class="field field--check">
          <input type="checkbox" name="cache_enabled" value="1" <?= !empty($config['cache_enabled']) ? 'checked' : '' ?>>
          <span><?= e(t('Кэш (индекс + страницы)')) ?></span>
        </label>
        <label class="field field--check">
          <input type="checkbox" name="debug" value="1" <?= !empty($config['debug']) ? 'checked' : '' ?>>
          <span><?= e(t('Debug (вывод ошибок — только для разработки!)')) ?></span>
        </label>
        <label class="field">
          <span class="field__label"><?= e(t('Макс. сторона фото при загрузке (px)')) ?></span>
          <input type="number" name="media_max_width" min="320" max="10000" value="<?= $c('media_max_width', '2560') ?>">
        </label>
        <label class="field">
          <span class="field__label"><?= e(t('Качество сжатия JPEG/WebP (40–100)')) ?></span>
          <input type="number" name="media_quality" min="40" max="100" value="<?= $c('media_quality', '82') ?>">
        </label>
        <div class="field">
          <span class="field__label">Favicon</span>
          <div class="cover-picker">
            <input type="text" name="favicon" id="set-favicon" value="<?= $c('favicon') ?>" placeholder="/media/2026/07/favicon.png">
            <button type="button" class="btn btn--icon btn--secondary js-pick-media" data-target="set-favicon" title="<?= e(t('Выбрать из медиатеки')) ?>" aria-label="<?= e(t('Выбрать из медиатеки')) ?>"><?= icon('image') ?></button>
          </div>
        </div>
        <div class="field">
          <span class="field__label"><?= e(t('OG-image по умолчанию')) ?></span>
          <div class="cover-picker">
            <input type="text" name="og_image" id="set-og-image" value="<?= $c('og_image') ?>" placeholder="/media/2026/07/og.jpg">
            <button type="button" class="btn btn--icon btn--secondary js-pick-media" data-target="set-og-image" title="<?= e(t('Выбрать из медиатеки')) ?>" aria-label="<?= e(t('Выбрать из медиатеки')) ?>"><?= icon('image') ?></button>
          </div>
        </div>
      </div>

      <div class="card">
        <h2 class="card__title"><?= e(t('Внешние скрипты')) ?></h2>
        <label class="field">
          <span class="field__label"><?= e(t('Разрешённые домены')) ?></span>
          <textarea name="external_scripts" rows="3" placeholder="mc.yandex.ru&#10;www.googletagmanager.com"><?= e((string)($config['external_scripts'] ?? '')) ?></textarea>
          <span class="field__hint"><?= e(t('Счётчики и виджеты: по одному домену в строке. Остальные браузер заблокирует.')) ?></span>
        </label>
      </div>

      <div class="card">
        <h2 class="card__title"><?= e(t('Соцсети')) ?></h2>
        <?php foreach (socialNetworks() as $key => $label): ?>
          <label class="field">
            <span class="field__label"><?= e($label) ?></span>
            <input type="url" name="social[<?= e($key) ?>]" value="<?= e((string)($config['social'][$key] ?? '')) ?>" placeholder="https://">
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</form>

<?php include __DIR__ . '/_media-pick-modal.php'; ?>
