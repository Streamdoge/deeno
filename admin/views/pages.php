<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;

/** @var Post[] $pages  @var bool $deleted  @var bool $error  @var string $adminBase  @var Security $security
 *  @var string $siteUrl  @var string $fStatus  @var string $fSearch */
usort($pages, fn(Post $a, Post $b) => $a->position <=> $b->position);
?>

<?php if ($deleted): ?>
  <div class="alert alert--success" data-toast><?= e(t('Страница удалена.')) ?></div>
<?php elseif ($error): ?>
  <div class="alert alert--danger" data-toast><?= e(t('Не удалось удалить страницу.')) ?></div>
<?php endif; ?>

<form method="get" action="<?= e($adminBase) ?>pages/" class="filters js-autofilter">
  <a class="btn btn--primary" href="<?= e($adminBase) ?>pages/new/"><?= icon('plus') ?><?= e(t('Страница')) ?></a>
  <select name="status">
    <option value=""><?= e(t('Все статусы')) ?></option>
    <?php foreach (statusLabels(true) as $s => $label): ?>
      <option value="<?= e($s) ?>" <?= ($fStatus ?? '') === $s ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="search" name="q" placeholder="<?= e(t('Поиск по заголовку…')) ?>" value="<?= e($fSearch ?? '') ?>">
</form>

<div class="card">
<?php if (empty($pages)): ?>
  <p class="muted" style="padding:1rem"><?= e(t('Статичных страниц пока нет.')) ?></p>
<?php else: ?>
  <table class="table table--striped">
    <thead>
      <tr>
        <th><?= e(t('Заголовок')) ?></th>
        <th>URL</th>
        <th><?= e(t('Статус')) ?></th>
        <th><?= e(t('В меню')) ?></th>
        <th><?= e(t('Позиция')) ?></th>
        <th class="table__actions-head"><?= e(t('Действия')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pages as $p): ?>
        <tr>
          <?php $link = materialLink($p, 'pages', $adminBase, $siteUrl . '/' . $p->slug . '/'); ?>
          <td class="table__title">
            <a href="<?= e($link['href']) ?>"<?= $link['blank'] ? ' target="_blank"' : '' ?>><?= e($p->title ?: $p->slug) ?></a>
          </td>
          <td class="muted">/<?= e($p->slug) ?>/</td>
          <td><span class="badge badge--<?= e($p->status) ?>"><?= e(statusLabel($p->status, true)) ?></span></td>
          <td><?= $p->showInMenu ? icon('tick') : '—' ?></td>
          <td class="muted"><?= (int)$p->position ?></td>
          <td class="table__actions">
            <a class="btn btn--small btn--secondary"
               href="<?= e($adminBase) ?>pages/edit/?file=<?= e(urlencode(basename($p->filePath))) ?>"><?= icon('pen') ?></a>
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

<!-- Модальное окно подтверждения удаления -->
<div class="modal" id="delete-modal" hidden>
  <div class="modal__box">
    <h3 class="modal__title"><?= e(t('Удалить страницу?')) ?></h3>
    <p class="modal__text">«<span id="delete-title"></span>» <?= e(t('будет удалена безвозвратно.')) ?></p>
    <form method="post" action="<?= e($adminBase) ?>pages/delete/">
      <?= $security->csrfField() ?>
      <input type="hidden" name="file" id="delete-file" value="">
      <div class="modal__actions">
        <button type="button" class="btn btn--secondary btn--icon js-modal-close" title="<?= e(t('Отмена')) ?>" aria-label="<?= e(t('Отмена')) ?>"><?= icon('x') ?></button>
        <button type="submit" class="btn btn--danger btn--icon" title="<?= e(t('Удалить')) ?>" aria-label="<?= e(t('Удалить')) ?>"><?= icon('trash') ?></button>
      </div>
    </form>
  </div>
</div>
