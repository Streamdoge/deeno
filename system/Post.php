<?php
declare(strict_types=1);

/**
 * Объект поста. Предоставляет API для шаблонов темы.
 */
class Post
{
    /** Слаг категории по умолчанию (посты без рубрики) — сегмент URL /posts/… */
    public const DEFAULT_CATEGORY = 'posts';

    public string $title;
    public string $slug;
    public string $category;
    public string $status;
    public string $author;
    public array  $tags;
    public string $cover;
    public string $excerpt_raw;
    public bool $safeHtml = false;
    public string $template;
    public int    $position;
    public string $icon;   // необязательная иконка (картинка/эмодзи) для навигации темы
    public bool   $showInMenu;
    public string $dateRaw;
    public string $dateModifiedRaw;
    public ?string $scheduledDate;

    // SEO
    public string $seoTitle;
    public string $seoDescription;
    public bool   $seoNoindex;
    public string $canonical;
    public string $ogTitle;
    public string $ogImage;
    public string $ogDescription;

    public array $customFields;

    /** Путь к исходному .md файлу */
    public string $filePath;

    /** Базовый URL сайта (задаётся из ContentManager) */
    public string $siteUrl = '';

    /** Формат даты по умолчанию (из настроек, задаётся из ContentManager) */
    public string $dateFormat = 'd.m.Y';

    /** Тело поста; null — ещё не загружено (посты из индекса кэша загружают тело лениво) */
    private ?string $body;

    public function __construct(array $meta, ?string $body, string $filePath)
    {
        $this->filePath        = $filePath;
        $this->body            = $body;
        $this->title           = (string)($meta['title'] ?? '');
        $this->slug            = (string)($meta['slug'] ?? '');
        $this->category        = (string)($meta['category'] ?? '');
        $this->status          = (string)($meta['status'] ?? 'draft');
        $this->author          = (string)($meta['author'] ?? '');
        $this->tags            = (array)($meta['tags'] ?? []);
        $this->cover           = (string)($meta['cover'] ?? '');
        $this->excerpt_raw     = (string)($meta['excerpt'] ?? '');
        $this->safeHtml        = !empty($meta['safe_html']);
        $this->template        = (string)($meta['template'] ?? '');
        $this->position        = (int)($meta['position'] ?? 0);
        $this->icon            = (string)($meta['icon'] ?? '');
        $this->showInMenu      = (bool)($meta['show_in_menu'] ?? true);
        $this->dateRaw         = (string)($meta['date'] ?? '');
        $this->dateModifiedRaw = (string)($meta['date_modified'] ?? $this->dateRaw);
        $this->scheduledDate   = isset($meta['scheduled_date']) ? (string)$meta['scheduled_date'] : null;

        $this->seoTitle        = (string)($meta['seo_title'] ?? '');
        $this->seoDescription  = (string)($meta['seo_description'] ?? '');
        $this->seoNoindex      = (bool)($meta['seo_noindex'] ?? false);
        $this->canonical       = (string)($meta['canonical'] ?? '');
        $this->ogTitle         = (string)($meta['og_title'] ?? '');
        $this->ogImage         = (string)($meta['og_image'] ?? '');
        $this->ogDescription   = (string)($meta['og_description'] ?? '');

        $this->customFields    = (array)($meta['custom_fields'] ?? []);
    }

    /** Исходный Markdown поста (ленивая загрузка с диска) */
    public function rawBody(): string
    {
        if ($this->body === null) {
            $raw = @file_get_contents($this->filePath);
            $this->body = $raw === false ? '' : FrontmatterParser::parse($raw)['body'];
        }
        return $this->body;
    }

    /** HTML содержимое поста */
    public function content(): string
    {
        // safe_html ставится при сохранении не-админом: сырой HTML экранируется
        $html = MarkdownParser::toHtml($this->rawBody(), $this->safeHtml);
        // Фильтр плагинов (см. PLUGINS.md): reading-time, lazy-images и т.п.
        return (string)Hooks::filter('post.content', $html, ['post' => $this]);
    }

    /** HTML превью (до <!--more-->) */
    public function excerpt(): string
    {
        if ($this->excerpt_raw !== '') {
            return htmlspecialchars($this->excerpt_raw, ENT_QUOTES, 'UTF-8');
        }
        return MarkdownParser::excerpt($this->rawBody(), $this->safeHtml);
    }

    /** Форматированная дата (без аргумента — формат из настроек сайта) */
    public function date(?string $format = null): string
    {
        if ($this->dateRaw === '') return '';
        $ts = strtotime($this->dateRaw);
        return $ts !== false ? date($format ?? $this->dateFormat, $ts) : $this->dateRaw;
    }

    /** Форматированная дата изменения (без аргумента — формат из настроек сайта) */
    public function dateModified(?string $format = null): string
    {
        if ($this->dateModifiedRaw === '') return '';
        $ts = strtotime($this->dateModifiedRaw);
        return $ts !== false ? date($format ?? $this->dateFormat, $ts) : $this->dateModifiedRaw;
    }

    /** Полный URL поста */
    public function url(): string
    {
        $base = rtrim($this->siteUrl, '/');
        $cat = $this->category !== '' ? $this->category : self::DEFAULT_CATEGORY;
        return $base . '/' . $cat . '/' . $this->slug . '/';
    }

    /**
     * URL обложки для <img> на странице — с меткой ?v=<mtime> для сброса
     * кэша браузера. Картинки в /media/ отдаются с 30-дневным кэшем; без метки
     * заменённая обложка (тот же файл, новое содержимое) у прежних посетителей
     * ещё месяц показывалась бы из кэша.
     *
     * ВАЖНО: метку добавляет ТОЛЬКО этот метод, вызываемый в темах для <img>.
     * Путь к og:image / twitter:image / JSON-LD берётся из сырого $this->cover
     * (см. SeoManager) и остаётся чистым — соцсети и поисковики кэшируют
     * превью по адресу, меняющийся ?v там нежелателен.
     *
     * Метка ставится только локальному существующему файлу. Внешний URL
     * (https://чужой-сайт/…) и ссылка на несуществующий файл возвращаются
     * как есть — иначе получили бы битый ?v=false или лишний stat.
     */
    public function coverSrc(): string
    {
        $cover = $this->cover;
        if ($cover === '' || !str_starts_with($cover, '/media/')) {
            return $cover;
        }

        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        // Отсекаем возможную query/fragment: и для поиска файла, и для итога —
        // иначе к «pic.jpg?x=1» приклеился бы второй «?v=…» (битый URL).
        $urlPath = (string)parse_url($cover, PHP_URL_PATH);
        $mtime   = @filemtime($root . '/' . ltrim($urlPath, '/'));

        return $mtime === false ? $cover : $urlPath . '?v=' . $mtime;
    }

    /** Значение кастомного поля */
    public function custom(string $key): mixed
    {
        return $this->customFields[$key] ?? null;
    }

    public function isSticky(): bool
    {
        return $this->status === 'sticky';
    }

    /**
     * Отдаётся ли материал по прямой ссылке гостю (в списках свой фильтр —
     * unlisted туда не попадает). Черновик и отложенная публикация дадут 404,
     * поэтому админка по этому признаку решает, вести ли ссылку на сайт или
     * сразу в редактор.
     */
    public function isPubliclyVisible(): bool
    {
        return in_array($this->status, ['published', 'sticky', 'unlisted'], true);
    }

    /** Есть ли разрыв <!--more--> */
    public function hasMore(): bool
    {
        return MarkdownParser::hasMore($this->rawBody());
    }
}
