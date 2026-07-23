<?php
declare(strict_types=1);

/**
 * Тесты файловых менеджеров deeno в ПЕСОЧНИЦЕ.
 * ROOT_DIR указывает на временную папку (пути данных), а классы грузятся с
 * реального system/+admin/ — поэтому реальные content/users/media НЕ трогаются.
 * Запуск: php tests/managers.php   (0 — все прошли, 1 — падения). В CI.
 */

$REAL = dirname(__DIR__);
$TMP  = sys_get_temp_dir() . '/deeno_mgr_' . bin2hex(random_bytes(4));
foreach (['content/posts', 'content/pages', 'media/thumbnails', 'cache', 'backups', 'users', 'system/logs', 'plugins'] as $d) {
    @mkdir("$TMP/$d", 0755, true);
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$dir/$f";
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
register_shutdown_function(fn() => rrmdir($GLOBALS['TMP']));
$GLOBALS['TMP'] = $TMP;

define('ROOT_DIR', $TMP);            // пути данных менеджеров → песочница
define('FFC_ADMIN', true);           // admin/* классы (PostController) защищены этой константой
spl_autoload_register(function (string $c) use ($REAL): void {
    foreach (["$REAL/system/$c.php", "$REAL/admin/$c.php"] as $f) {
        if (is_file($f)) { require_once $f; return; }
    }
});

// ── раннер ──
$GLOBALS['__t'] = 0; $GLOBALS['__f'] = 0;
function ok(bool $c, string $m): void {
    $GLOBALS['__t']++;
    echo $c ? "  \033[32m✓\033[0m $m\n" : "  \033[31m✗ $m\033[0m\n";
    if (!$c) $GLOBALS['__f']++;
}
function eq($e, $a, string $m): void {
    ok($e === $a, $m . ($e === $a ? '' : " (ждал " . var_export($e, true) . ", получил " . var_export($a, true) . ")"));
}
function section(string $s): void { echo "\n\033[1m$s\033[0m\n"; }

// ── помощники фикстур ──
function writePost(string $slug, array $meta, string $body = 'Тело поста.'): void
{
    $meta = ['slug' => $slug] + $meta;
    file_put_contents(ROOT_DIR . "/content/posts/$slug.md", FrontmatterSerializer::serialize($meta, $body));
}
function writePage(string $slug, array $meta, string $body = 'Тело страницы.'): void
{
    $meta = ['slug' => $slug] + $meta;
    file_put_contents(ROOT_DIR . "/content/pages/$slug.md", FrontmatterSerializer::serialize($meta, $body));
}

// ────────────────────────────────────────────────────────────
section('ContentManager — фильтры, сортировка, отложенные, related');

writePost('a', ['title' => 'A', 'status' => 'published', 'category' => 'novosti', 'tags' => ['php', 'cms', 'news'], 'author' => 'admin', 'date' => '2026-01-10']);
writePost('b', ['title' => 'B', 'status' => 'published', 'category' => 'novosti', 'tags' => ['cms', 'news'], 'author' => 'petya', 'date' => '2026-01-05']);
writePost('c', ['title' => 'C', 'status' => 'draft', 'category' => 'novosti', 'author' => 'admin', 'date' => '2026-01-08']);
writePost('d', ['title' => 'D', 'status' => 'sticky', 'category' => 'tech', 'tags' => ['php'], 'author' => 'admin', 'date' => '2026-01-01']);
writePost('e', ['title' => 'E', 'status' => 'scheduled', 'scheduled_date' => '2020-01-01T00:00', 'category' => 'tech', 'date' => '2020-01-01']);
writePost('f', ['title' => 'F', 'status' => 'scheduled', 'scheduled_date' => '2099-01-01T00:00', 'category' => 'tech', 'date' => '2099-01-01']);

$cfg = ['site_url' => 'https://s.ru', 'order_by' => 'date'];
$cms = new ContentManager($cfg);

$vis = array_map(fn($p) => $p->slug, $cms->posts());
sort($vis);
eq(['a', 'b', 'd', 'e'], $vis, 'posts() по умолчанию: published+sticky+дозревший scheduled (без draft/будущего)');
eq('d', $cms->posts()[0]->slug, 'сортировка: sticky первым');
eq(6, count($cms->posts(0, ['status' => 'all'])), 'status=all → все 6');
eq(['a', 'b'], (function () use ($cms) { $s = array_map(fn($p) => $p->slug, $cms->posts(0, ['category' => 'novosti'])); sort($s); return $s; })(), 'фильтр по категории (только видимые)');
eq(['a', 'd'], (function () use ($cms) { $s = array_map(fn($p) => $p->slug, $cms->posts(0, ['tag' => 'php'])); sort($s); return $s; })(), 'фильтр по тегу');
eq(['a', 'c', 'd'], (function () use ($cms) { $s = array_map(fn($p) => $p->slug, $cms->posts(0, ['status' => 'all', 'author' => 'admin'])); sort($s); return $s; })(), 'фильтр по автору (среди всех)');
eq('B', $cms->related($cms->postBySlug('novosti', 'a'), 3)[0]->title ?? null, 'related(): по общему тегу cms → B');
ok($cms->hasScheduled(), 'hasScheduled(): есть будущий отложенный → true');
eq('A', $cms->postBySlug('novosti', 'a')->title ?? null, 'postBySlug(): находит пост');
$cats = $cms->categories();
eq(2, $cats['novosti'] ?? 0, 'categories(): novosti = 2 (только видимые)');

writePage('about', ['title' => 'О нас', 'status' => 'published']);
eq('О нас', $cms->page('about')->title ?? null, 'page(): находит страницу');

// ────────────────────────────────────────────────────────────
section('UserManager — учётки, роли, авторизация');

$um = new UserManager();
$hash = fn(string $p) => password_hash($p, PASSWORD_BCRYPT, ['cost' => 8]);
$um->save(['username' => 'admin', 'password' => $hash('adminpass'), 'role' => 'admin', 'active' => true]);
$um->save(['username' => 'ed', 'password' => $hash('edpass'), 'role' => 'editor', 'active' => true]);

eq(2, $um->count(), 'count() = 2');
eq(1, $um->activeAdmins(), 'activeAdmins() = 1 (защита «последнего админа» — на нём)');
ok($um->find('admin') !== null, 'find(admin) найден');
ok($um->find('nope') === null, 'find(несуществующий) → null');
ok($um->verify('admin', 'adminpass') !== null, 'verify: правильный пароль');
ok($um->verify('admin', 'wrong') === null, 'verify: неверный пароль → null');
ok($um->save(['username' => 'Bad Name!', 'password' => 'x', 'role' => 'admin']) === false, 'save: невалидный username отклонён');
ok($um->save(['username' => 'x', 'password' => 'x', 'role' => 'superhero']) === false, 'save: неизвестная роль отклонена');
ok($um->delete('ed') === true, 'delete(ed) → true');
eq(1, $um->count(), 'после удаления = 1');

// ────────────────────────────────────────────────────────────
section('StatsManager — учёт просмотров');

$sm = new StatsManager();
$sm->track('/x/'); $sm->track('/x/'); $sm->track('/y/');
eq(3, $sm->totalViews(1), 'totalViews(сегодня) = 3');
$top = $sm->topPages(1);
eq(2, $top['/x/'] ?? 0, 'topPages: /x/ = 2');
eq(1, $top['/y/'] ?? 0, 'topPages: /y/ = 1');

// Уникальные: суточный хэш IP+UA, без cookie и без хранения IP
eq(0, $sm->uniqueVisitors(1), 'uniqueVisitors: без признака посетителя не считаются');

$sm->track('/x/', '10.0.0.1|Mozilla');
$sm->track('/y/', '10.0.0.1|Mozilla');   // тот же посетитель, вторая страница
eq(1, $sm->uniqueVisitors(1), 'один и тот же IP+UA за день = 1 уникальный');

$sm->track('/x/', '10.0.0.1|Chrome');    // тот же IP, другой UA
$sm->track('/x/', '10.0.0.2|Mozilla');   // другой IP, тот же UA
eq(3, $sm->uniqueVisitors(1), 'разные IP или UA считаются раздельно');

$today = date('Y-m-d');
$daily = $sm->dailyUniques(3);
eq(3, $daily[$today] ?? 0, 'dailyUniques: сегодня = 3');
eq(3, count($daily), 'dailyUniques(3): три дня, включая пустые');
eq(0, $daily[date('Y-m-d', strtotime('-2 days'))] ?? -1, 'dailyUniques: позавчера = 0');

// Просмотры считаются независимо от уникальных
eq(7, $sm->totalViews(1), 'totalViews учитывает все заходы, а не только уникальные');

// В файл не должны попадать ни IP, ни User-Agent — только усечённые хэши
$raw = (string)@file_get_contents(ROOT_DIR . '/cache/stats.php');
if ($raw === '') $raw = (string)@file_get_contents(ROOT_DIR . '/cache/stats.json');
ok($raw !== '', 'stats-файл записан');
ok(!str_contains($raw, '10.0.0.1') && !str_contains($raw, 'Mozilla'),
   'в stats-файле нет ни IP, ни User-Agent');

// Хэш привязан к дате: за другой день тот же посетитель даёт другой идентификатор
$idToday = new ReflectionMethod(StatsManager::class, 'visitorId');
// До PHP 8.1 приватный метод без этого не вызвать; с 8.5 вызов помечен deprecated
if (PHP_VERSION_ID < 80100) $idToday->setAccessible(true);
$a = $idToday->invoke($sm, '10.0.0.1|Mozilla', '2026-07-20');
$b = $idToday->invoke($sm, '10.0.0.1|Mozilla', '2026-07-21');
ok($a !== $b, 'суточная соль: один посетитель в разные дни — разные хэши');
eq(12, strlen($a), 'хэш посетителя усечён до 12 символов');

// Список на дашборде не должен разрастаться: показываем топ-5, скролл не нужен.
// Идёт последним в секции — эти заходы сдвигают счётчики просмотров выше.
foreach (['/a/', '/b/', '/c/', '/d/', '/e/', '/f/', '/g/'] as $i => $p) {
    for ($k = 0; $k <= $i; $k++) $sm->track($p);
}
eq(5, count($sm->topPages(1)), 'topPages: не больше 5 страниц по умолчанию');
eq('/g/', array_key_first($sm->topPages(1)), 'topPages: первым самый посещаемый');

// ── Журнал: просмотр дописывает строку, а не переписывает агрегат ──
// Раньше каждый просмотр читал и перезаписывал файл целиком (~19 мс на
// заполненном окне), причём и при отдаче страницы из кэша.
$logFile = ROOT_DIR . '/cache/stats-raw.log';
$aggFile = ROOT_DIR . '/cache/stats.php';
$sm->totalViews(1);                                   // свернуть всё накопленное
ok(!is_file($logFile) || filesize($logFile) === 0, 'после чтения журнал свёрнут и пуст');

$aggBefore = (int)@filemtime($aggFile);
$sizeBefore = (int)@filesize($aggFile);
clearstatcache();
$sm->track('/svezhiy/', 'ip|ua');
ok((int)@filesize($logFile) > 0, 'просмотр попал в журнал');
eq($sizeBefore, (int)@filesize($aggFile), 'агрегат при просмотре НЕ переписывается');

// Свежий просмотр обязан быть виден сразу, ещё до свёртки по расписанию
$fresh = new StatsManager();
ok(array_key_exists('/svezhiy/', $fresh->topPages(1, 50)), 'непросвёрнутый просмотр виден при чтении');
ok(!is_file($logFile) || filesize($logFile) === 0, 'чтение свернуло журнал');

// Параллельные просмотры не должны затирать друг друга: раньше это был
// read-modify-write без блокировки, и часть заходов терялась.
$before = $sm2 = null;
$counter = new StatsManager();
$was = $counter->topPages(1, 100)['/parallel/'] ?? 0;
for ($i = 0; $i < 20; $i++) {
    (new StatsManager())->track('/parallel/', "ip-$i|ua");   // разные экземпляры = разные запросы
}
eq($was + 20, (new StatsManager())->topPages(1, 100)['/parallel/'] ?? 0, '20 отдельных просмотров учтены все до одного');

// ────────────────────────────────────────────────────────────
section('MediaManager — валидация загрузки');

$mm = new MediaManager([]);
ok(str_contains($mm->upload(['error' => UPLOAD_ERR_INI_SIZE])['error'] ?? '', 'лимит сервера'), 'код ошибки 1 → «лимит сервера»');
ok(str_contains($mm->upload(['error' => 0, 'size' => 11 * 1024 * 1024, 'name' => 'big.jpg', 'tmp_name' => '/x'])['error'] ?? '', '10 МБ'), 'слишком большой → «больше 10 МБ»');
ok(str_contains($mm->upload(['error' => 0, 'size' => 100, 'name' => 'virus.exe', 'tmp_name' => '/x'])['error'] ?? '', 'Недопустимый тип'), 'плохое расширение → отклонено');

// подделка: .png с текстовым содержимым → MIME не совпадёт
$fake = ROOT_DIR . '/fake.png';
file_put_contents($fake, "это не картинка");
ok(str_contains($mm->upload(['error' => 0, 'size' => filesize($fake), 'name' => 'fake.png', 'tmp_name' => $fake])['error'] ?? '', 'не соответствует'), 'MIME ≠ расширение → отклонено');

// валидный PNG (через GD) → сохранён
$png = ROOT_DIR . '/real.png';
$img = imagecreatetruecolor(4, 4); imagepng($img, $png);
$res = $mm->upload(['error' => 0, 'size' => filesize($png), 'name' => 'Фото Тест.png', 'tmp_name' => $png]);
ok(isset($res['url']) && !isset($res['error']), 'валидный PNG (кириллица+пробел в имени) → загружен');
ok(isset($res['url']) && is_file(ROOT_DIR . $res['url']), 'файл реально сохранён в media/');

// Превью. optimize() отдаёт готовый GD-ресурс в makeThumbnail(), чтобы не
// декодировать файл дважды — проверяем, что превью при этом реально создаётся.
ok(isset($res['thumb']) && $res['thumb'] !== $res['url'], 'PNG: превью сгенерировано (не подменено оригиналом)');
ok(isset($res['thumb']) && is_file(ROOT_DIR . $res['thumb']), 'PNG: файл превью лежит в media/thumbnails/');

// JPEG идёт по своей ветке optimize() (progressive, quality) — тоже с ресурсом.
$jpg = ROOT_DIR . '/real.jpg';
$img = imagecreatetruecolor(8, 6); imagejpeg($img, $jpg);
$resJ = $mm->upload(['error' => 0, 'size' => filesize($jpg), 'name' => 'photo.jpg', 'tmp_name' => $jpg]);
ok(isset($resJ['thumb']) && is_file(ROOT_DIR . $resJ['thumb']), 'JPEG: превью сгенерировано');

// GIF — optimize() выходит на первой же строке и отдаёт null, поэтому
// makeThumbnail() обязан сам прочитать файл с диска. Страховка от того,
// что передача ресурса сломает превью для необрабатываемых форматов.
$gif = ROOT_DIR . '/real.gif';
$img = imagecreatetruecolor(8, 6); imagegif($img, $gif);
$resG = $mm->upload(['error' => 0, 'size' => filesize($gif), 'name' => 'anim.gif', 'tmp_name' => $gif]);
ok(isset($resG['thumb']) && is_file(ROOT_DIR . $resG['thumb']), 'GIF (optimize пропускает): превью всё равно создано');

// ── SVG и ICO: подсказки настроек обещали их для логотипа и favicon,
//    а загрузчик отклонял. Разрешены с 2026-07-20, SVG — только после чистки.
$svgPath = ROOT_DIR . '/logo.svg';
file_put_contents($svgPath, <<<'SVG'
<?xml version="1.0"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="64" height="64">
  <script>alert(document.cookie)</script>
  <rect width="64" height="64" fill="#4F6EF7" onload="alert(1)"/>
  <a xlink:href="javascript:alert(2)"><circle cx="32" cy="32" r="20" fill="#fff"/></a>
  <foreignObject><body xmlns="http://www.w3.org/1999/xhtml">html внутри svg</body></foreignObject>
</svg>
SVG);
$resS  = $mm->upload(['error' => 0, 'size' => filesize($svgPath), 'name' => 'logo.svg', 'tmp_name' => $svgPath]);
$saved = isset($resS['url']) ? (string)@file_get_contents(ROOT_DIR . $resS['url']) : '';
ok(isset($resS['url']) && !isset($resS['error']), 'SVG загружается (логотип/favicon)');
ok($saved !== '' && !str_contains($saved, '<script'), 'SVG: тег script вырезан');
ok($saved !== '' && !str_contains(strtolower($saved), 'onload'), 'SVG: обработчик onload вырезан');
ok($saved !== '' && !str_contains(strtolower($saved), 'javascript:'), 'SVG: ссылка javascript: вырезана');
ok($saved !== '' && !str_contains(strtolower($saved), 'foreignobject'), 'SVG: foreignObject вырезан');
ok(str_contains($saved, '<rect') && str_contains($saved, '#4F6EF7'), 'SVG: сама графика сохранена');
ok(isset($resS['thumb']) && $resS['thumb'] === $resS['url'], 'SVG: превью не генерируется (GD его не читает)');

// Не-XML под видом svg: DOM не разберёт → файл не должен остаться в медиатеке
$badSvg = ROOT_DIR . '/broken.svg';
file_put_contents($badSvg, '<svg><rect'); // оборванная разметка
$resBad = $mm->upload(['error' => 0, 'size' => filesize($badSvg), 'name' => 'broken.svg', 'tmp_name' => $badSvg]);
ok(isset($resBad['error']), 'битый SVG отклонён');

// ICO: содержимое не исполняется, чистка не нужна, превью нет
$icoPath = ROOT_DIR . '/favicon.ico';
file_put_contents($icoPath, "\x00\x00\x01\x00\x01\x00\x10\x10\x00\x00\x01\x00\x20\x00" . str_repeat("\x00", 64));
$resI = $mm->upload(['error' => 0, 'size' => filesize($icoPath), 'name' => 'favicon.ico', 'tmp_name' => $icoPath]);
ok(isset($resI['url']) && !isset($resI['error']), 'ICO загружается (favicon)');

// finfo не знает формат ICO и отдаёт для него application/octet-stream —
// то есть «просто байты». Без проверки сигнатуры под видом иконки прошёл бы
// любой неопознанный бинарник, поэтому заголовок ICONDIR проверяется отдельно.
$junkIco = ROOT_DIR . '/junk.ico';
file_put_contents($junkIco, random_bytes(200));
ok(isset($mm->upload(['error' => 0, 'size' => 200, 'name' => 'junk.ico', 'tmp_name' => $junkIco])['error']),
   'произвольный бинарник под именем .ico отклоняется');
file_put_contents($junkIco, "\x00\x00\x01\x00\x00\x00" . str_repeat("\x00", 64));   // 0 изображений
ok(isset($mm->upload(['error' => 0, 'size' => 70, 'name' => 'empty.ico', 'tmp_name' => $junkIco])['error']),
   'ICO без единого изображения отклоняется');
file_put_contents($junkIco, "\x00\x00\x02\x00\x01\x00\x10\x10\x00\x00\x01\x00\x20\x00" . str_repeat("\x00", 64));
ok(!isset($mm->upload(['error' => 0, 'size' => 78, 'name' => 'cursor.ico', 'tmp_name' => $junkIco])['error']),
   'курсор (тип 2) — валидный ICO, принимается');

// Опасные форматы по-прежнему закрыты
ok(str_contains($mm->upload(['error' => 0, 'size' => 10, 'name' => 'shell.php', 'tmp_name' => '/x'])['error'] ?? '', 'Недопустимый тип'), 'php по-прежнему отклоняется');
ok(str_contains($mm->upload(['error' => 0, 'size' => 10, 'name' => 'page.html', 'tmp_name' => '/x'])['error'] ?? '', 'Недопустимый тип'), 'html по-прежнему отклоняется');

// Файлы появились в списке медиатеки
$names = array_column($mm->all(), 'name');
ok((bool)preg_grep('~\.svg$~', $names), 'SVG виден в медиатеке');
ok((bool)preg_grep('~\.ico$~', $names), 'ICO виден в медиатеке');

// ── Медиатека видит файлы на ЛЮБОЙ глубине ──
// Раньше стояли два жёстких шаблона (корень и ровно media/ГОД/МЕСЯЦ/файл),
// поэтому демо-контент в media/demo/ и всё, залитое по FTP в свою папку,
// в медиатеке не показывалось: ни выбрать в модалке, ни удалить.
@mkdir(ROOT_DIR . '/media/demo', 0755, true);
@mkdir(ROOT_DIR . '/media/2020/01', 0755, true);
@mkdir(ROOT_DIR . '/media/arhiv/staryy-sayt/kartinki', 0755, true);
file_put_contents(ROOT_DIR . '/media/demo/cover.jpg', 'x');
file_put_contents(ROOT_DIR . '/media/2020/01/old.jpg', 'x');
file_put_contents(ROOT_DIR . '/media/arhiv/staryy-sayt/kartinki/deep.png', 'x');
file_put_contents(ROOT_DIR . '/media/thumbnails/demo_cover.jpg', 'x');   // превью — не контент

$urls = array_column($mm->all(), 'url');
ok(in_array('/media/demo/cover.jpg', $urls, true), 'виден файл в подпапке второго уровня (media/demo/)');
ok(in_array('/media/2020/01/old.jpg', $urls, true), 'виден файл в структуре год/месяц');
ok(in_array('/media/arhiv/staryy-sayt/kartinki/deep.png', $urls, true), 'виден файл на четвёртом уровне вложенности');
eq([], array_values(preg_grep('~/media/thumbnails/~', $urls)), 'миниатюры в список медиатеки НЕ попадают');

// Раз файл виден — он должен и удаляться, иначе список врёт пользователю
ok($mm->delete('/media/demo/cover.jpg'), 'файл из подпапки удаляется');
ok(!$mm->delete('/media/thumbnails/demo_cover.jpg'), 'миниатюру напрямую удалить нельзя');
ok(!$mm->delete('/media/../../config.php'), 'выход за пределы /media/ отклоняется');

// ────────────────────────────────────────────────────────────
section('Post::coverSrc — метка ?v для сброса кэша обложек');

// Обложки в /media/ отдаются с 30-дневным кэшем: заменённая картинка (то же
// имя, новое содержимое) месяц показывалась бы из кэша браузера. coverSrc()
// дописывает ?v=<mtime> к <img> в темах. КЛЮЧЕВОЕ: сырой $post->cover при этом
// НЕ меняется — из него берётся og:image/JSON-LD, и метка там нежелательна.
@mkdir(ROOT_DIR . '/media/2026/07', 0755, true);
file_put_contents(ROOT_DIR . '/media/2026/07/cover.jpg', 'x');
$mt = filemtime(ROOT_DIR . '/media/2026/07/cover.jpg');

$coverPost = new Post(['cover' => '/media/2026/07/cover.jpg'], 'body', ROOT_DIR . '/content/posts/x.md');
eq('/media/2026/07/cover.jpg?v=' . $mt, $coverPost->coverSrc(), 'coverSrc: локальному файлу дописана ?v=<mtime>');
eq('/media/2026/07/cover.jpg', $coverPost->cover, 'сырой cover НЕ тронут (для og:image/JSON-LD)');

$mkCover = fn(string $c) => (new Post(['cover' => $c], 'b', ROOT_DIR . '/content/posts/x.md'))->coverSrc();
eq('/media/2026/07/net.jpg', $mkCover('/media/2026/07/net.jpg'), 'несуществующий файл → без метки (не битый ?v=false)');
eq('https://cdn.example/pic.jpg', $mkCover('https://cdn.example/pic.jpg'), 'внешний URL → без метки');
eq('', $mkCover(''), 'пустой cover → пусто');
eq('/assets/logo.png', $mkCover('/assets/logo.png'), 'путь вне /media/ → без метки');
eq('/media/2026/07/cover.jpg?v=' . $mt, $mkCover('/media/2026/07/cover.jpg?x=1'), 'существующая query отбрасывается, ставится своя ?v');

// SeoManager должен взять ЧИСТЫЙ cover в og:image — без ?v
$seo = new SeoManager(['site_url' => 'https://s.ru']);
$seoHtml = $seo->head($coverPost);
ok(str_contains($seoHtml, 'og:image" content="https://s.ru/media/2026/07/cover.jpg"'), 'og:image без метки ?v (чистый URL для соцсетей)');
ok(!preg_match('~og:image[^>]*\?v=~', $seoHtml), 'в og:image нет ?v ни при каких условиях');

// ────────────────────────────────────────────────────────────
section('PostController — сохранение статуса, slug, отложенное');

$pc = new PostController($cms, new Security($cfg), $cfg);
$admin = ['username' => 'admin', 'role' => 'admin'];
$statusOf = function (array $res) use ($cms): ?string {
    if (!isset($res['filename'])) return $res['error'] ?? null;
    $raw = @file_get_contents($cms->postsDir() . $res['filename']);
    return $raw === false ? null : (string)(FrontmatterParser::parse($raw)['meta']['status'] ?? '');
};

eq('draft', $statusOf($pc->save(['type' => 'post', 'title' => 'Черновик тест', 'status' => 'draft', 'content' => 'x'], $admin)), 'draft сохраняется');
eq('sticky', $statusOf($pc->save(['type' => 'post', 'title' => 'Закреп тест', 'status' => 'sticky', 'content' => 'x'], $admin)), 'sticky НЕ теряется (регресс)');
eq('published', $statusOf($pc->save(['type' => 'post', 'title' => 'Опубл тест', 'status' => 'published', 'content' => 'x'], $admin)), 'published сохраняется');
eq('unlisted', $statusOf($pc->save(['type' => 'post', 'title' => 'Вне списков тест', 'status' => 'unlisted', 'content' => 'x'], $admin)), 'unlisted сохраняется');
eq('scheduled', $statusOf($pc->save(['type' => 'post', 'title' => 'Будущее тест', 'status' => 'scheduled', 'scheduled_date' => '2099-01-01T00:00', 'content' => 'x'], $admin)), 'отложенный (будущее) → scheduled');
eq('published', $statusOf($pc->save(['type' => 'post', 'title' => 'Прошлое тест', 'status' => 'scheduled', 'scheduled_date' => '2020-01-01T00:00', 'content' => 'x'], $admin)), 'отложенный (прошлое) → авто-published');
eq('draft', $statusOf($pc->save(['type' => 'post', 'title' => 'Мусор', 'status' => 'invalid-status', 'content' => 'x'], $admin)), 'неизвестный статус → draft (не published)');

$rslug = $pc->save(['type' => 'post', 'title' => 'Мой Новый Пост', 'status' => 'draft', 'content' => 'x'], $admin);
$slugMeta = (string)(FrontmatterParser::parse((string)file_get_contents($cms->postsDir() . $rslug['filename']))['meta']['slug'] ?? '');
eq('moy-novyy-post', $slugMeta, 'slug транслитерируется из заголовка');

ok(isset($pc->save(['type' => 'post', 'title' => '', 'content' => 'x'], $admin)['error']), 'пустой заголовок → ошибка');

// ── Права: author работает только со своими постами ──
// Проверка на уровне контроллера (открытие редактора). Запрет на ЗАПИСЬ чужого
// поста живёт в маршруте admin/index.php (requireOwnPost) и проверяется
// по HTTP в smoke.sh — здесь до него не достать.
$author  = ['username' => 'petya', 'role' => 'author'];
$foreign = $pc->save(['type' => 'post', 'title' => 'Пост админа', 'status' => 'draft', 'content' => 'x'], $admin);
$mine    = $pc->save(['type' => 'post', 'title' => 'Пост автора', 'status' => 'draft', 'content' => 'x'], $author);

ok(!empty($pc->editorData($foreign['filename'], $author, 'post')['denied']), 'editorData: чужой пост автору → denied');
ok(empty($pc->editorData($mine['filename'], $author, 'post')['denied']), 'editorData: свой пост автору открывается');
ok(empty($pc->editorData($foreign['filename'], $admin, 'post')['denied']), 'editorData: админу доступен любой пост');

$mineMeta = FrontmatterParser::parse((string)file_get_contents($cms->postsDir() . $mine['filename']))['meta'];
eq('petya', (string)($mineMeta['author'] ?? ''), 'author пишет под своим именем');
ok(!empty($mineMeta['safe_html']), 'посту не-админа принудительно ставится safe_html');

// ────────────────────────────────────────────────────────────
section('DataFile — атомарная запись и update()');

$dfBase = ROOT_DIR . '/cache/dftest';
$dfPath = $dfBase . '.php';

// Права целевого файла не должны сбрасываться на umask при каждой записи:
// админ мог выставить 600 на config.php, и сохранение настроек не имеет
// права открыть файл всему серверу.
DataFile::write($dfPath, ['a' => 1]);
chmod($dfPath, 0600);
DataFile::write($dfPath, ['a' => 2]);
clearstatcache();
eq('600', substr(sprintf('%o', fileperms($dfPath)), -3), 'права файла сохраняются при перезаписи');

// Нечитаемые данные (ресурс не сериализуется в JSON) не должны обнулять файл:
// раньше file_put_contents записал бы пустоту поверх рабочего config.php.
$fh = fopen('php://memory', 'r');
ok(DataFile::write($dfPath, ['bad' => $fh]) === false, 'несериализуемые данные → write возвращает false');
fclose($fh);
eq(2, DataFile::read($dfPath)['a'] ?? null, 'при неудачной записи прежнее содержимое цело');
eq([], glob(ROOT_DIR . '/cache/*.tmp') ?: [], 'временные файлы не остаются на диске');

// update(): цикл «прочитал → изменил → записал» под блокировкой
DataFile::update($dfBase, fn(array $d) => ['n' => 0]);
for ($i = 0; $i < 25; $i++) {
    DataFile::update($dfBase, function (array $d) { $d['n'] = ($d['n'] ?? 0) + 1; return $d; });
}
[$dfRes] = DataFile::readWithLegacy($dfBase);
eq(25, $dfRes['n'] ?? -1, 'update(): 25 последовательных изменений не теряются');
ok(DataFile::update($dfBase, fn(array $d) => 'не массив') === false, 'update(): мутатор вернул не массив → записи нет');
eq(25, DataFile::readWithLegacy($dfBase)[0]['n'] ?? -1, 'после отказа мутатора данные не изменились');

// Security использует update() — счётчик попыток должен расти монотонно
$secCfg = new Security([]);
for ($i = 0; $i < 3; $i++) $secCfg->registerFailure('10.1.1.1', 'bob');
ok(!$secCfg->isBlocked('10.1.1.1'), '3 попытки — блокировки ещё нет');
for ($i = 0; $i < 7; $i++) $secCfg->registerFailure('10.1.1.1', 'bob');
ok($secCfg->isBlocked('10.1.1.1'), '10 попыток — IP заблокирован (счётчик не терялся)');
$secCfg->clearFailures('10.1.1.1');
ok(!$secCfg->isBlocked('10.1.1.1'), 'clearFailures снимает блокировку');

// ── Задержка по аккаунту (защита от перебора с разных адресов) ──
// Все попытки ниже идут с РАЗНЫХ IP: лимит по адресу их не видит,
// именно этот сценарий (ботнет, IPv6-подсеть) и закрывает задержка.
$secDelay = new Security([]);
eq(0, $secDelay->loginDelay('victim'), 'до попыток задержки нет');
for ($i = 1; $i <= 5; $i++) $secDelay->registerFailure("172.16.0.$i", 'victim');
eq(0, $secDelay->loginDelay('victim'), '5 попыток — ещё без задержки (человек ошибается)');
$secDelay->registerFailure('172.16.0.6', 'victim');
eq(1, $secDelay->loginDelay('victim'), '6-я попытка → задержка 1 с');
$secDelay->registerFailure('172.16.0.7', 'victim');
eq(2, $secDelay->loginDelay('victim'), '7-я → 2 с (растёт)');
for ($i = 8; $i <= 15; $i++) $secDelay->registerFailure("172.16.0.$i", 'victim');
eq(3, $secDelay->loginDelay('victim'), 'задержка упирается в потолок 3 с (не превращается в отказ)');

ok(!$secDelay->isBlocked('172.16.0.9'), 'аккаунт НЕ блокируется: иначе атакующий запер бы владельца');
eq(0, $secDelay->loginDelay('drugoy'), 'счётчик привязан к логину, соседние не страдают');

// Несуществующий логин копит задержку так же — иначе время ответа
// подсказывало бы, какие имена заведены в системе
for ($i = 1; $i <= 8; $i++) $secDelay->registerFailure("172.17.0.$i", 'takogo-net');
eq(3, $secDelay->loginDelay('takogo-net'), 'несуществующий логин ведёт себя одинаково с существующим');

$secDelay->clearFailures('172.16.0.1', 'victim');
eq(0, $secDelay->loginDelay('victim'), 'успешный вход обнуляет задержку');
eq(0, $secDelay->loginDelay(''), 'пустой логин — без задержки и без записи');

// ── Смена пароля помечается временем (SEC-7) ──
// Отметку читает Security::startSession(): сессии, открытые ДО смены,
// завершаются. Сценарий — «сессию увели, владелец сменил пароль».
$pwUser = UserManager::withNewPassword(['username' => 'pw', 'role' => 'author'], 'НовыйПароль123');
ok(($pwUser['password_changed_at'] ?? 0) > 0, 'withNewPassword проставляет отметку времени');
ok(password_verify('НовыйПароль123', $pwUser['password']), 'пароль при этом действительно записан');
ok(!isset($pwUser['password_changed_at']) || $pwUser['password_changed_at'] <= time(), 'отметка не из будущего');

// ── Секретный ключ (SEC-6) ──
ok(Security::secretKeyOk() === is_file(ROOT_DIR . '/system/secret.key'), 'secretKeyOk отражает наличие файла ключа');
$sk1 = Security::appSecret();
ok(strlen($sk1) === 64, 'appSecret вернул 64-символьный ключ');
eq($sk1, Security::appSecret(), 'ключ стабилен между вызовами (иначе ломаются все подписи)');
ok(Security::secretKeyOk(), 'после первого вызова ключ сохранён на диск');
eq('600', substr(sprintf('%o', fileperms(ROOT_DIR . '/system/secret.key')), -3), 'ключ закрыт правами 600');

// ────────────────────────────────────────────────────────────
section('Security — CSRF');

$_SESSION = [];
$sec = new Security($cfg);
$tok = $sec->csrfToken();
ok(strlen($tok) >= 32, 'токен достаточной длины');
eq($tok, $sec->csrfToken(), 'повторный вызов — тот же токен в рамках сессии');
ok($sec->verifyCsrf($tok) === true, 'verifyCsrf: верный токен');
ok($sec->verifyCsrf('поддельный') === false, 'verifyCsrf: неверный токен');
ok($sec->verifyCsrf(null) === false, 'verifyCsrf: null');

// ── Сессии: из админки выбрасывало раньше своего таймаута ──
// PHP чистит файлы сессий по session.gc_maxlifetime (по умолчанию 1440 с),
// а не по SESSION_TIMEOUT, и держит их в общем temp, где чужой сборщик мусора
// на shared-хостинге удаляет их своими сроками. startSession() поднимает срок
// и уводит сессии в свой каталог; Router обязан идти той же дорогой.
// startSession() трогает save_path и заголовки, поэтому проверяем её в отдельном
// процессе: в самом раннере вывод уже начался и PHP менять путь не даст.
$code = 'define("ROOT_DIR", ' . var_export(ROOT_DIR, true) . ');'
      . 'foreach (["Security","DataFile"] as $c) require ' . var_export($REAL . '/system/', true) . ' . $c . ".php";'
      . '(new Security([]))->startSession();'
      . 'echo json_encode(["path" => session_save_path(), "gc" => (int)ini_get("session.gc_maxlifetime")]);';
$out  = (string)@shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
$info = json_decode($out, true) ?: [];

$sessDir = ROOT_DIR . '/system/sessions';
ok(is_dir($sessDir), 'startSession: каталог /system/sessions создаётся');
ok(is_file($sessDir . '/.htaccess'), 'каталог сессий закрыт от HTTP (.htaccess)');
eq('0700', substr(sprintf('%o', fileperms($sessDir)), -4), 'права каталога сессий — 0700');
eq($sessDir, (string)($info['path'] ?? ''), 'сессии живут в своём каталоге, а не в общем temp');
eq(3600, (int)($info['gc'] ?? 0), 'gc_maxlifetime поднят до SESSION_TIMEOUT (1 ч), а не 1440 с');

// Смена User-Agent НЕ должна разлогинивать: эмуляция устройства в DevTools и
// обновление браузера меняют строку UA, а раньше это считалось угоном сессии.
$code2 = 'define("ROOT_DIR", ' . var_export(ROOT_DIR, true) . ');'
       . 'foreach (["Security","DataFile"] as $c) require ' . var_export($REAL . '/system/', true) . ' . $c . ".php";'
       . '$_SERVER["HTTP_USER_AGENT"] = "Mozilla/5.0 (Macintosh) Chrome/140";'
       . '$s = new Security([]); $s->startSession();'
       . '$s->loginUser(["username" => "u", "role" => "admin"]);'
       . '$_SERVER["HTTP_USER_AGENT"] = "Mozilla/5.0 (iPhone) Mobile/15E148";' // DevTools-эмуляция
       . '$s2 = new Security([]); $s2->startSession();'
       . 'echo json_encode(["user" => $s2->currentUser()["username"] ?? null, "ua_hash" => isset($_SESSION["ua_hash"])]);';
$out2  = (string)@shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($code2) . ' 2>/dev/null');
$info2 = json_decode($out2, true) ?: [];
eq('u', $info2['user'] ?? null, 'смена User-Agent (DevTools-эмуляция) не выбрасывает из сессии');
ok(($info2['ua_hash'] ?? true) === false, 'ua_hash в сессию больше не пишется');

