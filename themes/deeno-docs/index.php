<?php declare(strict_types=1);
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$categoryManager = new CategoryManager();
$secList  = $sections ?? [];
$byCat    = $postsByCat ?? [];
?>

<?php if (isset($searchQuery)): ?>
  <article class="doc">
    <p class="doc__eyebrow"><?= $e($tr('Поиск')) ?></p>
    <h1 class="doc__title">«<?= $e($searchQuery) ?>»</h1>
    <?php if (empty($posts)): ?>
      <p class="doc__lead"><?= $e($tr('Ничего не найдено. Попробуйте другой запрос.')) ?></p>
    <?php else: ?>
      <ul class="doc-list">
        <?php foreach ($posts as $p): ?>
          <li><a href="<?= $e($p->url()) ?>"><?= $e($p->title) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </article>

<?php elseif (isset($category) && $category !== ''): ?>
  <?php $list = $byCat[$category] ?? ($posts ?? []); ?>
  <article class="doc">
    <h1 class="doc__title"><?= $e($categoryTitle ?? $categoryManager->get($category)['title']) ?></h1>
    <?php if (!empty($categoryDescription)): ?>
      <p class="doc__lead"><?= $e($categoryDescription) ?></p>
    <?php endif; ?>
    <ul class="doc-list">
      <?php foreach ($list as $p): ?>
        <li><a href="<?= $e($p->url()) ?>"><?= $e($p->title) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </article>

<?php else: ?>
  <article class="doc">
    <h1 class="doc__title"><?= $e($site->title) ?></h1>
    <?php $lead = $site->tagline !== '' ? $site->tagline : $site->description; ?>
    <?php if ($lead !== ''): ?><p class="doc__lead"><?= $e($lead) ?></p><?php endif; ?>

    <?php if (empty($secList)): ?>
      <p class="doc__lead"><?= $e($tr('Постов пока нет.')) ?></p>
    <?php else: ?>
      <div class="doc-index">
        <?php foreach ($secList as $s): ?>
          <section class="doc-index__sec">
            <h2><a href="<?= $e($site->url . '/' . $s . '/') ?>"><?= $e($categoryManager->get($s)['title']) ?></a></h2>
            <ul class="doc-list">
              <?php foreach ($byCat[$s] as $p): ?>
                <li><a href="<?= $e($p->url()) ?>"><?= $e($p->title) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </article>
<?php endif; ?>
