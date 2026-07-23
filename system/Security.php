<?php
declare(strict_types=1);

/**
 * Безопасность админки: сессии, CSRF-токены, защита от brute-force,
 * токены предпросмотра и восстановления пароля.
 * Правила — раздел 14 ТЗ.
 *
 * Данные (счётчики IP) — в guard-файле /system/security-data.php.
 * Имя с дефисом выбрано сознательно: на case-insensitive ФС (macOS)
 * файл данных "security.php" перезаписал бы класс "Security.php".
 */
class Security
{
    private const MAX_ATTEMPTS     = 10;    // неудачных попыток...
    private const ATTEMPT_WINDOW   = 600;   // ...за 10 минут
    private const BLOCK_TIME       = 900;   // блокировка на 15 минут

    /**
     * Второй счётчик — по имени пользователя (лимит выше считается по IP).
     * Нужен против распределённого перебора: ботнет или IPv6-подсеть дают
     * каждому запросу свой адрес, и лимит по IP такую атаку не видит вовсе.
     *
     * Здесь именно ЗАДЕРЖКА, а не блокировка аккаунта. Блокировка была бы
     * подарком атакующему: одна попытка раз в четверть часа — и владелец
     * аккаунта не войдёт никогда. Задержка же тормозит перебор, но живого
     * человека пропускает — он подождёт пару секунд и не заметит.
     *
     * Честная граница: против массово-параллельной атаки задержка помогает
     * ограниченно (каждый запрос ждёт в своём процессе). Она поднимает цену
     * перебора и вместе с лимитом по IP усложняет подбор, но не заменяет
     * длинный пароль. Так и написано в README.
     */
    private const USER_WINDOW     = 900;    // окно счёта по аккаунту: 15 минут
    private const USER_FREE_TRIES = 5;      // столько попыток без задержки
    private const USER_MAX_DELAY  = 3;      // потолок задержки, секунд
    private const SESSION_TIMEOUT  = 3600;  // 1 час неактивности
    private const SESSION_ABSOLUTE = 86400; // жёсткий потолок: 24 часа с момента входа

    private string $securityBase;
    private string $legacyJson;
    private string $logDir;
    private string $sessionDir;