// ────────────────────────────────────────────────────────────
section('RedirectManager — цепочки 301');

$rm = new RedirectManager();
$rm->add('/staryy-url/', '/novyy-url/');
eq('/novyy-url/', $rm->find('/staryy-url/'), 'редирект: старый → новый');
ok($rm->find('/net-takogo/') === null, 'неизвестный путь → null');
$rm->add('/a/', '/b/');
$rm->add('/b/', '/c/'); // при добавлении /b/→/c/ старая цель /a/→/b/ должна расплестись в /a/→/c/
eq('/c/', $rm->find('/a/'), 'цепочка расплетена: /a/ ведёт сразу на /c/');
ok($rm->find('/b/') !== '/b/', 'нет само-редиректа');

// ────────────────────────────────────────────────────────────
section('SearchManager — поиск и ранжирование');

writePost('srch', ['title' => 'Уникальный Заголовок', 'status' => 'published', 'tags' => ['findme'], 'date' => '2026-02-01']);
$search = new SearchManager(new ContentManager($cfg));
eq('Уникальный Заголовок', $search->search('уникальный')[0]->title ?? null, 'находит по слову из заголовка');
ok(count($search->search('findme')) >= 1, 'находит по тегу');
eq([], $search->search('несуществующееслово'), 'нет совпадений → пусто');

