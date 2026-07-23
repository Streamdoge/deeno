<?php
declare(strict_types=1);

/**
 * Транслитерация заголовков в slug: «Как настроить хостинг» → kak-nastroit-hosting.
 * Та же таблица продублирована в admin.js для живой подстановки в редакторе;
 * серверная версия — авторитетная (применяется при сохранении).
 */
class Slugger
{
    private const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    public static function make(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, self::MAP);
        // Всё, что не латиница/цифры — в дефис
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        // Схлопнуть повторные дефисы и ограничить длину
        $text = preg_replace('/-{2,}/', '-', $text) ?? '';
        return mb_substr($text, 0, 120);
    }

    /** Валидный ли slug (уже готовый, без транслитерации) */
    public static function isValid(string $slug): bool
    {
        return (bool)preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }
}
