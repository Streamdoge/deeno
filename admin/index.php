<?php
declare(strict_types=1);

/**
 * Роутер админки.
 * Вызывается двумя путями: Apache напрямую (/admin/ — реальная папка)
 * или через главный Router (когда URL вида /admin/posts/ уходит в index.php).
 * Поэтому файл самодостаточен.
 */

if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__DIR__));
    spl_autoload_register(function (string $class): void {
        $file = ROOT_DIR . '/system/' . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

define('FFC_ADMIN', true);

// Версия. Нужна явно: на Apache /admin/ — реальная папка, запрос сюда приходит
// в обход корневого index.php, и без этого «Система» на дашборде показывала «—»
require_once ROOT_DIR . '/system/version.php';

// Конфиг — guard-файл config.php; config.json читается как legacy (см. DataFile)
[$config] = DataFile::readWithLegacy(ROOT_DIR . '/config');
$config = is_array($config) ? $config : [];

// Нет пользователей или конфига → CMS не установлена → в мастер (zero-config).
// Путь строим от корня сайта: работает и при установке в подпапку
if (is_file(ROOT_DIR . '/install.php')
    && (empty($config) || count(glob(ROOT_DIR . '/users/*.{php,json}', GLOB_BRACE) ?: []) === 0)) {
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    while (basename($dir) === 'admin') {
        $dir = dirname($dir);
    }
    header('Location: ' . rtrim($dir, '/') . '/install.php');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', empty($config['debug']) ? '0' : '1');
if (!empty($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Плагины: слушатели post.saved / media.uploaded должны работать и в админке.
// Повторный вызов безопасен — require_once не подключит plugin.php дважды.
PluginManager::loadEnabled($config);

$security = new Security($config);
$security->startSession();

// Content-Security-Policy: инлайн-скрипты только с nonce
$cspNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; object-src 'none'; "
    . "base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$users = new UserManager();
$cache = new CacheManager($config);
$cms   = new ContentManager($config, $cache);

/**
 * Локализация интерфейса. Порядок: личный выбор в сессии → для вошедшего язык
 * сайта → для ГОСТЯ подсказка браузера (Accept-Language).
 *
 * Гость — это экраны входа и восстановления пароля: там ещё некому было выбрать
 * язык, а настройка сайта к посетителю отношения не имеет. Раньше форма входа
 * всегда шла на языке сайта, и англоязычный админ видел русский экран.
 */
function detectAdminLang(array $config): string
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    foreach (explode(',', $accept) as $part) {
        $code = substr(trim(explode(';', $part)[0]), 0, 2);
        if (in_array($code, ['ru', 'en'], true)) {
            return $code;
        }
    }
    return (string)($config['language'] ?? 'en');
}

$currentUser = $security->currentUser();
$adminLang   = (string)($_SESSION['admin_lang'] ?? '');
$adminTheme  = 'light';
if ($currentUser !== null) {
    // Личные настройки панели живут в профиле (Настройки → «Панель управления»)
    $profile = (new UserManager())->find((string)($currentUser['username'] ?? ''));
    if ($adminLang === '' && in_array((string)($profile['language'] ?? ''), ['ru', 'en'], true)) {
        $adminLang = (string)$profile['language'];
    }
    if (in_array((string)($profile['admin_theme'] ?? ''), ['light', 'dark'], true)) {
        $adminTheme = (string)$profile['admin_theme'];
    }
}
if ($adminLang === '') {
    $adminLang = $currentUser !== null
        ? (string)($config['language'] ?? 'ru')
        : detectAdminLang($config);
}
$GLOBALS['ffcLang'] = new Lang($adminLang);
function t(string $s): string
{
    return $GLOBALS['ffcLang']->get($s);
}

/** Тонкие линейные иконки (штрих 1.5px, цвет — currentColor через CSS .ico) */
function icon(string $name): string
{
    static $icons = [
        'home'     => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'pen'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/>',
        'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'layers'   => '<path d="M12 2 2 7l10 5 10-5Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
        'sliders'  => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><path d="M1 14h6M9 8h6M17 16h6"/>',
        'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'check'    => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/>',
        'alert'    => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4M12 17h.01"/>',
        'trash'    => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6M14 11v6"/>',
        'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        'quote'    => '<path d="M10 11H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v6c0 2-1 3-3 4"/><path d="M20 11h-4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v6c0 2-1 3-3 4"/>',
        'minus'    => '<path d="M4 12h16"/>',
        'tick'     => '<path d="M20 6 9 17l-5-5"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>',
        'moon'     => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'menu'     => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'plug'     => '<path d="M12 22v-3"/><path d="M9 8V2M15 8V2"/><path d="M18 8H6v5a4 4 0 0 0 4 4h4a4 4 0 0 0 4-4V8Z"/>',
        'globe'    => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"/>',
        'tag'      => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'archive'  => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'table'    => '<path d="M12 3v18"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/>',
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>',
        'align-left'    => '<path d="M15 12H3M17 6H3M21 18H3"/>',
        'align-center'  => '<path d="M17 12H7M19 6H5M21 18H3"/>',
        'align-right'   => '<path d="M21 12H9M21 6H7M21 18H3"/>',
        'align-justify' => '<path d="M3 12h18M3 6h18M3 18h18"/>',
        'video'    => '<path d="m22 8-6 4 6 4V8Z"/><rect width="14" height="12" x="2" y="6" rx="2"/>',
        'eraser'   => '<path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21"/><path d="M22 21H7"/><path d="m5 11 9 9"/>',
        'x'        => '<path d="M18 6 6 18M6 6l12 12"/>',
        'highlighter'  => '<path d="m9 11-6 6v3h9l3-3"/><path d="m22 12-4.6 4.6a2 2 0 0 1-2.8 0l-5.2-5.2a2 2 0 0 1 0-2.8L14 4"/>',
        'paint-bucket' => '<path d="m19 11-8-8-8.6 8.6a2 2 0 0 0 0 2.8l5.2 5.2a2 2 0 0 0 2.8 0L19 11Z"/><path d="m5 2 5 5"/><path d="M2 13h15"/><path d="M22 20a2 2 0 1 1-4 0c0-1.6 1.7-2.4 2-4 .3 1.6 2 2.4 2 4Z"/>',
    ];
    $body = $icons[$name] ?? '';
    return '<span class="ico"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg></span>';
}

$adminPath = (string)($config['admin_path'] ?? 'admin');
$adminBase = '/' . $adminPath . '/';

// Контекст для adminErrorPage: при ЧПУ этот файл подключается внутри метода
// Router, поэтому «глобальные» переменные не видны через global — кладём явно
$GLOBALS['ffcAdminBase'] = $adminBase;
$GLOBALS['ffcCspNonce']  = $cspNonce;
$GLOBALS['ffcSiteTitle'] = (string)($config['site_title'] ?? 'deeno');

// ----------------------------------------------------------------
// Разбор URL: /admin/<action>/<sub>/
// ----------------------------------------------------------------
$uriPath  = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$segments = array_values(array_filter(explode('/', trim($uriPath, '/')), fn($s) => $s !== ''));
if (($segments[0] ?? '') === $adminPath) {
    array_shift($segments);
}
$action = $segments[0] ?? 'dashboard';
$sub    = $segments[1] ?? '';
if ($action === 'index.php' || $action === '') {
    $action = 'dashboard';
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$user   = $security->currentUser();

// ----------------------------------------------------------------
// Хелперы
// ----------------------------------------------------------------

// Тип never — PHP 8.1+, а мы обещаем 8.0 (раздел 2 ТЗ)
function adminRedirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/** Рендер вида внутри layout админки */
function adminRender(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $contentView = __DIR__ . '/views/' . $view . '.php';
    require __DIR__ . '/views/layout.php';
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Подписи статусов материала для интерфейса. В value, CSS-классах и файлах
 * статус остаётся английским — переводится только видимый текст.
 *
 * @return array<string, string> статус => подпись, в порядке показа
 */
function statusLabels(bool $isPage = false): array
{
    return $isPage
        ? ['draft' => t('Черновик'), 'published' => t('Опубликована')]
        : [
            'published' => t('Опубликован'),
            'sticky'    => t('Закреплён'),
            'draft'     => t('Черновик'),
            'scheduled' => t('Отложен'),
            'unlisted'  => t('Вне списков'),
        ];
}

/** Подпись одного статуса; неизвестный показываем как есть. */
function statusLabel(string $status, bool $isPage = false): string
{
    return statusLabels($isPage)[$status] ?? statusLabels(false)[$status] ?? $status;
}

/**
 * Куда ведёт заголовок материала в списке.
 *
 * Опубликованное (published/sticky/unlisted) открывается на сайте в новой
 * вкладке; черновик и отложенная публикация публично отдают 404, поэтому ведут
 * сразу в редактор — в текущей вкладке.
 *
 * @param  string $kind  'posts' или 'pages' — раздел админки
 * @return array{href: string, blank: bool}
 */
function materialLink(Post $p, string $kind, string $adminBase, string $publicUrl): array
{
    if ($p->isPubliclyVisible()) {
        return ['href' => $publicUrl, 'blank' => true];
    }
    return [
        'href'  => $adminBase . $kind . '/edit/?file=' . urlencode(basename($p->filePath)),
        'blank' => false,
    ];
}

/** Поддерживаемые соцсети: ключ => подпись. Используется в настройках и темами. */
function socialNetworks(): array
{
    return [
        'telegram'  => 'Telegram',
        'vk'        => 'VK',
        'x'         => 'X (Twitter)',
        'youtube'   => 'YouTube',
        'instagram' => 'Instagram',
        'facebook'  => 'Facebook',
        'github'    => 'GitHub',
        'linkedin'  => 'LinkedIn',
    ];
}

/** Эффективный лимит загрузки в байтах: минимум из upload_max_filesize, post_max_size и 10 МБ. */
function uploadLimitBytes(): int
{
    $toBytes = static function (string $v): int {
        $v = trim($v);
        if ($v === '') return 0;
        $n = (int)$v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024; // fallthrough
            case 'm': $n *= 1024; // fallthrough
            case 'k': $n *= 1024;
        }
        return $n;
    };
    $limits = [10 * 1024 * 1024];
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $b = $toBytes((string)ini_get($k));
        if ($b > 0) $limits[] = $b;
    }
    return min($limits);
}

/** Стилизованная standalone-страница ошибки (CSRF, 403 и т.п.) */
function adminErrorPage(int $code, string $title, string $message): void
{
    http_response_code($code);
    $base  = (string)($GLOBALS['ffcAdminBase'] ?? './');
    $site  = (string)($GLOBALS['ffcSiteTitle'] ?? 'deeno');
    $nonce = (string)($GLOBALS['ffcCspNonce'] ?? '');
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <script nonce="<?= e($nonce) ?>">
    try { document.documentElement.dataset.theme = localStorage.getItem('deeno-theme') || 'light'; } catch (e) {}
  </script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> / <?= e($site) ?></title>
  <link rel="stylesheet" href="<?= e($base) ?>assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body class="login-page">
<div class="login-card">
  <h1 class="login-card__title"><?= e($title) ?></h1>
  <div class="alert alert--danger"><?= e($message) ?></div>
  <a class="btn btn--primary btn--block" href="<?= e($base) ?>"><?= e(t('К обзору')) ?></a>
</div>
</body>
</html>
    <?php
    exit;
}

/** Единый ответ на неверный CSRF-токен */
function csrfFail(): void
{
    adminErrorPage(403, t('Сессия устарела'), t('Сессия устарела, попробуйте ещё раз.'));
}

/**
 * Проверка прав: роль пользователя должна
 * входить в список разрешённых, иначе 403. Вызывается в начале роутов.
 * Роли: admin — всё; editor — контент и медиа; author — свои посты и медиа.
 */
function requireRole(array $roles, ?array $user): void
{
    if (!in_array((string)($user['role'] ?? ''), $roles, true)) {
        adminErrorPage(403, t('Доступ запрещён'), t('У вас нет прав для этого действия.'));
    }
}

/**
 * Author работает только со своими постами — проверка на КАЖДОМ изменяющем
 * маршруте, а не только при открытии редактора: форму можно не открывать,
 * а отправить POST с чужим именем файла напрямую.
 *
 * $allowNew — пустое имя файла означает создание нового материала (разрешено
 * при сохранении). При удалении пустое имя остаётся ошибкой, как и было.
 * Другие роли и страницы сюда не доходят: страницы закрыты requireRole выше.
 */
function requireOwnPost(string $file, ?array $user, string $type, ContentManager $cms, bool $allowNew = false): void
{
    if (($user['role'] ?? '') !== 'author' || $type !== 'post') return;
    if ($allowNew && $file === '') return;

    $post = $cms->postByFilename($file);
    if ($post === null || $post->author !== (string)($user['username'] ?? '')) {
        adminErrorPage(403, t('Доступ запрещён'), t('У вас нет прав для этого действия.'));
    }
}

// ----------------------------------------------------------------
// Вход / выход
// ----------------------------------------------------------------

if ($action === 'login' && $user !== null) {
    adminRedirect($adminBase);
}

// ----------------------------------------------------------------
// Восстановление пароля (доступно до входа)
// ----------------------------------------------------------------

if ($action === 'reset' && $user === null) {
    $resetMsg  = '';
    $resetErr  = '';
    $tokenOk   = false;

    $rUser = strtolower(trim((string)($_REQUEST['u'] ?? '')));
    $rExp  = (int)($_REQUEST['exp'] ?? 0);
    $rTok  = (string)($_REQUEST['t'] ?? '');

    // Ссылка из письма: проверяем токен, показываем форму нового пароля
    if ($rUser !== '' && $rTok !== '') {
        $target  = $users->find($rUser);
        $tokenOk = $target !== null
            && Security::verifyResetToken($rUser, $rExp, (string)($target['password'] ?? ''), $rTok);
        if (!$tokenOk) {
            $resetErr = t('Ссылка недействительна или устарела. Запросите новую.');
        }
    }

    if ($isPost && $security->verifyCsrf($_POST['csrf'] ?? null)) {
        // Запрос письма
        if (($_POST['do'] ?? '') === 'request') {
            $ip = $security->clientIp();
            if (!$security->allowPasswordReset($ip)) {
                $resetErr = t('Слишком много запросов. Подождите 15 минут.');
            } else {
                $security->registerPasswordReset($ip);
                $login  = strtolower(trim((string)($_POST['username'] ?? '')));
                $target = $users->find($login);
                if ($target !== null && !empty($target['email']) && !empty($target['active'])) {
                    $exp   = time() + Security::RESET_TTL;
                    $token = Security::resetToken($login, $exp, (string)($target['password'] ?? ''));
                    $link  = rtrim((string)($config['site_url'] ?? ''), '/') . $adminBase
                        . 'reset/?u=' . urlencode($login) . '&exp=' . $exp . '&t=' . $token;
                    Mailer::send($config, (string)$target['email'],
                        t('Восстановление пароля') . ' — ' . (string)($config['site_title'] ?? 'deeno'),
                        t('Чтобы задать новый пароль, откройте ссылку (действует 30 минут):') . "\n\n" . $link . "\n\n"
                        . t('Если вы не запрашивали смену пароля — просто игнорируйте это письмо.'));
                }
                // Нейтральный ответ: не раскрываем, существует ли аккаунт
                $resetMsg = t('Если такой пользователь существует и у него указан email — письмо отправлено. Не пришло? Проверьте спам или обратитесь к администратору.');
            }
        }

        // Установка нового пароля по токену
        if (($_POST['do'] ?? '') === 'set' && $tokenOk) {
            $p1 = (string)($_POST['password'] ?? '');
            $p2 = (string)($_POST['password2'] ?? '');
            if (($pe = UserManager::passwordError($p1)) !== null) {
                $resetErr = t($pe);
            } elseif ($p1 !== $p2) {
                $resetErr = t('Пароли не совпадают.');
            } else {
                $target = UserManager::withNewPassword((array)$users->find($rUser), $p1);
                $users->save($target);
                adminRedirect($adminBase . '?reset_done=1');
            }
        }
    }

    $csrfField = $security->csrfField();
    $siteTitle = (string)($config['site_title'] ?? 'deeno');
    $siteLogo  = (string)($config['logo'] ?? '');
    require __DIR__ . '/views/reset.php';
    exit;
}

// Форма входа рендерится на любом URL админки без редиректа:
// /admin/ — физическая папка, поэтому вход работает даже на хостинге,
// где не настроен fallback ЧПУ на index.php (nginx без try_files)
if ($user === null && $action !== 'logout') {
    $error   = '';
    $blocked = false;
    $ip      = $security->clientIp();

    if ($security->isBlocked($ip)) {
        $blocked = true;
    } elseif ($isPost && isset($_POST['username'], $_POST['password'])) {
        $username = (string)$_POST['username'];
        $password = (string)$_POST['password'];

        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            $error = t('Сессия устарела, попробуйте ещё раз.');
        } else {
            // Задержка при частых попытках по ЭТОМУ логину — против перебора
            // с меняющихся адресов, который лимит по IP не видит. Ждём до
            // проверки пароля и одинаково для существующих и несуществующих
            // логинов, иначе время ответа выдаёт, какие имена заведены.
            if (($delay = $security->loginDelay($username)) > 0) {
                sleep($delay);
            }
            $found = $users->verify($username, $password);
            if ($found !== null) {
                $security->loginUser($found);
                $security->clearFailures($ip, (string)$found['username']);
                $users->touchLastLogin((string)$found['username']);
                adminRedirect($adminBase);
            }
            $blocked = $security->registerFailure($ip, $username);
            $error   = t('Неверное имя пользователя или пароль.');
        }
    }

    if ($blocked) {
        http_response_code(429);
        header('Retry-After: ' . max(1, $security->blockRemaining($ip)));
        $error = sprintf(
            t('Слишком много попыток входа. Подождите %d мин.'),
            (int)ceil(max(1, $security->blockRemaining($ip)) / 60)
        );
    }

    $csrfField = $security->csrfField();
    $siteTitle = (string)($config['site_title'] ?? 'deeno');
    // Логотип сайта — тот же, что в шапке сайдбара (Настройки → «Логотип»)
    $siteLogo  = (string)($config['logo'] ?? '');
    $resetDone = isset($_GET['reset_done']);
    require __DIR__ . '/views/login.php';
    exit;
}

if ($action === 'logout') {
    if ($isPost && $security->verifyCsrf($_POST['csrf'] ?? null)) {
        $security->logout();
    }
    // На /admin/ гостя встретит форма входа (без зависимости от ЧПУ)
    adminRedirect($adminBase);
}

// Сюда доходят только авторизованные (страховка)
if ($user === null) {
    adminRedirect($adminBase);
}

// Маршрут /admin/lang/ убран (2026-07-20): язык выбирается в Настройках →
// «Панель управления» и хранится в профиле пользователя, а не только в сессии.

// ----------------------------------------------------------------
// Общие данные для layout
// ----------------------------------------------------------------
$siteTitle = (string)($config['site_title'] ?? 'deeno');
$siteUrl   = rtrim((string)($config['site_url'] ?? ''), '/');
$common    = [
    'user'      => $user,
    'adminBase' => $adminBase,
    'siteTitle'   => $siteTitle,
    'siteUrl'     => $siteUrl,
    'siteLogo'    => (string)($config['logo'] ?? ''),
    'siteFavicon' => (string)($config['favicon'] ?? ''),
    'security'  => $security,
    'action'    => $action,
    'cspNonce'  => $cspNonce,
    'adminLang' => $adminLang,
    'adminTheme' => $adminTheme,
];

// Джамп-бар (⌘K): контент + быстрые действия с учётом роли; счётчики для сайдбара.
// Метаданные приходят из кэшированного индекса — полные тела не читаются.
$role      = (string)($user['role'] ?? 'author');
$jumpPosts = $cms->posts(0, ['status' => 'all']);
if ($role === 'author') {
    $me = (string)($user['username'] ?? '');
    $jumpPosts = array_values(array_filter($jumpPosts, fn($p) => $p->author === $me));
}
$jumpPages = $role === 'author' ? [] : $cms->pages();

$categoriesCount = 0;
if ($role !== 'author') {
    require_once __DIR__ . '/CategoryController.php';
    $categoriesCount = count((new CategoryController($cms))->all());
}

$paletteItems = [];
foreach ($jumpPosts as $p) {
    $paletteItems[] = [
        'title' => $p->title !== '' ? $p->title : $p->slug,
        'url'   => $adminBase . 'posts/edit/?file=' . urlencode(basename($p->filePath)),
        'group' => t('Посты'),
        'meta'  => $p->status,
    ];
}
foreach ($jumpPages as $p) {
    $paletteItems[] = [
        'title' => $p->title !== '' ? $p->title : $p->slug,
        'url'   => $adminBase . 'pages/edit/?file=' . urlencode(basename($p->filePath)),
        'group' => t('Страницы'),
        'meta'  => $p->status,
    ];
}
$paletteActions = [
    ['title' => t('+ Новый пост'), 'url' => $adminBase . 'posts/new/', 'group' => t('Действия'), 'meta' => ''],
];
if ($role !== 'author') {
    $paletteActions[] = ['title' => t('+ Новая страница'), 'url' => $adminBase . 'pages/new/', 'group' => t('Действия'), 'meta' => ''];
    $paletteActions[] = ['title' => t('Категории'), 'url' => $adminBase . 'categories/', 'group' => t('Действия'), 'meta' => ''];
}
$paletteActions[] = ['title' => t('Медиа'), 'url' => $adminBase . 'media/', 'group' => t('Действия'), 'meta' => ''];
if ($role === 'admin') {
    $paletteActions[] = ['title' => t('Пользователи'), 'url' => $adminBase . 'users/',    'group' => t('Действия'), 'meta' => ''];
    $paletteActions[] = ['title' => t('Плагины'),      'url' => $adminBase . 'plugins/',  'group' => t('Действия'), 'meta' => ''];
    $paletteActions[] = ['title' => t('Настройки'),    'url' => $adminBase . 'settings/', 'group' => t('Действия'), 'meta' => ''];
}
$paletteActions[] = ['title' => t('Открыть сайт'), 'url' => ($siteUrl ?: '') . '/', 'group' => t('Действия'), 'meta' => ''];

$common['paletteItems'] = array_merge($paletteActions, $paletteItems);
$common['navCounts']    = ['posts' => count($jumpPosts), 'pages' => count($jumpPages), 'categories' => $categoriesCount];

// ----------------------------------------------------------------
// Dashboard
// ----------------------------------------------------------------

if ($action === 'dashboard') {
    $allPosts = $cms->posts(0, ['status' => 'all']);

    // Author видит на дашборде только своё
    if ($role === 'author') {
        $me = (string)($user['username'] ?? '');
        $allPosts = array_values(array_filter($allPosts, fn($p) => $p->author === $me));
    }

    $counters = [
        'published' => count(array_filter($allPosts, fn($p) => in_array($p->status, ['published', 'sticky'], true))),
        'drafts'    => count(array_filter($allPosts, fn($p) => $p->status === 'draft')),
        'pages'     => count($cms->pages()),
        'users'     => $users->count(),
    ];
    // «В работе» — черновики + отложенные (требуют внимания)
    $inWork = $counters['drafts']
        + count(array_filter($allPosts, fn($p) => $p->status === 'scheduled'));

    // Последние 5 изменённых материалов
    $recent = $allPosts;
    usort($recent, fn($a, $b) => strtotime($b->dateModifiedRaw ?: '0') <=> strtotime($a->dateModifiedRaw ?: '0'));
    $recent = array_slice($recent, 0, 5);

    // Чек-лист безопасности
    $checklist = [
        'HTTPS включён'            => $security->isHttps(),
        'Режим debug выключен'      => empty($config['debug']),
        'install.php удалён'        => !file_exists(ROOT_DIR . '/install.php'),
        'Кэш включён'               => !empty($config['cache_enabled']),
        // Без сохранённого ключа ссылки восстановления пароля и предпросмотра
        // перестают работать — молча, поэтому вынесено на видное место
        'Секретный ключ сохранён'   => Security::secretKeyOk(),
    ];

    // Статистика просмотров (раздел 10.1 ТЗ): текущий календарный месяц
    // (с 1-го числа по сегодня — не скользящее окно), топ-5, график по дням
    $statsDays  = (int)date('j'); // день месяца = сколько дней прошло с 1-го числа
    $monthNames = [
        1 => 'январь', 2 => 'февраль', 3 => 'март', 4 => 'апрель',
        5 => 'май', 6 => 'июнь', 7 => 'июль', 8 => 'август',
        9 => 'сентябрь', 10 => 'октябрь', 11 => 'ноябрь', 12 => 'декабрь',
    ];
    $statsMonthLabel = t($monthNames[(int)date('n')]);

    $stats      = new StatsManager();
    $viewsTotal = $stats->totalViews($statsDays);
    $viewsDaily = $stats->dailyTotals($statsDays); // ['Y-m-d' => n] — даты нужны графику
    $topPages   = $stats->topPages($statsDays);
    // Уникальные считаются в пределах суток (суточная соль хэша), поэтому за
    // месяц корректно показывать их по дням, а не одним числом — см. StatsManager
    $uniqDaily  = $stats->dailyUniques($statsDays);

    // Просмотры за 7 дней + тренд к предыдущей неделе (для верхней статкарты)
    $views7     = $stats->totalViews(7);
    $viewsPrev7 = $stats->totalViews(14) - $views7;
    $viewsTrend = $viewsPrev7 > 0 ? (int)round(($views7 - $viewsPrev7) / $viewsPrev7 * 100) : null;

    // Системная сводка.
    //
    // Размер каталога считается рекурсивным обходом — на медиатеке в тысячи
    // файлов это секунды, а показывается оно на каждом открытии «Обзора».
    // Точность здесь никому не нужна, поэтому результат кэшируется на 15 минут:
    // цифра «занимает сайт» может отставать, зато дашборд открывается сразу.
    $dirSize = function (string $dir) use ($cache): int {
        if (!is_dir($dir)) return 0;

        $key    = 'dirsize-' . md5($dir);
        $bucket = (int)floor(time() / 900);          // подпись меняется раз в 15 минут
        $hit    = $cache->get($key, (string)$bucket);
        if (is_int($hit)) {
            return $hit;
        }

        $sum = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) $sum += $f->getSize();
        }
        $cache->set($key, (string)$bucket, $sum);
        return $sum;
    };
    $fmtSize = function (int $b): string {
        if ($b >= 1073741824) return number_format($b / 1073741824, 1, '.', ' ') . ' ' . t('ГБ');
        if ($b >= 1048576)    return number_format($b / 1048576, 1, '.', ' ') . ' ' . t('МБ');
        return number_format(max(0, $b) / 1024, 0, '.', ' ') . ' ' . t('КБ');
    };
    $lastBackup = (new BackupManager())->all()[0]['mtime'] ?? null;
    $backupDays = $lastBackup !== null ? (int)floor((time() - (int)$lastBackup) / 86400) : null;

    // «Свободно на диске» убрано (2026-07-20): disk_free_space() показывает
    // свободное место на РАЗДЕЛЕ сервера, а не квоту аккаунта. На shared-хостинге
    // это вводило в заблуждение — при лимите в 1 ГБ на дашборде значилось
    // 300+ ГБ. Квота живёт уровнем выше (cPanel) и из PHP не читается.
    // Полезнее показать, сколько занимает сама установка.
    // Дата последнего бэкапа здесь не нужна — она есть в статкарте наверху
    $sysinfo = [
        'deeno'                  => defined('DEENO_VERSION') ? DEENO_VERSION : '—',
        'PHP'                    => PHP_VERSION,
        t('Занимает сайт')       => $fmtSize($dirSize(ROOT_DIR)),
        t('Медиатека')           => $fmtSize($dirSize(ROOT_DIR . '/media')),
        t('Кэш')                 => $fmtSize($dirSize(ROOT_DIR . '/cache')),
        t('Бэкапы')              => $fmtSize($dirSize(ROOT_DIR . '/backups')),
    ];

    adminRender('dashboard', $common + [
        'title'      => t('Обзор'),
        'counters'   => $counters,
        'inWork'     => $inWork,
        'views7'     => $views7,
        'viewsTrend' => $viewsTrend,
        'backupDays' => $backupDays,
        'recent'     => $recent,
        'checklist'  => $checklist,
        'viewsTotal'      => $viewsTotal,
        'viewsDaily'      => $viewsDaily,
        'topPages'        => $topPages,
        'uniqDaily'       => $uniqDaily,
        'statsMonthLabel' => $statsMonthLabel,
        'sysinfo'    => $sysinfo,
    ]);
    exit;
}

// ----------------------------------------------------------------
// Посты: список, редактор, сохранение, удаление
// ----------------------------------------------------------------

if ($action === 'posts' || $action === 'pages') {
    require_once __DIR__ . '/PostController.php';
    $controller = new PostController($cms, $security, $config);
    $type       = $action === 'pages' ? 'page' : 'post';

    // Страницы задают структуру сайта — только admin и editor
    if ($type === 'page') {
        requireRole(['admin', 'editor'], $user);
    }

    // Форма редактора: новый материал или правка существующего
    if ($sub === 'new' || $sub === 'edit') {
        $filename = $sub === 'edit' ? (string)($_GET['file'] ?? '') : '';
        $data     = $controller->editorData($filename, $user, $type);
        if (!empty($data['denied'])) {
            adminErrorPage(403, t('Доступ запрещён'), t('У вас нет прав для этого действия.'));
        }

        if ($type === 'post') {
            require_once __DIR__ . '/CategoryController.php';
            $data['categoryList'] = (new CategoryController($cms))->all();
        }

        adminRender('editor', $common + $data + [
            'title'   => $data['isNew']
                ? ($type === 'page' ? t('Новая страница') : t('Новый пост'))
                : t('Редактирование'),
            'saved'     => isset($_GET['saved']),
            'editErr'   => t((string)($_GET['msg'] ?? '')),
            'mediaList' => (new MediaManager())->all(),
        ]);
        exit;
    }

    // Сохранение (POST + CSRF)
    if ($sub === 'save' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        // Author не может перезаписать чужой пост, подставив его имя файла
        requireOwnPost((string)($_POST['file'] ?? ''), $user, $type, $cms, true);
        $_POST['type'] = $type;
        $result = $controller->save($_POST, $user);
        if (isset($result['error'])) {
            $back = (string)($_POST['file'] ?? '') !== ''
                ? $action . '/edit/?file=' . urlencode((string)$_POST['file']) . '&'
                : $action . '/new/?';
            adminRedirect($adminBase . $back . 'msg=' . urlencode($result['error']));
        }
        adminRedirect($adminBase . $action . '/edit/?file=' . urlencode($result['filename']) . '&saved=1');
    }

    // Удаление (POST + CSRF)
    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $file = (string)($_POST['file'] ?? '');

        // Author удаляет только свои посты
        requireOwnPost($file, $user, $type, $cms);

        $ok = $type === 'page' ? $cms->deletePageFile($file) : $cms->deletePostFile($file);
        if ($ok) {
            Hooks::run('post.deleted', ['file' => $file, 'type' => $type]);
        }
        adminRedirect($adminBase . $action . '/?' . ($ok ? 'deleted=1' : 'error=1'));
    }

    // Список страниц (фильтры: статус + поиск по заголовку/slug)
    if ($action === 'pages') {
        $fStatus = (string)($_GET['status'] ?? '');
        $fSearch = trim((string)($_GET['q'] ?? ''));
        $pages   = $cms->pages();
        if (in_array($fStatus, ['published', 'draft'], true)) {
            $pages = array_values(array_filter($pages, fn($p) => $p->status === $fStatus));
        }
        if ($fSearch !== '') {
            $needle = mb_strtolower($fSearch);
            $pages  = array_values(array_filter(
                $pages,
                fn($p) => str_contains(mb_strtolower($p->title . ' ' . $p->slug), $needle)
            ));
        }
        adminRender('pages', $common + [
            'title'   => t('Страницы'),
            'pages'   => $pages,
            'fStatus' => $fStatus,
            'fSearch' => $fSearch,
            'deleted' => isset($_GET['deleted']),
            'error'   => isset($_GET['error']),
        ]);
        exit;
    }

    $all = $cms->posts(0, ['status' => 'all']);

    // Author видит только свои посты (чекпоинт этапа 3)
    if (($user['role'] ?? '') === 'author') {
        $me  = (string)($user['username'] ?? '');
        $all = array_values(array_filter($all, fn($p) => $p->author === $me));
    }

    // Список категорий для фильтра (до фильтрации)
    $categories = [];
    foreach ($all as $p) {
        $c = $p->category !== '' ? $p->category : Post::DEFAULT_CATEGORY;
        $categories[$c] = true;
    }
    $categories = array_keys($categories);
    sort($categories);

    // Фильтры
    $fStatus   = (string)($_GET['status'] ?? '');
    $fCategory = (string)($_GET['category'] ?? '');
    $fSearch   = trim((string)($_GET['q'] ?? ''));

    if ($fStatus !== '') {
        $all = array_values(array_filter($all, fn($p) => $p->status === $fStatus));
    }
    if ($fCategory !== '') {
        $all = array_values(array_filter($all, fn($p) => ($p->category !== '' ? $p->category : Post::DEFAULT_CATEGORY) === $fCategory));
    }
    if ($fSearch !== '') {
        $all = array_values(array_filter($all, fn($p) => mb_stripos($p->title, $fSearch) !== false));
    }

    // Пагинация: 20 на страницу (раздел 10.3 ТЗ)
    $perPage = 20;
    $total   = count($all);
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $posts   = array_slice($all, ($page - 1) * $perPage, $perPage);

    adminRender('posts', $common + [
        'title'      => t('Посты'),
        'posts'      => $posts,
        'total'      => $total,
        'page'       => $page,
        'perPage'    => $perPage,
        'categories' => $categories,
        'fStatus'    => $fStatus,
        'fCategory'  => $fCategory,
        'fSearch'    => $fSearch,
        'deleted'    => isset($_GET['deleted']),
        'error'      => isset($_GET['error']),
    ]);
    exit;
}

