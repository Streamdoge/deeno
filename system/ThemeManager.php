<?php
declare(strict_types=1);

/**
 * Загрузка шаблонов активной темы и рендер страниц.
 * Наследование: если в активной теме нет нужного шаблона, берётся
 * одноимённый из темы default. Минимальная тема — theme.json + style.css.
 */
class ThemeManager
{
    public const FALLBACK_THEME = 'default';

    private string $themeName;
    private string $themeDir;
    private string $defaultDir;
    private string $themeUrl;

    public function __construct(array $config)
    {
        $root             = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $this->themeName  = (string)($config['theme'] ?? self::FALLBACK_THEME);
        $this->themeDir   = $root . '/themes/' . $this->themeName . '/';
        $this->defaultDir = $root . '/themes/' . self::FALLBACK_THEME . '/';
        $siteUrl          = rtrim((string)($config['site_url'] ?? ''), '/');
        $this->themeUrl   = $siteUrl . '/themes/' . $this->themeName;
    }

    /**
     * Объект $theme для шаблонов.
     */
    public function themeObject(): object
    {
        $themeUrl = $this->themeUrl;
        $themeDir = $this->themeDir;
        return new class($themeUrl, $themeDir) {
            public string $url;
            private string $dir;
            public function __construct(string $url, string $dir) { $this->url = $url; $this->dir = $dir; }
            /**
             * URL ассета темы с кэш-бастингом ?v=<filemtime> — как у админки.
             * Без него браузер держит старые style.css/main.js после обновления
             * темы сколь угодно долго (Safari особенно упорно), и правки просто
             * не доезжают до пользователя. Файла нет — отдаём URL как есть.
             */
            public function asset(string $file): string {
                $rel  = ltrim($file, '/');
                $url  = $this->url . '/assets/' . $rel;
                $mt   = @filemtime($this->dir . 'assets/' . $rel);
                return $mt === false ? $url : $url . '?v=' . $mt;
            }
        };
    }

    /**
     * Путь к файлу шаблона с учётом наследования от default.
     *
     * Имя шаблона — только простое имя файла. Значение приходит в том числе
     * из поля «Шаблон» редактора (frontmatter `template`), то есть его задаёт
     * автор поста; без проверки `../../install` заставил бы require подключить
     * посторонний .php из дерева проекта. Подкаталоги запрещены сознательно:
     * ими не пользуется ни одна тема, а при необходимости правило расширяется
     * до «разрешить /, запретить .. и ведущий слэш».
     */
    public function resolve(string $template): ?string
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $template)) return null;

        $file = $this->themeDir . $template . '.php';
        if (is_file($file)) return $file;

        $fallback = $this->defaultDir . $template . '.php';
        return is_file($fallback) ? $fallback : null;
    }

    /**
     * Рендер шаблона темы.
     * $template — имя файла без .php (index, post, page, 404)
     * $vars — переменные для шаблона
     */
    public function render(string $template, array $vars = []): void
    {
        $file = $this->resolve($template) ?? $this->resolve('404');
        if ($file === null) {
            http_response_code(500);
            echo 'Theme is broken: no templates found.';
            return;
        }

        // Передаём переменные в область видимости шаблона
        extract($vars, EXTR_SKIP);

        $layoutFile  = $this->resolve('layout');
        $contentFile = $file;

        if ($layoutFile !== null) {
            // layout.php включает $contentFile через require
            require $layoutFile;
        } else {
            require $file;
        }
    }

    /**
     * Рендер только части контента (без layout), возвращает строку.
     */
    public function renderPartial(string $template, array $vars = []): string
    {
        $file = $this->resolve($template);
        if ($file === null) return '';

        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        return ob_get_clean() ?: '';
    }

    public function themeDir(): string
    {
        return $this->themeDir;
    }

    /**
     * Каталоги, чьи шаблоны влияют на вывод (для подписи кэша страниц):
     * активная тема + default (наследование).
     */
    public function signatureDirs(): array
    {
        return array_values(array_unique([$this->themeDir, $this->defaultDir]));
    }
}
