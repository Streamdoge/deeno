<?php
declare(strict_types=1);

/**
 * Разбор URL и диспетчеризация запросов.
 * Алгоритм (раздел 7.3 ТЗ):
 *  1. /rss.xml / /sitemap.xml
 *  2. /tag/...
 *  3. /admin/...
 *  4. /media/...
 *  5. Один сегмент → искать в pages, иначе как категория
 *  6. Два сегмента → пост по category + slug
 *  7. 404
 */
class Router
{
    private array $config;
    private ContentManager $cms;
    private ThemeManager $theme;
    private ?CacheManager $cache;
    // Фронт-сессия вошедшего пользователя (мемоизация, чтобы session_start один раз)
    private bool $frontChecked = false;
    private ?array $frontUser = null;
    private ?Security $frontSecurity = null;

    public function __construct(array $config, ContentManager $cms, ThemeManager $theme, ?CacheManager $cache = null)
    {
        $this->config = $config;
        $this->cms    = $cms;
        $this->theme  = $theme;
        $this->cache  = $cache;
    }

    public function dispatch(): void
    {
        // Режим обслуживания: гостю — 503 и exit внутри handleMaintenance;
        // вошедшему админу метод просто возвращается, и обработка продолжается
        // как обычно (иначе у админа был бы пустой экран и лок-аут из настроек).
        if (!empty($this->config['maintenance_mode'])) {
            $this->handleMaintenance();
        }

        $uri = $this->getUri();
        $segments = $this->parseSegments($uri);

        if ($this->servePageCache($uri, $segments)) {
            return;
        }

        // Мимо кэша страница выводится потоком, а для CSP нужен готовый HTML:
        // отпечатки инлайн-скриптов считаются по содержимому страницы (см. Csp).
        // Буфер нужен только ради этого и снимается сразу.
        if ($this->cspApplies($segments)) {
            ob_start();
            $this->route($uri, $segments);
            $html = (string)ob_get_clean();
            $this->sendCsp($html);
            echo $html;
        } else {
            $this->route($uri, $segments);
        }
        $this->trackIfCountable($uri);
    }

    /**
     * Нужен ли нашей политике этот маршрут.
     * Админка шлёт свой строгий CSP с nonce (её страницы не кэшируются) —
     * перекрывать его фронтовым нельзя: header() затрёт админский.
     * Медиа и фиды — не HTML, скриптов там нет.
     */
    private function cspApplies(array $segments): bool
    {
        $first     = $segments[0] ?? '';
        $adminPath = (string)($this->config['admin_path'] ?? 'admin');
        return !in_array($first, [$adminPath, 'media', 'rss.xml', 'sitemap.xml'], true);
    }

    /** Отправить Content-Security-Policy, собранный под конкретную страницу */
    private function sendCsp(string $html): void
    {
        if (headers_sent()) return;
        header('Content-Security-Policy: ' . Csp::header($this->config, $html));
    }

    /**
     * Статистика просмотров (раздел 10.1 ТЗ): только успешные GET-ответы
     * контентных страниц от гостей-людей. Никаких персональных данных.
     */
    private function trackIfCountable(string $uri): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        if (http_response_code() !== 200) return;

        // Служебные разделы не считаем
        $adminPath = (string)($this->config['admin_path'] ?? 'admin');
        $first = explode('/', trim($uri, '/'))[0] ?? '';
        if (in_array($first, [$adminPath, 'media', 'preview', 'search', 'rss.xml', 'sitemap.xml'], true)) {
            return;
        }

