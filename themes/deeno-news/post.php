<?php declare(strict_types=1); ?>

<?php
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$categoryManager = new CategoryManager();
$postCategorySlug = $post->category !== '' ? $post->category : Post::DEFAULT_CATEGORY;
?>

<article class="entry">
  <?php if ($post->cover): ?>
    <figure class="entry__cover"><img src="<?= $e($post->coverSrc()) ?>" alt="<?= $e($post->title) ?>"></figure>
  <?php endif; ?>

  <header class="entry__head">
    <h1 class="entry__title"><?= $e($post->title) ?></h1>
    <p class="entry__meta">
      <?php if ($post->author): ?><span><?= $e($post->author) ?></span><?php endif; ?>
      <span><?= $e($post->date()) ?></span>
      <a href="<?= $e($site->url . '/' . $postCategorySlug . '/') ?>"><?= $e($categoryManager->get($postCategorySlug)['title']) ?></a>
    </p>
  </header>

  <div class="entry__content">
    <?= $post->content() ?>
  </div>

  <?php if (!empty($post->tags)): ?>
    <p class="entry__tags">
      <?php foreach ($post->tags as $tg): ?>
        <a href="<?= $e($site->url . '/tag/' . $tg . '/') ?>">#<?= $e($tg) ?></a>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
</article>

<?php
$related = $cms->related($post, 3);
if (!empty($related)):
?>
<aside class="related">
  <h2 class="related__title"><?= $e($tr('Читайте также')) ?></h2>
  <div class="related__grid">
    <?php foreach ($related as $r): ?>
      <article class="related__item">
        <?php if ($r->cover): ?>
          <a class="related__thumb" href="<?= $e($r->url()) ?>"><img src="<?= $e($r->coverSrc()) ?>" alt="<?= $e($r->title) ?>" loading="lazy"></a>
        <?php endif; ?>
        <h3><a href="<?= $e($r->url()) ?>"><?= $e($r->title) ?></a></h3>
        <p class="related__date"><?= $e($r->date()) ?></p>
      </article>
    <?php endforeach; ?>
  </div>
</aside>
<?php endif; ?>
