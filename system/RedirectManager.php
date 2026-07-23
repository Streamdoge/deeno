<?php
declare(strict_types=1);

/**
 * 301-редиректы при смене slug/категории (хранятся в /system/redirects.json).
 * Записи добавляет админка при сохранении поста/страницы с новым URL;
 * Router проверяет карту перед отдачей 404.
 */
class RedirectManager
{
    private const MAX_ENTRIES = 500;

    private string $file;

    public function __construct()
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $this->file = $root . '/system/redirects.json';
    }

    /** Куда ведёт путь (нормализованный, со слешами по краям) или null */
    public function find(string $path): ?string
    {
        $map    = $this->load();
        $target = $map[$this->normalize($path)] ?? null;
        return is_string($target) ? $target : null;
    }

    /** Добавить редирект old → new (с расплетением цепочек и защитой от циклов) */
    public function add(string $old, string $new): void
    {
        $old = $this->normalize($old);
        $new = $this->normalize($new);
        if ($old === $new) return;

        $map = $this->load();

        // Все записи, ведущие на старый URL, перенаправляем сразу на новый
        foreach ($map as $from => $to) {
            if ($to === $old) {
                $map[$from] = $new;
            }
        }
        $map[$old] = $new;
        // Если новый URL сам был источником редиректа — убираем, иначе цикл
        unset($map[$new]);

        // Ограничение размера: выкидываем самые старые записи
        if (count($map) > self::MAX_ENTRIES) {
            $map = array_slice($map, -self::MAX_ENTRIES, null, true);
        }

        @file_put_contents(
            $this->file,
            json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    // ----------------------------------------------------------------

    private function load(): array
    {
        if (!is_file($this->file)) return [];
        $map = json_decode((string)@file_get_contents($this->file), true);
        return is_array($map) ? $map : [];
    }

    /** Нормализация: /path/ — со слешами в начале и в конце */
    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : $path . '/';
    }
}
