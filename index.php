<?php
declare(strict_types=1);

// Встроенный сервер PHP (разработка): статику отдаём напрямую, как Apache,
// но .md/.json остаются закрытыми — как их закрывает .htaccess в production
if (PHP_SAPI === 'cli-server') {
    $p = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $f = __DIR__ . $p;
    if ($p === '/install.php' && is_file($f)) {
        return false; // мастер установки исполняется напрямую, как на Apache
    }
    if ($p !== '/' && is_file($f) && !preg_match('/\.(php|md|json|log)$/i', $p) && !str_contains($p, '..')) {
        return false;
    }
}

define('ROOT_DIR', __DIR__);
require __DIR__ . '/system/version.php';

// Автозагрузка классов системы (нужна уже для чтения конфига)
spl_autoload_register(function (string $class): void {
    $file = ROOT_DIR . '/system/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Конфиг — guard-файл config.php (см. DataFile); config.json читается как legacy
[$config] = DataFile::readWithLegacy(ROOT_DIR . '/config');

// Zero-config установка: нет пользователей ИЛИ нет конфига —
// значит CMS ещё не установлена, отправляем в мастер (он создаст и то и другое).
// Абсолютный путь: относительный редирект с /admin/ увёл бы в /admin/install.php
$notInstalled = !is_array($config)
    || count(glob(ROOT_DIR . '/users/*.{php,json}', GLOB_BRACE) ?: []) === 0;
if ($notInstalled && is_file(ROOT_DIR . '/install.php')) {
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    while (basename($dir) === 'admin') { // SCRIPT_NAME может указывать в /admin/
        $dir = dirname($dir);
    }
    header('Location: ' . rtrim($dir, '/') . '/install.php');
    exit;
}
if (!is_array($config)) {
    http_response_code(500);
    die('Config not found and install.php is missing. Re-upload install.php and open it.');
}

// В production ошибки не выводятся (правило 5 ТЗ); включаются флагом debug в config.json
error_reporting(E_ALL);
ini_set('display_errors', empty($config['debug']) ? '0' : '1');

// Timezone
if (!empty($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Плагины (см. PLUGINS.md)
PluginManager::loadEnabled($config);

// Ядро
$cache = new CacheManager($config);
$cms   = new ContentManager($config, $cache);
$theme = new ThemeManager($config);

// Роутер
$router = new Router($config, $cms, $theme, $cache);
$router->dispatch();
