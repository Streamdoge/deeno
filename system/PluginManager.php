<?php
declare(strict_types=1);

/**
 * Плагины: /plugins/<имя>/plugin.json + plugin.php.
 *
 * plugin.json — метаданные (name, description, version, author).
 * plugin.php  — исполняется при загрузке, вешает слушателей через Hooks.
 * Включённые плагины — массив имён каталогов в config.json → "plugins".
 *
 * Плагин — исполняемый PHP: устанавливать только из доверенных источников
 * (то же правило, что и для тем).
 */
class PluginManager
{
    private static string $dir;

    private static function dir(): string
    {
        return self::$dir ??= (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/plugins/';
    }

    /** Все установленные плагины: [имя-каталога => метаданные] */
    public static function all(): array
    {
        $plugins = [];
        foreach (glob(self::dir() . '*/plugin.json') ?: [] as $file) {
            $meta = json_decode((string)@file_get_contents($file), true);
            if (!is_array($meta)) continue;
            $dirName = basename(dirname($file));
            $plugins[$dirName] = [
                'dir'         => $dirName,
                'name'        => (string)($meta['name'] ?? $dirName),
                'description' => (string)($meta['description'] ?? ''),
                'version'     => (string)($meta['version'] ?? ''),
                'author'      => (string)($meta['author'] ?? ''),
            ];
        }
        ksort($plugins);
        return $plugins;
    }

    /** Имена включённых плагинов из конфига (только существующие) */
    public static function enabled(array $config): array
    {
        $list = array_filter((array)($config['plugins'] ?? []), 'is_string');
        return array_values(array_intersect($list, array_keys(self::all())));
    }

    /** Подключить включённые плагины (вызывается один раз при старте) */
    public static function loadEnabled(array $config): void
    {
        foreach (self::enabled($config) as $name) {
            // Имя каталога уже сверено со сканом all(), но перестрахуемся
            if (!preg_match('/^[a-z0-9_-]+$/i', $name)) continue;
            $file = self::dir() . $name . '/plugin.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }
}
