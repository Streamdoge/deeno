<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/**
 * @var Post[] $posts  @var int $total  @var int $page  @var int $perPage
 * @var array $categories  @var string $fStatus  @var string $fCategory  @var string $fSearch
 * @var bool $deleted  @var bool $error  @var string $adminBase  @var Security $security
 */
$statuses = statusLabels();
?>

<?php if ($deleted): ?>
  <div class="alert alert--success" data-toast><?= e(t('Пост удалён.')) ?></div>
<?php elseif ($error): ?>
  <div class="alert alert--danger" data-toast><?= e(t('Не удалось удалить пост.')) ?></div>
<?php endif; ?>

<form method="get" action="<?= e($adminBase) ?>posts/" class="filters js-autofilter">
  <a class="btn btn--primary" href="<?= e($adminBase) ?>posts/new/"><?= icon('plus') ?><?= e(t('Пост')) ?></a>
  <select name="status">
    <option value=""><?= e(t('Все статусы')) ?></option>
    <?php foreach ($statuses as $s => $label): ?>
      <option value="<?= e($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="category">
    <option value=""><?= e(t('Все категории')) ?></option>
    <?php foreach ($categories as $c): ?>
      <option value="<?= e($c) ?>" <?= $fCategory === $c ? 'selected' : '' ?>><?= $c === Post::DEFAULT_CATEGORY ? e(t('Без категории')) : e($c) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="search" name="q" placeholder="<?= e(t('Поиск по заголовку…')) ?>" value="<?= e($fSearch) ?>">
</form>

<div class="card">
<?php if (empty($posts)): ?>
  <p class="muted" style="padding:1rem"><?= e(t('Постов не найдено.')) ?></p>
<?php else: ?>
  <table class="table table--striped">
    <thead>
      <tr>
        <th><?= e(t('Заголовок')) ?></th>
        <th><?= e(t('Автор')) ?></th>
        <th><?= e(t('Категория')) ?></th>
        <th><?= e(t('Теги')) ?></th>
        <th><?= e(t('Статус')) ?></th>
        <th><?= e(t('Дата')) ?></th>
        <th class="table__actions-head"><?= e(t('Действия')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($posts as $p): ?>
        <tr>
          <?php $link = materialLink($p, 'posts', $adminBase, $p->url()); ?>
          <td class="table__title">
            <a href="<?= e($link['href']) ?>"<?= $link['blank'] ? ' target="_blank"' : '' ?>><?= e($p->title ?: $p->slug) ?></a>
          </td>
          <td><?= e($p->author) ?></td>
          <td><?= $p->category !== '' ? e($p->category) : e(t('Без категории')) ?></td>
          <td class="muted"><?= e(implode(', ', $p->tags)) ?></td>
          <td><span class="badge badge--<?= e($p->status) ?>"><?= e(statusLabel($p->status)) ?></span></td>
          <td class="muted"><?= e($p->date()) ?></td>
          <td class="table__actions">
            <a class="btn btn--small btn--secondary"
               href="<?= e($adminBase) ?>posts/edit/?file=<?= e(urlencode(basename($p->filePath))) ?>"><?= icon('pen') ?></a>
            <button class="btn btn--small btn--danger js-delete"
                    data-file="<?= e(basename($p->filePath)) ?>"
                    data-title="<?= e($p->title ?: $p->slug) ?>"><?= icon('trash') ?></button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<?php if ($total > $perPage): ?>
  <?php $totalPages = (int)ceil($total / $perPage); ?>
  <nav class="pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <?php
        $qs = http_build_query(array_filter([
            'status' => $fStatus, 'category' => $fCategory, 'q' => $fSearch, 'page' => $i > 1 ? $i : null,
        ]));
      ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="<?= e($adminBase . 'posts/' . ($qs !== '' ? '?' . $qs : '')) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </nav>
<?php endif; ?>

<!-- Модальное окно подтверждения удаления -->
<div class="modal" id="delete-modal" hidden>
  <div class="modal__box">
    <h3 class="modal__title"><?= e(t('Удалить пост?')) ?></h3>
    <p class="modal__text">«<span id="delete-title"></span>» <?= e(t('будет удалён безвозвратно.')) ?></p>
    <form method="post" action="<?= e($adminBase) ?>posts/delete/">
      <?= $security->csrfField() ?>
      <input type="hidden" name="file" id="delete-file" value="">
      <div class="modal__actions">
        <button type="button" class="btn btn--secondary btn--icon js-modal-close" title="<?= e(t('Отмена')) ?>" aria-label="<?= e(t('Отмена')) ?>"><?= icon('x') ?></button>
        <button type="submit" class="btn btn--danger btn--icon" title="<?= e(t('Удалить')) ?>" aria-label="<?= e(t('Удалить')) ?>"><?= icon('trash') ?></button>
      </div>
    </form>
  </div>
</div>
