<?php
declare(strict_types=1);

defined('FFC_ADMIN') or exit;

require_once __DIR__ . '/ArchiveInstaller.php';

/**
 * Установка плагинов из ZIP.
 * Вся работа с архивом — в ArchiveInstaller (там же защита от zip slip);
 * здесь только специфика плагинов: манифест, каталог и тексты.
 */
class PluginInstaller extends ArchiveInstaller
{
    protected function manifestName(): string { return 'plugin.json'; }
    protected function dirName(): string      { return 'plugins'; }

    protected function errNoManifest(): string
    {
        return Lang::t('В архиве нет plugin.json — это не плагин deeno.');
    }

    protected function errAlreadyExists(string $name): string
    {
        return sprintf(Lang::t('Плагин «%s» уже установлен. Сначала удалите его.'), $name);
    }

    protected function errNoName(): string
    {
        return Lang::t('Не удалось определить имя плагина.');
    }

    protected function errCannotInstall(): string
    {
        return Lang::t('Не удалось установить файлы плагина.');
    }
}
