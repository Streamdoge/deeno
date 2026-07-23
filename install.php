<?php
declare(strict_types=1);

/**
 * deeno — Setup Wizard (раздел 11 ТЗ).
 *
 * Zero-config установка: закинул файлы → открыл сайт →
 * мастер сам проверил окружение, создал .htaccess, написал config.json
 * и первого администратора. Никаких правок файлов руками.
 *
 * Шаги: 1 окружение → 2 сайт → 3 администратор → 4 готово.
 * После установки (когда в /users/ есть хоть один пользователь)
 * файл отвечает 403 — повторная установка невозможна.
 */

if (version_compare(PHP_VERSION, '8.0', '<')) {
    exit('deeno требует PHP 8.0+. Сейчас: ' . PHP_VERSION);
}

define('ROOT_DIR', __DIR__);
spl_autoload_register(function (string $class): void {
    $file = ROOT_DIR . '/system/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ----------------------------------------------------------------
// Защита от повторной установки
// ----------------------------------------------------------------
function usersExist(): bool
{
    return count(glob(ROOT_DIR . '/users/*.{php,json}', GLOB_BRACE) ?: []) > 0;
}

if (usersExist()) {
    http_response_code(403);
    exit('deeno уже установлена. Удалите install.php с сервера.');
}

session_start();
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['install_csrf'];

function checkCsrf(): void
{
    if (!hash_equals((string)($_SESSION['install_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('CSRF token mismatch.');
    }
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ----------------------------------------------------------------
// Автоопределение адреса сайта
// ----------------------------------------------------------------
function detectSiteUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir  = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

// ----------------------------------------------------------------
// Проверки окружения (шаг 1)
// ----------------------------------------------------------------

/** [метка, ok|warn|fail, пояснение] */
function environmentChecks(): array
{
    $out = [];
    $out[] = ['PHP ' . PHP_VERSION, 'ok', 'нужно 8.0+'];

    $modules = [
        'json'     => ['fail', 'обязателен: конфиги и данные'],
        'session'  => ['fail', 'обязателен: вход в админку'],
        'mbstring' => ['fail', 'обязателен: кириллица'],
        'fileinfo' => ['warn', 'проверка типов загружаемых файлов'],
        'gd'       => ['warn', 'миниатюры и оптимизация фото'],
        'zip'      => ['warn', 'бэкапы и установка тем'],
    ];
    foreach ($modules as $m => [$level, $why]) {
        $out[] = extension_loaded($m)
            ? ['Модуль ' . $m, 'ok', $why]
            : ['Модуль ' . $m . ' отсутствует', $level, $why];
    }

    foreach (['content/posts', 'content/pages', 'users', 'media', 'cache', 'backups', 'system', '.'] as $dir) {
        $path = ROOT_DIR . '/' . $dir;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        $out[] = is_writable($path)
            ? ['Запись в /' . ($dir === '.' ? '' : $dir), 'ok', '']
            : ['Нет записи в /' . ($dir === '.' ? '' : $dir), $dir === '.' ? 'warn' : 'fail', 'выставьте права (обычно 755/775)'];
    }

    // .htaccess: создаём сами — FTP-клиенты часто теряют dot-файлы
    $ht = ROOT_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, defaultHtaccess());
    }
    $out[] = is_file($ht)
        ? ['.htaccess на месте', 'ok', 'создан автоматически, если отсутствовал']
        : ['.htaccess не создать', 'warn', 'на Apache загрузите файл вручную; на nginx см. nginx.conf.example'];

    // HTTP-проверка: закрыт ли config.json снаружи (главная проверка хостинга)
    [$level, $label, $why] = probeHttpProtection();
    $out[] = [$label, $level, $why];

    return $out;
}

function defaultHtaccess(): string
{
    return <<<HT
Options -Indexes

<IfModule mod_headers.c>
  Header set X-Frame-Options "SAMEORIGIN"
  Header set X-Content-Type-Options "nosniff"
  Header set Referrer-Policy "strict-origin-when-cross-origin"
  Header set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
  # img-src с blob: обязателен: браузер применяет этот заголовок и CSP админки
  # как пересечение, без blob: не открывается кадрирование фото при загрузке.
  Header set Content-Security-Policy "default-src 'self' https: data: 'unsafe-inline'; img-src 'self' https: data: blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'"
  Header set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"
  Header unset X-Powered-By
</IfModule>

# Запрет прямого доступа к .md и .json
<FilesMatch "\\.(md|json)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order Allow,Deny
    Deny from all
  </IfModule>
</FilesMatch>

RewriteEngine On
RewriteBase /

RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

RewriteRule ^ index.php [L]
HT;
}

/**
 * Проверка защиты: создаём временный .json-файл с маркером и пробуем
 * прочитать его по HTTP. Так мы проверяем реальную политику сервера
 * для .json/.md (сам config теперь guard-файл и защищает себя сам).
 */
function probeHttpProtection(): array
{
    $probeName = 'probe-' . bin2hex(random_bytes(6)) . '.json';
    $probeFile = ROOT_DIR . '/' . $probeName;
    @file_put_contents($probeFile, '{"deeno_probe":"' . $probeName . '"}');
    $result = probeUrl(detectSiteUrl() . '/' . $probeName, $probeName);
    @unlink($probeFile);
    return $result;
}

function probeUrl(string $url, string $marker): array
{
    $body = null;
    $code = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'deeno-install-check',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
        }
    } else {
        return ['warn', 'HTTP-проверка защиты пропущена', 'нет curl и allow_url_fopen — проверьте вручную: ' . $url . ' должен отвечать 403/404'];
    }

    if (is_string($body) && str_contains($body, $marker)) {
        // warn, а не fail: установка возможна, но проблему нужно решить —
        // критичные данные (пользователи, конфиг, счётчики) в guard-файлах
        $server = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
        $hint = str_contains($server, 'apache') || str_contains($server, 'litespeed')
            ? 'если включён AllowOverride, но файл всё равно открыт — статику отдаёт nginx-фронт мимо Apache: попросите хостера добавить deny и try_files из nginx.conf.example'
            : 'похоже, это nginx: отдайте хостеру блок из nginx.conf.example — исходники .md сейчас читаются снаружи';
        return ['warn', '.json/.md открыты по HTTP (' . $code . ')', $hint];
    }
    return ['ok', '.json/.md закрыты снаружи (HTTP ' . ($code ?: '—') . ')', ''];
}

function hasFailures(array $checks): bool
{
    foreach ($checks as [, $level]) {
        if ($level === 'fail') return true;
    }
    return false;
}

// ----------------------------------------------------------------
// Языки из /system/lang/
// ----------------------------------------------------------------
function languageOptions(): array
{
    $langs = [];
    foreach (glob(ROOT_DIR . '/system/lang/*.json') ?: [] as $f) {
        $code = basename($f, '.json');
        $langs[$code] = ['ru' => 'Русский', 'en' => 'English'][$code] ?? $code;
    }
    return $langs ?: ['ru' => 'Русский'];
}

// ----------------------------------------------------------------
// Финальная установка
// ----------------------------------------------------------------
function performInstall(array $site, array $admin): ?string
{
    // config.json: дефолты + существующий конфиг (если был) + данные мастера
    $defaults = [
        'site_title' => 'Мой сайт', 'site_description' => '', 'site_url' => '',
        'theme' => 'default', 'language' => 'en', 'posts_per_page' => 10,
        'date_format' => 'd.m.Y', 'timezone' => 'UTC', 'admin_path' => 'admin',
        'installed' => false, 'debug' => false, 'cache_enabled' => true,
        'maintenance_mode' => false, 'maintenance_message' => '', 'order_by' => 'date',
        'category_order' => 'alpha', 'article_order' => 'manual',
        'smtp_host' => '', 'smtp_port' => 587, 'smtp_user' => '', 'smtp_pass' => '',
        'rss_enabled' => true, 'rss_items' => 20, 'sitemap_enabled' => true,
        'logo' => '', 'favicon' => '', 'og_image' => '', 'media_max_width' => 2560,
        'media_quality' => 82, 'plugins' => [],
    ];
    [$existing] = DataFile::readWithLegacy(ROOT_DIR . '/config');
    $config = array_merge($defaults, is_array($existing) ? $existing : [], [
        'site_title'       => $site['title'],
        'site_description' => $site['description'],
        'site_url'         => rtrim($site['url'], '/'),
        'language'         => $site['language'],
        'timezone'         => $site['timezone'],
        'installed'        => true,
    ]);

    // Конфиг — guard-файл: закрыт снаружи даже без deny-правил сервера
    if (!DataFile::writeMigrating(ROOT_DIR . '/config', $config)) {
        return 'Не удалось записать config.php — проверьте права на корень сайта.';
    }

    // Первый администратор (guard-файл, см. DataFile)
    $ok = (new UserManager())->save([
        'username'     => $admin['username'],
        'display_name' => $admin['display_name'] ?: $admin['username'],
        'email'        => $admin['email'],
        'password'     => UserManager::hashPassword($admin['password']),
        'role'         => 'admin',
        'active'       => true,
        'created'      => date('c'),
    ]);
    if (!$ok) {
        return 'Не удалось создать пользователя — проверьте права на /users/.';
    }

    // Секрет приложения создаётся заранее, пока права точно есть
    Security::appSecret();

    return null;
}

// ----------------------------------------------------------------
// Обработка шагов
// ----------------------------------------------------------------
$step  = max(1, min(4, (int)($_POST['step'] ?? $_GET['step'] ?? 1)));
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    checkCsrf();

    if (($_POST['do'] ?? '') === 'site') {
        $_SESSION['install_site'] = [
            'title'       => trim((string)($_POST['site_title'] ?? '')) ?: 'Мой сайт',
            'description' => trim((string)($_POST['site_description'] ?? '')),
            'url'         => trim((string)($_POST['site_url'] ?? '')) ?: detectSiteUrl(),
            'language'    => array_key_exists((string)($_POST['language'] ?? ''), languageOptions()) ? (string)$_POST['language'] : 'en',
            'timezone'    => in_array((string)($_POST['timezone'] ?? ''), DateTimeZone::listIdentifiers(), true) ? (string)$_POST['timezone'] : 'UTC',
        ];
        $step = 3;
    }

    if (($_POST['do'] ?? '') === 'admin') {
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['password2'] ?? '');
        $site     = $_SESSION['install_site'] ?? null;

        if (!is_array($site)) {
            $error = 'Сначала заполните настройки сайта.';
            $step = 2;
        } elseif (!preg_match('/^[a-z0-9_-]{1,64}$/', $username)) {
            $error = 'Логин: только латиница, цифры, дефис и подчёркивание.';
            $step = 3;
        } elseif (($pe = UserManager::passwordError($password)) !== null) {
            $error = $pe;
            $step = 3;
        } elseif ($password !== $confirm) {
            $error = 'Пароли не совпадают.';
            $step = 3;
        } else {
            $err = performInstall($site, [
                'username'     => $username,
                'display_name' => trim((string)($_POST['display_name'] ?? '')),
                'email'        => trim((string)($_POST['email'] ?? '')),
                'password'     => $password,
            ]);
            if ($err !== null) {
                $error = $err;
                $step = 3;
            } else {
                $_SESSION['install_admin_url'] = rtrim((string)$site['url'], '/') . '/admin/';
                unset($_SESSION['install_site']);
                // Установка завершена — мастер сразу удаляет сам себя,
                // не спрашивая: пока файл существует, он лишь отвечает 403
                $selfDeleted = @unlink(__FILE__);
                $step = 4;
            }
        }
    }
}

