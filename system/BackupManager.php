<?php
declare(strict_types=1);

/**
 * ZIP-бэкапы сайта: content/ + media/ + users/ + themes/ + config.php + данные system/.
 * Не включаются: cache/ (пересобирается сам), backups/ (рекурсия), код CMS в system/.
 */
class BackupManager
{
    private const INCLUDE_DIRS  = ['content', 'media', 'users', 'themes'];
    private const INCLUDE_FILES = [
        'config.php', 'config.json',
        'system/categories.php',  // метаданные категорий (Название/Описание)
        'system/redirects.json',  // 301-редиректы при смене slug/категории
    ];

    private string $root;
    private string $backupsDir;

    public function __construct()
    {
        $this->root       = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/';
        $this->backupsDir = $this->root . 'backups/';
    }

    /**
     * Создать бэкап. Возвращает ['file' => имя] или ['error' => текст].
     */
    public function create(): array
    {
        if (!class_exists('ZipArchive')) {
            return ['error' => 'Расширение PHP zip не установлено на сервере.'];
        }
        if (!is_dir($this->backupsDir) && !@mkdir($this->backupsDir, 0755, true)) {
            return ['error' => 'Нет прав на запись в /backups/.'];
        }

        $name = 'backup-' . date('Y-m-d-His') . '.zip';
        $path = $this->backupsDir . $name;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['error' => 'Не удалось создать ZIP-файл.'];
        }

        foreach (self::INCLUDE_FILES as $file) {
            if (is_file($this->root . $file)) {
                $zip->addFile($this->root . $file, $file);
            }
        }

        foreach (self::INCLUDE_DIRS as $dir) {
            $this->addDir($zip, $this->root . $dir, $dir);
        }

        $zip->close();
        return is_file($path) ? ['file' => $name] : ['error' => 'ZIP не был записан.'];
    }

    /** Список бэкапов: [name, size, mtime], новые сверху */
    public function all(): array
    {
        $items = [];
        foreach (glob($this->backupsDir . 'backup-*.zip') ?: [] as $path) {
            $items[] = [
                'name'  => basename($path),
                'size'  => (int)@filesize($path),
                'mtime' => (int)@filemtime($path),
            ];
        }
        usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $items;
    }

    /** Абсолютный путь для скачивания (с валидацией имени) */
    public function path(string $name): ?string
    {
        if (!preg_match('/^backup-[\d-]+\.zip$/', $name)) return null;
        $path = $this->backupsDir . $name;
        return is_file($path) ? $path : null;
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);
        return $path !== null && @unlink($path);
    }

    // ----------------------------------------------------------------

    private function addDir(ZipArchive $zip, string $dir, string $local): void
    {
        if (!is_dir($dir)) return;

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $rel = $local . '/' . substr($item->getPathname(), strlen($dir) + 1);
            if ($item->isDir()) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($item->getPathname(), $rel);
            }
        }
    }
}
