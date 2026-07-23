<?php
declare(strict_types=1);

defined('FFC_ADMIN') or exit;

/**
 * Контроллер категорий: агрегированный список, метаданные (Название/Описание)
 * и массовое переименование/объединение/удаление.
 * Категория сама по себе — не отдельная сущность, а поле `category` в
 * frontmatter постов; переименование/объединение/удаление — это переписывание
 * файлов постов, метаданные (CategoryManager) — опциональная надстройка сверху.
 */
class CategoryController
{
    private CategoryManager $meta;

    public function __construct(private ContentManager $cms)
    {
        $this->meta = new CategoryManager();
    }

    /**
     * Все категории с количеством постов (все статусы — черновики тоже,
     * в отличие от ContentManager::categories(), который считает только видимые)
     * и метаданными (title/description). Включает и категории без единого
     * поста, если для них явно заведены метаданные (count = 0).
     */
    public function all(): array
    {
        $counts = [];
        foreach ($this->cms->posts(0, ['status' => 'all']) as $post) {
            $cat = $post->category !== '' ? $post->category : Post::DEFAULT_CATEGORY;
            $counts[$cat] = ($counts[$cat] ?? 0) + 1;
        }
        foreach ($this->meta->all() as $slug => $m) {
            $counts[$slug] ??= 0;
        }
        ksort($counts);

        $result = [];
        foreach ($counts as $slug => $count) {
            $meta = $this->meta->get($slug);
            $result[$slug] = [
                'title'       => $meta['title'],
                'description' => $meta['description'],
                'position'    => $meta['position'],
                'icon'        => $meta['icon'],
                'created'     => $meta['created'],
                'modified'    => $meta['modified'],
                'count'       => $count,
            ];
        }
        return $result;
    }

    /**
     * Завести новую категорию без единого поста (только метаданные).
     * Возвращает ['error' => true] или ['ok' => true].
     */
    public function create(string $title, string $slugRaw, string $description, int $position = 0, string $icon = ''): array
    {
        $slug = Slugger::make($slugRaw);
        if ($slug === '') {
            return ['error' => true];
        }
        if (isset($this->all()[$slug])) {
            return ['error' => true];
        }
        $this->meta->save($slug, $title, $description, $position, $icon);
        return ['ok' => true];
    }

    /**
     * Сохранить категорию: метаданные (title/description) +, если ссылка
     * изменилась, переименование/объединение по всем постам.
     * Возвращает ['error' => true] или ['ok' => true].
     */
    public function save(string $from, string $title, string $slugRaw, string $description, int $position = 0, string $icon = ''): array
    {
        $to = Slugger::make($slugRaw);
        if ($from === '' || $to === '') {
            return ['error' => true];
        }

        if ($to !== $from) {
            $this->reassignPosts($from, $to);
            $this->meta->delete($from);
        }
        $this->meta->save($to, $title, $description, $position, $icon);

        return ['ok' => true];
    }

    /** Удалить категорию: посты возвращаются к дефолтной, метаданные стираются. */
    public function delete(string $slug): void
    {
        $this->reassignPosts($slug, '');
        $this->meta->delete($slug);
    }

    /**
     * Переписать поле category во всех постах с $from на $to для всех постов.
     * $to === '' — сброс к дефолтной категории (blog).
     */
    private function reassignPosts(string $from, string $to): void
    {
        $dir = $this->cms->postsDir();

        foreach (glob($dir . '*.md') ?: [] as $path) {
            $raw    = (string)file_get_contents($path);
            $parsed = FrontmatterParser::parse($raw);
            $meta   = $parsed['meta'];

            $current = (string)($meta['category'] ?? '');
            if (($current !== '' ? $current : Post::DEFAULT_CATEGORY) !== $from) {
                continue;
            }

            $meta['category'] = $to;
            $content = FrontmatterSerializer::serialize($meta, $parsed['body']);
            if (@file_put_contents($path, $content, LOCK_EX) === false) {
                continue;
            }

            $slug = (string)($meta['slug'] ?? '');
            if ($slug !== '') {
                $oldUrl = '/' . $from . '/' . $slug . '/';
                $newUrl = '/' . ($to !== '' ? $to : Post::DEFAULT_CATEGORY) . '/' . $slug . '/';
                if ($oldUrl !== $newUrl) {
                    (new RedirectManager())->add($oldUrl, $newUrl);
                }
            }

            Hooks::run('post.saved', ['file' => basename($path), 'meta' => $meta, 'new' => false]);
        }
    }
}
