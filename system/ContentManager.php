<?php
declare(strict_types=1);

/**
 * Загрузка и фильтрация постов/страниц из файловой системы.
 * Не знает про HTTP — только работа с файлами.
 */
class ContentManager
{
    private string $postsDir;
    private string $pagesDir;
    private string $siteUrl;
    private string $orderBy;
    private string $dateFormat;
    private ?CacheManager $cache;

    /** Мемоизация в рамках запроса: посты загружаются с диска один раз */
    private ?array $allPosts = null;
    private ?array $allPages = null;

    public function __construct(array $config, ?CacheManager $cache = null)
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $this->postsDir = $root . '/content/posts/';
        $this->pagesDir = $root . '/content/pages/';
        $this->siteUrl  = rtrim((string)($config['site_url'] ?? ''), '/');
        $this->orderBy  = (string)($config['order_by'] ?? 'date');
        $this->dateFormat = (string)($config['date_format'] ?? 'd.m.Y') ?: 'd.m.Y';
        $this->cache    = $cache;
    }

    // ----------------------------------------------------------------
    // Публичное API для шаблонов ($cms->...)
    // ----------------------------------------------------------------

    /**
     * Вернуть посты. Фильтры: category, tag, author, status.
     * По умолчанию только published + sticky.
     */
    public function posts(int $limit = 0, array $filters = []): array
    {
        $posts = $this->loadAllPosts();

        // Фильтрация по статусу (по умолчанию — видимые; 'all' — без фильтра, для админки)
        $status = $filters['status'] ?? null;
        if ($status === null) {
            $posts = array_filter($posts, fn(Post $p) => in_array($p->status, ['published', 'sticky'], true));
        } elseif ($status !== 'all') {
            $posts = array_filter($posts, fn(Post $p) => $p->status === $status);
        }

        if (isset($filters['category'])) {
            $cat = $filters['category'];
            $posts = array_filter($posts, fn(Post $p) => $p->category === $cat);
        }
        if (isset($filters['tag'])) {
            $tag = $filters['tag'];
            $posts = array_filter($posts, fn(Post $p) => in_array($tag, $p->tags, true));
        }
        if (isset($filters['author'])) {
            $author = $filters['author'];
            $posts = array_filter($posts, fn(Post $p) => $p->author === $author);
        }

        $posts = array_values($posts);
        $this->sortPosts($posts);

        if ($limit > 0) {
            $posts = array_slice($posts, 0, $limit);
        }

        return $posts;
    }

    /**
     * Найти пост по категории и slug.
     */
    public function postBySlug(string $category, string $slug): ?Post
    {
        foreach ($this->loadAllPosts() as $post) {
            if ($post->slug === $slug && $post->category === $category) {
                return $post;
            }
        }
        // Посты без категории → /posts/ (Post::DEFAULT_CATEGORY)
        if ($category === Post::DEFAULT_CATEGORY) {
            foreach ($this->loadAllPosts() as $post) {
                if ($post->slug === $slug && $post->category === '') {
                    return $post;
                }
            }
        }
        return null;
    }

    /**
     * Найти страницу по slug.
     */
    public function page(string $slug): ?Post
    {
        // Slug — только буквы, цифры, дефис, подчёркивание (защита от path traversal)
        if (!preg_match('/^[\p{L}\p{N}_-]+$/u', $slug)) return null;

        $file = $this->pagesDir . $slug . '.md';
        if (!file_exists($file)) return null;
        return $this->loadFile($file);
    }

    /**
     * Все статичные страницы.
     */
    public function pages(): array
    {
        if ($this->allPages !== null) return $this->allPages;
        if (!is_dir($this->pagesDir)) return $this->allPages = [];

        $pages = [];
        foreach (glob($this->pagesDir . '*.md') ?: [] as $file) {
            $post = $this->loadFile($file);
            if ($post !== null) {
                $pages[] = $post;
            }
        }
        return $this->allPages = $pages;
    }

    /**
     * Страницы для меню навигации: published + show_in_menu, по position.
     */
    public function menuPages(): array
    {
        $pages = array_filter(
            $this->pages(),
            fn(Post $p) => in_array($p->status, ['published', 'sticky'], true) && $p->showInMenu
        );
        usort($pages, fn(Post $a, Post $b) => $a->position <=> $b->position);
        return array_values($pages);
    }

    /**
     * Упорядочить массив постов по режиму (для темы документации):
     * 'manual' (position), 'created' (dateRaw), 'modified' (dateModifiedRaw),
     * 'alpha' (по заголовку). Пустые даты — в конец, тай-брейк по slug.
     */
    public static function orderPostsBy(array $posts, string $mode): array
    {
        $key = function (Post $p) use ($mode): int|string {
            switch ($mode) {
                case 'manual':   return $p->position;
                case 'created':  return $p->dateRaw !== '' ? $p->dateRaw : '9999';
                case 'modified': return $p->dateModifiedRaw !== '' ? $p->dateModifiedRaw : '9999';
                default:         return mb_strtolower($p->title); // alpha
            }
        };
        usort($posts, fn(Post $a, Post $b): int => ($key($a) <=> $key($b)) ?: strcmp($a->slug, $b->slug));
        return array_values($posts);
    }

    /**
     * Все теги с количеством постов.
     */
    public function tags(): array
    {
        $counts = [];
        foreach ($this->posts() as $post) {
            foreach ($post->tags as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
    }

    /**
     * Все категории с количеством постов.
     */
    public function categories(): array
    {
        $counts = [];
        foreach ($this->posts() as $post) {
            $cat = $post->category ?: Post::DEFAULT_CATEGORY;
            $counts[$cat] = ($counts[$cat] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }

    /**
     * Похожие посты (по тегам).
     */
    public function related(Post $current, int $limit = 3): array
    {
        $all = $this->posts();
        $scored = [];
        foreach ($all as $post) {
            if ($post->slug === $current->slug) continue;
            $common = count(array_intersect($post->tags, $current->tags));
            if ($common > 0) {
                $scored[] = ['post' => $post, 'score' => $common];
            }
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_map(fn($s) => $s['post'], array_slice($scored, 0, $limit));
    }

    /**
     * Пост по имени файла (любой статус) — для админки и предпросмотра.
     */
    public function postByFilename(string $filename): ?Post
    {
        if (!preg_match('/^[\w\-.]+\.md$/u', $filename) || str_contains($filename, '..')) {
            return null;
        }
        $file = $this->postsDir . $filename;
        if (!is_file($file)) return null;

        $post = $this->loadFile($file);
        if ($post !== null) {
            $this->checkScheduled($post);
        }
        return $post;
    }

    /** Каталог постов (для записи из админки) */
    public function postsDir(): string
    {
        return $this->postsDir;
    }

    /**
     * Точечно обновить поля frontmatter поста (drag-and-drop: position/category).
     * Остальной frontmatter и тело сохраняются. false — файл не найден/не записан.
     */
    public function updatePostMeta(string $filename, array $changes): bool
    {
        if (!preg_match('/^[\w\-.]+\.md$/u', $filename) || str_contains($filename, '..')) {
            return false;
        }
        $path = $this->postsDir . $filename;
        if (!is_file($path)) return false;
        $parsed = FrontmatterParser::parse((string)file_get_contents($path));
        $meta   = array_merge($parsed['meta'], $changes);
        return @file_put_contents($path, FrontmatterSerializer::serialize($meta, $parsed['body']), LOCK_EX) !== false;
    }

    /** Каталог статичных страниц (для записи из админки) */
    public function pagesDir(): string
    {
        return $this->pagesDir;
    }

    /** Удалить статичную страницу по имени файла */
    public function deletePageFile(string $filename): bool
    {
        if (!preg_match('/^[\w\-.]+\.md$/u', $filename) || str_contains($filename, '..')) {
            return false;
        }
        $path = $this->pagesDir . $filename;
        if (!is_file($path)) return false;

        $ok = @unlink($path);
        if ($ok) {
            $this->allPages = null;
        }
        return $ok;
    }

    /**
     * Удалить пост по имени файла (только имя, без пути).
     * Возвращает true при успехе.
     */
    public function deletePostFile(string $filename): bool
    {
        // Только имя файла вида xxx.md — никаких путей
        if (!preg_match('/^[\w\-.]+\.md$/u', $filename) || str_contains($filename, '..')) {
            return false;
        }
        $path = $this->postsDir . $filename;
        if (!is_file($path)) return false;

        $ok = @unlink($path);
        if ($ok) {
            // Сбросить мемоизацию — список постов изменился
            $this->allPosts = null;
        }
        return $ok;
    }

    // ----------------------------------------------------------------
    // Внутренние методы
    // ----------------------------------------------------------------

    /**
     * Загрузить все посты из /content/posts/.
     * Метаданные берутся из индекса в /cache/ (пересборка при изменении файлов),
     * тела постов загружаются лениво самим Post.
     */
    private function loadAllPosts(): array
    {
        if ($this->allPosts !== null) return $this->allPosts;
        if (!is_dir($this->postsDir)) return $this->allPosts = [];

        $files = glob($this->postsDir . '*.md') ?: [];
        $sig   = $this->filesSignature($files);

        $index = $this->cache?->get('posts-index', $sig);
        if (!is_array($index)) {
            $index = $this->buildIndex($files);
            $this->cache?->set('posts-index', $sig, $index);
        }

        $posts = [];
        foreach ($index as $item) {
            $post = new Post($item['meta'], null, $item['file']);
            $post->siteUrl = $this->siteUrl;
            $post->dateFormat = $this->dateFormat;
            // Проверка отложенной публикации
            $this->checkScheduled($post);
            $posts[] = $post;
        }
        return $this->allPosts = $posts;
    }

    /** Распарсить frontmatter всех файлов и собрать индекс метаданных */
    private function buildIndex(array $files): array
    {
        $index = [];
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) continue;

            $meta = FrontmatterParser::parse($raw)['meta'];
            // Если slug не задан — извлечь из имени файла (без даты YYYY-MM-DD-)
            if (empty($meta['slug'])) {
                $base = basename($file, '.md');
                $meta['slug'] = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $base) ?? $base;
            }
            $index[] = ['file' => $file, 'meta' => $meta];
        }
        return $index;
    }

    /** Подпись набора файлов: меняется при добавлении/удалении/правке.
     *  mtime имеет секундное разрешение, поэтому дополнительно учитываем размер. */
    private function filesSignature(array $files): string
    {
        $parts = [];
        foreach ($files as $f) {
            $parts[] = $f . ':' . (int)@filemtime($f) . ':' . (int)@filesize($f);
        }
        return md5(implode('|', $parts));
    }

    /**
     * Подпись всего, что влияет на HTML страниц: контент, config.json
     * и шаблоны тем (правка темы должна сбрасывать кэш).
     * $themeDirs — каталоги активной темы и default (наследование).
     * Используется полностраничным кэшем в Router.
     */
    public function contentSignature(string|array $themeDirs = []): string
    {
        $parts = [
            $this->filesSignature(glob($this->postsDir . '*.md') ?: []),
            $this->filesSignature(glob($this->pagesDir . '*.md') ?: []),
        ];
        foreach ((array)$themeDirs as $dir) {
            if ($dir !== '') {
                $parts[] = $this->filesSignature(glob($dir . '*.php') ?: []);
            }
        }
        $root    = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $cfg     = is_file($root . '/config.php') ? $root . '/config.php' : $root . '/config.json';
        $parts[] = 'config:' . (int)@filemtime($cfg) . ':' . (int)@filesize($cfg);
        // Правка кода плагина тоже должна сбрасывать кэш страниц
        $parts[] = $this->filesSignature(glob($root . '/plugins/*/plugin.php') ?: []);
        return md5(implode('|', $parts));
    }

    /**
     * Есть ли посты, ожидающие отложенной публикации.
     * Пока такие есть, полностраничный кэш отключается: пост должен
     * появиться на сайте по времени, а не по изменению файлов.
     */
    public function hasScheduled(): bool
    {
        foreach ($this->loadAllPosts() as $post) {
            if ($post->status === 'scheduled') return true;
        }
        return false;
    }

    /** Загрузить и распарсить один .md файл */
    private function loadFile(string $path): ?Post
    {
        $raw = @file_get_contents($path);
        if ($raw === false) return null;

        $parsed = FrontmatterParser::parse($raw);
        $post = new Post($parsed['meta'], $parsed['body'], $path);
        $post->siteUrl = $this->siteUrl;
        $post->dateFormat = $this->dateFormat;

        // Если slug не задан — извлечь из имени файла
        if ($post->slug === '') {
            $base = basename($path, '.md');
            // Убираем дату в начале (YYYY-MM-DD-)
            $post->slug = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $base) ?? $base;
        }

        return $post;
    }

    /**
     * Проверить отложенную публикацию.
     * Статус меняется только в памяти: чтение не должно писать в файлы
     * (гонки при параллельных запросах). Файл актуализирует админка при сохранении.
     */
    private function checkScheduled(Post $post): void
    {
        if ($post->status !== 'scheduled' || $post->scheduledDate === null) return;
        $ts = strtotime($post->scheduledDate);
        if ($ts !== false && $ts <= time()) {
            $post->status = 'published';
        }
    }

    /** Сортировка постов */
    private function sortPosts(array &$posts): void
    {
        if ($this->orderBy === 'position') {
            usort($posts, function (Post $a, Post $b) {
                // sticky всегда первый
                if ($a->isSticky() !== $b->isSticky()) {
                    return $a->isSticky() ? -1 : 1;
                }
                return $a->position <=> $b->position;
            });
        } else {
            // По дате, новые первыми
            usort($posts, function (Post $a, Post $b) {
                if ($a->isSticky() !== $b->isSticky()) {
                    return $a->isSticky() ? -1 : 1;
                }
                return strtotime($b->dateRaw ?: '0') <=> strtotime($a->dateRaw ?: '0');
            });
        }
    }
}
