<?php declare(strict_types=1); ?>

<div class="error-page">
  <p class="error-page__code">404</p>
  <h1 class="error-page__title">Такой страницы нет</h1>
  <p class="error-page__text">Возможно, материал был удалён или в адресе опечатка.</p>
  <p><a class="error-page__home" href="<?= htmlspecialchars($site->url . '/', ENT_QUOTES, 'UTF-8') ?>">← На главную</a></p>
</div>
