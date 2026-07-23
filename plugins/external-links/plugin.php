<?php
declare(strict_types=1);

/**
 * Внешние ссылки: target="_blank" + rel="noopener" для всех <a>,
 * ведущих на чужие домены. Пример фильтра с разбором HTML.
 */
Hooks::add('post.content', function (string $html, array $ctx): string {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));

    $result = preg_replace_callback(
        '/<a\s[^>]*href="(https?:\/\/[^"]+)"[^>]*>/i',
        function (array $m) use ($host): string {
            $tag = $m[0];
            $linkHost = strtolower((string)parse_url($m[1], PHP_URL_HOST));
            if ($host !== '' && ($linkHost === $host || str_ends_with($linkHost, '.' . $host))) {
                return $tag; // свой домен — не трогаем
            }
            if (stripos($tag, 'target=') === false) {
                $tag = substr_replace($tag, ' target="_blank" rel="noopener"', -1, 0);
            }
            return $tag;
        },
        $html
    );

    return $result ?? $html;
});
