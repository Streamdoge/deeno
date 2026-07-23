<?php
declare(strict_types=1);

/**
 * Парсер YAML-фронтматтера из .md файлов.
 * Поддерживает подмножество YAML, достаточное для полей поста:
 * строки, числа, булевы, null, даты, inline-массивы [a, b, c],
 * блочные подключи (custom_fields).
 */
class FrontmatterParser
{
    /**
     * Разбирает содержимое файла .md.
     * Возвращает ['meta' => [...], 'body' => '...']
     */
    public static function parse(string $content): array
    {
        // Нормализуем переводы строк (CRLF/CR → LF): файлы, отредактированные
        // в Windows или залитые по FTP в текстовом режиме, иначе ломали разбор.
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $meta = [];
        $body = $content;

        // Шапка — только если первая строка ровно «---», а закрывающий «---»
        // стоит отдельной строкой. Так «---» внутри значения (title: a --- b)
        // или горизонтальная линия в теле не обрывают frontmatter преждевременно.
        if (preg_match('/^---[ \t]*\n(.*?\n)?---[ \t]*(?:\n|$)/s', $content, $m)) {
            $meta = self::parseYaml($m[1] ?? '');
            $body = ltrim(substr($content, strlen($m[0])));
        }

        return ['meta' => $meta, 'body' => $body];
    }

    /**
     * Минимальный парсер YAML для фронтматтера.
     */
    private static function parseYaml(string $yaml): array
    {
        $result = [];
        $lines = explode("\n", $yaml);
        $currentKey = null;
        $inBlock = false; // режим блочных подключей (custom_fields)

        foreach ($lines as $line) {
            // Пустые строки и комментарии
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            // Блочный подключ (2 пробела отступ)
            if ($inBlock && preg_match('/^  (\w+):\s*(.*)$/', $line, $m)) {
                $result[$currentKey][$m[1]] = self::castValue(trim($m[2]));
                continue;
            }

            $inBlock = false;

            // Обычная пара key: value
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $rawValue = trim($m[2]);

                // Inline массив [a, b, c]
                if (str_starts_with($rawValue, '[') && str_ends_with($rawValue, ']')) {
                    $inner = substr($rawValue, 1, -1);
                    $items = array_map(
                        fn($v) => self::castValue(trim($v, " \t'\"")),
                        explode(',', $inner)
                    );
                    $result[$key] = array_filter($items, fn($v) => $v !== '');
                    $result[$key] = array_values($result[$key]);
                } elseif ($rawValue === '') {
                    // Начало блока подключей
                    $result[$key] = [];
                    $currentKey = $key;
                    $inBlock = true;
                } else {
                    $result[$key] = self::castValue($rawValue);
                }
            }
        }

        return $result;
    }

    /**
     * Приводит строковое значение YAML к нужному PHP-типу.
     */
    private static function castValue(string $value): mixed
    {
        // Убираем кавычки
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return substr($value, 1, -1);
        }

        $lower = strtolower($value);
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;
        if ($lower === 'null' || $lower === '~') return null;

        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }

        return $value;
    }
}