// ────────────────────────────────────────────────────────────
section('CacheManager — подпись и инвалидация');

$cache = new CacheManager(['cache_enabled' => true]);
ok($cache->isEnabled(), 'isEnabled() при включённом кэше');
$cache->set('k1', 'sig-a', ['v' => 42]);
eq(['v' => 42], $cache->get('k1', 'sig-a'), 'get() с верной подписью');
eq(null, $cache->get('k1', 'sig-b'), 'get() с чужой подписью → null (инвалидация)');
ok(!(new CacheManager(['cache_enabled' => false]))->isEnabled(), 'isEnabled() false при выключенном кэше');

// ────────────────────────────────────────────────────────────
section('BackupManager — ZIP-бэкап');

if (class_exists('ZipArchive')) {
    $bk = new BackupManager();
    $res = $bk->create();
    ok(!isset($res['error']), 'create() без ошибки');
    ok(count($bk->all()) >= 1, 'all(): созданный бэкап в списке');
} else {
    echo "  (расширение zip не установлено — BackupManager пропущен)\n";
}

// ────────────────────────────────────────────────────────────
section('ThemeManager — наследование шаблонов');

@mkdir(ROOT_DIR . '/themes/default', 0755, true);
@mkdir(ROOT_DIR . '/themes/custom', 0755, true);
file_put_contents(ROOT_DIR . '/themes/default/layout.php', "<?php\n");
file_put_contents(ROOT_DIR . '/themes/default/post.php', "<?php\n");
file_put_contents(ROOT_DIR . '/themes/custom/theme.json', '{"name":"custom"}');
file_put_contents(ROOT_DIR . '/themes/custom/layout.php', "<?php\n");
$tm = new ThemeManager(['theme' => 'custom']);
ok(str_ends_with((string)$tm->resolve('layout'), 'custom/layout.php'), 'resolve: свой файл активной темы');
ok(str_ends_with((string)$tm->resolve('post'), 'default/post.php'), 'resolve: недостающий файл наследуется из default');
eq(null, $tm->resolve('nonexistent'), 'resolve: нет нигде → null');

