<?php
declare(strict_types=1);

/**
 * Лёгкая статистика просмотров (раздел 10.1 ТЗ).
 *
 * Хранение — две части:
 *   1. /cache/stats.php  — агрегат:
 *      ['days' => ['Y-m-d' => ['/path/' => n]], 'uniq' => ['Y-m-d' => [хэш => 1]]];
 *   2. /cache/stats-raw.log — журнал новых просмотров, по строке на просмотр.
 *
 * Почему журнал. Раньше каждый просмотр читал агрегат целиком, менял его и
 * переписывал файл. На заполненном окне (31 день × 500 путей × 5000 хэшей —
 * это ~5 МБ) один просмотр обходился в ~19 мс, причём и при отдаче страницы
 * из полностраничного кэша: кэш экономил рендер темы, а самая дорогая
 * операция запроса оставалась на месте. Плюс параллельные просмотры затирали
 * счётчики друг друга — read-modify-write без блокировки.
 *
 * Теперь просмотр лишь дописывает строку в конец журнала (append) — это O(1)
 * и не требует чтения. Журнал сворачивается в агрегат лениво: при чтении
 * статистики (дашборд) либо когда журнал перерастает LOG_MAX_BYTES.
 *
 * Окно хранения — 31 день (с запасом на самый длинный месяц), старые дни
 * отсекаются при свёртке. На дашборде показывается текущий календарный месяц
 * (с 1-го числа по сегодня) — вызывающий код сам передаёт нужное число дней
 * методам ниже. Каталог /cache/ закрыт от HTTP (.htaccess), наружу файлы
 * не отдаются.
 *
 * Уникальные посетители — БЕЗ cookie и без хранения IP. Считается
 * substr(hmac(IP + User-Agent, секрет дня), 0, 12), где секрет дня — производная
 * от /system/secret.key и календарной даты. Соль меняется каждые сутки, поэтому
 * сопоставить посетителя между днями нельзя даже при доступе к файлу, а по
 * усечённому хэшу нельзя восстановить IP. Следствие, важное для интерпретации:
 * уникальные считаются ТОЛЬКО в пределах дня — сумма за месяц даёт «уникальных
 * в сутки, сложенных по дням», а не число разных людей за месяц.
 */
class StatsManager
{
    /** Хранение (retention): с запасом на месяц из 31 дня */
    public const WINDOW_DAYS = 31;

    /** Не даём одному дню распухнуть от перебора мусорных URL */
    private const MAX_PATHS_PER_DAY = 500;

    /** Потолок хэшей за день: бот-волна не должна раздувать stats.json */
    private const MAX_UNIQUE_PER_DAY = 5000;

    /** Длина усечённого хэша посетителя (48 бит — коллизии единичны на таких объёмах) */
    private const VISITOR_HASH_LEN = 12;

    /**
     * Потолок журнала. При превышении просмотр сам сворачивает его в агрегат —
     * страховка на случай, если статистику долго не открывают. 256 КБ — это
     * примерно 5000 строк, свёртка такого журнала занимает доли секунды.
     */
    private const LOG_MAX_BYTES = 262144;

    private string $base;
    private string $logFile;
    private string $lockFile;

    /**
     * Мемоизация в пределах запроса: дашборд спрашивает статистику 6+ раз
     * (просмотры за месяц, за 7 и 14 дней, топ страниц, уникальные), и раньше
     * каждый вызов читал и разбирал файл заново.
     */
    private ?array $data = null;

