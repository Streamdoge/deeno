<?php
declare(strict_types=1);

defined('FFC_ADMIN') or exit;

/**
 * Общая часть установки расширений из ZIP — тем и плагинов.
 *
 * Раньше это были два файла по ~120 строк, различавшихся именем манифеста,
 * каталогом и текстами ошибок. Логика распаковки в них дублировалась целиком,
 * включая защиту от zip slip: правку пришлось бы вносить дважды, и вторая
 * копия однажды осталась бы старой.
 *
 * Требование к архиву: внутри есть манифест (`theme.json` / `plugin.json`) —
 * в корне архива или в единственной вложенной папке.
 *
 * ⚠️ Установка расширения = выполнение чужого PHP на сервере с полными
 * правами. Это осознанное свойство (как у любой CMS с плагинами), поэтому
 * маршруты установки закрыты ролью admin. Защита здесь — только от кривых
 * и вредоносных АРХИВОВ, а не от вредоносного кода внутри них.
 */
abstract class ArchiveInstaller
{
    protected const MAX_SIZE = 20 * 1024 * 1024; // 20MB

    protected string $baseDir;

    /** Имя файла-манифеста внутри архива: theme.json | plugin.json */
    abstract protected function manifestName(): string;

    /** Каталог установки относительно корня: themes | plugins */
    abstract protected function dirName(): string;

    /** Сообщение, когда манифеста в архиве нет */
    abstract protected function errNoManifest(): string;

    /** Сообщение, когда каталог с таким именем уже существует */
    abstract protected function errAlreadyExists(string $name): string;

    /** Сообщение, когда имя не удалось определить */
    abstract protected function errNoName(): string;

    /** Сообщение, когда файлы не удалось перенести на место */
    abstract protected function errCannotInstall(): string;

    public function __construct()
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 2);
        $this->baseDir = $root . '/' . $this->dirName() . '/';
    }

    /**
     * Установить из загруженного ZIP.
     * Возвращает ['name' => имя] или ['error' => текст].
     */
    public function install(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['error' => Lang::t('Файл не загружен.')];
        }
        if (($file['size'] ?? 0) > static::MAX_SIZE) {
            return ['error' => Lang::t('Архив больше 20 МБ.')];
        }
        if (!class_exists('ZipArchive')) {
            return ['error' => Lang::t('Расширение PHP zip не установлено.')];
        }

        $zip = new ZipArchive();
        if ($zip->open((string)$file['tmp_name']) !== true) {
            return ['error' => Lang::t('Не удалось открыть ZIP-архив.')];
        }

        // Zip slip: запрещаем '..' и абсолютные пути; попутно ищем манифест
        $manifestPath = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if (str_contains($entry, '..') || str_starts_with($entry, '/') || str_contains($entry, '\\')) {
                $zip->close();
                return ['error' => Lang::t('Архив содержит недопустимые пути.')];
            }
            if (basename($entry) === $this->manifestName()) {
                $depth = substr_count($entry, '/');
                if ($depth <= 1 && ($manifestPath === null || $depth < substr_count((string)$manifestPath, '/'))) {
                    $manifestPath = $entry;
                }
            }
        }

        if ($manifestPath === null) {
            $zip->close();
            return ['error' => $this->errNoManifest()];
        }

        // Имя: из манифеста или из имени архива
        $meta = json_decode((string)$zip->getFromName($manifestPath), true) ?: [];
        $name = Slugger::make((string)($meta['name'] ?? pathinfo((string)$file['name'], PATHINFO_FILENAME)));
        if ($name === '') {
            $zip->close();
            return ['error' => $this->errNoName()];
        }
        if (is_dir($this->baseDir . $name)) {
            $zip->close();
            return ['error' => $this->errAlreadyExists($name)];
        }

        // Распаковка во временный каталог, затем перенос
        $tmp = $this->baseDir . '.tmp-' . bin2hex(random_bytes(4));
        if (!@mkdir($tmp, 0755, true) || !$zip->extractTo($tmp)) {
            $zip->close();
            $this->rrmdir($tmp);
            return ['error' => Lang::t('Не удалось распаковать архив.')];
        }
        $zip->close();

        // Манифест мог лежать в подпапке архива — берём её содержимое
        $srcDir = dirname($tmp . '/' . $manifestPath);
        if (!@rename($srcDir, $this->baseDir . $name)) {
            $this->rrmdir($tmp);
            return ['error' => $this->errCannotInstall()];
        }
        $this->rrmdir($tmp);

        return ['name' => $name];
    }

    /** Удалить установленное (валидация имени — только каталог внутри своей папки) */
    public function delete(string $name): bool
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $name)) return false;
        $dir = $this->baseDir . $name;
        if (!is_dir($dir)) return false;
        $this->rrmdir($dir);
        return !is_dir($dir);
    }

    protected function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
