<?php declare(strict_types=1); ?>

<?php
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$isHome = !isset($searchQuery) && !isset($tag) && (!isset($category) || $category === '');
$currentPage = $page ?? 1;
$categoryManager = new CategoryManager();
$catName = fn(string $slug): string => $categoryManager->get($slug !== '' ? $slug : Post::DEFAULT_CATEGORY)['title'];

// На первой странице главной первый пост — «обложка номера»
$cover = null;
$rest  = $posts;
if ($isHome && $currentPage === 1 && count($posts) > 1) {
    $cover = $posts[0];
    $rest  = array_slice($posts, 1);
}
?>

<?php if (isset($searchQuery)): ?>
  <header class="section-head">
    <p class="section-head__eyebrow">Поиск</p>
    <h1 class="section-head__title">«<?= $e($searchQuery) ?>»</h1>
    <p class="section-head__meta">Найдено: <?= count($posts) ?></p>
  </header>
<?php elseif (isset($tag)): ?>
  <header class="section-head">
    <p class="section-head__eyebrow">Тег</p>
    <h1 class="section-head__title"><?= $e($tag) ?></h1>
  </header>
<?php elseif (isset($category) && $category !== ''): ?>
  <header class="section-head">
    <p class="section-head__eyebrow">Рубрика</p>
    <h1 class="section-head__title"><?= $e($categoryTitle ?? $category) ?></h1>
    <?php if (!empty($categoryDescription)): ?>
      <p class="section-head__lead"><?= $e($categoryDescription) ?></p>
    <?php endif; ?>
  </header>
<?php endif; ?>

<?php if (empty($posts)): ?>
  <p class="list-empty"><?= isset($searchQuery) ? 'Ничего не найдено. Попробуйте другой запрос.' : 'Постов пока нет.' ?></p>
<?php else: ?>

  <?php if ($cover !== null): ?>
    <article class="cover-story">
      <p class="cover-story__eyebrow">
        <?= $e($catName($cover->category)) ?> · <?= $e($cover->date()) ?>
      </p>
      <h2 class="cover-story__title">
        <a href="<?= $e($cover->url()) ?>"><?= $e($cover->title) ?></a>
      </h2>
      <?php if ($cover->cover): ?>
        <a href="<?= $e($cover->url()) ?>"><img class="cover-story__img" src="<?= $e($cover->coverSrc()) ?>" alt="<?= $e($cover->title) ?>"></a>
      <?php endif; ?>
      <div class="cover-story__excerpt"><?= $cover->excerpt() ?></div>
      <a class="cover-story__more" href="<?= $e($cover->url()) ?>">Читать →</a>
    </article>
  <?php endif; ?>

  <div class="story-grid">
    <?php foreach ($rest as $post): ?>
      <article class="story">
        <p class="story__eyebrow">
          <?= $e($catName($post->category)) ?> · <?= $e($post->date()) ?>
        </p>
        <h3 class="story__title">
          <a href="<?= $e($post->url()) ?>"><?= $e($post->title) ?></a>
        </h3>
        <div class="story__excerpt"><?= $post->excerpt() ?></div>
        <?php if (!empty($post->tags)): ?>
          <p class="story__tags">
            <?php foreach ($post->tags as $tg): ?>
              <a href="<?= $e($site->url . '/tag/' . $tg . '/') ?>">#<?= $e($tg) ?></a>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if (isset($total, $perPage) && $total > $perPage): ?>
    <?php
      $totalPages = (int)ceil($total / $perPage);
      $base = $site->url . '/' . (isset($category) && $category !== '' ? $category . '/' : '');
      $prevUrl = $currentPage > 2 ? $base . 'page/' . ($currentPage - 1) . '/' : $base;
      $nextUrl = $base . 'page/' . ($currentPage + 1) . '/';
    ?>
    <nav class="pagination" aria-label="Страницы">
      <?php if ($currentPage > 1): ?>
        <a class="pagination__link" href="<?= $e($prevUrl) ?>">← Свежее</a>
      <?php else: ?><span></span><?php endif; ?>
      <span class="pagination__info">Страница <?= $currentPage ?> из <?= $totalPages ?></span>
      <?php if ($currentPage < $totalPages): ?>
        <a class="pagination__link" href="<?= $e($nextUrl) ?>">Раньше →</a>
      <?php else: ?><span></span><?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>
