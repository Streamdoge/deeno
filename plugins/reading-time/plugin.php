<?php
declare(strict_types=1);

/**
 * Время чтения: фильтр post.content добавляет строку «≈ N мин чтения»
 * перед текстом поста. Пример фильтра для PLUGINS.md.
 */
Hooks::add('post.content', function (string $html, array $ctx): string {
    preg_match_all('/[\p{L}\p{N}]+/u', strip_tags($html), $m);
    $words = count($m[0]);
    if ($words < 100) {
        return $html; // короткая заметка — не украшаем
    }
    $minutes = max(1, (int)ceil($words / 200));
    $label   = '&#8776; ' . $minutes . '&nbsp;мин чтения';
    return '<p class="reading-time" style="color:#737373;font-size:.875em;margin-top:0">'
        . $label . '</p>' . $html;
});