    public function __construct()
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        // Guard-файл (см. DataFile)
        $this->base     = $root . '/cache/stats';
        $this->logFile  = $root . '/cache/stats-raw.log';
        $this->lockFile = $root . '/cache/stats.lock';
    }

    /**
     * Засчитать просмотр пути (путь уже нормализован Router-ом).
     *
     * @param string $visitor сырой признак посетителя (IP + User-Agent). В файл
     *                        не попадает — хэшируется солью текущих суток.
     *                        Пустая строка — считаем только просмотр.
     */
    public function track(string $path, string $visitor = ''): void
    {
        if ($path === '' || strlen($path) > 200) {
            return;
        }

        $today = date('Y-m-d');
        $id    = $visitor !== '' ? $this->visitorId($visitor, $today) : '';

        // Табы и переводы строк — разделители формата журнала, в пути их быть
        // не должно; вырезаем, чтобы строка не «разъехалась» на две записи.
        $safePath = str_replace(["\t", "\r", "\n"], '', $path);
        $line     = $today . "\t" . $safePath . "\t" . $id . "\n";

        // Дозапись в конец: не читаем файл и не переписываем его. Строки такой
        // длины при O_APPEND пишутся целиком, поэтому параллельные просмотры
        // не перемешиваются и, в отличие от прежней схемы, не теряются.
        if (@file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX) === false) {
            return;
        }

        // Сбрасываем мемоизацию: в журнале появилось несчитанное, и данные,
        // прочитанные до этого момента, устарели. Без сброса просмотр,
        // записанный после чтения, не попал бы в ответ того же экземпляра.
        $this->data = null;

        // Журнал не должен расти бесконечно, если статистику долго не открывают
        if ((int)@filesize($this->logFile) > self::LOG_MAX_BYTES) {
            $this->compact();
        }
    }

    /** Уникальные за окно — сумма ДНЕВНЫХ значений (см. оговорку в шапке класса) */
    public function uniqueVisitors(int $days = self::WINDOW_DAYS): int
    {
        $total = 0;
        foreach ($this->dailyUniques($days) as $n) {
            $total += $n;
        }
        return $total;
    }

    /** Уникальные по дням (включая нулевые) — для графика, от старых к новым */
    public function dailyUniques(int $days = self::WINDOW_DAYS): array
    {
        $uniq = $this->load()['uniq'] ?? [];
        $out  = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $out[$day] = count($uniq[$day] ?? []);
        }
        return $out;
    }

    /**
     * Идентификатор посетителя в пределах суток: HMAC от IP+UA на секрете дня.
     * Соль привязана к дате, поэтому один и тот же человек в разные дни даёт
     * разные хэши — сквозного отслеживания нет by design.
     */
    private function visitorId(string $visitor, string $day): string
    {
        $salt = hash_hmac('sha256', 'stats-uniq:' . $day, Security::appSecret());
        return substr(hash_hmac('sha256', $visitor, $salt), 0, self::VISITOR_HASH_LEN);
    }

    /** Сумма просмотров за окно */
    public function totalViews(int $days = self::WINDOW_DAYS): int
    {
        $total = 0;
        foreach ($this->window($days) as $paths) {
            $total += array_sum($paths);
        }
        return $total;
    }

    /** Просмотры по дням (включая нулевые) — для спарклайна, от старых к новым */
    public function dailyTotals(int $days = self::WINDOW_DAYS): array
    {
        $window = $this->window($days);
        $out    = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $out[$day] = array_sum($window[$day] ?? []);
        }
        return $out;
    }

    /** Топ страниц за окно: ['/path/' => n], по убыванию */
    public function topPages(int $days = self::WINDOW_DAYS, int $limit = 5): array
    {
        $sums = [];
        foreach ($this->window($days) as $paths) {
            foreach ($paths as $path => $n) {
                $sums[$path] = ($sums[$path] ?? 0) + (int)$n;
            }
        }
        arsort($sums);
        return array_slice($sums, 0, $limit, true);
    }

    // ----------------------------------------------------------------

    /**
     * Агрегат со свёрнутым журналом. В пределах запроса читается один раз.
     * Если в журнале есть незасчитанные просмотры — сворачиваем их сейчас,
     * чтобы дашборд показывал свежие числа, а не «до последней свёртки».
     */
    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        if ((int)@filesize($this->logFile) > 0) {
            return $this->data = $this->compact();
        }
        return $this->data = $this->readAggregate();
    }

    private function readAggregate(): array
    {
        [$data] = DataFile::readWithLegacy($this->base);
        if (!is_array($data) || !isset($data['days']) || !is_array($data['days'])) {
            return ['days' => [], 'uniq' => []];
        }
        // Файлы, записанные до появления уникальных, поля 'uniq' не имеют
        if (!isset($data['uniq']) || !is_array($data['uniq'])) {
            $data['uniq'] = [];
        }
        return $data;
    }

    /**
     * Свернуть журнал в агрегат и вернуть результат.
     *
     * Журнал сначала переименовывается во временный файл: rename в пределах
     * одной ФС атомарен, поэтому свой кусок получает ровно один процесс, а
     * параллельные просмотры тем временем спокойно пишут в новый журнал.
     * Слияние с агрегатом идёт под блокировкой — это единственное место,
     * где файл статистики читается и переписывается целиком.
     */
    private function compact(): array
    {
        $mine = $this->logFile . '.' . bin2hex(random_bytes(4)) . '.merging';
        if (!@rename($this->logFile, $mine)) {
            // Журнала уже нет — его забрал другой процесс
            return $this->readAggregate();
        }

        $lock = @fopen($this->lockFile, 'c');
        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }

        $data  = $this->readAggregate();
        $lines = @file($mine, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            if (count($parts) < 2) continue;
            [$day, $path] = $parts;
            $id = $parts[2] ?? '';
            if ($day === '' || $path === '') continue;

            if (!isset($data['days'][$day])) $data['days'][$day] = [];

            // Потолок путей не должен мешать учёту уникальных — считаем их первыми
            if ($id !== ''
                && !isset($data['uniq'][$day][$id])
                && count($data['uniq'][$day] ?? []) < self::MAX_UNIQUE_PER_DAY) {
                $data['uniq'][$day][$id] = 1;
            }

            if (isset($data['days'][$day][$path])
                || count($data['days'][$day]) < self::MAX_PATHS_PER_DAY) {
                $data['days'][$day][$path] = (int)($data['days'][$day][$path] ?? 0) + 1;
            }
        }

        $data['days'] = $this->prune($data['days']);
        $data['uniq'] = $this->prune($data['uniq'] ?? []);

        DataFile::writeMigrating($this->base, $data);

        if ($lock !== false) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
        @unlink($mine);

        return $this->data = $data;
    }

    /** Дни внутри окна */
    private function window(int $days): array
    {
        $edge = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        return array_filter(
            $this->load()['days'],
            fn(string $day) => $day >= $edge,
            ARRAY_FILTER_USE_KEY
        );
    }

    private function prune(array $days): array
    {
        $edge = date('Y-m-d', strtotime('-' . (self::WINDOW_DAYS - 1) . ' days'));
        return array_filter($days, fn(string $d) => $d >= $edge, ARRAY_FILTER_USE_KEY);
    }
}
