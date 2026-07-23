<?php declare(strict_types=1);
/**
 * deeno-docs — тема документации/вики в стилистике deeno UI.
 * 3 колонки: слева дерево разделов (категории) → статей (посты),
 * по центру статья, справа «На этой странице» (TOC со scroll-spy, строит main.js).
 */
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$categoryManager = new CategoryManager();

// Дерево: раздел = категория, статья = пост в ней. Порядок — из настроек.
$postsByCat = [];
foreach ($cms->posts(0) as $p) {
    $slug = $p->category !== '' ? $p->category : Post::DEFAULT_CATEGORY;
    $postsByCat[$slug][] = $p;
}
$sections = $categoryManager->ordered(array_keys($postsByCat), $site->categoryOrder);
$docFlat  = []; // плоский список статей в порядке дерева — для пред/след
foreach ($sections as $s) {
    $postsByCat[$s] = ContentManager::orderPostsBy($postsByCat[$s], $site->articleOrder);
    foreach ($postsByCat[$s] as $p) { $docFlat[] = $p; }
}
$activeSlug = isset($post) ? $post->slug : '';

// Иконка пункта дерева: картинка (путь) или эмодзи/текст. Пусто — ничего.
$renderIcon = function (string $ico) use ($e): string {
    if ($ico === '') return '';
    return str_contains($ico, '/')
        ? '<img class="tree__icon tree__icon--img" src="' . $e($ico) . '" alt="">'
        : '<span class="tree__icon">' . $e($ico) . '</span>';
};

// Статические страницы (вступление к вики) — над категориями; нет — не занимают место.
$docPages = $cms->menuPages();

// Язык интерфейса темы: кука deeno_lang (ru/en), иначе язык сайта.
$lang = (string)($_COOKIE['deeno_lang'] ?? '');
if (!in_array($lang, ['ru', 'en'], true)) {
    $lang = str_starts_with(strtolower((string)$site->language), 'en') ? 'en' : 'ru';
}
$langTo = $lang === 'ru' ? 'en' : 'ru';
$trDict = [
    'Поиск' => 'Search',
    'Введите запрос' => 'Search…',
    'Нажмите Enter, чтобы начать поиск' => 'Press Enter to search',
    'Работает на deeno' => 'Powered by deeno',
    'Редактировать' => 'Edit',
    'Предпросмотр — материал ещё не опубликован' => 'Preview — not published yet',
    'Меню' => 'Menu',
    'Язык' => 'Language',
    'Тема' => 'Theme',
    'На этой странице' => 'On this page',
    'Назад' => 'Previous',
    'Далее' => 'Next',
    'Документация' => 'Documentation',
    'Ничего не найдено. Попробуйте другой запрос.' => 'Nothing found. Try another query.',
    'Постов пока нет.' => 'No posts yet.',
    'Такой страницы нет' => 'Page not found',
    'Возможно, материал был удалён или в адресе опечатка.' => 'The page may have been removed or the address mistyped.',
    '← На главную' => '← Home',
    'Порядок статей задан сортировкой в Настройках. Перенести статью в другой раздел можно и сейчас.'
        => 'Article order comes from the sorting mode in Settings. You can still move an article to another section.',
];
$tr = fn(string $s): string => $lang === 'en' ? ($trDict[$s] ?? $s) : $s;
?>
<!DOCTYPE html>
<html lang="<?= $e($lang) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Панель браузера в цвет темы. Значения — это --canvas из style.css:
       светлая #f9fafb, тёмная #0F1117. Работает в Chrome/Android и в Safari
       до 26-й версии. Safari 26 тег ИГНОРИРУЕТ и берёт цвет из background-color
       фиксированных/липких элементов, а при их отсутствии — из фона <body>;
       под него подстроен .overlay в style.css. Тег стоит ДО скрипта ниже,
       иначе скрипту нечего будет обновлять. -->
  <meta name="theme-color" content="#f9fafb">
  <script>(function(){try{var d=localStorage.getItem('deeno-site-theme')==='dark';if(d){document.documentElement.setAttribute('data-theme','dark');var m=document.querySelector('meta[name="theme-color"]');if(m)m.setAttribute('content','#0F1117');}}catch(e){}})();</script>
  <?php if (isset($seo)): ?>
  <?= $seo->head($post ?? null, $seoCtx ?? []) ?>
  <?php else: ?>
  <title><?= isset($post) ? $e($post->title . ' | ' . $site->title) : $e($site->title) ?></title>
  <?php endif; ?>
  <link rel="stylesheet" href="<?= $e($theme->asset('style.css')) ?>">
  <?= Hooks::filter('site.head', '') ?>
</head>
<body>

<?php if (!empty($isPreview)): ?>
<div class="preview-bar"><?= $e($tr('Предпросмотр — материал ещё не опубликован')) ?></div>
<?php endif; ?>

