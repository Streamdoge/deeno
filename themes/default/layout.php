<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($site->language, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if (isset($seo)): ?>
  <?= $seo->head($post ?? null, $seoCtx ?? []) ?>
  <?php else: ?>
  <title><?= isset($post) ? htmlspecialchars($post->title . ' | ' . $site->title, ENT_QUOTES, 'UTF-8') : htmlspecialchars($site->title, ENT_QUOTES, 'UTF-8') ?></title>
  <?php endif; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($theme->asset('style.css'), ENT_QUOTES, 'UTF-8') ?>">
  <?= Hooks::filter('site.head', '') ?>
</head>
<body>

<?php if (!empty($isPreview)): ?>
<div class="preview-bar">Предпросмотр — материал ещё не опубликован</div>
<?php endif; ?>

<header class="site-header">
  <div class="site-header__inner">
    <a class="site-title" href="<?= htmlspecialchars($site->url . '/', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($site->title, ENT_QUOTES, 'UTF-8') ?></a>
    <?php if ($site->tagline !== ''): ?>
      <span class="site-tagline"><?= htmlspecialchars($site->tagline, ENT_QUOTES, 'UTF-8') ?></span>
    <?php endif; ?>
    <nav class="site-nav">
      <?php foreach ($cms->menuPages() as $p): ?>
        <a href="<?= htmlspecialchars($site->url . '/' . $p->slug . '/', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($p->title, ENT_QUOTES, 'UTF-8') ?></a>
      <?php endforeach; ?>
    </nav>
    <form class="site-search" method="get" action="<?= htmlspecialchars($site->url . '/search/', ENT_QUOTES, 'UTF-8') ?>">
      <input type="search" name="q" placeholder="Поиск…"
             value="<?= htmlspecialchars((string)($searchQuery ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </form>
  </div>
</header>

<main class="site-main">
  <?php require $contentFile; ?>
</main>

<footer class="site-footer">
  <div class="site-footer__inner">
    <span class="site-footer__brand"><?= htmlspecialchars($site->title, ENT_QUOTES, 'UTF-8') ?></span>
    <?php if (!empty($site->social)): ?>
      <ul class="site-footer__social">
        <?php foreach ($site->social as $s): ?>
          <li><a href="<?= htmlspecialchars($s['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener nofollow" aria-label="<?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>"><?= SocialIcons::svg($s['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <span class="site-footer__note">
      <?php if ($site->footerText !== ''): ?><?= htmlspecialchars($site->footerText, ENT_QUOTES, 'UTF-8') ?> · <?php endif; ?>Работает на deeno
    </span>
  </div>
</footer>

<?php if (!empty($editUrl)): ?>
<!-- In-page edit: видит только вошедший пользователь админки -->
<a class="edit-fab" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>">
  <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
  Редактировать
</a>
<?php endif; ?>

<?= Hooks::filter('site.footer', '') ?>
</body>
</html>
