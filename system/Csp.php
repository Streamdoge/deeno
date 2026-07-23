<?php
declare(strict_types=1);

/**
 * Content-Security-Policy публичной части сайта.
 *
 * Раньше политика жила статической строкой в .htaccess и разрешала скрипты
 * с любого https-домена и любые инлайн-скрипты — то есть фактически ничего
 * не запрещала. Здесь она собирается из трёх частей:
 *
 *  1. `'self'` — свои файлы (assets темы, admin.js);
 *  2. отпечатки (sha256) инлайн-скриптов, которые есть в самой странице —
 *     их в темах ровно один: анти-мигание тёмной темы в <head>, он обязан
 *     быть инлайновым, иначе страница моргает белым при загрузке;
 *  3. домены, которые администратор явно разрешил в Настройках
 *     (счётчики посещений и подобные виджеты).
 *
 * Почему отпечатки, а не одноразовый nonce, как в админке: страницы отдаются
 * из полностраничного кэша — один готовый HTML на всех посетителей. Nonce,
 * вшитый в кэш, перестаёт быть одноразовым и защищает не больше, чем его
 * отсутствие. Отпечаток же зависит только от содержимого скрипта и с кэшем
 * дружит: изменилась тема — изменился HTML — пересчитался отпечаток.
 *
 * Админка сюда НЕ попадает: у неё свой строгий CSP с nonce (её страницы
 * не кэшируются), см. admin/index.php.
 */
class Csp
{
    /**
     * Проигрыватели видео: iframe для YouTube/Vimeo генерирует сам движок
     * из ссылки в тексте (MarkdownParser::videoEmbed), поэтому домены
     * зашиты здесь, а не отданы на откуп настройке.
     */
    private const FRAME_SRC = [
        'https://www.youtube-nocookie.com',
        'https://player.vimeo.com',
    ];

    /**
     * Готовое значение заголовка Content-Security-Policy.
     *
     * @param array  $config конфигурация сайта (external_scripts)
     * @param string $html   отрендеренная страница — из неё берутся отпечатки
     */
    public static function header(array $config, string $html = ''): string
    {
        $hosts = self::allowedHosts($config);

        // Темы строят ссылки на свои ассеты абсолютными, от настройки «Адрес
        // сайта» ($theme->asset()). Если она разошлась с фактическим доменом
        // (www против без www, http против https, смена домена), браузер
        // считает такой файл сторонним и 'self' его не покрывает — сайт
        // остался бы без стилей и скриптов. Свой же адрес разрешаем явно:
        // политику это не ослабляет, а от тихой поломки страхует.
        $own    = self::ownOrigin($config);
        $base   = $own === '' ? ["'self'"] : ["'self'", $own];
        $script = array_merge($base, self::scriptHashes($html), $hosts);

        // Счётчику мало исполниться: он ещё шлёт данные (connect-src) и часто
        // рисует пиксель-картинку (img-src). Без этого он молча не работает.
        $connect = array_merge($base, $hosts);
        $frame   = array_merge(self::FRAME_SRC, $hosts);
        $styleSrc = implode(' ', array_merge($base, ["'unsafe-inline'"]));
        $fontSrc  = implode(' ', array_merge($base, ['data:']));

        $directives = [
            'default-src ' . implode(' ', $base),
            'script-src ' . implode(' ', $script),
            // Инлайн-стили остаются: их используют темы и виджеты для мелких
            // вычисляемых значений (ширина полосы графика и т.п.). Риск от
            // стилей несопоставим с исполнением скриптов.
            'style-src ' . $styleSrc,
            // Картинки: свои, data:/blob: (кадрирование при загрузке) и любые
            // https — в статью можно вставить картинку с чужого сайта.
            "img-src 'self' data: blob: https:",
            'font-src ' . $fontSrc,
            'connect-src ' . implode(' ', $connect),
            'frame-src ' . implode(' ', $frame),
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'",
        ];

        return implode('; ', $directives);
    }

    /**
     * Отпечатки инлайн-скриптов страницы: ['sha256-...', ...].
     *
     * Теги с src пропускаются — это внешние файлы, их покрывает 'self' или
     * разрешённый домен. JSON-LD (application/ld+json) — это данные для
     * поисковиков, а не исполняемый код: браузер его не запускает и CSP
     * к нему не применяет, поэтому отпечаток ему не нужен.
     */
    public static function scriptHashes(string $html): array
    {
        if ($html === '' || stripos($html, '<script') === false) {
            return [];
        }

        preg_match_all('~<script\b([^>]*)>(.*?)</script\s*>~is', $html, $matches, PREG_SET_ORDER);

        $hashes = [];
        foreach ($matches as $m) {
            $attrs = (string)($m[1] ?? '');
            if (preg_match('~\bsrc\s*=~i', $attrs)) continue;
            if (preg_match('~type\s*=\s*["\']?application/ld\+json~i', $attrs)) continue;

            // Хэш считается от точного содержимого между тегами — так же,
            // как это делает браузер: без trim и без нормализации переносов.
            //
            // Кавычки обязательны: по грамматике CSP отпечаток — это
            // quoted-string ('sha256-…'). Без них браузер не распознаёт токен,
            // молча игнорирует его и блокирует скрипт. Наружу ошибка выглядит
            // как «скрипт просто не отработал», поэтому проверяется тестом.
            $hash = "'sha256-" . base64_encode(hash('sha256', (string)($m[2] ?? ''), true)) . "'";
            $hashes[$hash] = true;
        }

        return array_keys($hashes);
    }

    /**
     * Собственный origin сайта из настройки «Адрес сайта» — схема+хост(+порт),
     * без пути. Пустая строка, если настройка не заполнена или не разбирается:
     * тогда в политике остаётся одно 'self'.
     */
    public static function ownOrigin(array $config): string
    {
        $url = trim((string)($config['site_url'] ?? ''));
        if ($url === '') return '';

        $parts  = parse_url($url);
        $host   = (string)($parts['host'] ?? '');
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) return '';

        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return $scheme . '://' . strtolower($host) . $port;
    }

    /**
     * Домены, разрешённые администратором в Настройках → «Внешние скрипты».
     * Принимается только схема+хост (https://mc.yandex.ru); путь, кавычки и
     * подстановочные символы отбрасываются — в CSP они либо не работают,
     * либо расширяют политику шире, чем ожидает пользователь.
     *
     * @return list<string>
     */
    public static function allowedHosts(array $config): array
    {
        $raw = (string)($config['external_scripts'] ?? '');
        if (trim($raw) === '') return [];

        $hosts = [];
        foreach (preg_split('~[\s,]+~', $raw) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') continue;
            // Пользователь может вставить полный адрес счётчика — берём хост
            if (!preg_match('~^https?://~i', $item)) {
                $item = 'https://' . $item;
            }
            $parts = parse_url($item);
            $host  = (string)($parts['host'] ?? '');
            if ($host === '' || !preg_match('~^[a-z0-9.-]+$~i', $host)) continue;

            $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
            $hosts[$scheme . '://' . strtolower($host)] = true;
        }

        return array_keys($hosts);
    }
}
