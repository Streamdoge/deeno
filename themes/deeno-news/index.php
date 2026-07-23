<?php declare(strict_types=1); ?>

<?php
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$currentPage = $page ?? 1;
$categoryManager = new CategoryManager();
?>

<?php if (isset($searchQuery)): ?>
  <header class="list-head">
    <p class="list-head__eyebrow"><?= $e($tr('Поиск')) ?></p>
    <h1>«<?= $e($searchQuery) ?>»</h1>
    <p class="list-head__meta"><?= $e($tr('Найдено:')) ?> <?= count($posts) ?></p>
  </header>
<?php elseif (isset($tag)): ?>
  <header class="list-head">
    <p class="list-head__eyebrow"><?= $e($tr('Тег')) ?></p>
    <h1><?= $e($tag) ?></h1>
  </header>
<?php elseif (isset($category) && $category !== ''): ?>
  <header class="list-head">
    <p class="list-head__eyebrow"><?= $e($tr('Рубрика')) ?></p>
    <h1><?= $e($categoryTitle ?? $category) ?></h1>
    <?php if (!empty($categoryDescription)): ?>
      <p class="list-head__lead"><?= $e($categoryDescription) ?></p>
    <?php endif; ?>
  </header>
<?php endif; ?>

<?php if (empty($posts)): ?>
  <p class="list-empty"><?= $e(isset($searchQuery) ? $tr('Ничего не найдено. Попробуйте другой запрос.') : $tr('Постов пока нет.')) ?></p>
<?php else: ?>

  <div class="bricks">
    <?php foreach ($posts as $post): ?>
      <article class="brick">
        <?php if ($post->cover): ?>
          <a class="brick__thumb" href="<?= $e($post->url()) ?>">
            <img src="<?= $e($post->coverSrc()) ?>" alt="<?= $e($post->title) ?>" loading="lazy">
          </a>
        <?php endif; ?>
        <div class="brick__body">
          <p class="brick__meta">
            <?php $postCategorySlug = $post->category !== '' ? $post->category : Post::DEFAULT_CATEGORY; ?>
            <a href="<?= $e($site->url . '/' . $postCategorySlug . '/') ?>"><?= $e($categoryManager->get($postCategorySlug)['title']) ?></a>
            <span><?= $e($post->date()) ?></span>
          </p>
          <h2 class="brick__title"><a href="<?= $e($post->url()) ?>"><?= $e($post->title) ?></a></h2>
          <div class="brick__excerpt"><?= $post->excerpt() ?></div>
          <?php if ($post->hasMore()): ?>
            <a class="brick__more" href="<?= $e($post->url()) ?>"><?= $e($tr('Читать далее')) ?></a>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if (isset($total, $perPage) && $total > $perPage): ?>
    <?php
      $totalPages = (int)ceil($total / $perPage);
      $base = $site->url . '/' . (isset($category) && $category !== '' ? $category . '/' : '');
      $pageUrl = fn(int $n): string => $n <= 1 ? $base : $base . 'page/' . $n . '/';
    ?>
    <nav class="pagination" aria-label="Страницы">
      <?php if ($currentPage > 1): ?>
        <a class="pagination__arrow" href="<?= $e($pageUrl($currentPage - 1)) ?>">←</a>
      <?php endif; ?>
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i === $currentPage): ?>
          <span class="pagination__num current"><?= $i ?></span>
        <?php else: ?>
          <a class="pagination__num" href="<?= $e($pageUrl($i)) ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($currentPage < $totalPages): ?>
        <a class="pagination__arrow" href="<?= $e($pageUrl($currentPage + 1)) ?>">→</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>
