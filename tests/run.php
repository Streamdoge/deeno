<?php
declare(strict_types=1);

/**
 * Лёгкий тест-раннер deeno — без composer/phpunit (в духе проекта).
 * Запуск: php tests/run.php   (код возврата 0 — все прошли, 1 — есть падения).
 *
 * Покрывает чистую логику ядра: парсер frontmatter, safe-mode Markdown,
 * slugger. Файловые операции (загрузка медиа) здесь не гоняем.
 */

define('ROOT_DIR', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    $file = ROOT_DIR . '/system/' . $class . '.php';
    if (is_file($file)) require_once $file;
});

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function ok(bool $cond, string $msg): void
{
    $GLOBALS['__tests']++;
    if ($cond) {
        echo "  \033[32m✓\033[0m $msg\n";
    } else {
        $GLOBALS['__fails']++;
        echo "  \033[31m✗ $msg\033[0m\n";
    }
}

function eq($expected, $actual, string $msg): void
{
    ok($expected === $actual, $msg . ($expected === $actual ? '' : " (ожидалось " . var_export($expected, true) . ", получено " . var_export($actual, true) . ")"));
}

function section(string $name): void { echo "\n\033[1m$name\033[0m\n"; }

// ────────────────────────────────────────────────────────────
section('FrontmatterParser');

$parsed = FrontmatterParser::parse("---\ntitle: Привет\ndraft: true\nviews: 42\ntags: [php, cms]\n---\nТекст поста");
eq('Привет', $parsed['meta']['title'] ?? null, 'строковое поле');
eq(true, $parsed['meta']['draft'] ?? null, 'булево true');
eq(42, $parsed['meta']['views'] ?? null, 'целое число');
eq(['php', 'cms'], $parsed['meta']['tags'] ?? null, 'inline-массив');
eq('Текст поста', trim($parsed['body']), 'тело отделено от шапки');

$noFm = FrontmatterParser::parse("Просто текст без шапки");
eq([], $noFm['meta'], 'без frontmatter — пустая мета');
eq('Просто текст без шапки', $noFm['body'], 'без frontmatter — тело целиком');

// CRLF-переводы строк (Windows/FTP) не должны ломать разбор
$crlf = FrontmatterParser::parse("---\r\ntitle: Привет\r\nviews: 7\r\n---\r\nТело");
eq('Привет', $crlf['meta']['title'] ?? null, 'CRLF: строковое поле');
eq(7, $crlf['meta']['views'] ?? null, 'CRLF: число');
eq('Тело', trim($crlf['body']), 'CRLF: тело отделено');

// «---» внутри значения не обрывает шапку раньше времени
$dashes = FrontmatterParser::parse("---\ntitle: a --- b\nviews: 3\n---\nТело поста");
eq('a --- b', $dashes['meta']['title'] ?? null, '«---» в значении сохранено');
eq(3, $dashes['meta']['views'] ?? null, 'поле после «---» в значении прочитано');
eq('Тело поста', trim($dashes['body']), 'тело не обрезано на «---» в значении');

// Горизонтальная линия «---» в теле остаётся в теле
$hr = FrontmatterParser::parse("---\ntitle: T\n---\nПеред\n\n---\n\nПосле");
eq('T', $hr['meta']['title'] ?? null, 'HR в теле: шапка прочитана');
ok(str_contains($hr['body'], "---"), 'HR в теле: разделитель остался в теле');

// ────────────────────────────────────────────────────────────
section('Настройки — формат даты и соцсети');

$pd = new Post(['date' => '2026-07-17'], '', 'x.md');
eq('17.07.2026', $pd->date(), 'дата: формат по умолчанию d.m.Y');
$pd->dateFormat = 'Y-m-d';
eq('2026-07-17', $pd->date(), 'дата: формат из настроек применяется без аргумента');
eq('17/07/2026', $pd->date('d/m/Y'), 'дата: явный аргумент перекрывает настройку');

ok(str_contains(SocialIcons::svg('telegram'), '<svg'), 'соцсеть: известная сеть → SVG');
eq('', SocialIcons::svg('myspace'), 'соцсеть: неизвестная сеть → пусто');

$pu = new Post(['slug' => 'moya-statya'], '', 'x.md');
$pu->siteUrl = 'https://s.ru';
eq('https://s.ru/posts/moya-statya/', $pu->url(), 'URL без категории → /posts/ (DEFAULT_CATEGORY)');
$pu->category = 'novosti';
eq('https://s.ru/novosti/moya-statya/', $pu->url(), 'URL с категорией → /категория/');

// ────────────────────────────────────────────────────────────
section('FrontmatterSerializer — round-trip и правила');