<div class="shell">

  <!-- Левое дерево (на мобильном — выезжающий слева drawer) -->
  <aside class="side">
    <div class="side__head">
      <a class="side__logo" href="<?= $e($site->url . '/') ?>"><?php if ($site->logo !== ''): ?><img class="side__logo-img" src="<?= $e($site->logo) ?>" alt=""><?php endif; ?><span><?= $e($site->title) ?></span></a>
      <?php if ($site->tagline !== ''): ?>
        <p class="side__tagline"><?= $e($site->tagline) ?></p>
      <?php endif; ?>
      <button class="search-trigger" id="search-open" aria-label="<?= $e($tr('Поиск')) ?>">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <span><?= $e($tr('Поиск')) ?></span>
      </button>
    </div>

    <?php
    // Расстановка мышью. data-reorder-manual="0" — порядок статей считается
    // (алфавит/даты), значит переставлять внутри раздела нечего: остаётся
    // только перенос статьи в другой раздел. См. main.js и Router::commonVars().
    $canReorder = !empty($reorder);
    $reorderAttrs = $canReorder
        ? ' data-reorder-url="' . $e($reorder['url']) . '"'
          . ' data-reorder-csrf="' . $e($reorder['csrf']) . '"'
          . ' data-reorder-manual="' . (!empty($reorder['manualPosts']) ? '1' : '0') . '"'
          . ' data-reorder-hint="' . $e($tr('Порядок статей задан сортировкой в Настройках. Перенести статью в другой раздел можно и сейчас.')) . '"'
        : '';
    ?>
    <nav class="tree<?= $canReorder ? ' is-reorderable' : '' ?>" id="side-nav"<?= $reorderAttrs ?>>
      <?php if (!empty($docPages)): ?>
        <div class="tree__top">
          <?php foreach ($docPages as $pg): $u = $site->url . '/' . $pg->slug . '/'; ?>
            <a class="tree__link tree__link--top<?= $pg->slug === $activeSlug ? ' active' : '' ?>" href="<?= $e($u) ?>"><?= $renderIcon($pg->icon) ?><span class="tree__label"><?= $e($pg->title) ?></span></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php foreach ($sections as $s): $sMeta = $categoryManager->get($s); ?>
        <div class="tree__section is-open" data-category="<?= $e($s) ?>">
          <button type="button" class="tree__toggle" aria-expanded="true">
            <svg class="tree__chevron" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
            <?= $renderIcon($sMeta['icon']) ?>
            <span><?= $e($sMeta['title']) ?></span>
          </button>
          <div class="tree__items">
            <?php foreach ($postsByCat[$s] as $p): ?>
              <a class="tree__link<?= $p->slug === $activeSlug ? ' active' : '' ?>" data-file="<?= $e(basename($p->filePath)) ?>" href="<?= $e($p->url()) ?>"><?= $renderIcon($p->icon) ?><span class="tree__label"><?= $e($p->title) ?></span></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>

    <div class="side__footer">
      <div class="side__tools">
        <button type="button" class="side__theme" id="theme-toggle"
                title="<?= $e($tr('Тема')) ?>" aria-label="<?= $e($tr('Тема')) ?>">
          <svg class="theme-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
          <svg class="theme-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
        </button>
        <button type="button" class="side__lang" id="lang-toggle" data-lang="<?= $e($langTo) ?>"
                title="<?= $e($tr('Язык')) ?>" aria-label="<?= $e($tr('Язык')) ?>">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>
          <span><?= $e(strtoupper($langTo)) ?></span>
        </button>
      </div>
    </div>
  </aside>

  <!-- Затемнение под drawer (мобильное меню) -->
  <div class="overlay" id="overlay"></div>

  <div class="main">
    <!-- Мобильная шапка: бургер слева -->
    <header class="mobilebar">
      <button class="burger" id="menu-toggle" aria-label="<?= $e($tr('Меню')) ?>">
        <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
      <a class="mobilebar__logo" href="<?= $e($site->url . '/') ?>"><?php if ($site->logo !== ''): ?><img class="mobilebar__logo-img" src="<?= $e($site->logo) ?>" alt=""><?php endif; ?><span><?= $e($site->title) ?></span></a>
    </header>

    <main class="content">
      <?php require $contentFile; ?>
    </main>

    <footer class="content__footer">
      <span><?= $site->footerText !== '' ? $e($site->footerText) : $e($site->title) . ' © ' . date('Y') ?></span>
      <span><?= $e($tr('Работает на deeno')) ?></span>
    </footer>
  </div>

  <!-- Правая колонка: «На этой странице» (наполняет main.js из заголовков статьи) -->
  <aside class="toc" id="toc" hidden>
    <div class="toc__title"><?= $e($tr('На этой странице')) ?></div>
    <nav class="toc__nav" id="toc-nav"></nav>
  </aside>

  <!-- Оверлей поиска (как jump-модалка дашборда: поле + Esc) -->
  <div class="search-overlay" id="search-overlay" hidden>
    <form class="search-box" method="get" action="<?= $e($site->url) ?>/search/">
      <div class="search-box__field">
        <svg class="search-box__icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <input type="search" name="q" id="search-field" placeholder="<?= $e($tr('Введите запрос')) ?>"
               value="<?= $e((string)($searchQuery ?? '')) ?>" autocomplete="off">
        <kbd>Esc</kbd>
      </div>
      <p class="search-box__hint"><?= $e($tr('Нажмите Enter, чтобы начать поиск')) ?></p>
    </form>
  </div>

</div>

<?php if (!empty($editUrl)): ?>
<a class="edit-fab" href="<?= $e($editUrl) ?>">
  <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
  <?= $e($tr('Редактировать')) ?>
</a>
<?php endif; ?>

<script src="<?= $e($theme->asset('main.js')) ?>" defer></script>
<?= Hooks::filter('site.footer', '') ?>
</body>
</html>
