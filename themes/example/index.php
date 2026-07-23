<?php declare(strict_types=1); ?>
<?php
/**
 * index.php — списки постов. Один файл на четыре случая:
 *   • главная (лента),            • результаты поиска ($searchQuery),
 *   • страница тега ($tag),       • страница категории ($category).
 *
 * Переменные списка: $posts (Post[]), $total, $perPage, $page (номер страницы),
 * $category / $categoryTitle / $categoryDescription, $tag, $searchQuery.
 */
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$isHome = !isset($searchQuery) && !isset($tag) && (!isset($category) || $category === '');
$currentPage = $page ?? 1;
$categoryManager = new CategoryManager(); // человекочитаемые названия категорий по slug
?>

<?php /* ── Шапка списка: своя для каждого случая ── */ ?>
<?php if ($isHome && $currentPage === 1): ?>
  <section class="hero">
    <h1 class="hero__title"><?= $e($site->title) ?></h1>
    <?php if ($site->description !== ''): ?>
      <p class="hero__lead"><?= $e($site->description) ?></p>
    <?php endif; ?>
  </section>
<?php elseif (isset($searchQuery)): ?>
  <header class="list-header">
    <p class="list-header__eyebrow">Поиск</p>
    <h1 class="list-header__title">«<?= $e($searchQuery) ?>»</h1>
    <p class="list-header__count">Найдено: <?= count($posts) ?></p>
  </header>
<?php elseif (isset($tag)): ?>
  <header class="list-header">
    <p class="list-header__eyebrow">Тег</p>
    <h1 class="list-header__title"><?= $e($tag) ?></h1>
  </header>
<?php elseif (isset($category) && $category !== ''): ?>
  <header class="list-header">
    <p class="list-header__eyebrow">Категория</p>
    <h1 class="list-header__title"><?= $e($categoryTitle ?? $category) ?></h1>
    <?php if (!empty($categoryDescription)): ?>
      <p class="list-header__lead"><?= $e($categoryDescription) ?></p>
    <?php endif; ?>
  </header>
<?php endif; ?>

<?php /* ── Сами карточки ── */ ?>
<?php if (empty($posts)): ?>
  <p class="list-empty"><?= isset($searchQuery) ? 'Ничего не найдено. Попробуйте другой запрос.' : 'Постов не найдено.' ?></p>
<?php else: ?>
  <ul class="post-list">
    <?php foreach ($posts as $post): ?>
      <li class="post-card">
        <h2 class="post-card__title">
          <a href="<?= $e($post->url()) ?>"><?= $e($post->title) ?></a>
        </h2>
        <div class="post-card__meta">
          <span><?= $e($post->date()) ?></span>
          <?php if ($post->author): ?>
            <span><?= $e($post->author) ?></span>
          <?php endif; ?>
          <?php if ($post->category): ?>
            <a href="<?= $e($site->url . '/' . $post->category . '/') ?>"><?= $e($categoryManager->get($post->category)['title']) ?></a>
          <?php endif; ?>
        </div>
        <?php if (!empty($post->tags)): ?>
          <div class="post-card__tags">
            <?php foreach ($post->tags as $tag): ?>
              <a href="<?= $e($site->url . '/tag/' . $tag . '/') ?>" class="tag"><?= $e($tag) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php /* excerpt() — готовый HTML, НЕ экранируем */ ?>
        <div class="post-card__excerpt"><?= $post->excerpt() ?></div>
        <?php if ($post->hasMore()): ?>
          <a href="<?= $e($post->url()) ?>" class="post-card__more">Читать далее &rarr;</a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php /* ── Пагинация: /page/N/ для главной, /category/page/N/ для категории ── */ ?>
  <?php if (isset($total, $perPage) && $total > $perPage): ?>
    <?php
      $totalPages = (int)ceil($total / $perPage);
      $base = $site->url . '/' . (isset($category) && $category !== '' ? $category . '/' : '');
      $prevUrl = $currentPage > 2 ? $base . 'page/' . ($currentPage - 1) . '/' : $base;
      $nextUrl = $base . 'page/' . ($currentPage + 1) . '/';
    ?>
    <nav class="pagination" aria-label="Страницы">
      <span class="pagination__info">Страница <?= $currentPage ?> из <?= $totalPages ?></span>
      <div class="pagination__arrows">
        <?php if ($currentPage > 1): ?>
          <a class="pagination__arrow" href="<?= $e($prevUrl) ?>" aria-label="Предыдущая страница">&larr;</a>
        <?php else: ?>
          <span class="pagination__arrow pagination__arrow--disabled" aria-hidden="true">&larr;</span>
        <?php endif; ?>
        <?php if ($currentPage < $totalPages): ?>
          <a class="pagination__arrow" href="<?= $e($nextUrl) ?>" aria-label="Следующая страница">&rarr;</a>
        <?php else: ?>
          <span class="pagination__arrow pagination__arrow--disabled" aria-hidden="true">&rarr;</span>
        <?php endif; ?>
      </div>
    </nav>
  <?php endif; ?>
<?php endif; ?>
