<?php
declare(strict_types=1);

/**
 * Запись YAML-шапки поста — зеркало FrontmatterParser.
 * Генерирует ровно то подмножество YAML, которое понимает парсер:
 * скаляры, inline-массивы [a, b], блок custom_fields с отступом в 2 пробела.
 * Гарантия round-trip: serialize() → parse() возвращает те же данные.
 */
class FrontmatterSerializer
{
    /** Порядок полей в шапке — как в разделе 5.1 ТЗ */
    private const FIELD_ORDER = [
        'title', 'slug', 'date', 'date_modified', 'author', 'status',
        'scheduled_date', 'category', 'tags', 'cover', 'excerpt',
        'template', 'position', 'icon',
        'seo_title', 'seo_description', 'seo_noindex', 'canonical',
        'og_title', 'og_image', 'og_description',
        'custom_fields',
    ];

    /**
     * Собрать полный .md файл: YAML-шапка + тело.
     * Пустые значения не пишутся — шапка остаётся чистой.
     */
    public static function serialize(array $meta, string $body): string
    {
        $lines = ['---'];

        // Сначала известные поля в каноническом порядке, потом остальные
        $ordered = [];
        foreach (self::FIELD_ORDER as $key) {
            if (array_key_exists($key, $meta)) {
                $ordered[$key] = $meta[$key];
            }
        }
        foreach ($meta as $key => $value) {
            if (!array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        foreach ($ordered as $key => $value) {
            // Пропускаем пустое: null, '', пустые массивы.
            // Булев false НЕ пропускается: show_in_menu: false — значимое значение
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if ($key === 'custom_fields' && is_array($value)) {
                $lines[] = 'custom_fields:';
                foreach ($value as $ck => $cv) {
                    if (!preg_match('/^\w+$/', (string)$ck) || $cv === '' || $cv === null) continue;
                    $lines[] = '  ' . $ck . ': ' . self::scalar($cv);
                }
                continue;
            }
            if (is_array($value)) {
                $lines[] = $key . ': [' . implode(', ', array_map(
                    fn($v) => self::arrayItem((string)$v),
                    $value
                )) . ']';
                continue;
            }
            $lines[] = $key . ': ' . self::scalar($value);
        }

        $lines[] = '---';
        return implode("\n", $lines) . "\n\n" . rtrim($body) . "\n";
    }

    /** Скаляр в YAML-представление, безопасное для нашего парсера */
    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_int($value) || is_float($value)) return (string)$value;

        $s = (string)$value;
        // Парсер построчный: переносы строк в значении недопустимы
        $s = trim((string)preg_replace('/\s*\R\s*/u', ' ', $s));

        return self::needsQuotes($s) ? self::quote($s) : $s;
    }

    /**
     * Значение надо квотировать, если парсер иначе истолкует его:
     * `#` (комментарий), ведущие/замыкающие кавычки, [..] (массив),
     * true/false/null/число (сменится тип), пустота и краевые пробелы.
     */
    private static function needsQuotes(string $s): bool
    {
        if ($s === '') return true;
        if (trim($s) !== $s) return true;
        if (str_contains($s, '#')) return true;
        if (str_starts_with($s, '[') && str_ends_with($s, ']')) return true;
        if (str_starts_with($s, '"') || str_starts_with($s, "'")) return true;
        if (str_ends_with($s, '"') || str_ends_with($s, "'")) return true;

        $lower = strtolower($s);
        if (in_array($lower, ['true', 'false', 'null', '~'], true)) return true;
        if (is_numeric($s)) return true;

        return false;
    }

    /**
     * Квотирование под наш парсер: он снимает только внешнюю пару кавычек,
     * не разбирая экранирование. Выбираем кавычки, которых нет по краям значения.
     */
    private static function quote(string $s): string
    {
        // Внутренние кавычки не мешают; опасны только совпадающие краевые
        if (!str_starts_with($s, '"') && !str_ends_with($s, '"')) {
            return '"' . $s . '"';
        }
        if (!str_starts_with($s, "'") && !str_ends_with($s, "'")) {
            return "'" . $s . "'";
        }
        // Значение и начинается, и заканчивается кавычками обоих видов —
        // добавим пробел в конец, чтобы можно было безопасно заключить в двойные
        return '"' . $s . ' "';
    }

    /**
     * Элемент inline-массива: внутри [a, b] запятые и скобки недопустимы.
     * Теги и категории приводим к безопасному виду.
     */
    private static function arrayItem(string $s): string
    {
        $s = trim(str_replace([',', '[', ']', "\n", "\r"], ' ', $s));
        $s = (string)preg_replace('/\s{2,}/', ' ', $s);
        return $s;
    }
}
