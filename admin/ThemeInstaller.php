<?php
declare(strict_types=1);

defined('FFC_ADMIN') or exit;

require_once __DIR__ . '/ArchiveInstaller.php';

/**
 * Установка тем из ZIP и список установленных тем.
 * Вся работа с архивом — в ArchiveInstaller (там же защита от zip slip);
 * здесь только специфика тем: манифест, каталог, тексты и список для
 * страницы «Темы».
 */
class ThemeInstaller extends ArchiveInstaller
{
    protected function manifestName(): string { return 'theme.json'; }
    protected function dirName(): string      { return 'themes'; }

    protected function errNoManifest(): string
    {
        return Lang::t('В архиве нет theme.json — это не тема deeno.');
    }

    protected function errAlreadyExists(string $name): string
    {
        return sprintf(Lang::t('Тема «%s» уже установлена. Сначала удалите её.'), $name);
    }

    protected function errNoName(): string
    {
        return Lang::t('Не удалось определить имя темы.');
    }

    protected function errCannotInstall(): string
    {
        return Lang::t('Не удалось установить файлы темы.');
    }

    /** Список тем с метаданными из theme.json */
    public function all(string $activeName): array
    {
        $themes = [];
        foreach (glob($this->baseDir . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            $meta = [];
            if (is_file($dir . '/theme.json')) {
                $meta = json_decode((string)@file_get_contents($dir . '/theme.json'), true) ?: [];
            }
            $themes[] = [
                'dir'         => $name,
                'name'        => (string)($meta['name'] ?? $name),
                'version'     => (string)($meta['version'] ?? ''),
                'author'      => (string)($meta['author'] ?? ''),
                'description' => (string)($meta['description'] ?? ''),
                'screenshot'  => is_file($dir . '/screenshot.png') ? '/themes/' . $name . '/screenshot.png' : '',
                'active'      => $name === $activeName,
                'isDefault'   => $name === ThemeManager::FALLBACK_THEME,
            ];
        }
        usort($themes, fn($a, $b) => [$b['active'], $a['dir']] <=> [$a['active'], $b['dir']]);
        return $themes;
    }
}
