<?php
declare(strict_types=1);

/**
 * Метаданные категорий (Название/Описание) поверх slug-поля постов.
 * Категория как сущность не хранится сама по себе — это опциональная
 * надстройка: slug => ['title' => ..., 'description' => ...].
 * Хранится guard-файлом (см. DataFile), как config/users/stats.
 */
class CategoryManager
{
    private string $base;

    public function __construct()
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $this->base = $root . '/system/categories';
    }

    public function all(): array
    {
        [$data] = DataFile::readWithLegacy($this->base);
        return is_array($data) ? $data : [];
    }

    /** Метаданные категории с дефолтами: title = $slug, description = '',
     *  position = 0, created/modified = '' (у неявных категорий их нет) */
    public function get(string $slug): array
    {
        $meta = $this->all()[$slug] ?? [];
        return [
            'title'       => (string)($meta['title'] ?? '') !== '' ? (string)$meta['title'] : $slug,
            'description' => (string)($meta['description'] ?? ''),
            'position'    => (int)($meta['position'] ?? 0),
            'icon'        => (string)($meta['icon'] ?? ''),
            'created'     => (string)($meta['created'] ?? ''),
            'modified'    => (string)($meta['modified'] ?? ''),
        ];
    }

    /** Есть ли у категории явная запись метаданных (а не только посты) */
    public function exists(string $slug): bool
    {
        return isset($this->all()[$slug]);
    }

    public function save(string $slug, string $title, string $description, int $position = 0, string $icon = ''): void
    {
        $all = $this->all();
        $now = date('c');
        $existing = $all[$slug] ?? null;
        $all[$slug] = [
            'title'       => $title !== '' ? $title : $slug,
            'description' => $description,
            'position'    => $position,
            'icon'        => $icon,
            // created сохраняется от первой записи, modified обновляется всегда
            'created'     => (string)($existing['created'] ?? $now),
            'modified'    => $now,
        ];
        DataFile::writeMigrating($this->base, $all);
    }

    /**
     * Упорядочить slug'и категорий по режиму: 'manual' (поле position),
     * 'created' / 'modified' (даты), 'alpha' (по названию). Пустые даты —
     * в конец. Стабильный тай-брейк по slug.
     */
    public function ordered(array $slugs, string $mode): array
    {
        $all = $this->all();
        $key = function (string $slug) use ($all, $mode): int|string {
            $m = $all[$slug] ?? [];
            switch ($mode) {
                case 'manual':
                    return (int)($m['position'] ?? 0);
                case 'created':
                    return ($m['created'] ?? '') !== '' ? (string)$m['created'] : '9999';
                case 'modified':
                    return ($m['modified'] ?? '') !== '' ? (string)$m['modified'] : '9999';
                default: // alpha
                    return mb_strtolower((string)($m['title'] ?? '') !== '' ? (string)$m['title'] : $slug);
            }
        };
        usort($slugs, function (string $a, string $b) use ($key): int {
            return ($key($a) <=> $key($b)) ?: strcmp($a, $b);
        });
        return $slugs;
    }

    /** Только позиция (для drag-and-drop): не трогает остальные поля и modified. */
    public function setPosition(string $slug, int $position): void
    {
        $all = $this->all();
        if (!isset($all[$slug])) return; // только явные категории
        $all[$slug]['position'] = $position;
        DataFile::writeMigrating($this->base, $all);
    }

    public function delete(string $slug): void
    {
        $all = $this->all();
        if (isset($all[$slug])) {
            unset($all[$slug]);
            DataFile::writeMigrating($this->base, $all);
        }
    }
}
