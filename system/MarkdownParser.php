<?php
declare(strict_types=1);

/**
 * Обёртка над Parsedown.
 *
 * $safe = true включает safe mode: сырой HTML в Markdown экранируется.
 * Используется для контента, сохранённого не-админами (раздел 14 ТЗ) —
 * иначе Author мог бы внедрить <script> и увести сессию администратора.
 *
 * Бандлится Parsedown 1.8.0 (single-file, без composer). Safe mode покрыт
 * тестом tests/MarkdownSafeModeTest — прогон типовых XSS-пейлоадов (script,
 * onerror/onload, svg, iframe, javascript:, data:text/html): все экранируются.
 */
class MarkdownParser
{
    private static ?Parsedown $instance = null;

    private static function getInstance(bool $safe): Parsedown
    {
        if (self::$instance === null) {
            require_once __DIR__ . '/Parsedown.php';
            require_once __DIR__ . '/Markdown.php';
            self::$instance = new Markdown();
        }
        self::$instance->setSafeMode($safe);
        return self::$instance;
    }

    public static function toHtml(string $markdown, bool $safe = false): string
    {
        return self::embedVideos(self::getInstance($safe)->text($markdown));
    }

    /**
     * Строка-абзац, содержащая только ссылку YouTube/Vimeo, → адаптивный iframe.
     * URL проверяется по провайдеру и ID, iframe генерируем мы — безопасно для всех ролей.
     */
    private static function embedVideos(string $html): string
    {
        return preg_replace_callback(
            '~<p>\s*(?:<a[^>]*>)?\s*(https?://[^\s<"]+)\s*(?:</a>)?\s*</p>~i',
            static function (array $m): string {
                return self::videoEmbed($m[1]) ?? $m[0];
            },
            $html
        ) ?? $html;
    }

    private static function videoEmbed(string $url): ?string
    {
        if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([\w-]{11})~', $url, $m)) {
            $src = 'https://www.youtube-nocookie.com/embed/' . $m[1];
        } elseif (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            $src = 'https://player.vimeo.com/video/' . $m[1];
        } else {
            return null;
        }
        $src = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
        return '<div class="md-video"><iframe src="' . $src . '" loading="lazy" allowfullscreen'
            . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe></div>';
    }

    /**
     * Возвращает текст до <!--more-->, конвертированный в HTML.
     * Если маркера нет — возвращает весь текст.
     */
    public static function excerpt(string $markdown, bool $safe = false): string
    {
        $pos = strpos($markdown, '<!--more-->');
        if ($pos !== false) {
            return self::toHtml(substr($markdown, 0, $pos), $safe);
        }
        return self::toHtml($markdown, $safe);
    }

    /**
     * Текст поста после <!--more-->.
     */
    public static function hasMore(string $markdown): bool
    {
        return str_contains($markdown, '<!--more-->');
    }
}
