<?php declare(strict_types=1); ?>

<div class="error-page">
  <h1>404</h1>
  <h2>Страница не найдена</h2>
  <p>Такой страницы не существует. Возможно, она была удалена или вы перешли по неверной ссылке.</p>
  <p><a href="<?= htmlspecialchars($site->url . '/', ENT_QUOTES, 'UTF-8') ?>">&larr; На главную</a></p>
</div>
