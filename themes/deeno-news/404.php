<?php declare(strict_types=1); ?>

<div class="error-page">
  <p class="error-page__code">404</p>
  <h1><?= htmlspecialchars($tr('Такой страницы нет'), ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="error-page__text"><?= htmlspecialchars($tr('Возможно, материал был удалён или в адресе опечатка.'), ENT_QUOTES, 'UTF-8') ?></p>
  <p><a class="error-page__home" href="<?= htmlspecialchars($site->url . '/', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tr('← На главную'), ENT_QUOTES, 'UTF-8') ?></a></p>
</div>