$checks   = $step === 1 ? environmentChecks() : [];
$site     = $_SESSION['install_site'] ?? null;
$siteUrl  = is_array($site) ? (string)$site['url'] : detectSiteUrl();
$adminUrl = (string)($_SESSION['install_admin_url'] ?? ($siteUrl . '/admin/'));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Установка — deeno</title>
  <link rel="stylesheet" href="admin/assets/admin.css">
  <style>
    .install-card { width: min(560px, calc(100vw - 2rem)); }
    .install-steps { display: flex; gap: 6px; justify-content: center; margin: 4px 0 20px; }
    .install-steps span {
      width: 28px; height: 28px; border-radius: 50%;
      display: inline-flex; align-items: center; justify-content: center;
      background: var(--input-fill); color: var(--mid-gray); font-size: 13px; font-weight: 500;
    }
    .install-steps span.on { background: var(--ink); color: var(--on-primary); }
    .envlist { list-style: none; margin: 0 0 16px; padding: 0; }
    .envlist li { display: flex; gap: 8px; padding: 6px 0; border-bottom: 1px solid var(--hairline); font-size: 14px; }
    .envlist li:last-child { border-bottom: none; }
    .envlist .st { flex: none; width: 44px; font-weight: 600; font-size: 12px; }
    .st-ok { color: #3f7d20; } .st-warn { color: #b45309; } .st-fail { color: var(--ember); }
    .envlist .why { margin-left: auto; color: var(--mid-gray); font-size: 12px; text-align: right; }
  </style>
</head>
<body class="login-page">

<div class="login-card install-card">
  <h1 class="login-card__title">deeno</h1>
  <p class="login-card__subtitle">Установка</p>

  <div class="install-steps">
    <?php for ($i = 1; $i <= 4; $i++): ?>
      <span class="<?= $i <= $step ? 'on' : '' ?>"><?= $i ?></span>
    <?php endfor; ?>
  </div>

  <?php if ($error !== ''): ?>
    <div class="alert alert--danger"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if ($step === 1): ?>
    <ul class="envlist">
      <?php foreach ($checks as [$label, $level, $why]): ?>
        <li>
          <span class="st st-<?= e($level) ?>"><?= ['ok' => 'OK', 'warn' => 'ВНИМ', 'fail' => 'СБОЙ'][$level] ?></span>
          <span><?= e($label) ?></span>
          <?php if ($why): ?><span class="why"><?= e($why) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (hasFailures($checks)): ?>
      <div class="alert alert--danger">Устраните пункты «СБОЙ» и обновите страницу.</div>
      <a class="btn btn--secondary btn--block" href="install.php">Проверить снова</a>
    <?php else: ?>
      <a class="btn btn--primary btn--block" href="install.php?step=2">Продолжить</a>
    <?php endif; ?>

  <?php elseif ($step === 2): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="do" value="site">
      <label class="field">
        <span class="field__label">Название сайта</span>
        <input type="text" name="site_title" required value="<?= e((string)($site['title'] ?? 'Мой сайт')) ?>">
      </label>
      <label class="field">
        <span class="field__label">Описание (подзаголовок на главной)</span>
        <input type="text" name="site_description" value="<?= e((string)($site['description'] ?? '')) ?>">
      </label>
      <label class="field">
        <span class="field__label">Адрес сайта</span>
        <input type="text" name="site_url" value="<?= e($siteUrl) ?>">
      </label>
      <label class="field">
        <span class="field__label">Язык админки</span>
        <select name="language">
          <?php foreach (languageOptions() as $code => $name): ?>
            <option value="<?= e($code) ?>" <?= ($site['language'] ?? 'en') === $code ? 'selected' : '' ?>><?= e($name) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field__label">Часовой пояс</span>
        <select name="timezone">
          <?php $tzCur = (string)($site['timezone'] ?? 'Europe/Moscow'); ?>
          <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?>
            <option value="<?= e($tz) ?>" <?= $tz === $tzCur ? 'selected' : '' ?>><?= e($tz) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn--primary btn--block">Продолжить</button>
    </form>

  <?php elseif ($step === 3): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="do" value="admin">
      <label class="field">
        <span class="field__label">Логин администратора (латиница)</span>
        <input type="text" name="username" required pattern="[a-zA-Z0-9_-]{1,64}" value="<?= e((string)($_POST['username'] ?? '')) ?>">
      </label>
      <label class="field">
        <span class="field__label">Отображаемое имя</span>
        <input type="text" name="display_name" value="<?= e((string)($_POST['display_name'] ?? '')) ?>">
      </label>
      <label class="field">
        <span class="field__label">Email (для восстановления пароля)</span>
        <input type="email" name="email" value="<?= e((string)($_POST['email'] ?? '')) ?>">
      </label>
      <label class="field">
        <span class="field__label">Пароль (не короче <?= (int)UserManager::MIN_PASSWORD ?> символов)</span>
        <input type="password" name="password" required minlength="<?= (int)UserManager::MIN_PASSWORD ?>" autocomplete="new-password">
      </label>
      <label class="field">
        <span class="field__label">Пароль ещё раз</span>
        <input type="password" name="password2" required minlength="<?= (int)UserManager::MIN_PASSWORD ?>" autocomplete="new-password">
      </label>
      <button type="submit" class="btn btn--primary btn--block">Установить</button>
    </form>

  <?php else: ?>
    <div class="alert alert--success">Готово! deeno установлена<?= !empty($selfDeleted) ? ', install.php удалён автоматически' : '' ?>.</div>
    <?php if (empty($selfDeleted)): ?>
      <div class="alert alert--danger">
        Не удалось удалить install.php автоматически (права файла?).
        Удалите его с сервера вручную — пока он на месте, он отвечает 403.
      </div>
    <?php endif; ?>
    <div class="actions" style="flex-direction:column">
      <a class="btn btn--primary btn--block" href="<?= e($adminUrl) ?>">Войти в админку</a>
      <a class="btn btn--secondary btn--block" href="<?= e($siteUrl) ?>/">Открыть сайт</a>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
