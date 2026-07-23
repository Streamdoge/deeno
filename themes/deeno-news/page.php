<?php declare(strict_types=1); ?>

<?php $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>

<article class="entry">
  <?php if ($page->cover): ?>
    <figure class="entry__cover"><img src="<?= $e($page->coverSrc()) ?>" alt="<?= $e($page->title) ?>"></figure>
  <?php endif; ?>

  <header class="entry__head">
    <h1 class="entry__title"><?= $e($page->title) ?></h1>
  </header>

  <div class="entry__content">
    <?= $page->content() ?>
  </div>
</article>
