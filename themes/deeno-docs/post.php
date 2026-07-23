<?php declare(strict_types=1);
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$categoryManager = new CategoryManager();
$catSlug = $post->category !== '' ? $post->category : Post::DEFAULT_CATEGORY;

// Пред/след по плоскому дереву ($docFlat формируется в layout.php)
$flat = $docFlat ?? [];
$prev = $next = null;
foreach ($flat as $i => $d) {
    if ($d->slug === $post->slug) {
        $prev = $flat[$i - 1] ?? null;
        $next = $flat[$i + 1] ?? null;
        break;
    }
}
?>

<article class="doc">
  <nav class="doc__crumbs" aria-label="breadcrumbs">
    <a href="<?= $e($site->url . '/' . $catSlug . '/') ?>"><?= $e($categoryManager->get($catSlug)['title']) ?></a>
    <span class="doc__crumbs-sep">/</span>
    <span><?= $e($post->title) ?></span>
  </nav>

  <h1 class="doc__title"><?= $e($post->title) ?></h1>

  <div class="doc__content entry__content">
    <?= $post->content() ?>
  </div>

  <?php if ($prev || $next): ?>
    <nav class="doc__pager">
      <?php if ($prev): ?>
        <a class="doc__pager-link doc__pager-prev" href="<?= $e($prev->url()) ?>">
          <span class="doc__pager-dir">← <?= $e($tr('Назад')) ?></span>
          <span class="doc__pager-title"><?= $e($prev->title) ?></span>
        </a>
      <?php else: ?><span></span><?php endif; ?>
      <?php if ($next): ?>
        <a class="doc__pager-link doc__pager-next" href="<?= $e($next->url()) ?>">
          <span class="doc__pager-dir"><?= $e($tr('Далее')) ?> →</span>
          <span class="doc__pager-title"><?= $e($next->title) ?></span>
        </a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</article>