$m0 = [
    'title'  => 'Мой пост',
    'slug'   => 'moy-post',
    'status' => 'published',
    'author' => 'admin',
    'tags'   => ['php', 'cms', 'тест'],
    'views'  => 42,
    'draft'  => true,
    'empty'  => '',            // пустое — не должно записаться
    'custom_fields' => ['color' => 'red'],
];
$serial = FrontmatterSerializer::serialize($m0, "Тело поста.\n\nВторой абзац.");
$rt = FrontmatterParser::parse($serial);
eq('Мой пост', $rt['meta']['title'] ?? null, 'round-trip: строка');
eq(['php', 'cms', 'тест'], $rt['meta']['tags'] ?? null, 'round-trip: массив (в т.ч. кириллица)');
eq(42, $rt['meta']['views'] ?? null, 'round-trip: целое');
eq(true, $rt['meta']['draft'] ?? null, 'round-trip: булево');
eq('red', $rt['meta']['custom_fields']['color'] ?? null, 'round-trip: подключ custom_fields');
ok(!array_key_exists('empty', $rt['meta']), 'пустое поле не сериализуется');
ok(str_starts_with(trim($rt['body']), 'Тело поста.'), 'round-trip: тело сохранено');

// ────────────────────────────────────────────────────────────
section('Post — статусы, custom, more, excerpt');

$sticky = new Post(['status' => 'sticky', 'custom_fields' => ['color' => 'red']], 'body', 'x.md');
ok($sticky->isSticky(), 'isSticky: sticky → true');
eq('red', $sticky->custom('color'), 'custom(): значение из custom_fields');
eq(null, $sticky->custom('nope'), 'custom(): отсутствующий ключ → null');
ok(!(new Post(['status' => 'published'], 'b', 'x.md'))->isSticky(), 'isSticky: published → false');

// Признак, по которому админка решает: заголовок ведёт на сайт или в редактор
// (черновик и отложенный публично отдают 404 — см. Router::isPubliclyVisible)
foreach (['published', 'sticky', 'unlisted'] as $st) {
    ok((new Post(['status' => $st], 'b', 'x.md'))->isPubliclyVisible(), "isPubliclyVisible: $st → true");
}
foreach (['draft', 'scheduled'] as $st) {
    ok(!(new Post(['status' => $st], 'b', 'x.md'))->isPubliclyVisible(), "isPubliclyVisible: $st → false");
}

ok((new Post([], "Анонс\n\n<!--more-->\n\nОстальное", 'x.md'))->hasMore(), 'hasMore: <!--more--> → true');
ok(!(new Post([], "Без разрыва", 'x.md'))->hasMore(), 'hasMore: нет разрыва → false');
eq('Явный анонс', (new Post(['excerpt' => 'Явный анонс'], 'Тело', 'x.md'))->excerpt(), 'excerpt(): явное поле в приоритете');

// ────────────────────────────────────────────────────────────
section('MarkdownParser — safe mode (XSS)');

$xss = [
    '<script>alert(1)</script>',
    '<img src=x onerror="alert(1)">',
    '<svg onload=alert(1)>',
    '[click](javascript:alert(1))',
    '![x](javascript:alert(1))',
    '<iframe src="javascript:alert(1)"></iframe>',
    '<body onload=alert(1)>',
    '[x](data:text/html,<script>alert(1)</script>)',
    '<input autofocus onfocus=alert(1)>',
    '<details open ontoggle=alert(1)>',
    '<div style="background:url(javascript:alert(1))">x</div>',
    '<a href="jav&#x09;ascript:alert(1)">x</a>',
];
foreach ($xss as $payload) {
    $html = MarkdownParser::toHtml($payload, true);
    $doc  = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    $danger = false;
    foreach ($doc->getElementsByTagName('*') as $el) {
        $tag = strtolower($el->nodeName);
        if (in_array($tag, ['script', 'iframe', 'object', 'embed', 'svg'], true)) $danger = true;
        foreach ($el->attributes ?? [] as $a) {
            $an = strtolower($a->nodeName);
            $av = strtolower(trim(html_entity_decode((string)$a->nodeValue)));
            if (str_starts_with($an, 'on')) $danger = true;
            if (in_array($an, ['href', 'src'], true) && (str_starts_with($av, 'javascript:') || str_starts_with($av, 'data:text/html'))) $danger = true;
        }
    }
    ok(!$danger, 'обезврежен: ' . substr($payload, 0, 40));
}

// Админский (доверенный) контент — сырой HTML намеренно проходит
$trusted = MarkdownParser::toHtml('<div class="hero">OK</div>', false);
ok(str_contains($trusted, '<div class="hero">'), 'safe=false — сырой HTML сохранён (для admin)');