// Имя шаблона приходит из поля «Шаблон» редактора, то есть его задаёт автор
// поста. Без проверки render() подключил бы посторонний .php вне темы —
// например /install.php. Приманка кладётся ровно туда, куда указывал бы
// './themes/custom/../../bait.php', поэтому тест краснеет, если проверку убрать.
// Приманка нужна под КАЖДЫЙ случай: без реального файла по целевому пути
// resolve() вернул бы null и без всякой защиты, и тест был бы зелёным впустую.
@mkdir(ROOT_DIR . '/themes/custom/sub', 0755, true);
file_put_contents(ROOT_DIR . '/bait.php', "<?php\n");                      // ../../bait
file_put_contents(ROOT_DIR . '/themes/bait.php', "<?php\n");               // ../bait
file_put_contents(ROOT_DIR . '/themes/custom/sub/tpl.php', "<?php\n");     // sub/tpl
file_put_contents(ROOT_DIR . '/themes/custom/.php', "<?php\n");            // пустое имя
eq(null, $tm->resolve('../../bait'), 'resolve: выход за пределы темы → null');
eq(null, $tm->resolve('../bait'), 'resolve: путь вверх → null');
eq(null, $tm->resolve('sub/tpl'), 'resolve: подкаталог запрещён');
eq(null, $tm->resolve(''), 'resolve: пустое имя → null');

