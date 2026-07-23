<?php declare(strict_types=1); ?>
<?php $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>

<div class="error-page">
  <h1 class="error-page__code">404</h1>
  <h2 class="error-page__title">Страница не найдена</h2>
  <p class="error-page__text">Такой страницы не существует — возможно, она удалена или ссылка неверна.</p>
  <p><a href="<?= $e($site->url . '/') ?>">&larr; На главную</a></p>
</div>