// ----------------------------------------------------------------
// Категории (admin + editor): агрегированный список, метаданные, merge, удаление
// ----------------------------------------------------------------

if ($action === 'categories') {
    requireRole(['admin', 'editor'], $user);
    require_once __DIR__ . '/CategoryController.php';
    $catController = new CategoryController($cms);

    // Новая категория без единого поста — только метаданные (POST + CSRF)
    if ($sub === 'create' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $title       = trim((string)($_POST['title'] ?? ''));
        $slug        = (string)($_POST['slug'] ?? '');
        $description = trim((string)($_POST['description'] ?? ''));
        $position    = (int)($_POST['position'] ?? 0);
        $icon        = trim((string)($_POST['icon'] ?? ''));
        $result      = $catController->create($title, $slug, $description, $position, $icon);
        adminRedirect($adminBase . 'categories/?' . (isset($result['error']) ? 'error=1' : 'saved=1'));
    }

    // Сохранение: название/описание + (если ссылка изменилась) переименование/объединение (POST + CSRF)
    if ($sub === 'save' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $from        = (string)($_POST['from'] ?? '');
        $title       = trim((string)($_POST['title'] ?? ''));
        $slug        = (string)($_POST['slug'] ?? '');
        $description = trim((string)($_POST['description'] ?? ''));
        $position    = (int)($_POST['position'] ?? 0);
        $icon        = trim((string)($_POST['icon'] ?? ''));
        $result      = $catController->save($from, $title, $slug, $description, $position, $icon);
        adminRedirect($adminBase . 'categories/?' . (isset($result['error']) ? 'error=1' : 'saved=1'));
    }

    // Удаление (POST + CSRF) — посты возвращаются к дефолтной категории
    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $from = (string)($_POST['from'] ?? '');
        // Виртуальная категория постов без рубрики (Post::DEFAULT_CATEGORY) — удалять нечего
        if ($from !== '' && $from !== Post::DEFAULT_CATEGORY) {
            $catController->delete($from);
        }
        adminRedirect($adminBase . 'categories/?deleted=1');
    }

    adminRender('categories', $common + [
        'title'      => t('Категории'),
        'categories' => $catController->all(),
        'mediaList'  => (new MediaManager())->all(),
        'saved'      => isset($_GET['saved']),
        'deleted'    => isset($_GET['deleted']),
        'error'      => isset($_GET['error']),
    ]);
    exit;
}