        // Вошедшие в админку и боты не считаются
        if (!empty($_COOKIE[session_name()])) return;
        $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|curl|wget|httpclient|python/', $ua)) {
            return;
        }

        // IP + UA уходят в StatsManager только как сырьё для суточного хэша —
        // ни то, ни другое в файл статистики не попадает (см. шапку класса)
        $visitor = (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $ua;

        (new StatsManager())->track($uri, $visitor);
    }

    /**
     * Полностраничный HTML-кэш для гостей.
     * Возвращает true, если ответ отдан (из кэша или отрендерен и закэширован).
     */
    private function servePageCache(string $uri, array $segments): bool
    {
        if ($this->cache === null || !$this->cache->isEnabled()) return false;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;

        $first     = $segments[0] ?? '';
        $adminPath = $this->config['admin_path'] ?? 'admin';
        // Мимо кэша: админка, медиа, RSS/sitemap (свои заголовки), авторизованные
        if ($first === $adminPath || $first === 'media') return false;
        if ($first === 'rss.xml' || $first === 'sitemap.xml') return false;
        // Предпросмотр и поиск зависят от query string, а ключ кэша — только путь
        if ($first === 'preview' || $first === 'search') return false;
        if (!empty($_COOKIE[session_name()])) return false;
        // Пока есть отложенные посты, кэш неактуален по времени, а не по файлам
        if ($this->cms->hasScheduled()) return false;

        $sig = $this->cms->contentSignature($this->theme->signatureDirs());
        // Ключ кэша учитывает язык интерфейса (кука deeno_lang), чтобы RU- и
        // EN-версии страницы не перезатирали друг друга в кэше.
        $lang     = (string)($_COOKIE['deeno_lang'] ?? '');
        $cacheKey = in_array($lang, ['ru', 'en'], true) ? $uri . '@' . $lang : $uri;
        $hit = $this->cache->getPage($cacheKey, $sig);
        if ($hit !== null) {
            http_response_code((int)$hit['status']);
            // Отпечатки считаются по тому же HTML, что уходит посетителю, —
            // поэтому закэшированная страница получает корректный CSP без
            // хранения чего-либо дополнительного рядом с кэшем.
            $this->sendCsp((string)$hit['html']);
            echo $hit['html'];
            $this->trackIfCountable($uri);
            return true;
        }

        ob_start();
        $this->route($uri, $segments);
        $html = (string)ob_get_clean();
        $this->sendCsp($html);
        echo $html;

        // Кэшируем только успешные ответы: 404 не пишем,
        // чтобы перебором мусорных URL нельзя было забить диск
        if (http_response_code() === 200 && $html !== '') {
            $this->cache->setPage($cacheKey, $sig, 200, $html);
        }
        $this->trackIfCountable($uri);
        return true;
    }

    // ----------------------------------------------------------------

    private function getUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        // Убираем query string
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }
        // Нормализация: trailing slash
        if ($uri !== '/' && !str_ends_with($uri, '/')) {
            $uri .= '/';
        }
        return $uri;
    }

    private function parseSegments(string $uri): array
    {
        $trimmed = trim($uri, '/');
        if ($trimmed === '') return [];
        return explode('/', $trimmed);
    }

    private function route(string $uri, array $segments): void
    {
        $count = count($segments);

        // 1. Служебные файлы
        if ($uri === '/rss.xml/' || $uri === '/rss.xml') {
            $this->handleRss();
            return;
        }
        if ($uri === '/sitemap.xml/' || $uri === '/sitemap.xml') {
            $this->handleSitemap();
            return;
        }

        // 2. Главная страница (+ пагинация /page/2/)
        if ($count === 0) {
            // Если в настройках выбрана статическая страница как главная — показываем её.
            // Лента постов при этом остаётся доступной на /page/1/, /page/2/…
            $home = (string)($this->config['homepage'] ?? '');
            if ($home !== '' && ($hp = $this->cms->page($home)) !== null) {
                $this->renderPage($hp);
                return;
            }
            $this->handleHome(1);
            return;
        }
        if ($count === 2 && $segments[0] === 'page' && is_numeric($segments[1])) {
            $this->handleHome((int)$segments[1]);
            return;
        }

        // 2a. Поиск
        if ($segments[0] === 'search') {
            $this->handleSearch();
            return;
        }

        // 3. Теги
        if ($segments[0] === 'tag') {
            $tag = $segments[1] ?? '';
            $this->handleTag($tag);
            return;
        }

        // 3a. Живой предпросмотр текущего содержимого редактора (POST формы, без сохранения)
        if ($segments[0] === 'preview' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->handleLivePreview();
            return;
        }

        // 3b. Предпросмотр черновика по секретной ссылке (GET)
        if ($segments[0] === 'preview' && $count === 2) {
            $this->handlePreview($segments[1]);
            return;
        }

        // 4. Админка — передаётся в admin/index.php
        $adminPath = $this->config['admin_path'] ?? 'admin';
        if ($segments[0] === $adminPath) {
            $this->handleAdmin();
            return;
        }

        // 5. Медиафайлы — отдаём напрямую через Apache, но обработаем на случай
        if ($segments[0] === 'media') {
            $this->handleMedia($segments);
            return;
        }

        // 6. Пагинация категории: /category/page/2/
        if ($count === 3 && $segments[1] === 'page' && is_numeric($segments[2])) {
            $this->handleCategory($segments[0], (int)$segments[2]);
            return;
        }

        // 7. Один сегмент
        if ($count === 1) {
            $slug = $segments[0];
            // Проверяем страницы (черновик-страница по прямой ссылке — не показываем)
            $page = $this->cms->page($slug);
            if ($page !== null && $this->isPubliclyVisible($page)) {
                $this->renderPage($page);
                return;
            }
            // Иначе — список категории
            $this->handleCategory($slug, 1);
            return;
        }

        // 8. Два сегмента: category/slug
        if ($count === 2) {
            $post = $this->cms->postBySlug($segments[0], $segments[1]);
            // Публично по прямой ссылке — только published/sticky/unlisted.
            // Черновики и ещё не наступившие отложенные — 404 (смотрятся через /preview/токен).
            if ($post !== null && $this->isPubliclyVisible($post)) {
                $this->renderPost($post);
                return;
            }
        }

        // 9. Вложенная страница (parent/slug)
        if ($count === 2) {
            $page = $this->cms->page($segments[0] . '-' . $segments[1]);
            if ($page !== null && $this->isPubliclyVisible($page)) {
                $this->renderPage($page);
                return;
            }
        }

        $this->handle404();
    }

    // ----------------------------------------------------------------
    // Обработчики
    // ----------------------------------------------------------------

    private function handleHome(int $page): void
    {
        $perPage = (int)($this->config['posts_per_page'] ?? 10);
        $all     = $this->cms->posts(0);
        $total   = count($all);

        if ($page < 1 || ($page > 1 && ($page - 1) * $perPage >= $total)) {
            $this->handle404();
            return;
        }

        $posts = array_slice($all, ($page - 1) * $perPage, $perPage);
        $site  = $this->siteObject();

        $this->theme->render('index', compact('posts', 'site', 'page', 'total', 'perPage')
            + $this->commonVars());
    }

    private function handleSearch(): void
    {
        $query   = trim((string)($_GET['q'] ?? ''));
        $search  = new SearchManager($this->cms);
        $posts   = $search->search($query);
        $site    = $this->siteObject();

        $this->theme->render('index', compact('posts', 'site') + [
            'searchQuery' => $query,
        ] + $this->commonVars(['noindex' => true, 'title' => 'Поиск']));
    }

    private function handleCategory(string $category, int $page): void
    {
        $perPage = (int)($this->config['posts_per_page'] ?? 10);
        // Post::DEFAULT_CATEGORY — виртуальная категория постов без category (так строит
        // URL Post::url()), поэтому сравниваем по той же логике, а не по буквальному полю
        $all = array_values(array_filter(
            $this->cms->posts(),
            fn(Post $p) => ($p->category !== '' ? $p->category : Post::DEFAULT_CATEGORY) === $category
        ));
        $total = count($all);

        // Категория существует, если есть посты ИЛИ явно заведены метаданные
        // (создана в админке без единого поста — пустой архив, а не 404)
        $categories = new CategoryManager();
        $exists     = $total > 0 || $categories->exists($category);
        $pageValid  = $total > 0
            ? ($page >= 1 && ($page - 1) * $perPage < $total)
            : ($page === 1);

        if (!$exists || !$pageValid) {
            $this->handle404();
            return;
        }

        $offset  = ($page - 1) * $perPage;
        $posts   = array_slice($all, $offset, $perPage);
        $site    = $this->siteObject();
        $meta    = $categories->get($category);

        $categoryTitle       = $meta['title'];
        $categoryDescription = $meta['description'];

        $this->theme->render(
            'index',
            compact('posts', 'site', 'category', 'categoryTitle', 'categoryDescription', 'page', 'total', 'perPage')
                + $this->commonVars(['title' => $categoryTitle, 'description' => $categoryDescription])
        );
    }

    private function handleTag(string $tag): void
    {
        $posts = $this->cms->posts(0, ['tag' => $tag]);

        // Несуществующий тег — 404, а не пустой список
        if ($tag === '' || empty($posts)) {
            $this->handle404();
            return;
        }

        $site  = $this->siteObject();

        $this->theme->render('index', compact('posts', 'site', 'tag')
            + $this->commonVars(['title' => $tag]));
    }

    /**
     * Пользователь админки на фронте (in-page edit).
     * Гостям сессию не создаём — только если cookie уже есть.
     */
    private function frontEditor(): ?array
    {
        if ($this->frontChecked) {
            return $this->frontUser;
        }
        $this->frontChecked = true;
        if (empty($_COOKIE[session_name()])) {
            return $this->frontUser = null;
        }
        $this->frontSecurity = new Security($this->config);
        $this->frontSecurity->startSession();
        return $this->frontUser = $this->frontSecurity->currentUser();
    }

    /** URL редактора для материала, если смотрит вошедший пользователь */
    private function editUrlFor(Post $post, string $type): ?string
    {
        if ($this->frontEditor() === null) {
            return null;
        }
        $adminPath = (string)($this->config['admin_path'] ?? 'admin');
        $section   = $type === 'page' ? 'pages' : 'posts';
        return '/' . $adminPath . '/' . $section . '/edit/?file=' . urlencode(basename($post->filePath));
    }

    /** Виден ли пост публично по прямой ссылке (не в списках — там свой фильтр). */
    private function isPubliclyVisible(Post $post): bool
    {
        return $post->isPubliclyVisible();
    }

    private function renderPost(Post $post, array $extra = []): void
    {
        $site = $this->siteObject();
        $extra['editUrl'] = $extra['editUrl'] ?? $this->editUrlFor($post, 'post');
        $this->theme->render($post->template ?: 'post', compact('post', 'site')
            + $extra + $this->commonVars());
    }

    private function renderPage(Post $page, array $extra = []): void
    {
        $site = $this->siteObject();
        $tpl  = $page->template ?: 'page';
        $this->theme->render($tpl, ['post' => $page, 'page' => $page, 'site' => $site,
                'editUrl' => $extra['editUrl'] ?? $this->editUrlFor($page, 'page')]
            + $extra + $this->commonVars());
    }

    private function handle404(): void
    {
        // Перед 404 — проверка 301-редиректов (смена slug/категории)
        $path     = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $redirect = (new RedirectManager())->find($path);
        if ($redirect !== null) {
            http_response_code(301);
            header('Location: ' . $redirect);
            return;
        }

        http_response_code(404);
        $site = $this->siteObject();
        $this->theme->render('404', compact('site')
            + $this->commonVars(['noindex' => true, 'title' => '404']));
    }

    /** Общие переменные каждого рендера: $cms, $theme, $seo */
    private function commonVars(array $seoCtx = []): array
    {
        // Данные для drag-and-drop расстановки — только вошедшему admin/editor.
        // Флаги manualPosts/manualSections говорят теме, что можно двигать: при
        // сортировке по алфавиту или датам порядок пересчитывается при каждом
        // рендере, поэтому расставлять руками нечего — остаётся только перенос
        // статьи в другой раздел (он меняет категорию и работает всегда).
        $reorder = null;
        $fu = $this->frontEditor();
        if ($fu !== null && in_array((string)($fu['role'] ?? ''), ['admin', 'editor'], true) && $this->frontSecurity !== null) {
            $adminPath = (string)($this->config['admin_path'] ?? 'admin');
            $reorder = [
                'url'            => '/' . $adminPath . '/reorder/',
                'csrf'           => $this->frontSecurity->csrfToken(),
                'manualPosts'    => (string)($this->config['article_order'] ?? 'manual') === 'manual',
                'manualSections' => (string)($this->config['category_order'] ?? 'alpha') === 'manual',
            ];
        }
        return [
            'cms'     => $this->cms,
            'theme'   => $this->theme->themeObject(),
            'seo'     => new SeoManager($this->config),
            'seoCtx'  => $seoCtx,
            'reorder' => $reorder,
        ];
    }

    /**
     * Предпросмотр поста любого статуса: /preview/<имя-файла-без-.md>/?token=...
     * Токен — HMAC от имени файла, ссылку выдаёт редактор в админке.
     */
    private function handlePreview(string $base): void
    {
        $token   = (string)($_GET['token'] ?? '');
        $expires = (int)($_GET['exp'] ?? 0);
        $file    = $base . '.md';

        if (!Security::verifyPreviewToken($file, $token, $expires)) {
            $this->handle404();
            return;
        }
        $post = $this->cms->postByFilename($file);
        if ($post === null) {
            $this->handle404();
            return;
        }

        header('X-Robots-Tag: noindex, nofollow');
        $this->renderPost($post, ['isPreview' => true]);
    }

    /**
     * Живой предпросмотр текущего содержимого редактора: форма постится сюда
     * (POST), пост собирается из полей БЕЗ сохранения на диск и рендерится темой.
     * Доступ — только залогиненному пользователю админки + валидный CSRF.
     * Санитизация HTML — по роли, ровно как при сохранении (не-админам сырой
     * HTML экранируется). Ничего не пишется, страница noindex и не кэшируется.
     */
    private function handleLivePreview(): void
    {
        $security = new Security($this->config);
        $security->startSession();
        $user = $security->currentUser();
        if ($user === null || !$security->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->handle404();
            return;
        }

        $role = (string)($user['role'] ?? 'author');
        $type = ($_POST['type'] ?? 'post') === 'page' ? 'page' : 'post';

        $tags = array_values(array_filter(
            array_map('trim', explode(',', (string)($_POST['tags'] ?? ''))),
            fn($t) => $t !== ''
        ));

        $meta = [
            'title'           => trim((string)($_POST['title'] ?? '')),
            'slug'            => Slugger::make((string)($_POST['slug'] ?? '')) ?: 'preview',
            'status'          => (string)($_POST['status'] ?? 'draft'),
            'author'          => (string)($_POST['author'] ?? ($user['username'] ?? '')),
            'category'        => Slugger::make((string)($_POST['category'] ?? '')),
            'tags'            => $tags,
            'cover'           => trim((string)($_POST['cover'] ?? '')),
            'excerpt'         => trim((string)($_POST['excerpt'] ?? '')),
            'template'        => trim((string)($_POST['template'] ?? '')),
            'date'            => date('Y-m-d'),
            'seo_title'       => trim((string)($_POST['seo_title'] ?? '')),
            'seo_description' => trim((string)($_POST['seo_description'] ?? '')),
            'seo_noindex'     => !empty($_POST['seo_noindex']),
            'canonical'       => trim((string)($_POST['canonical'] ?? '')),
            'og_title'        => trim((string)($_POST['og_title'] ?? '')),
            'og_image'        => trim((string)($_POST['og_image'] ?? '')),
            'og_description'  => trim((string)($_POST['og_description'] ?? '')),
            // Санитизация как при сохранении: не-админам сырой HTML экранируется
            'safe_html'       => $role !== 'admin',
        ];
        if ($type === 'page') {
            $meta['show_in_menu'] = !empty($_POST['show_in_menu']);
            $meta['position']     = (int)($_POST['position'] ?? 0);
        }

        // Тело передаём в конструктор напрямую — файл с диска НЕ читается.
        $post = new Post($meta, (string)($_POST['content'] ?? ''), 'preview.md');
        $post->siteUrl = rtrim((string)($this->config['site_url'] ?? ''), '/');

        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, private');
        $extra = ['isPreview' => true, 'editUrl' => ''];
        if ($type === 'page') {
            $this->renderPage($post, $extra);
        } else {
            $this->renderPost($post, $extra);
        }
    }

    private function handleRss(): void
    {
        if (file_exists(ROOT_DIR . '/system/RssManager.php')) {
            require_once ROOT_DIR . '/system/RssManager.php';
            $rss = new RssManager($this->config, $this->cms);
            $rss->output();
        } else {
            http_response_code(404);
            echo 'RSS not available.';
        }
    }

    private function handleSitemap(): void
    {
        if (file_exists(ROOT_DIR . '/system/SitemapManager.php')) {
            require_once ROOT_DIR . '/system/SitemapManager.php';
            $sm = new SitemapManager($this->config, $this->cms);
            $sm->output();
        } else {
            http_response_code(404);
            echo 'Sitemap not available.';
        }
    }

    private function handleAdmin(): void
    {
        $adminIndex = ROOT_DIR . '/admin/index.php';
        if (file_exists($adminIndex)) {
            require $adminIndex;
        } else {
            http_response_code(503);
            echo 'Admin panel not installed.';
        }
    }

    private function handleMedia(array $segments): void
    {
        // Apache уже отдаёт файлы из /media/ напрямую. Этот обработчик — fallback.
        $mediaDir = realpath(ROOT_DIR . '/media');
        $path     = realpath(ROOT_DIR . '/' . implode('/', $segments));

        // realpath схлопывает '..': файл обязан лежать внутри /media/,
        // иначе /media/../config.json отдал бы любой файл сайта
        if ($mediaDir === false || $path === false || !is_file($path)
            || !str_starts_with($path, $mediaDir . DIRECTORY_SEPARATOR)
            || str_ends_with(strtolower($path), '.php')) {
            $this->handle404();
            return;
        }

        $mime = mime_content_type($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
    }

    private function handleMaintenance(): void
    {
        // Admin может видеть сайт. Сессию поднимаем через Security — только он
        // знает, где лежат файлы сессий и с каким сроком жизни (свой каталог
        // /system/sessions); голый session_start() искал бы их в системном temp
        // и не находил вошедшего.
        (new Security($this->config))->startSession();
        if (!empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
            return; // продолжить нормальную обработку
        }

        http_response_code(503);
        header('Retry-After: 3600');

        $msg = htmlspecialchars(
            (string)($this->config['maintenance_message'] ?? 'Сайт на техническом обслуживании.'),
            ENT_QUOTES,
            'UTF-8'
        );

        $customPage = ROOT_DIR . '/content/static/maintenance.html';
        if (file_exists($customPage)) {
            readfile($customPage);
        } else {
            echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Обслуживание</title></head>";
            echo "<body style='font-family:sans-serif;text-align:center;padding:4rem'>";
            echo "<h1>🛠 {$msg}</h1></body></html>";
        }
        exit;
    }

    private function siteObject(): object
    {
        $config = $this->config;
        return new class($config) {
            public string $title;
            public string $tagline;
            public string $description;
            public string $footerText;
            public string $url;
            public string $language;
            /** Путь к логотипу-картинке (пусто — тема показывает только название). */
            public string $logo;
            /** RSS-лента /rss.xml включена в настройках (иначе лента 404-ит). */
            public bool $rss;
            /** Режимы сортировки для тем документации: manual|alpha|created|modified. */
            public string $categoryOrder;
            public string $articleOrder;
            /** @var array<int,array{name:string,url:string}> Ссылки на соцсети (непустые) */
            public array $social;

            public function __construct(array $c) {
                $this->title       = (string)($c['site_title'] ?? '');
                $this->tagline     = (string)($c['site_tagline'] ?? '');
                $this->description = (string)($c['site_description'] ?? '');
                $this->footerText  = (string)($c['footer_text'] ?? '');
                $this->url         = rtrim((string)($c['site_url'] ?? ''), '/');
                $this->language    = (string)($c['language'] ?? 'ru');
                $this->logo        = (string)($c['logo'] ?? '');
                $this->rss         = !empty($c['rss_enabled']);
                $this->categoryOrder = (string)($c['category_order'] ?? 'alpha');
                $this->articleOrder  = (string)($c['article_order'] ?? 'manual');
                $this->social      = [];
                foreach ((array)($c['social'] ?? []) as $name => $link) {
                    if (is_string($link) && $link !== '') {
                        $this->social[] = ['name' => (string)$name, 'url' => $link];
                    }
                }
            }
        };
    }
}
