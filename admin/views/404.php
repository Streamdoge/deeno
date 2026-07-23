<?php
declare(strict_types=1);
defined('FFC_ADMIN') or exit;
?>
<div class="card">
  <h2 class="card__title"><?= e(t('Раздел не найден')) ?></h2>
  <p class="muted"><?= e(t('Такого раздела в панели управления нет.')) ?></p>
  <p><a class="btn btn--primary" href="<?= e($adminBase) ?>"><?= e(t('К обзору')) ?></a></p>
</div>