// ----------------------------------------------------------------
// Расстановка (drag-and-drop): новый порядок разделов/статей и перенос
// поста между разделами. JSON-ответ. Роли admin/editor.
// ----------------------------------------------------------------
if ($action === 'reorder' && $isPost) {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf']);
        exit;
    }
    if (!in_array((string)($user['role'] ?? ''), ['admin', 'editor'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'role']);
        exit;
    }
    $payload = json_decode((string)($_POST['data'] ?? ''), true);
    if (!is_array($payload['sections'] ?? null)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad']);
        exit;
    }

    // Номера пишем ТОЛЬКО там, где выбран ручной порядок: при сортировке по
    // алфавиту или датам дерево всё равно рендерится расчётом, и записанный
    // position игнорировался бы — перестановка «откатывалась» на глазах у
    // пользователя, а файлы молча переписывались. Перенос МЕЖДУ разделами
    // меняет категорию и работает при любом режиме. Проверка именно здесь,
    // а не только в теме: JS можно обойти, правило должно быть одно.
    $manualPosts    = (string)($config['article_order'] ?? 'manual') === 'manual';
    $manualSections = (string)($config['category_order'] ?? 'alpha') === 'manual';

    $catMgr    = new CategoryManager();
    $redirects = new RedirectManager();
    foreach ($payload['sections'] as $i => $sec) {
        $cat      = (string)($sec['category'] ?? '');
        $storeCat = $cat === Post::DEFAULT_CATEGORY ? '' : $cat;
        // Порядок раздела (только у явных категорий, не у «Без категории»)
        if ($manualSections && $cat !== '' && $cat !== Post::DEFAULT_CATEGORY && $catMgr->exists($cat)) {
            $catMgr->setPosition($cat, (int)$i);
        }
        foreach ((array)($sec['posts'] ?? []) as $j => $file) {
            $post = $cms->postByFilename((string)$file);
            if ($post === null) continue;
            $oldCat  = $post->category !== '' ? $post->category : Post::DEFAULT_CATEGORY;
            $newCat  = $cat !== '' ? $cat : Post::DEFAULT_CATEGORY;
            $moved   = $oldCat !== $newCat;
            // Перенос в другой раздел меняет URL → 301-редирект
            if ($moved) {
                $redirects->add(
                    '/' . $oldCat . '/' . $post->slug . '/',
                    '/' . ($storeCat !== '' ? $storeCat : Post::DEFAULT_CATEGORY) . '/' . $post->slug . '/'
                );
            }
            $meta = [];
            if ($manualPosts) $meta['position'] = (int)$j;
            if ($moved)       $meta['category'] = $storeCat;
            if ($meta !== []) $cms->updatePostMeta((string)$file, $meta);
        }
    }
    echo json_encode(['ok' => true, 'positions' => $manualPosts]);
    exit;
}