// ────────────────────────────────────────────────────────────
section('Csp — политика безопасности публичной части');

// Отпечаток должен совпадать с тем, что считает браузер: sha256 от точного
// содержимого между тегами, base64. Эталон получен независимо (openssl).
// Кавычки вокруг отпечатка обязательны по грамматике CSP. Без них браузер
// не распознаёт токен, молча игнорирует и БЛОКИРУЕТ скрипт — на сайте это
// выглядит как «переключатель темы перестал работать», без ошибок в консоли.
// Поймано только проверкой в браузере, поэтому проверяется точным сравнением.
eq(["'sha256-gaaMFNHZyRta8zB2VHkWLMP4tMxJ+d8v3dTW7nw2r6M='"], Csp::scriptHashes('<script>var a=1;</script>'), 'отпечаток инлайн-скрипта: эталон + обязательные кавычки');
eq([], Csp::scriptHashes('<script src="/main.js"></script>'), 'внешний скрипт отпечатка не требует (его покрывает self)');
eq([], Csp::scriptHashes('<script type="application/ld+json">{"a":1}</script>'), 'JSON-LD пропускается — браузер его не исполняет');
eq(1, count(Csp::scriptHashes('<script>x</script><script>x</script>')), 'одинаковые скрипты дают один отпечаток');
eq(2, count(Csp::scriptHashes('<script>a</script><script>b</script>')), 'разные скрипты — разные отпечатки');

