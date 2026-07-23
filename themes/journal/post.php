<?php declare(strict_types=1); ?>

<?php
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$categoryManager = new CategoryManager();
$postCategorySlug = $post->category !== '' ? $post->category : Post::DEFAULT_CATEGORY;
?>

<article class="article">
  <header class="article__head">
    <p class="article__eyebrow">
      <a href="<?= $e($site->url . '/' . $postCategorySlug . '/') ?>"><?= $e($categoryManager->get($postCategorySlug)['title']) ?></a>
      · <?= $e($post->date()) ?>
      <?php if ($post->author): ?> · <?= $e($post->author) ?><?php endif; ?>
    </p>
    <h1 class="article__title"><?= $e($post->title) ?></h1>
  </header>

  <?php if ($post->cover): ?>
    <figure class="article__cover">
      <img src="<?= $e($post->cover) ?>" alt="<?= $e($post->title) ?>">
    </figure>
  <?php endif; ?>

  <div class="article__body">
    <?= $post->content() ?>
  </div>

  <?php if (!empty($post->tags)): ?>
    <p class="article__tags">
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
  <h2 class="related__title">Читайте также</h2>
  <div class="story-grid story-grid--related">
    <?php foreach ($related as $r): ?>
      <article class="story">
        <p class="story__eyebrow"><?= $e($r->date()) ?></p>
        <h3 class="story__title"><a href="<?= $e($r->url()) ?>"><?= $e($r->title) ?></a></h3>
      </article>
    <?php endforeach; ?>
  </div>
</aside>
<?php endif; ?>