// ----------------------------------------------------------------
// Темы (только Admin): список, активация, установка ZIP, удаление
// ----------------------------------------------------------------

if ($action === 'themes') {
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        adminRender('404', $common + ['title' => t('Доступ запрещён')]);
        exit;
    }

    require_once __DIR__ . '/ThemeInstaller.php';
    $installer = new ThemeInstaller();

    if ($sub === 'activate' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $name   = (string)($_POST['name'] ?? '');
        $themes = array_map('basename', glob(ROOT_DIR . '/themes/*', GLOB_ONLYDIR) ?: []);
        if (in_array($name, $themes, true)) {
            $config['theme'] = $name;
            DataFile::writeMigrating(ROOT_DIR . '/config', $config);
            adminRedirect($adminBase . 'themes/?activated=1');
        }
        adminRedirect($adminBase . 'themes/');
    }

    if ($sub === 'install' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $result = $installer->install($_FILES['theme_zip'] ?? []);
        adminRedirect($adminBase . 'themes/?' . (isset($result['error'])
            ? 'error=' . urlencode(t($result['error']))
            : 'installed=1'));
    }

    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $name = (string)($_POST['name'] ?? '');
        // Нельзя удалить активную тему и default (база наследования)
        if ($name !== ($config['theme'] ?? '') && $name !== ThemeManager::FALLBACK_THEME) {
            $installer->delete($name);
        }
        adminRedirect($adminBase . 'themes/?deleted=1');
    }

    adminRender('themes', $common + [
        'title'     => t('Темы'),
        'themes'    => $installer->all((string)($config['theme'] ?? 'default')),
        'activated' => isset($_GET['activated']),
        'installed' => isset($_GET['installed']),
        'deleted'   => isset($_GET['deleted']),
        'themesErr' => (string)($_GET['error'] ?? ''),
    ]);
    exit;
}