eq(['https://mc.yandex.ru'], Csp::allowedHosts(['external_scripts' => 'mc.yandex.ru']), 'домен без схемы → https');
eq(['https://www.googletagmanager.com'], Csp::allowedHosts(['external_scripts' => 'https://www.googletagmanager.com/gtag/js?id=X']), 'из полного адреса берётся только хост');
eq([], Csp::allowedHosts(['external_scripts' => '']), 'пустая настройка → пусто');
eq([], Csp::allowedHosts(['external_scripts' => "*\n' unsafe-inline'"]), 'подстановки и кавычки отбрасываются');
eq(['https://a.ru', 'https://b.ru'], Csp::allowedHosts(['external_scripts' => "a.ru\nb.ru\na.ru"]), 'дубли схлопываются, порядок сохраняется');

eq('https://deeno.tech', Csp::ownOrigin(['site_url' => 'https://Deeno.Tech/blog/']), 'ownOrigin: схема+хост, без пути, в нижнем регистре');
eq('', Csp::ownOrigin(['site_url' => 'не адрес']), 'ownOrigin: мусор → пусто');

$cspH = Csp::header(['site_url' => 'https://deeno.tech', 'external_scripts' => 'mc.yandex.ru'], '<script>x</script>');
ok(str_contains($cspH, "script-src 'self' https://deeno.tech 'sha256-"), 'script-src: свои + отпечаток в кавычках');
ok((bool)preg_match("~script-src[^;]*'sha256-[A-Za-z0-9+/]+={0,2}'~", $cspH), 'отпечаток в script-src имеет корректный синтаксис CSP');
ok(str_contains($cspH, 'https://mc.yandex.ru'), 'script-src: разрешённый домен счётчика');
// Именно директива script-src, а не весь хвост заголовка: в style-src
// 'unsafe-inline' присутствует законно и не должен путать проверку.
preg_match('~script-src([^;]*)~', $cspH, $mScript);
ok(!str_contains((string)($mScript[1] ?? ''), "'unsafe-inline'"), "script-src БЕЗ 'unsafe-inline' — главный смысл политики");
ok(str_contains($cspH, "style-src 'self' https://deeno.tech 'unsafe-inline'"), "style-src сохраняет 'unsafe-inline' (инлайн-стили тем)");
ok(str_contains($cspH, 'https://www.youtube-nocookie.com') && str_contains($cspH, 'https://player.vimeo.com'), 'frame-src: видео YouTube/Vimeo не сломано');
ok(str_contains($cspH, "object-src 'none'") && str_contains($cspH, "base-uri 'self'"), 'object-src и base-uri закрыты');

