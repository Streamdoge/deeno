<?php declare(strict_types=1); ?>

<article>
  <header class="post-header">
    <h1 class="post-title"><?= htmlspecialchars($page->title, ENT_QUOTES, 'UTF-8') ?></h1>
  </header>

  <div class="post-content">
    <?= $page->content() ?>
  </div>
</article>
