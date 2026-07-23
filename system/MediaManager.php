<?php
declare(strict_types=1);

/**
 * Загрузка и управление медиафайлами (раздел 14.5 ТЗ).
 * Организация: /media/YYYY/MM/файл.jpg, thumbnails 300x200 через GD.
 *
 * SVG разрешён с 2026-07-20 (логотипы и фавиконки в подсказках его обещали,
 * а загрузить было нельзя). Файл при загрузке чистится — см. sanitizeSvg():
 * вырезаются script, обработчики on*, javascript:-ссылки и DTD. ICO разрешён
 * как есть: он не исполняется, но и GD его не читает — превью не будет.
 */
class MediaManager
{
    private const ALLOWED = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        // Логотипы и фавиконки. SVG — это XML, в нём может лежать скрипт,
        // поэтому файл чистится при загрузке (см. sanitizeSvg). ICO безопасен,
        // но GD его не читает — превью и оптимизация для него пропускаются.
        'svg'  => ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'],
        'ico'  => ['image/vnd.microsoft.icon', 'image/x-icon', 'application/octet-stream'],
    ];

    /** Расширения, которые GD не обрабатывает: без оптимизации и превью */
    private const NO_GD = ['pdf', 'svg', 'ico'];
    private const MAX_SIZE     = 10 * 1024 * 1024; // 10MB
    private const THUMB_W      = 300;
    private const THUMB_H      = 200;

    /* Оптимизация фотографий при загрузке (переопределяется в настройках) */
    private const MAX_DIMENSION_DEFAULT = 2560; // максимальная длинная сторона, px
    private const QUALITY_DEFAULT       = 82;   // JPEG/WebP качество
    private const PNG_COMPRESSION       = 6;    // zlib для PNG (9 — в разы медленнее, см. optimize())

    private string $mediaDir;
    private string $thumbsDir;
    private int $maxDimension;
    private int $quality;
    /** @var callable(string):string Переводчик для строк с параметрами */
    private $tr;

    public function __construct(array $config = [])
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $this->mediaDir  = $root . '/media/';
        $this->thumbsDir = $root . '/media/thumbnails/';

        $this->maxDimension = max(320, (int)($config['media_max_width'] ?? self::MAX_DIMENSION_DEFAULT));
        $this->quality      = min(100, max(40, (int)($config['media_quality'] ?? self::QUALITY_DEFAULT)));

        // Интерполированные сообщения нельзя перевести на границе админки
        // по точному совпадению, поэтому шаблон переводится до подстановки.
        // Вне админки t() нет — тогда строки остаются русскими (исходный язык).
        $this->tr = \function_exists('t') ? 't' : static fn(string $s): string => $s;
    }

    /**
     * Загрузить файл из $_FILES-записи.
     * Возвращает ['url' => ..., 'thumb' => ...] или ['error' => 'текст'].
     */
    public function upload(array $file): array
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            // Код 1/2 — файл больше серверного лимита PHP (upload_max_filesize).
            // Он часто меньше нашего 10 МБ, поэтому показываем реальный лимит.
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                return ['error' => sprintf(
                    ($this->tr)('Файл превышает лимит сервера (%s). Уменьшите изображение.'),
                    (string)ini_get('upload_max_filesize')
                )];
            }
            if ($err === UPLOAD_ERR_PARTIAL) {
                return ['error' => ($this->tr)('Файл передан не полностью — повторите загрузку.')];
            }
            if ($err === UPLOAD_ERR_NO_FILE) {
                return ['error' => ($this->tr)('Файл не выбран.')];
            }
            return ['error' => sprintf(($this->tr)('Ошибка загрузки (код %d).'), $err)];
        }
        if (($file['size'] ?? 0) > self::MAX_SIZE) {
            return ['error' => ($this->tr)('Файл больше 10 МБ.')];
        }

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext])) {
            return ['error' => sprintf(($this->tr)('Недопустимый тип файла. Разрешены: %s.'), implode(', ', array_keys(self::ALLOWED)))];
        }

        // MIME проверяется по содержимому, а не по расширению (раздел 14.5 ТЗ)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? (string)finfo_file($finfo, (string)$file['tmp_name']) : '';
        if (!in_array($mime, self::ALLOWED[$ext], true)) {
            return ['error' => 'Содержимое файла не соответствует расширению.'];
        }

        // ICO приходится пропускать с application/octet-stream: finfo не знает
        // этот формат и отдаёт «просто байты» даже для настоящей иконки. Но
        // тогда под видом .ico прошёл бы любой неопознанный бинарник, поэтому
        // проверяем сигнатуру: 00 00 01 00 (иконка) или 00 00 02 00 (курсор).
        if ($ext === 'ico' && $mime === 'application/octet-stream'
            && !self::hasIcoSignature((string)$file['tmp_name'])) {
            return ['error' => ($this->tr)('Файл не является иконкой ICO.')];
        }

        // Переименование: транслит имени + случайный суффикс
        $base = Slugger::make(pathinfo((string)$file['name'], PATHINFO_FILENAME));
        if ($base === '') $base = 'file';
        $name = $base . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;

        $subdir  = date('Y') . '/' . date('m') . '/';
        $destDir = $this->mediaDir . $subdir;
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
            return ['error' => sprintf(($this->tr)('Не удалось создать каталог /media/%s'), $subdir)];
        }

        $dest = $destDir . $name;
        $moved = is_uploaded_file((string)$file['tmp_name'])
            ? move_uploaded_file((string)$file['tmp_name'], $dest)
            : @rename((string)$file['tmp_name'], $dest); // для тестов вне HTTP-загрузки
        if (!$moved) {
            return ['error' => 'Не удалось сохранить файл.'];
        }

        // SVG — единственный формат, который браузер исполняет как документ:
        // внутри может быть <script> или onload. Чистим сразу после сохранения,
        // до того как файл станет доступен по URL.
        if ($ext === 'svg' && !$this->sanitizeSvg($dest)) {
            @unlink($dest);
            return ['error' => ($this->tr)('Не удалось обработать SVG-файл.')];
        }

        // Оптимизация фотографии: EXIF-поворот, даунскейл, пережатие.
        // Перекодирование заодно удаляет метаданные (включая GPS).
        // Возвращает готовое изображение — передаём его в превью, чтобы
        // не декодировать файл повторно (на фото 4000×3000 это ~11% времени).
        $optimized = in_array($ext, self::NO_GD, true) ? null : $this->optimize($dest, $ext);

        $url   = '/media/' . $subdir . $name;
        $thumb = in_array($ext, self::NO_GD, true)
            ? null                                   // GD этих форматов не читает
            : $this->makeThumbnail($dest, $subdir . $name, $optimized);

        Hooks::run('media.uploaded', ['url' => $url, 'path' => $dest]);

        return ['url' => $url, 'thumb' => $thumb ?? $url];
    }

    /**
     * Список всех файлов медиатеки (кроме thumbnails).
     * Каждый элемент: url, thumb, name, size, isImage.
     */
    public function all(): array
    {
        $items = [];

        foreach ($this->scanFiles() as $path) {
            $rel = substr($path, strlen($this->mediaDir));

            $ext     = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $isImage = $ext !== 'pdf';   // svg и ico показываем как картинки: браузер их рисует
            $thumbFile = $this->thumbsDir . $this->thumbName($rel);

            $items[] = [
                'url'     => '/media/' . $rel,
                'thumb'   => is_file($thumbFile) ? '/media/thumbnails/' . $this->thumbName($rel) : '/media/' . $rel,
                'name'    => basename($path),
                'size'    => (int)@filesize($path),
                'mtime'   => (int)@filemtime($path),
                'isImage' => $isImage,
            ];
        }

        usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $items;
    }

    /**
     * Все файлы медиатеки, на любой глубине вложенности.
     *
     * Раньше здесь стояли два жёстких шаблона: `media/*` и `media/*∕*∕*` —
     * то есть файл в корне либо ровно `media/ГОД/МЕСЯЦ/имя`. Всё, что не
     * попадало в эту форму, для медиатеки не существовало: демо-контент в
     * `media/demo/`, папки, залитые по FTP, распакованный архив со старого
     * сайта. Файлы при этом прекрасно отдавались по прямой ссылке и работали
     * обложками постов — их просто нельзя было ни выбрать в модалке, ни
     * удалить из админки, и человек считал, что картинок нет.
     *
     * Обход заодно оказался вчетверо быстрее прежних glob: на 2000 файлов
     * 4,8 мс против 22,5 — `GLOB_BRACE` с восемью расширениями дорог, а
     * метод вызывается на каждом открытии редактора.
     *
     * @return list<string> абсолютные пути
     */
    private function scanFiles(): array
    {
        if (!is_dir($this->mediaDir)) {
            return [];
        }

        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($this->mediaDir, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $entry): bool {
                // В thumbnails не заходим вовсе: это служебные превью, а не
                // содержимое медиатеки. Симлинки пропускаем — по ним можно
                // уйти за пределы /media/ или зациклиться.
                if ($entry->isLink()) return false;
                if ($entry->isDir())  return $entry->getFilename() !== 'thumbnails';

                return (bool)preg_match('~\.(jpg|jpeg|png|gif|webp|pdf|svg|ico)$~i', $entry->getFilename());
            }
        );

        $files = [];
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    /** Удалить файл по URL вида /media/YYYY/MM/name.jpg (и его thumbnail) */
    public function delete(string $url): bool
    {
        if (!str_starts_with($url, '/media/')) return false;
        $rel = substr($url, strlen('/media/'));

        // Только безопасные относительные пути
        if (str_contains($rel, '..') || !preg_match('#^[\w\-./]+$#u', $rel) || str_starts_with($rel, 'thumbnails/')) {
            return false;
        }

        $path = $this->mediaDir . $rel;
        $real = realpath($path);
        $root = realpath($this->mediaDir);
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return false;
        }

        $ok = @unlink($real);
        if ($ok) {
            @unlink($this->thumbsDir . $this->thumbName($rel));
        }
        return $ok;
    }

    // ----------------------------------------------------------------
    // Оптимизация при загрузке
    // ----------------------------------------------------------------

    /**
     * EXIF-поворот, даунскейл до maxDimension по длинной стороне,
     * пережатие. Результат заменяет оригинал, только если он не хуже:
     * перекодированный файл берётся, когда он меньше либо когда
     * изображение реально повёрнуто/уменьшено.
     * GIF и PDF не трогаем (анимация/не растр).
     */
    private function optimize(string $path, string $ext): ?\GdImage
    {
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) return null;
        if (!function_exists('imagecreatetruecolor')) return null; // нет GD — храним как есть

        $info = @getimagesize($path);
        if ($info === false) return null;
        [$w, $h] = $info;
        // Пиксельная бомба: маленький файл с огромным холстом — не тратим память
        if ($w < 1 || $h < 1 || $w * $h > 40_000_000) return null;

        $isJpeg = $ext === 'jpg' || $ext === 'jpeg';
        $im = match (true) {
            $ext === 'png'  => @imagecreatefrompng($path),
            $ext === 'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default         => @imagecreatefromjpeg($path),
        };
        if (!$im) return null;

        $changed = false;

        // Фото с телефона: применяем EXIF-ориентацию, дальше она сотрётся
        if ($isJpeg && function_exists('exif_read_data')) {
            $orientation = (int)((@exif_read_data($path))['Orientation'] ?? 1);
            $deg = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
            if ($deg !== 0) {
                $rot = imagerotate($im, $deg, 0);
                if ($rot !== false) {
                    $im = $rot;
                    [$w, $h] = [imagesx($im), imagesy($im)];
                    $changed = true;
                }
            }
        }

        // Даунскейл по длинной стороне
        if (max($w, $h) > $this->maxDimension) {
            $ratio = $this->maxDimension / max($w, $h);
            $nw = max(1, (int)round($w * $ratio));
            $nh = max(1, (int)round($h * $ratio));
            $dst = imagecreatetruecolor($nw, $nh);
            // Не отдаём наружу $im: он мог быть повёрнут, а на диск это не попало —
            // превью разошлось бы с самим файлом. Пусть превью читает диск.
            if ($dst === false) return null;
            if (!$isJpeg) { // сохранить прозрачность PNG/WebP
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, (int)imagecolorallocatealpha($dst, 0, 0, 0, 127));
            }
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $im = $dst;
            $changed = true;
        }

        // Перекодируем во временный файл и оставляем лучший вариант
        $tmp = $path . '.opt';
        if ($isJpeg) {
            imageinterlace($im, true); // progressive JPEG
            $ok = @imagejpeg($im, $tmp, $this->quality);
        } elseif ($ext === 'png') {
            imagesavealpha($im, true);
            // Уровень 6, а не 9: на shared-хостинге zlib-9 душит CPU непропорционально
            // выигрышу. Замер на PNG 1254×1254: уровень 9 — 10.3 с и 788 КБ,
            // уровень 6 — 0.6 с и 881 КБ. В 13 раз быстрее ценой +12% размера,
            // из-за чего загрузка фото висела ~20 секунд.
            $ok = @imagepng($im, $tmp, self::PNG_COMPRESSION);
        } else {
            $ok = function_exists('imagewebp') ? @imagewebp($im, $tmp, $this->quality) : false;
        }

        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);
            return null; // на диске остался оригинал, а $im мог быть изменён
        }
        if ($changed || (int)@filesize($tmp) < (int)@filesize($path)) {
            @rename($tmp, $path);
        } else {
            // Пережатие не помогло — на диске оригинал, но $im его и есть
            // ($changed === false, значит ни поворота, ни даунскейла не было).
            @unlink($tmp);
        }
        return $im; // содержимое $im совпадает с тем, что лежит на диске
    }

    /**
     * Настоящая ли это ICO-иконка: заголовок ICONDIR — 2 нулевых байта,
     * тип (1 — иконка, 2 — курсор) и ненулевое число изображений.
     */
    private static function hasIcoSignature(string $path): bool
    {
        $head = (string)@file_get_contents($path, false, null, 0, 6);
        if (strlen($head) < 6) {
            return false;
        }
        $parts = unpack('vreserved/vtype/vcount', $head);
        return is_array($parts)
            && $parts['reserved'] === 0
            && in_array($parts['type'], [1, 2], true)
            && $parts['count'] > 0;
    }

    /** Имя thumbnail: путь с '/' → '_', чтобы всё лежало в одной папке */
    /**
     * Чистка SVG перед публикацией. Файл лежит в /media/ и отдаётся браузеру
     * с нашего домена, поэтому скрипт внутри него выполнился бы в контексте
     * сайта — это полноценный XSS. Убираем всё исполняемое:
     *
     *  - элементы script, foreignObject, а также иностранные вставки (iframe,
     *    embed, object, а из SVG-специфичного — set/animate с обработчиками);
     *  - любые атрибуты on* (onload, onclick, …);
     *  - ссылки на javascript:/data:text/html в href и xlink:href;
     *  - внешние сущности (XXE) — загрузка DTD отключена.
     *
     * Разметка и стили остаются: логотип и favicon рисуются как задумано.
     * Вернёт false, если файл не разбирается как XML.
     */
    private function sanitizeSvg(string $path): bool
    {
        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') {
            return false;
        }
        // Внешние сущности не резолвим: <!ENTITY> мог бы вытащить файл с сервера
        $prev = libxml_use_internal_errors(true);
        $doc  = new DOMDocument();
        // Только LIBXML_NONET (не ходить в сеть за DTD). LIBXML_NOENT здесь
        // намеренно НЕ передаётся: вопреки названию он не «убирает сущности»,
        // а разворачивает их — то есть включил бы ровно ту подстановку, от
        // которой мы защищаемся (внешняя сущность прочитала бы файл сервера).
        $ok   = $doc->loadXML($raw, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $doc->documentElement === null) {
            return false;
        }
        if (strtolower($doc->documentElement->localName) !== 'svg') {
            return false;   // не SVG, чем бы оно ни было
        }

        $forbidden = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'handler', 'use'];
        $xpath = new DOMXPath($doc);

        // 1) опасные элементы — вместе с содержимым
        foreach (iterator_to_array($xpath->query('//*') ?: []) as $node) {
            if (!$node instanceof DOMElement) continue;
            if (in_array(strtolower($node->localName), $forbidden, true)) {
                $node->parentNode?->removeChild($node);
            }
        }
        // 2) обработчики событий и ссылки на скрипты
        foreach (iterator_to_array($xpath->query('//*') ?: []) as $node) {
            if (!$node instanceof DOMElement) continue;
            foreach (iterator_to_array($node->attributes ?? []) as $attr) {
                $name  = strtolower($attr->nodeName);
                $value = trim((string)$attr->nodeValue);
                $flat  = strtolower(preg_replace('~\s+~', '', $value) ?? '');
                if (str_starts_with($name, 'on')
                    || str_starts_with($flat, 'javascript:')
                    || str_starts_with($flat, 'data:text/html')) {
                    $node->removeAttributeNode($attr);
                }
            }
        }
        // 3) DTD целиком — там же живут сущности
        if ($doc->doctype !== null) {
            $doc->doctype->parentNode?->removeChild($doc->doctype);
        }

        $clean = $doc->saveXML();
        return $clean !== false && @file_put_contents($path, $clean, LOCK_EX) !== false;
    }

    private function thumbName(string $rel): string
    {
        return str_replace('/', '_', $rel);
    }

    /**
     * Создать thumbnail 300x200 (cover-кадрирование). Вернёт URL или null.
     * $src — уже декодированное изображение из optimize(), чтобы не читать файл
     * с диска второй раз. null (GIF, PDF, любой отказ optimize()) — читаем сами.
     */
    private function makeThumbnail(string $path, string $rel, ?\GdImage $src = null): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'pdf' || !function_exists('imagecreatetruecolor')) return null;

        if ($src === null) {
            $src = match ($ext) {
                'jpg', 'jpeg' => @imagecreatefromjpeg($path),
                'png'         => @imagecreatefrompng($path),
                'gif'         => @imagecreatefromgif($path),
                'webp'        => @imagecreatefromwebp($path),
                default       => false,
            };
        }
        if (!$src instanceof \GdImage) return null;

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) return null;

        // cover: заполняем 300x200 без искажений, обрезая лишнее
        $scale = max(self::THUMB_W / $sw, self::THUMB_H / $sh);
        $cw    = (int)round(self::THUMB_W / $scale);
        $ch    = (int)round(self::THUMB_H / $scale);
        $cx    = (int)(($sw - $cw) / 2);
        $cy    = (int)(($sh - $ch) / 2);

        $dst = imagecreatetruecolor(self::THUMB_W, self::THUMB_H);
        // Прозрачность для png/gif/webp
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, $cx, $cy, self::THUMB_W, self::THUMB_H, $cw, $ch);

        if (!is_dir($this->thumbsDir)) {
            @mkdir($this->thumbsDir, 0755, true);
        }
        $thumbFile = $this->thumbsDir . $this->thumbName($rel);

        $ok = match ($ext) {
            'jpg', 'jpeg' => imagejpeg($dst, $thumbFile, 82),
            'png'         => imagepng($dst, $thumbFile),
            'gif'         => imagegif($dst, $thumbFile),
            'webp'        => imagewebp($dst, $thumbFile, 82),
            default       => false,
        };


        return $ok ? '/media/thumbnails/' . $this->thumbName($rel) : null;
    }
}