// ────────────────────────────────────────────────────────────
section('PluginManager — список и включённые');

@mkdir(ROOT_DIR . '/plugins/pa', 0755, true);
@mkdir(ROOT_DIR . '/plugins/pb', 0755, true);
file_put_contents(ROOT_DIR . '/plugins/pa/plugin.json', '{"name":"Плагин A"}');
file_put_contents(ROOT_DIR . '/plugins/pa/plugin.php', "<?php\n");
file_put_contents(ROOT_DIR . '/plugins/pb/plugin.json', '{"name":"Плагин B"}');
file_put_contents(ROOT_DIR . '/plugins/pb/plugin.php', "<?php\n");
$all = PluginManager::all();
ok(isset($all['pa']) && isset($all['pb']), 'all(): оба плагина в списке');
eq(['pa'], array_values(PluginManager::enabled(['plugins' => ['pa']])), 'enabled(): фильтр по config');

// ────────────────────────────────────────────────────────────
section('CategoryManager — порядок, даты, сортировка');

$cm = new CategoryManager();
$cm->save('guide', 'Руководство', '', 2, '/media/i.svg');
usleep(1000);
$cm->save('intro', 'Введение', '', 1);
$g = $cm->get('guide');
ok($g['position'] === 2 && $g['created'] !== '' && $g['modified'] !== '', 'save(): position + created/modified записаны');
ok($g['icon'] === '/media/i.svg', 'save(): icon записан');
$cm->save('guide', 'Руководство 2', '', 5);
ok($cm->get('guide')['created'] === $g['created'], 'created сохраняется при повторной правке');
ok($cm->get('guide')['position'] === 5, 'position обновляется');