// ----------------------------------------------------------------
// Плагины (только Admin)
// ----------------------------------------------------------------

if ($action === 'plugins') {
    requireRole(['admin'], $user);
    require_once __DIR__ . '/PluginInstaller.php';
    $pluginInstaller = new PluginInstaller();

    // Вкл/выкл (POST + CSRF). Запись в config.json сама сбросит кэш страниц.
    if ($sub === 'toggle' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $name = (string)($_POST['name'] ?? '');
        if (isset(PluginManager::all()[$name])) {
            $enabled = PluginManager::enabled($config);
            $enabled = in_array($name, $enabled, true)
                ? array_values(array_diff($enabled, [$name]))
                : array_merge($enabled, [$name]);
            $config['plugins'] = $enabled;
            DataFile::writeMigrating(ROOT_DIR . '/config', $config);
        }
        adminRedirect($adminBase . 'plugins/?saved=1');
    }

    // Установка из ZIP (POST + CSRF)
    if ($sub === 'install' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $result = $pluginInstaller->install($_FILES['plugin_zip'] ?? []);
        adminRedirect($adminBase . 'plugins/?' . (isset($result['error'])
            ? 'error=' . urlencode(t($result['error']))
            : 'installed=1'));
    }

    // Удаление (POST + CSRF)
    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $name = (string)($_POST['name'] ?? '');
        $pluginInstaller->delete($name);
        // Отключаем удалённый плагин, если он был включён
        if (in_array($name, PluginManager::enabled($config), true)) {
            $config['plugins'] = array_values(array_diff(PluginManager::enabled($config), [$name]));
            DataFile::writeMigrating(ROOT_DIR . '/config', $config);
        }
        adminRedirect($adminBase . 'plugins/?deleted=1');
    }

    adminRender('plugins', $common + [
        'title'      => t('Плагины'),
        'plugins'    => PluginManager::all(),
        'enabled'    => PluginManager::enabled($config),
        'saved'      => isset($_GET['saved']),
        'installed'  => isset($_GET['installed']),
        'deleted'    => isset($_GET['deleted']),
        'pluginsErr' => (string)($_GET['error'] ?? ''),
    ]);
    exit;
}

