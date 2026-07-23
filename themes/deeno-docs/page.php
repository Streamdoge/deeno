<?php declare(strict_types=1);
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>

<article class="doc">
  <h1 class="doc__title"><?php if ($page->icon !== ''): ?><?php if (str_contains($page->icon, '/')): ?><img class="doc__title-icon" src="<?= $e($page->icon) ?>" alt=""><?php else: ?><span class="doc__title-icon"><?= $e($page->icon) ?></span><?php endif; ?> <?php endif; ?><?= $e($page->title) ?></h1>
  <div class="doc__content entry__content">
    <?= $page->content() ?>
  </div>
</article>