eq(['intro', 'guide'], $cm->ordered(['guide', 'intro'], 'manual'), 'ordered manual: position 1 (intro), 5 (guide)');
eq(['guide', 'intro'], $cm->ordered(['guide', 'intro'], 'created'), 'ordered created: guide создан раньше intro');
eq(['intro', 'guide'], $cm->ordered(['guide', 'intro'], 'alpha'), 'ordered alpha: «Введение» < «Руководство 2»');

$cm->setPosition('guide', 0);
ok($cm->get('guide')['position'] === 0, 'setPosition(): позиция обновлена');
ok($cm->get('guide')['title'] === 'Руководство 2', 'setPosition(): остальные поля не тронуты');
$cm->setPosition('net-takoy', 3);
ok(!$cm->exists('net-takoy'), 'setPosition() по несуществующему slug: no-op');

// ────────────────────────────────────────────────────────────
section('ContentManager — расстановка (drag-and-drop): updatePostMeta, orderPostsBy');

$cms->updatePostMeta('a.md', ['position' => 3, 'category' => 'tech']);
$moved = $cms->postByFilename('a.md');
ok($moved !== null && $moved->position === 3 && $moved->category === 'tech', 'updatePostMeta(): position + category перезаписаны');
ok(str_contains((string)file_get_contents(ROOT_DIR . '/content/posts/a.md'), 'Тело поста.'), 'updatePostMeta(): тело поста сохранено');
ok($cms->updatePostMeta('../evil.md', ['position' => 1]) === false, 'updatePostMeta(): path traversal отклонён');
ok($cms->updatePostMeta('net-takogo.md', ['position' => 1]) === false, 'updatePostMeta(): несуществующий файл → false');
$cms->updatePostMeta('a.md', ['position' => 0, 'category' => 'novosti']); // вернуть

$p2 = fn(string $s, int $pos, string $title) => new Post(['slug' => $s, 'position' => $pos, 'title' => $title], 'x', "$s.md");
$list = [$p2('x', 2, 'Бета'), $p2('y', 0, 'Альфа'), $p2('z', 1, 'Гамма')];
eq(['y', 'z', 'x'], array_map(fn($p) => $p->slug, ContentManager::orderPostsBy($list, 'manual')), 'orderPostsBy manual: по position 0,1,2');
eq(['y', 'x', 'z'], array_map(fn($p) => $p->slug, ContentManager::orderPostsBy($list, 'alpha')), 'orderPostsBy alpha: Альфа<Бета<Гамма');

// ────────────────────────────────────────────────────────────
echo "\n";
if ($GLOBALS['__f'] === 0) {
    echo "\033[32mManagers: все {$GLOBALS['__t']} проверок прошли ✓\033[0m\n";
    exit(0);
}
echo "\033[31mManagers: {$GLOBALS['__f']} из {$GLOBALS['__t']} упали ✗\033[0m\n";
exit(1);
