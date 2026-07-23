<?php declare(strict_types=1); ?>
<?php
/**
 * post.php — одиночный пост. Доступен $post (объект Post) и $site.
 * $post->content() — тело в готовом HTML (Markdown уже отрендерен, конструкции
 * ==выделение==, {цвет}, ::: выравнивание, видео — уже внутри). НЕ экранировать.
 */
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$categoryManager = new CategoryManager();
?>

<article>
  <header class="post-header">
    <h1 class="post-title"><?= $e($post->title) ?></h1>
    <div class="post-meta">
      <span><?= $e($post->date()) ?></span>
      <?php if ($post->author): ?>
        <span>Автор: <?= $e($post->author) ?></span>
      <?php endif; ?>
      <?php if ($post->category): ?>
        <a href="<?= $e($site->url . '/' . $post->category . '/') ?>"><?= $e($categoryManager->get($post->category)['title']) ?></a>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($post->cover): ?>
    <img src="<?= $e($post->coverSrc()) ?>" alt="<?= $e($post->title) ?>" class="post-cover">
  <?php endif; ?>

  <div class="post-content">
    <?= $post->content() ?>
  </div>

  <?php if (!empty($post->tags)): ?>
    <div class="post-tags">
      <?php foreach ($post->tags as $tag): ?>
        <a href="<?= $e($site->url . '/tag/' . $tag . '/') ?>" class="tag"><?= $e($tag) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</article>

<?php /* ── Похожие посты (по общим тегам) ── */ ?>
<?php $related = $cms->related($post, 3); if (!empty($related)): ?>
  <aside class="related">
    <h2 class="related__title">Похожие посты</h2>
    <ul class="post-list">
      <?php foreach ($related as $r): ?>
        <li class="post-card post-card--compact">
          <h3 class="post-card__title">
            <a href="<?= $e($r->url()) ?>"><?= $e($r->title) ?></a>
          </h3>
          <div class="post-card__meta"><span><?= $e($r->date()) ?></span></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </aside>
<?php endif; ?>
