<?php
declare(strict_types=1);

/**
 * Ленивые картинки: loading="lazy" + decoding="async" для <img>
 * в тексте постов. Самый короткий пример фильтра.
 */
Hooks::add('post.content', function (string $html, array $ctx): string {
    $result = preg_replace(
        '/<img\s(?![^>]*\bloading=)/i',
        '<img loading="lazy" decoding="async" ',
        $html
    );
    return $result ?? $html;
});