// ----------------------------------------------------------------
// Профиль (любая роль)
// ----------------------------------------------------------------

if ($action === 'profile') {
    $me         = $users->find((string)($user['username'] ?? ''));
    $profileErr = '';

    if ($isPost && $me !== null) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }

        // Любое изменение профиля подтверждается текущим паролем
        if (!password_verify((string)($_POST['current_password'] ?? ''), (string)($me['password'] ?? ''))) {
            $profileErr = t('Текущий пароль неверен.');
        } else {
            $p1 = (string)($_POST['password'] ?? '');
            $p2 = (string)($_POST['password2'] ?? '');
            if ($p1 !== '' && ($pe = UserManager::passwordError($p1)) !== null) {
                $profileErr = t($pe);
            } elseif ($p1 !== $p2 && $p1 !== '') {
                $profileErr = t('Пароли не совпадают.');
            } else {
                $me['display_name'] = trim((string)($_POST['display_name'] ?? '')) ?: $me['username'];
                $me['email']        = trim((string)($_POST['email'] ?? ''));
                if ($p1 !== '') {
                    $me = UserManager::withNewPassword($me, $p1);
                }
                if ($users->save($me)) {
                    $_SESSION['user']['display_name'] = $me['display_name'];
                    // Смена пароля завершает сессии, открытые до неё. Свою —
                    // не завершаем: пользователь только что подтвердил текущий
                    // пароль, выкидывать его на форму входа незачем. Сдвигаем
                    // отметку входа, чтобы она была свежее отметки смены.
                    if ($p1 !== '') {
                        $_SESSION['login_time'] = time();
                    }
                    adminRedirect($adminBase . 'profile/?saved=1');
                }
                $profileErr = t('Не удалось записать файл пользователя (права на /users/?).');
            }
        }
    }

    adminRender('profile', $common + [
        'title'      => t('Профиль'),
        'me'         => $me,
        'saved'      => isset($_GET['saved']),
        'profileErr' => $profileErr,
    ]);
    exit;
}

