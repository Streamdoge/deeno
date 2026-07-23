<?php
declare(strict_types=1);

/**
 * Файловый кэш: индексы данных и полностраничный HTML-кэш.
 * Формат — PHP-файлы с `return [...]`: их подхватывает OPcache, чтение почти бесплатно.
 * Актуальность проверяется подписью содержимого (signature), TTL не нужен:
 * изменился контент — изменилась подпись — кэш пересобрался.
 */
class CacheManager
{
    private string $cacheDir;
    private bool $enabled;

    public function __construct(array $config)
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $this->cacheDir = $root . '/cache/';
        $this->enabled  = (bool)($config['cache_enabled'] ?? true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Данные по ключу, если подпись совпадает. Иначе null. */
    public function get(string $key, string $signature): mixed
    {
        if (!$this->enabled) return null;
        $data = $this->read($this->cacheDir . $key . '.php');
        return ($data !== null && $data['sig'] === $signature) ? $data['data'] : null;
    }

    public function set(string $key, string $signature, mixed $data): void
    {
        if (!$this->enabled) return;
        $this->write($this->cacheDir . $key . '.php', ['sig' => $signature, 'data' => $data]);
    }

    /** Кэшированная страница: ['status' => int, 'html' => string] или null. */
    public function getPage(string $uri, string $signature): ?array
    {
        if (!$this->enabled) return null;
        $data = $this->read($this->pageFile($uri));
        return ($data !== null && $data['sig'] === $signature) ? $data['data'] : null;
    }

    public function setPage(string $uri, string $signature, int $status, string $html): void
    {
        if (!$this->enabled) return;
        $this->write($this->pageFile($uri), [
            'sig'  => $signature,
            'data' => ['status' => $status, 'html' => $html],
        ]);
    }

    // ----------------------------------------------------------------

    private function pageFile(string $uri): string
    {
        return $this->cacheDir . 'pages/' . md5($uri) . '.php';
    }

    private function read(string $file): ?array
    {
        if (!is_file($file)) return null;
        $data = @include $file;
        if (!is_array($data) || !array_key_exists('sig', $data) || !array_key_exists('data', $data)) {
            return null;
        }
        return $data;
    }

    private function write(string $file, array $payload): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Атомарная запись: во временный файл + rename,
        // чтобы параллельный запрос не прочитал недописанный кэш
        $tmp  = $file . '.' . uniqid('', true) . '.tmp';
        $code = '<?php return ' . var_export($payload, true) . ';';
        if (@file_put_contents($tmp, $code) !== false) {
            @rename($tmp, $file);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        }
    }
}
