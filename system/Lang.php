<?php
declare(strict_types=1);

/**
 * Локализация интерфейса админки (gettext-стиль).
 * Ключ — исходная строка на русском; перевод берётся из /system/lang/<код>.json.
 * Нет перевода — возвращается исходная строка, интерфейс не ломается.
 */
class Lang
{
    private array $map = [];

    public function __construct(string $langCode)
    {
        $langCode = preg_replace('/[^a-z]/', '', strtolower($langCode)) ?: 'ru';
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $file = $root . '/system/lang/' . $langCode . '.json';

        if (is_file($file)) {
            $map = json_decode((string)@file_get_contents($file), true);
            if (is_array($map)) {
                $this->map = $map;
            }
        }
    }

    public function get(string $source): string
    {
        $t = $this->map[$source] ?? $source;
        return is_string($t) ? $t : $source;
    }

    /**
     * Перевод вне админки: классы вроде PostController/ThemeInstaller работают и
     * без неё (тесты, CLI), где функции t() не существует. Тогда возвращается
     * исходная русская строка — так же, как при отсутствующем переводе.
     */
    public static function t(string $source): string
    {
        $lang = $GLOBALS['ffcLang'] ?? null;
        return $lang instanceof self ? $lang->get($source) : $source;
    }
}