// ----------------------------------------------------------------
// Пользователи (только Admin)
// ----------------------------------------------------------------

if ($action === 'users') {
    requireRole(['admin'], $user);

    // Создание / редактирование (POST + CSRF)
    if ($sub === 'save' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }

        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $existing = $users->find($username);
        $isNewUser = $existing === null;
        $password  = (string)($_POST['password'] ?? '');
        $role      = (string)($_POST['role'] ?? 'author');
        $active    = !empty($_POST['active']);
        $err       = '';

        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $username)) {
            $err = t('Логин: только латиница, цифры, дефис и подчёркивание.');
        } elseif (!in_array($role, UserManager::ROLES, true)) {
            $err = t('Неизвестная роль.');
        } elseif ($isNewUser && $password === '') {
            $err = t('Для нового пользователя нужен пароль.');
        } elseif ($password !== '' && ($pe = UserManager::passwordError($password)) !== null) {
            $err = t($pe);
        } elseif (!$isNewUser
            && ($existing['role'] ?? '') === 'admin' && !empty($existing['active'])
            && ($role !== 'admin' || !$active)
            && $users->activeAdmins() <= 1) {
            $err = t('Нельзя понизить или отключить последнего администратора.');
        } else {
            $record = $existing ?? ['username' => $username, 'created' => date('c')];
            $record['display_name'] = trim((string)($_POST['display_name'] ?? '')) ?: $username;
            $record['email']        = trim((string)($_POST['email'] ?? ''));
            $record['role']         = $role;
            $record['active']       = $active;
            if ($password !== '') {
                // Смена пароля админом завершает открытые сессии этого
                // пользователя — это и есть способ отобрать доступ немедленно
                $record = UserManager::withNewPassword($record, $password);
                if (strtolower($username) === strtolower((string)($user['username'] ?? ''))) {
                    $_SESSION['login_time'] = time();   // свою сессию не рвём
                }
            }
            if (!$users->save($record)) {
                $err = t('Не удалось записать файл пользователя (права на /users/?).');
            }
        }

        adminRedirect($adminBase . 'users/?' . ($err !== '' ? 'error=' . urlencode($err) : 'saved=1'));
    }

    // Удаление (POST + CSRF)
    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $name   = strtolower((string)($_POST['name'] ?? ''));
        $target = $users->find($name);
        $err    = '';
        if ($name === strtolower((string)($user['username'] ?? ''))) {
            $err = t('Нельзя удалить собственную учётную запись.');
        } elseif ($target !== null && ($target['role'] ?? '') === 'admin'
            && !empty($target['active']) && $users->activeAdmins() <= 1) {
            $err = t('Нельзя удалить последнего администратора.');
        } elseif ($target !== null) {
            $users->delete($name);
        }
        adminRedirect($adminBase . 'users/?' . ($err !== '' ? 'error=' . urlencode($err) : 'deleted=1'));
    }

    adminRender('users', $common + [
        'title'    => t('Пользователи'),
        'list'     => $users->all(),
        'saved'    => isset($_GET['saved']),
        'deleted'  => isset($_GET['deleted']),
        'usersErr' => (string)($_GET['error'] ?? ''),
        'selfName' => strtolower((string)($user['username'] ?? '')),
    ]);
    exit;
}

// ----------------------------------------------------------------
// Бэкапы (только Admin)
// ----------------------------------------------------------------

