<?php
declare(strict_types=1);

/**
 * Самозащищённые файлы данных: JSON внутри .php-файла
 * с guard-строкой. Даже если сервер отдаёт файл напрямую (нет deny-правил,
 * как на nginx без настройки), наружу уходит только 403 — не данные.
 */
class DataFile
{
    private const GUARD = "<?php http_response_code(403); exit('deeno'); ?>\n";

    /** Прочитать JSON из guard-файла (или из legacy-.json без guard) */
    public static function read(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        if (str_starts_with($raw, '<?php')) {
            $pos = strpos($raw, '?>');
            $raw = $pos === false ? '' : substr($raw, $pos + 2);
        }
        $data = json_decode(trim($raw), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Записать данные guard-файлом — атомарно.
     *
     * Пишем во временный файл рядом и подменяем им целевой через rename:
     * в пределах одной ФС это атомарная операция, поэтому параллельный
     * читатель видит либо старое содержимое целиком, либо новое целиком.
     * Прямая запись в файл этого не давала: обрыв на середине (кончилось
     * место, лимит процесса, падение PHP) оставлял обрезанный JSON — а для
     * config.php это лежащий сайт, причём install.php к тому моменту уже
     * удалён и восстановить настройки из админки нельзя.
     */
    public static function write(string $path, array $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;   // не портим существующий файл ради нечитаемых данных
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, self::GUARD . $json, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        // Временный файл создаётся по umask, а не по правам целевого. Без этого
        // выставленные вручную права (например 600 на config.php) сбрасывались бы
        // на общедоступные при каждом сохранении настроек.
        if (is_file($path)) {
            $perms = @fileperms($path);
            if ($perms !== false) {
                @chmod($tmp, $perms & 0777);
            }
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Прочитать, изменить и записать под блокировкой.
     *
     * `write()` атомарна сама по себе, но цикл «прочитал → изменил → записал»
     * ею не защищён: два параллельных запроса читают одно состояние, и второй
     * затирает изменения первого. Так терялись счётчики неудачных входов
     * (Security) — их можно было частично сбрасывать параллельными попытками.
     *
     * $mutator получает текущие данные (пустой массив, если файла нет) и
     * возвращает новые. Вернёт false — запись не выполняется.
     */
    public static function update(string $basePath, callable $mutator): bool
    {
        $lock = @fopen($basePath . '.lock', 'c');
        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }

        try {
            [$data] = self::readWithLegacy($basePath);
            $new    = $mutator(is_array($data) ? $data : []);
            if (!is_array($new)) {
                return false;
            }
            return self::writeMigrating($basePath, $new);
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /**
     * Прочитать с учётом legacy: сначала <base>.php, иначе <base>.json.
     * Возвращает [данные|null, путь который существует|null].
     */
    public static function readWithLegacy(string $basePath): array
    {
        if (is_file($basePath . '.php')) {
            return [self::read($basePath . '.php'), $basePath . '.php'];
        }
        if (is_file($basePath . '.json')) {
            return [self::read($basePath . '.json'), $basePath . '.json'];
        }
        return [null, null];
    }

    /** Записать в .php-формат и удалить legacy-.json, если был */
    public static function writeMigrating(string $basePath, array $data): bool
    {
        $ok = self::write($basePath . '.php', $data);
        if ($ok && is_file($basePath . '.json')) {
            @unlink($basePath . '.json');
        }
        return $ok;
    }
}
