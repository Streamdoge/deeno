<?php
declare(strict_types=1);

/**
 * Система хуков — фундамент плагинов (см. PLUGINS.md).
 *
 * Два вида хуков:
 *  - события (run): «случилось X» — слушатели получают payload;
 *  - фильтры (filter): значение проходит по цепочке слушателей,
 *    каждый возвращает (возможно изменённое) значение дальше.
 *
 * События ядра: post.saved, post.deleted, media.uploaded.
 * Фильтры ядра:  post.content (HTML статьи), site.head, site.footer
 *                (строки, которые тема выводит в <head> и перед </body>).
 */
class Hooks
{
    /** @var array<string, callable[]> */
    private static array $listeners = [];

    public static function add(string $event, callable $fn): void
    {
        self::$listeners[$event][] = $fn;
    }

    /** Вызвать событие; payload передаётся каждому слушателю */
    public static function run(string $event, array $payload = []): void
    {
        foreach (self::$listeners[$event] ?? [] as $fn) {
            $fn($payload);
        }
    }

    /**
     * Пропустить значение через цепочку фильтров.
     * Слушатель: fn($value, array $ctx) → новое значение.
     * Вернул null — значение остаётся прежним (защита от забытого return).
     */
    public static function filter(string $name, mixed $value, array $ctx = []): mixed
    {
        foreach (self::$listeners[$name] ?? [] as $fn) {
            $result = $fn($value, $ctx);
            if ($result !== null) {
                $value = $result;
            }
        }
        return $value;
    }
}