if ($action === 'backups') {
    requireRole(['admin'], $user);

    $backups = new BackupManager();

    if ($sub === 'create' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $result = $backups->create();
        adminRedirect($adminBase . 'backups/?' . (isset($result['error'])
            ? 'backup_err=' . urlencode(t($result['error']))
            : 'backup_ok=1'));
    }

    if ($sub === 'download') {
        $path = $backups->path((string)($_GET['file'] ?? ''));
        if ($path === null) {
            http_response_code(404);
            exit('Backup not found.');
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $backups->delete((string)($_POST['file'] ?? ''));
        adminRedirect($adminBase . 'backups/?backup_deleted=1');
    }

    adminRender('backups', $common + [
        'title'         => t('Бэкапы'),
        'backupsList'   => $backups->all(),
        'backupOk'      => isset($_GET['backup_ok']),
        'backupDeleted' => isset($_GET['backup_deleted']),
        'backupErr'     => (string)($_GET['backup_err'] ?? ''),
    ]);
    exit;
}

// ----------------------------------------------------------------
// Настройки сайта (только Admin)
// ----------------------------------------------------------------

if ($action === 'settings') {
    // Раздел открыт всем ролям, но не-админ видит только личные настройки
    // панели (тема и язык) — они переехали сюда из сайдбара. Всё, что меняет
    // сайт, по-прежнему доступно исключительно администратору.
    $isSiteAdmin = ($user['role'] ?? '') === 'admin';
    $settingsErr = '';

    if ($isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }

        // Личные настройки панели: пишем в профиль пользователя, чтобы выбор
        // пережил выход (раньше язык жил только в сессии и сбрасывался)
        $me = $users->find((string)($user['username'] ?? ''));
        if ($me !== null) {
            $lang = (string)($_POST['admin_lang'] ?? '');
            if (in_array($lang, ['ru', 'en'], true)) {
                $me['language'] = $lang;
                $_SESSION['admin_lang'] = $lang;
            }
            $uiTheme = (string)($_POST['admin_theme'] ?? '');
            if (in_array($uiTheme, ['light', 'dark'], true)) {
                $me['admin_theme'] = $uiTheme;
            }
            $users->save($me);
        }

        if (!$isSiteAdmin) {
            adminRedirect($adminBase . 'settings/?saved=1');
        }

        $themes = array_map('basename', glob(ROOT_DIR . '/themes/*', GLOB_ONLYDIR) ?: []);

        // Пресеты формата даты — все языконезависимые (числовые)
        $dateFormats = ['d.m.Y', 'd.m.Y H:i', 'j.n.Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'];

        // Главная: '' — лента постов; иначе slug существующей страницы
        $pageSlugs = array_map(fn($p) => $p->slug, $cms->pages());

        // Соцсети: только известные ключи с http(s)-ссылкой
        $social    = [];
        $postedSoc = is_array($_POST['social'] ?? null) ? $_POST['social'] : [];
        foreach (array_keys(socialNetworks()) as $sk) {
            $sv = trim((string)($postedSoc[$sk] ?? ''));
            if ($sv !== '' && preg_match('~^https?://~i', $sv)) {
                $social[$sk] = $sv;
            }
        }

        $new    = [
            'site_title'          => trim((string)($_POST['site_title'] ?? '')),
            'site_tagline'        => trim((string)($_POST['site_tagline'] ?? '')),
            'site_description'    => trim((string)($_POST['site_description'] ?? '')),
            'footer_text'         => trim((string)($_POST['footer_text'] ?? '')),
            'site_url'            => rtrim(trim((string)($_POST['site_url'] ?? '')), '/'),
            'language'            => in_array($_POST['language'] ?? '', ['ru', 'en'], true) ? (string)$_POST['language'] : 'ru',
            'timezone'            => trim((string)($_POST['timezone'] ?? 'Europe/Moscow')),
            'date_format'         => in_array($_POST['date_format'] ?? '', $dateFormats, true) ? (string)$_POST['date_format'] : 'd.m.Y',
            'homepage'            => in_array($_POST['homepage'] ?? '', $pageSlugs, true) ? (string)$_POST['homepage'] : '',
            'social'              => $social,
            'theme'               => in_array($_POST['theme'] ?? '', $themes, true) ? (string)$_POST['theme'] : 'default',
            'posts_per_page'      => max(1, min(100, (int)($_POST['posts_per_page'] ?? 10))),
            'order_by'            => ($_POST['order_by'] ?? '') === 'position' ? 'position' : 'date',
            'category_order'      => in_array($_POST['category_order'] ?? '', ['manual', 'alpha', 'created', 'modified'], true) ? (string)$_POST['category_order'] : 'alpha',
            'article_order'       => in_array($_POST['article_order'] ?? '', ['manual', 'alpha', 'created', 'modified'], true) ? (string)$_POST['article_order'] : 'manual',
            'maintenance_mode'    => !empty($_POST['maintenance_mode']),
            'maintenance_message' => trim((string)($_POST['maintenance_message'] ?? '')),
            'cache_enabled'       => !empty($_POST['cache_enabled']),
            'debug'               => !empty($_POST['debug']),
            'rss_enabled'         => !empty($_POST['rss_enabled']),
            'rss_items'           => max(1, min(100, (int)($_POST['rss_items'] ?? 20))),
            'sitemap_enabled'     => !empty($_POST['sitemap_enabled']),
            'logo'                => trim((string)($_POST['logo'] ?? '')),
            'favicon'             => trim((string)($_POST['favicon'] ?? '')),
            'og_image'            => trim((string)($_POST['og_image'] ?? '')),
            'media_max_width'     => max(320, min(10000, (int)($_POST['media_max_width'] ?? 2560))),
            'media_quality'       => max(40, min(100, (int)($_POST['media_quality'] ?? 82))),
            // Домены внешних скриптов (счётчики) для CSP. Нормализуем сразу:
            // из введённого остаются только схема+хост, по одному в строке —
            // так пользователь видит, что реально попало в политику.
            'external_scripts'    => implode("\n", Csp::allowedHosts([
                'external_scripts' => (string)($_POST['external_scripts'] ?? ''),
            ])),
        ];

        if ($new['site_title'] === '') {
            $settingsErr = t('Название сайта не может быть пустым.');
        } elseif (!in_array($new['timezone'], timezone_identifiers_list(), true)) {
            $settingsErr = t('Неизвестная временная зона.');
        } else {
            // Сливаем с текущим конфигом: неизвестные ключи (smtp_* и др.) сохраняются
            $merged = array_merge($config, $new);
            if (!DataFile::writeMigrating(ROOT_DIR . '/config', $merged)) {
                $settingsErr = t('Не удалось записать config.json (права на запись?).');
            } else {
                adminRedirect($adminBase . 'settings/?saved=1');
            }
        }
        $config = array_merge($config, $new);
    }

    adminRender('settings', $common + [
        'title'         => t('Настройки'),
        'config'        => $config,
        'themes'        => $isSiteAdmin ? array_map('basename', glob(ROOT_DIR . '/themes/*', GLOB_ONLYDIR) ?: []) : [],
        'pagesList'     => $isSiteAdmin ? $cms->pages() : [],
        'mediaList'     => $isSiteAdmin ? (new MediaManager())->all() : [],
        'saved'         => isset($_GET['saved']),
        'settingsErr'   => $settingsErr,
        'isSiteAdmin'   => $isSiteAdmin,
    ]);
    exit;
}

// ----------------------------------------------------------------
// Медиа: галерея, загрузка (AJAX), удаление
// ----------------------------------------------------------------

if ($action === 'media') {
    $media = new MediaManager($config);

    // Загрузка — отвечаем JSON (для drag & drop из редактора и галереи)
    if ($sub === 'upload' && $isPost) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$security->verifyCsrf($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? null))) {
            http_response_code(403);
            exit(json_encode(['error' => 'CSRF token mismatch.']));
        }
        $result = $media->upload($_FILES['file'] ?? []);
        if (isset($result['error'])) {
            http_response_code(422);
            $result['error'] = t($result['error']);
        }
        exit(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // Удаление (POST + CSRF). Медиатека общая и без владельца, поэтому
    // удаление файлов доступно только admin и editor (author загружает, но
    // не удаляет — чтобы не затронуть чужие ассеты).
    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        requireRole(['admin', 'editor'], $user);
        $ok = $media->delete((string)($_POST['url'] ?? ''));
        adminRedirect($adminBase . 'media/?' . ($ok ? 'deleted=1' : 'error=1'));
    }

    adminRender('media', $common + [
        'title'   => t('Медиа'),
        'items'   => $media->all(),
        'deleted' => isset($_GET['deleted']),
        'error'   => isset($_GET['error']),
    ]);
    exit;
}

// Неизвестный раздел админки
http_response_code(404);
adminRender('404', $common + ['title' => t('Раздел не найден')]);