    public function __construct(array $config = [])
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $this->securityBase = $root . '/system/security-data';
        $this->legacyJson   = $root . '/system/security.json';
        $this->logDir       = $root . '/system/logs/';
        $this->sessionDir   = $root . '/system/sessions';
    }

    // ----------------------------------------------------------------
    // Сессии
    // ----------------------------------------------------------------

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        ini_set('session.use_strict_mode', '1');

        // Из админки выбрасывало задолго до нашего таймаута: PHP удаляет файлы
        // сессий по своему session.gc_maxlifetime (по умолчанию 1440 с = 24 мин),
        // а не по SESSION_TIMEOUT. Поднимаем срок до нашего и уводим сессии в
        // собственный каталог: в общем /tmp (типовой save_path на shared-хостинге)
        // файлы подчищает чужой сборщик мусора со своими сроками, и наша
        // настройка там ничего не решает.
        ini_set('session.gc_maxlifetime', (string)self::SESSION_TIMEOUT);
        $this->prepareSessionDir();

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Таймаут неактивности и абсолютный потолок (раздел 14.1 ТЗ).
        //
        // Привязки к User-Agent здесь БОЛЬШЕ НЕТ (убрана 2026-07-20). Она
        // выбрасывала из админки на ровном месте: эмуляция устройства в DevTools
        // и обновление браузера меняют строку UA — сессия считалась угнанной.
        // Защита при этом почти нулевая: кто сумел забрать httponly-куку, тот
        // подставит и заголовок UA. Сессию держат strict mode, смена ID при
        // входе, httponly+SameSite, таймауты ниже и блокировка перебора.
        if (!empty($_SESSION['user'])) {
            $inactive = time() - (int)($_SESSION['last_activity'] ?? 0);
            $age      = time() - (int)($_SESSION['login_time'] ?? 0);
            if ($inactive > self::SESSION_TIMEOUT || $age > self::SESSION_ABSOLUTE) {
                $this->logout();
            }
        }
        // Сессии, открытые ДО смены пароля, доверия больше не заслуживают:
        // классический сценарий — «сессию увели, владелец сменил пароль»,
        // после которого чужой доступ обязан прекратиться, а не жить до
        // суточного потолка. Отметку ставит UserManager при смене пароля.
        if (!empty($_SESSION['user'])) {
            $changed = (int)$this->passwordChangedAt((string)($_SESSION['user']['username'] ?? ''));
            if ($changed > 0 && $changed > (int)($_SESSION['login_time'] ?? 0)) {
                $this->logout();
            }
        }
        $_SESSION['last_activity'] = time();
    }

    /** Данные вошедшего пользователя или null */
    public function currentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Когда у пользователя последний раз менялся пароль (unix-время, 0 — не менялся).
     * Читается напрямую из файла профиля: Security не должен зависеть от
     * UserManager, а нужен здесь ровно один скалярный признак.
     */
    private function passwordChangedAt(string $username): int
    {
        $key = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $key)) {
            return 0;
        }
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        [$user] = DataFile::readWithLegacy($root . '/users/' . $key);
        return is_array($user) ? (int)($user['password_changed_at'] ?? 0) : 0;
    }

    /** Зафиксировать вход: новая сессия, данные пользователя */
    public function loginUser(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'username'     => (string)($user['username'] ?? ''),
            'display_name' => (string)($user['display_name'] ?? $user['username'] ?? ''),
            'role'         => (string)($user['role'] ?? 'author'),
        ];
        $_SESSION['last_activity'] = time();
        $_SESSION['login_time']    = time();
        // user_role читает Router для режима обслуживания
        $_SESSION['user_role'] = $_SESSION['user']['role'];
    }

    public function logout(): void
    {
        unset($_SESSION['user'], $_SESSION['user_role'], $_SESSION['csrf'], $_SESSION['login_time']);
        session_regenerate_id(true);
    }

    public function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /**
     * Свой каталог сессий /system/sessions (0700, закрыт от HTTP как /cache/).
     * Если создать или писать в него нельзя (жёсткие права на хостинге) —
     * молча остаёмся на системном save_path: сессии тогда живут меньше, но
     * админка работает.
     */
    private function prepareSessionDir(): void
    {
        if (!is_dir($this->sessionDir)) {
            @mkdir($this->sessionDir, 0700, true);
        }
        if (!is_dir($this->sessionDir) || !is_writable($this->sessionDir)) {
            return;
        }

        $guard = $this->sessionDir . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents(
                $guard,
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order Allow,Deny\n  Deny from all\n</IfModule>\n"
            );
        }

        session_save_path($this->sessionDir);
    }

    // ----------------------------------------------------------------
    // CSRF
    // ----------------------------------------------------------------

    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** Скрытое поле для формы */
    public function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="'
            . htmlspecialchars($this->csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public function verifyCsrf(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }

    // ----------------------------------------------------------------
    // Brute-force (раздел 14.2 ТЗ)
    // ----------------------------------------------------------------

    public function isBlocked(string $ip): bool
    {
        $data  = $this->loadSecurityData();
        $entry = $data['ips'][$ip] ?? null;
        return $entry !== null && ($entry['blocked_until'] ?? 0) > time();
    }

    /** Секунд до снятия блокировки */
    public function blockRemaining(string $ip): int
    {
        $data = $this->loadSecurityData();
        return max(0, (int)($data['ips'][$ip]['blocked_until'] ?? 0) - time());
    }

    /**
     * Зарегистрировать неудачный вход. Возвращает true, если IP теперь заблокирован.
     *
     * Чтение и запись идут под блокировкой (DataFile::update): перебор — это
     * как раз поток параллельных запросов, и без неё попытки затирали друг
     * друга, а счётчик рос медленнее реального числа обращений.
     */
    public function registerFailure(string $ip, string $username): bool
    {
        $now     = time();
        $blocked = false;
        $user    = $this->userKey($username);

        DataFile::update($this->securityBase, function (array $data) use ($ip, $user, $now, &$blocked): array {
            $attempts   = $data['ips'][$ip]['attempts'] ?? [];
            $attempts   = array_values(array_filter($attempts, fn($t) => $t > $now - self::ATTEMPT_WINDOW));
            $attempts[] = $now;

            $entry = ['attempts' => $attempts];
            if (count($attempts) >= self::MAX_ATTEMPTS) {
                $entry['blocked_until'] = $now + self::BLOCK_TIME;
            }
            $data['ips'][$ip] = $entry;
            $blocked = isset($entry['blocked_until']);

            // Счётчик по аккаунту — источник задержки в loginDelay().
            // Пишется и для несуществующих логинов: иначе разница в поведении
            // подсказывала бы, какие имена в системе есть.
            if ($user !== '') {
                $tries = array_values(array_filter(
                    (array)($data['users'][$user] ?? []),
                    fn($t) => $t > $now - self::USER_WINDOW
                ));
                $tries[] = $now;
                $data['users'][$user] = $tries;
            }

            return $this->pruneSecurityData($data);
        });

        $this->logFailure($ip, $username);

        return $blocked;
    }

    /**
     * Успешный вход обнуляет оба счётчика: и по адресу, и по аккаунту —
     * иначе владелец, вспомнивший пароль с шестой попытки, продолжал бы
     * ждать задержку до конца окна.
     */
    public function clearFailures(string $ip, string $username = ''): void
    {
        $user = $this->userKey($username);
        DataFile::update($this->securityBase, function (array $data) use ($ip, $user): array {
            unset($data['ips'][$ip]);
            if ($user !== '') {
                unset($data['users'][$user]);
            }
            return $this->pruneSecurityData($data);
        });
    }

    /**
     * Сколько секунд подождать перед проверкой пароля для этого логина.
     * 0 — пока попыток мало. Дальше растёт на секунду за попытку до потолка.
     *
     * Считать нужно ДО проверки пароля и одинаково для существующих и
     * несуществующих логинов, иначе время ответа выдаёт, какие имена заведены.
     */
    public function loginDelay(string $username): int
    {
        $user = $this->userKey($username);
        if ($user === '') return 0;

        $now   = time();
        $data  = $this->loadSecurityData();
        $tries = array_filter(
            (array)($data['users'][$user] ?? []),
            fn($t) => $t > $now - self::USER_WINDOW
        );

        $extra = count($tries) - self::USER_FREE_TRIES;
        return $extra <= 0 ? 0 : min(self::USER_MAX_DELAY, $extra);
    }

    /** Ключ счётчика по аккаунту: логин в нижнем регистре, только допустимые символы */
    private function userKey(string $username): string
    {
        $key = strtolower(trim($username));
        return preg_match('/^[a-z0-9_-]{1,64}$/', $key) === 1 ? $key : '';
    }

    public function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    // ----------------------------------------------------------------
    // Секрет приложения и токены предпросмотра
    // ----------------------------------------------------------------

    /**
     * Секрет приложения: создаётся один раз в /system/secret.key.
     *
     * Если файл не удаётся записать (жёсткие права на хостинге), секрет
     * генерировался бы заново на каждый запрос — и молча ломались бы все
     * подписи: ссылки восстановления пароля, ссылки предпросмотра, суточные
     * хэши уникальных посетителей. Симптом со стороны — «ссылка из письма
     * не работает», причину искать негде. Поэтому неудача записи попадает
     * в лог, а на дашборде появляется пункт чек-листа (см. secretKeyOk()).
     */
    public static function appSecret(): string
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $file = $root . '/system/secret.key';
        $secret = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        if ($secret !== '') {
            return $secret;
        }

        $secret = bin2hex(random_bytes(32));
        if (@file_put_contents($file, $secret, LOCK_EX) === false) {
            $logDir = $root . '/system/logs/';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            @file_put_contents(
                $logDir . 'error.log',
                sprintf("[%s] CANNOT WRITE %s — ссылки сброса пароля и предпросмотра работать не будут\n",
                    date('Y-m-d H:i:s'), $file),
                FILE_APPEND | LOCK_EX
            );
            return $secret;
        }
        // Секрет подписывает токены — читать его посторонним ни к чему
        @chmod($file, 0600);

        return $secret;
    }

    /**
     * Сохранён ли секрет на диске. false — каждый запрос генерирует новый,
     * то есть подписанные ссылки живут ровно один запрос. Для чек-листа
     * безопасности на дашборде.
     */
    public static function secretKeyOk(): bool
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $file = $root . '/system/secret.key';
        return is_file($file) && trim((string)@file_get_contents($file)) !== '';
    }

    /** Срок жизни ссылки предпросмотра: 72 часа */
    public const PREVIEW_TTL = 259200;

    /**
     * Токен секретной ссылки предпросмотра.
     * Подписывает и файл, и время истечения — подделать exp в URL нельзя.
     */
    public static function previewToken(string $postFile, int $expires): string
    {
        return hash_hmac('sha256', 'preview:' . $postFile . ':' . $expires, self::appSecret());
    }

    public static function verifyPreviewToken(string $postFile, string $token, int $expires): bool
    {
        return $token !== ''
            && $expires > time()
            && hash_equals(self::previewToken($postFile, $expires), $token);
    }

    // ----------------------------------------------------------------
    // Восстановление пароля
    // ----------------------------------------------------------------

    /** Срок жизни ссылки восстановления: 30 минут */
    public const RESET_TTL = 1800;

    /**
     * Токен сброса пароля. В подпись входит фрагмент текущего хеша:
     * после смены пароля старая ссылка перестаёт работать сама собой.
     */
    public static function resetToken(string $username, int $expires, string $passwordHash): string
    {
        $data = 'reset:' . strtolower($username) . ':' . $expires . ':' . substr(sha1($passwordHash), 0, 16);
        return hash_hmac('sha256', $data, self::appSecret());
    }

    public static function verifyResetToken(string $username, int $expires, string $passwordHash, string $token): bool
    {
        return $token !== ''
            && $expires > time()
            && hash_equals(self::resetToken($username, $expires, $passwordHash), $token);
    }

    /** Не больше 3 писем сброса за 15 минут с одного IP */
    public function allowPasswordReset(string $ip): bool
    {
        $data = $this->loadSecurityData();
        $ts   = array_filter((array)($data['reset'][$ip] ?? []), fn($t) => $t > time() - 900);
        return count($ts) < 3;
    }

    public function registerPasswordReset(string $ip): void
    {
        DataFile::update($this->securityBase, function (array $data) use ($ip): array {
            $ts   = array_values(array_filter((array)($data['reset'][$ip] ?? []), fn($t) => $t > time() - 900));
            $ts[] = time();
            $data['reset'][$ip] = $ts;
            return $this->pruneSecurityData($data);
        });
    }

    // ----------------------------------------------------------------

    private function loadSecurityData(): array
    {
        [$data] = DataFile::readWithLegacy($this->securityBase);
        if ($data === null && is_file($this->legacyJson)) {
            $data = DataFile::read($this->legacyJson);
        }
        return is_array($data) ? $data + ['ips' => []] : ['ips' => []];
    }

    /**
     * Убрать протухшие записи, чтобы файл не рос бесконечно.
     * Только преобразование данных — запись делает DataFile::update()
     * под блокировкой, вызывающий код передаёт результат ему.
     */
    private function pruneSecurityData(array $data): array
    {
        $now = time();
        foreach ((array)($data['ips'] ?? []) as $ip => $entry) {
            $active  = array_filter((array)($entry['attempts'] ?? []), fn($t) => $t > $now - self::ATTEMPT_WINDOW);
            $blocked = ($entry['blocked_until'] ?? 0) > $now;
            if (empty($active) && !$blocked) {
                unset($data['ips'][$ip]);
            }
        }
        foreach ((array)($data['reset'] ?? []) as $ip => $ts) {
            $fresh = array_filter((array)$ts, fn($t) => $t > $now - 900);
            if (empty($fresh)) {
                unset($data['reset'][$ip]);
            }
        }
        // Счётчики по аккаунтам живут своё окно (15 минут), затем исчезают:
        // список логинов не должен копиться в файле дольше необходимого
        foreach ((array)($data['users'] ?? []) as $user => $ts) {
            $fresh = array_values(array_filter((array)$ts, fn($t) => $t > $now - self::USER_WINDOW));
            if (empty($fresh)) {
                unset($data['users'][$user]);
            } else {
                $data['users'][$user] = $fresh;
            }
        }
        if (is_file($this->legacyJson)) {
            @unlink($this->legacyJson);
        }
        return $data + ['ips' => []];
    }

    private function logFailure(string $ip, string $username): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
        $file = $this->logDir . 'login.log';

        // Ротация: при 1 МБ текущий лог уходит в login.log.1 (старый архив затирается)
        if (is_file($file) && (int)@filesize($file) > 1048576) {
            @rename($file, $this->logDir . 'login.log.1');
        }

        $line = sprintf("[%s] FAILED LOGIN ip=%s user=%s\n", date('Y-m-d H:i:s'), $ip, $username);
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
