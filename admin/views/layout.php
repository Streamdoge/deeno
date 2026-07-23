<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/** @var array $user  @var string $adminBase  @var string $siteTitle  @var string $title  @var string $cspNonce */
$isAdmin = ($user['role'] ?? '') === 'admin';
?>
<!DOCTYPE html>
<?php
// Тема панели приходит с сервера — она хранится в профиле пользователя
// (Настройки → «Панель управления»), поэтому мигания при загрузке нет и
// localStorage не нужен: настройка одинакова на всех устройствах владельца.
$uiTheme = ($adminTheme ?? 'light') === 'dark' ? 'dark' : 'light';
?>
<html lang="<?= e($adminLang ?? 'ru') ?>" data-theme="<?= e($uiTheme) ?>">
<head>
  <meta charset="UTF-8">
  <!-- Панель браузера в цвет темы: --canvas из admin.css (светлая #f9fafb,
       тёмная #0F1117). Работает в Chrome/Android и Safari до 26; Safari 26 тег
       игнорирует и берёт цвет из фона страницы. -->
  <meta name="theme-color" content="<?= $uiTheme === 'dark' ? '#0F1117' : '#f9fafb' ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf" content="<?= e($security->csrfToken()) ?>">
  <?php if (($siteFavicon ?? '') !== ''): ?><link rel="icon" href="<?= e($siteFavicon) ?>"><?php endif; ?>
  <title><?= e($title) ?> - <?= e($siteTitle) ?></title>
  <link rel="stylesheet" href="<?= e($adminBase) ?>assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/admin.css') ?>">
</head>
<body>

<div class="admin">

  <aside class="sidebar" id="sidebar">
    <div class="sidebar__brand">
      <a href="<?= e($siteUrl ?: '/') ?>/" target="_blank"><?php if (($siteLogo ?? '') !== ''): ?><img class="sidebar__logo" src="<?= e($siteLogo) ?>" alt=""><?php endif; ?><?= e($siteTitle) ?></a>
    </div>
    <nav class="sidebar__nav">
      <a href="<?= e($adminBase) ?>" class="<?= $action === 'dashboard' ? 'active' : '' ?>"><?= icon('grid') ?><?= e(t('Обзор')) ?></a>

      <span class="sidebar__group"><?= e(t('Контент')) ?></span>
      <a href="<?= e($adminBase) ?>posts/" class="<?= $action === 'posts' ? 'active' : '' ?>"><?= icon('pen') ?><?= e(t('Посты')) ?><span class="sidebar__count"><?= (int)($navCounts['posts'] ?? 0) ?></span></a>
      <?php if (($user['role'] ?? '') !== 'author'): ?>
        <a href="<?= e($adminBase) ?>categories/" class="<?= $action === 'categories' ? 'active' : '' ?>"><?= icon('tag') ?><?= e(t('Категории')) ?><span class="sidebar__count"><?= (int)($navCounts['categories'] ?? 0) ?></span></a>
        <a href="<?= e($adminBase) ?>pages/" class="<?= $action === 'pages' ? 'active' : '' ?>"><?= icon('file') ?><?= e(t('Страницы')) ?><span class="sidebar__count"><?= (int)($navCounts['pages'] ?? 0) ?></span></a>
      <?php endif; ?>
      <a href="<?= e($adminBase) ?>media/" class="<?= $action === 'media' ? 'active' : '' ?>"><?= icon('image') ?><?= e(t('Медиа')) ?></a>

      <span class="sidebar__group"><?= e(t('Система')) ?></span>
      <?php if ($isAdmin): ?>
        <a href="<?= e($adminBase) ?>users/" class="<?= $action === 'users' ? 'active' : '' ?>"><?= icon('users') ?><?= e(t('Пользователи')) ?></a>
        <a href="<?= e($adminBase) ?>themes/" class="<?= $action === 'themes' ? 'active' : '' ?>"><?= icon('layers') ?><?= e(t('Темы')) ?></a>
        <a href="<?= e($adminBase) ?>plugins/" class="<?= $action === 'plugins' ? 'active' : '' ?>"><?= icon('plug') ?><?= e(t('Плагины')) ?></a>
        <a href="<?= e($adminBase) ?>backups/" class="<?= $action === 'backups' ? 'active' : '' ?>"><?= icon('archive') ?><?= e(t('Бэкапы')) ?></a>
      <?php endif; ?>
      <?php /* Настройки видны всем: не-админ найдёт там тему и язык панели */ ?>
      <a href="<?= e($adminBase) ?>settings/" class="<?= $action === 'settings' ? 'active' : '' ?>"><?= icon('sliders') ?><?= e(t('Настройки')) ?></a>
    </nav>
    <div class="sidebar__footer">
      <?php /* Переключатели темы и языка переехали в Настройки → «Панель управления»
               (2026-07-20): это личные настройки, и им место рядом с остальными,
               а не в подвале меню. Раздел доступен всем ролям — не-админ видит
               только эту карточку. */ ?>
      <div class="sidebar__account">
        <a class="sidebar__user" href="<?= e($adminBase) ?>profile/" title="<?= e(t('Профиль')) ?>"><?= icon('user') ?><?= e($user['username'] ?? '') ?></a>
        <form method="post" action="<?= e($adminBase) ?>logout/">
          <?= $security->csrfField() ?>
          <button type="submit" class="sidebar__logout" title="<?= e(t('Выход')) ?>" aria-label="<?= e(t('Выход')) ?>"><?= icon('logout') ?></button>
        </form>
      </div>
    </div>
  </aside>

  <div class="overlay" id="overlay"></div>

  <div class="main">
    <?php
    // Хлебные крошки: раздел (ссылка) / текущий заголовок
    $sections = [
        'posts'      => t('Посты'),
        'pages'      => t('Страницы'),
        'categories' => t('Категории'),
        'media'      => t('Медиа'),
        'users'      => t('Пользователи'),
        'profile'    => t('Профиль'),
        'themes'     => t('Темы'),
        'plugins'    => t('Плагины'),
        'backups'    => t('Бэкапы'),
        'settings'   => t('Настройки'),
    ];
    $sectionLabel = $sections[$action] ?? '';
    $hasCrumb = $sectionLabel !== '' && $sectionLabel !== $title;
    ?>
    <header class="topbar">
      <button class="burger" id="burger" aria-label="Menu"><?= icon('menu') ?></button>
      <nav class="crumbs" aria-label="breadcrumbs">
        <?php if ($hasCrumb): ?>
          <a class="crumbs__link" href="<?= e($adminBase . $action) ?>/"><?= e($sectionLabel) ?></a>
          <span class="crumbs__sep">/</span>
        <?php endif; ?>
        <h1 class="crumbs__current"><?= e($title) ?></h1>
      </nav>
      <button type="button" class="jump-trigger" id="jump-trigger">
        <?= icon('search') ?><span class="jump-trigger__label"><?= e(t('Поиск и переходы…')) ?></span><kbd id="jump-kbd">Ctrl K</kbd>
      </button>
    </header>

    <main class="content">
      <?php require $contentView; ?>
    </main>
  </div>

</div>

<!-- Джамп-бар: поиск по контенту и быстрые действия (⌘K) -->
<div class="modal jump" id="jump-modal" hidden>
  <div class="modal__box jump__box">
    <div class="jump__field">
      <?= icon('search') ?>
      <input type="text" id="jump-input" autocomplete="off"
             placeholder="<?= e(t('Найти пост, страницу или действие…')) ?>">
      <kbd>Esc</kbd>
    </div>
    <div class="jump__list" id="jump-list" role="listbox"></div>
  </div>
</div>

<div class="toasts" id="toasts"></div>

<script nonce="<?= e($cspNonce) ?>">
window.DEENO_ADMIN_BASE = <?= json_encode($adminBase, JSON_THROW_ON_ERROR) ?>;
window.DEENO_MAX_UPLOAD = <?= (int)uploadLimitBytes() ?>;
// Переводы строк, живущих в admin.js (ключ — русская строка, как в Lang)
window.DEENO_I18N = <?= json_encode([
    'значение'                 => t('значение'),
    'Найдена несохранённая копия от %s. Восстановить?' => t('Найдена несохранённая копия от %s. Восстановить?'),
    'Черновик сохранён локально в %s' => t('Черновик сохранён локально в %s'),
    'Сеть недоступна.'          => t('Сеть недоступна.'),
    'загрузка %s…'              => t('загрузка %s…'),
    'Ошибка загрузки: %s'       => t('Ошибка загрузки: %s'),
    'Загрузка %s…'              => t('Загрузка %s…'),
    'Скопировано!'              => t('Скопировано!'),
    'Скопируйте URL:'           => t('Скопируйте URL:'),
    'текст'                     => t('текст'),
    'сл.'                       => t('сл.'),
    'зн.'                       => t('зн.'),
    'Свободно'                  => t('Свободно'),
    'Обрезать'                  => t('Обрезать'),
    'Повернуть'                 => t('Повернуть'),
    'Отмена'                    => t('Отмена'),
    'Выберите дату'             => t('Выберите дату'),
    'Время'                     => t('Время'),
    'Ссылка на видео (YouTube или Vimeo):' => t('Ссылка на видео (YouTube или Vimeo):'),
    'Файл больше лимита сервера: %s МБ.' => t('Файл больше лимита сервера: %s МБ.'),
    'Ничего не найдено.'        => t('Ничего не найдено.'),
    'Редактировать категорию'   => t('Редактировать категорию'),
    'Новая категория'           => t('Новая категория'),
    'Редактирование'            => t('Редактирование'),
    'Новый пользователь'        => t('Новый пользователь'),
    'Пароль'                    => t('Пароль'),
    'пусто — не менять'         => t('пусто — не менять'),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?>;
// Индекс джамп-бара: быстрые действия + все посты и страницы
window.DEENO_PALETTE = <?= json_encode($paletteItems ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= e($adminBase) ?>assets/admin.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/admin.js') ?>"></script>
</body>
</html>
