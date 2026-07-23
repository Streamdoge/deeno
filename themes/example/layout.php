<?php declare(strict_types=1); ?>
<?php
/**
 * layout.php — общий каркас страницы. Внутрь через $contentFile подключается
 * index.php / post.php / page.php / 404.php. Здесь живут <head>, шапка, подвал.
 *
 * Доступные объекты (подробнее — UserDoc/THEME.md):
 *   $site   — данные сайта: title, tagline, description, url, language, footerText, social[]
 *   $seo    — SEO-блок для <head> (title/description/OG/JSON-LD). Есть не всегда — проверяйте isset.
 *   $theme  — $theme->asset('style.css') → ссылка на файл темы с кэш-бастингом
 *   $cms    — доступ к контенту: $cms->menuPages(), $cms->related($post), $cms->posts()
 *   $isPreview — true, если это предпросмотр неопубликованного материала
 *   $editUrl   — ссылка «Редактировать» (только для вошедшего в админку); пусто у гостей
 *   Hooks::filter('site.head'|'site.footer', '') — точки вставки для плагинов (не удалять)
 *
 * $e() — короткий помощник экранирования. ВЕСЬ вывод пропускайте через него,
 * КРОМЕ $post->content()/$post->excerpt() — это уже готовый безопасный HTML.
 */
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $e($site->language) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <?php /* SEO: заголовок, description, canonical, Open Graph, JSON-LD — всё одним вызовом */ ?>
  <?php if (isset($seo)): ?>
    <?= $seo->head($post ?? null, $seoCtx ?? []) ?>
  <?php else: ?>
    <title><?= isset($post) ? $e($post->title . ' | ' . $site->title) : $e($site->title) ?></title>
  <?php endif; ?>

  <link rel="stylesheet" href="<?= $e($theme->asset('style.css')) ?>">
  <?php /* Плагины вставляют сюда счётчики/шрифты/мета — оставьте строку */ ?>
  <?= Hooks::filter('site.head', '') ?>
</head>
<body>

<?php /* Плашка предпросмотра — показывается только при isPreview */ ?>
<?php if (!empty($isPreview)): ?>
  <div class="preview-bar">Предпросмотр — материал ещё не опубликован</div>
<?php endif; ?>

<header class="site-header">
  <div class="site-header__inner">
    <a class="site-title" href="<?= $e($site->url . '/') ?>"><?= $e($site->title) ?></a>

    <?php if ($site->tagline !== ''): ?>
      <span class="site-tagline"><?= $e($site->tagline) ?></span>
    <?php endif; ?>

    <?php /* Меню из статичных страниц с галочкой «Показывать в меню», по позиции */ ?>
    <nav class="site-nav">
      <?php foreach ($cms->menuPages() as $p): ?>
        <a href="<?= $e($site->url . '/' . $p->slug . '/') ?>"><?= $e($p->title) ?></a>
      <?php endforeach; ?>
    </nav>

    <form class="site-search" method="get" action="<?= $e($site->url . '/search/') ?>">
      <input type="search" name="q" placeholder="Поиск…"
             value="<?= $e((string)($searchQuery ?? '')) ?>">
    </form>
  </div>
</header>

<main class="site-main">
  <?php require $contentFile; ?>
</main>

<footer class="site-footer">
  <div class="site-footer__inner">
    <span class="site-footer__brand"><?= $e($site->title) ?></span>

    <?php /* Ссылки на соцсети из настроек; иконки — общий хелпер SocialIcons */ ?>
    <?php if (!empty($site->social)): ?>
      <ul class="site-footer__social">
        <?php foreach ($site->social as $s): ?>
          <li><a href="<?= $e($s['url']) ?>" target="_blank" rel="noopener nofollow" aria-label="<?= $e($s['name']) ?>"><?= SocialIcons::svg($s['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php /* Свой текст подвала выводим ДОПОЛНИТЕЛЬНО к «Работает на deeno» (эта подпись несъёмная) */ ?>
    <span class="site-footer__note">
      <?php if ($site->footerText !== ''): ?><?= $e($site->footerText) ?> · <?php endif; ?>Работает на deeno
    </span>
  </div>
</footer>

<?php /* Кнопка in-page edit — видит только вошедший в админку */ ?>
<?php if (!empty($editUrl)): ?>
  <a class="edit-fab" href="<?= $e($editUrl) ?>">Редактировать</a>
<?php endif; ?>

<?php /* Плагины вставляют сюда скрипты/виджеты — оставьте строку */ ?>
<?= Hooks::filter('site.footer', '') ?>
</body>
</html>
