<?php declare(strict_types=1); ?>

<?php $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>

<article class="article">
  <header class="article__head">
    <h1 class="article__title"><?= $e($page->title) ?></h1>
  </header>

  <div class="article__body article__body--page">
    <?= $page->content() ?>
  </div>
</article>
