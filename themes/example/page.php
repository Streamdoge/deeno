<?php declare(strict_types=1); ?>
<?php
/**
 * page.php — статичная страница («О нас», «Контакты»). Доступен $page (Post) и $site.
 * У страниц нет категории/тегов/обложки — только заголовок и тело.
 */
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>

<article>
  <header class="post-header">
    <h1 class="post-title"><?= $e($page->title) ?></h1>
  </header>
  <div class="post-content">
    <?= $page->content() ?>
  </div>
</article>