// ────────────────────────────────────────────────────────────
section('MarkdownParser — свои конструкции (выделение/цвет/выравнивание/видео)');

ok(str_contains(MarkdownParser::toHtml('==важное=='), '<mark>важное</mark>'), 'выделение ==...== → <mark>');
ok(str_contains(MarkdownParser::toHtml('{red:текст}'), '<span class="c-red">текст</span>'), 'цвет {red:...} → span.c-red');
ok(!str_contains(MarkdownParser::toHtml('{неизвестный:x}'), '<span'), 'цвет вне палитры не конвертируется');
ok(str_contains(MarkdownParser::toHtml(":::center\nтекст\n:::"), '<div class="md-center">'), 'выравнивание ::: center → div.md-center');
$vid = MarkdownParser::toHtml('https://youtu.be/dQw4w9WgXcQ');
ok(str_contains($vid, '<iframe') && str_contains($vid, 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'ссылка YouTube → безопасный iframe');
// Конструкции работают и в safe mode (генерирует их наш код, а не пользователь)
ok(str_contains(MarkdownParser::toHtml('==акцент==', true), '<mark>акцент</mark>'), 'выделение работает в safe mode (author)');

// ────────────────────────────────────────────────────────────
section('Живой предпросмотр — санитизация по роли (safe_html)');

// handleLivePreview ставит safe_html = (роль !== admin) и отдаёт тело в Post.
// Проверяем сам механизм, на который он опирается.
$pAuthor = new Post(['safe_html' => true], '<script>alert(1)</script>', 'preview.md');
ok(!str_contains($pAuthor->content(), '<script>'), 'author (safe_html=true) — <script> экранирован в предпросмотре');

$pAdmin = new Post(['safe_html' => false], '<div class="x">ok</div>', 'preview.md');
ok(str_contains($pAdmin->content(), '<div class="x">'), 'admin (safe_html=false) — сырой HTML в предпросмотре сохранён');

// Тело передано в конструктор — файл preview.md не читается с диска
ok($pAuthor->rawBody() === '<script>alert(1)</script>', 'тело берётся из памяти, диск не читается');

// ────────────────────────────────────────────────────────────
section('Slugger');

eq('privet-mir', Slugger::make('Привет, мир!'), 'транслит + дефисы');
eq('hello-world', Slugger::make('  Hello   World  '), 'пробелы схлопываются');
eq('test-123', Slugger::make('Test_123'), 'подчёркивание → дефис');

// ────────────────────────────────────────────────────────────
section('SeoManager — мета-теги в <head>');

$_SERVER['HTTP_HOST']   = 's.ru';
$_SERVER['REQUEST_URI'] = '/novosti/hello/';
$seo = new SeoManager(['site_title' => 'Мой сайт', 'site_description' => 'Описание сайта', 'site_url' => 'https://s.ru']);

$sp = new Post(['title' => 'Заголовок поста', 'excerpt' => 'Анонс поста', 'category' => 'novosti', 'date' => '2026-01-01'], 'Тело', 'x.md');
$sp->siteUrl = 'https://s.ru';
$h = $seo->head($sp, []);
ok(str_contains($h, '<title>Заголовок поста / Мой сайт</title>'), 'title поста + название сайта');
ok(str_contains($h, 'name="description"') && str_contains($h, 'Анонс поста'), 'description из анонса');
ok(str_contains($h, '<meta property="og:type" content="article">'), 'og:type=article для поста');
ok(str_contains($h, 'application/ld+json') && str_contains($h, 'BlogPosting'), 'JSON-LD BlogPosting');
ok(str_contains($h, 'content="index,follow"'), 'обычный пост индексируется');

$hHome = $seo->head(null, []);
ok(str_contains($hHome, '<meta property="og:type" content="website">'), 'og:type=website на не-посте');
ok(str_contains($hHome, 'WebSite'), 'JSON-LD WebSite на не-посте');

$np = new Post(['title' => 'Скрытый', 'seo_noindex' => true], '', 'x.md');
ok(str_contains($seo->head($np, []), 'content="noindex,nofollow"'), 'seo_noindex → noindex,nofollow');

// Favicon: <link rel="icon"> выводится только когда задан в настройках
ok(!str_contains($seo->head(null, []), 'rel="icon"'), 'без настройки favicon ссылки нет');
$seoFav = new SeoManager(['site_url' => 'https://s.ru', 'favicon' => '/media/fav.png']);
ok(str_contains($seoFav->head(null, []), '<link rel="icon" href="/media/fav.png">'), 'favicon → <link rel="icon"> с путём');

// ────────────────────────────────────────────────────────────
section('Сортировка статей — ContentManager::orderPostsBy');

$mkPost = fn(string $title, int $pos, string $date, string $slug): Post =>
    new Post(['title' => $title, 'position' => $pos, 'date' => $date, 'slug' => $slug], '', $slug . '.md');
$docPosts = [
    $mkPost('Гамма', 3, '2026-01-03', 'gamma'),
    $mkPost('Альфа', 1, '2026-01-01', 'alpha'),
    $mkPost('Бета',  2, '2026-01-02', 'beta'),
];
$slugsOf = fn(array $ps): array => array_map(fn(Post $p) => $p->slug, $ps);

eq(['alpha', 'beta', 'gamma'], $slugsOf(ContentManager::orderPostsBy($docPosts, 'manual')), 'manual: по position 1,2,3');
eq(['alpha', 'beta', 'gamma'], $slugsOf(ContentManager::orderPostsBy($docPosts, 'created')), 'created: по дате');
eq(['Альфа', 'Бета', 'Гамма'], array_map(fn(Post $p) => $p->title, ContentManager::orderPostsBy($docPosts, 'alpha')), 'alpha: по заголовку');

$pIcon = new Post(['title' => 'X', 'icon' => '🚀'], '', 'x.md');
ok($pIcon->icon === '🚀', 'Post: поле icon читается');
ok((new Post(['title' => 'Y'], '', 'y.md'))->icon === '', 'Post: без icon — пусто');
ok(str_contains(FrontmatterSerializer::serialize(['title' => 'X', 'icon' => '🚀'], 'b'), 'icon: 🚀'), 'serialize: icon в шапке');

// ────────────────────────────────────────────────────────────
section('Локализация — полнота словаря en.json');

/**
 * Каждая строка, обёрнутая в t(), должна иметь перевод: иначе английский
 * интерфейс показывает русские подписи (так было с настройками логотипа,
 * иконок и порядка разделов). Ключи собираем прямо из исходников админки
 * и ядра. Граница (?<![A-Za-z0-9_]) отсекает совпадения внутри exit(/print(.
 */
$collectKeys = function (array $dirs): array {
    $keys = [];
    foreach ($dirs as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') continue;
            $src = (string)file_get_contents($file->getPathname());
            if (preg_match_all('/(?<![A-Za-z0-9_])t\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $src, $m)) {
                foreach ($m[1] as $k) $keys[stripslashes($k)] = true;
            }
            if (preg_match_all('/(?<![A-Za-z0-9_])t\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $src, $m)) {
                foreach ($m[1] as $k) $keys[stripslashes($k)] = true;
            }
        }
    }
    return array_keys($keys);
};

$enFile = ROOT_DIR . '/system/lang/en.json';
$en     = json_decode((string)file_get_contents($enFile), true);
ok(is_array($en) && $en !== [], 'en.json читается и не пуст');

$used    = $collectKeys([ROOT_DIR . '/admin', ROOT_DIR . '/system']);
$missing = array_values(array_filter($used, fn(string $k) => !array_key_exists($k, (array)$en)));
ok($missing === [], 'en.json: перевод есть у всех строк t()'
    . ($missing === [] ? '' : ' — нет: ' . implode(' | ', array_slice($missing, 0, 10))));

// Обратной проверки (нет ли в словаре лишнего) намеренно нет: часть строк
// уходит в t() не литералом — месяцы из массива, тексты ошибок менеджеров, —
// и любой такой детектор давал бы ложные срабатывания.

// Сообщения контроллеров переводятся через Lang::t — она обязана работать и
// там, где админки нет (тесты, CLI): тогда возвращается исходная строка.
unset($GLOBALS['ffcLang']);
eq('Заголовок обязателен.', Lang::t('Заголовок обязателен.'), 'Lang::t без админки → исходная строка');
$GLOBALS['ffcLang'] = new Lang('en');
eq('Title is required.', Lang::t('Заголовок обязателен.'), 'Lang::t с en → перевод');
eq('Неизвестная строка', Lang::t('Неизвестная строка'), 'Lang::t: нет перевода → исходная строка');
unset($GLOBALS['ffcLang']);

// ────────────────────────────────────────────────────────────
echo "\n";
$t = $GLOBALS['__tests']; $f = $GLOBALS['__fails'];
if ($f === 0) {
    echo "\033[32mВсе $t проверок прошли ✓\033[0m\n";
    exit(0);
}
echo "\033[31m$f из $t проверок упали ✗\033[0m\n";
exit(1);
