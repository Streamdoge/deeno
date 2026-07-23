<?php
declare(strict_types=1);

/**
 * Работа с пользователями (guard-файлы в /users/, см. DataFile).
 * Не знает про HTTP — только файлы. Формат — раздел 5.3 ТЗ.
 * Роли: admin | editor | author (модель прав — раздел 10 ТЗ).
 */
class UserManager
{
    public const BCRYPT_COST  = 12;
    public const MIN_PASSWORD = 10;
    public const ROLES        = ['admin', 'editor', 'author'];

    private string $usersDir;

    public function __construct()
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $this->usersDir = $root . '/users/';
    }

    /** Найти пользователя по имени */
    public function find(string $username): ?array
    {
        // Имя пользователя — только латиница/цифры/дефис/подчёркивание
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $username)) return null;

        [$user] = DataFile::readWithLegacy($this->usersDir . strtolower($username));
        return $user;
    }

    /**
     * Проверить логин/пароль. Возвращает данные пользователя или null.
     * Использует password_verify всегда (защита от тайминг-атак на имя).
     */
    public function verify(string $username, string $password): ?array
    {
        $user = $this->find($username);
        $hash = (string)($user['password'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinv');

        if (!password_verify($password, $hash)) return null;
        if ($user === null || empty($user['active'])) return null;

        return $user;
    }

    /** Все пользователи (без хешей паролей), по имени */
    public function all(): array
    {
        $users = [];
        foreach (glob($this->usersDir . '*.{php,json}', GLOB_BRACE) ?: [] as $file) {
            $username = strtolower(pathinfo($file, PATHINFO_FILENAME));
            if (isset($users[$username])) continue; // .php приоритетнее legacy-.json
            $user = DataFile::read($file);
            if (is_array($user)) {
                unset($user['password']);
                $users[$username] = $user;
            }
        }
        ksort($users);
        return array_values($users);
    }

    public function count(): int
    {
        $names = [];
        foreach (glob($this->usersDir . '*.{php,json}', GLOB_BRACE) ?: [] as $file) {
            $names[strtolower(pathinfo($file, PATHINFO_FILENAME))] = true;
        }
        return count($names);
    }

    /** Сколько активных администраторов (нельзя удалить/понизить последнего) */
    public function activeAdmins(): int
    {
        $n = 0;
        foreach ($this->all() as $u) {
            if (($u['role'] ?? '') === 'admin' && !empty($u['active'])) $n++;
        }
        return $n;
    }

    /** Сохранить пользователя (перезаписывает файл целиком, guard-формат) */
    public function save(array $user): bool
    {
        $username = strtolower((string)($user['username'] ?? ''));
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $username)) return false;
        if (!in_array((string)($user['role'] ?? ''), self::ROLES, true)) return false;

        return DataFile::writeMigrating($this->usersDir . $username, $user);
    }

    /** Удалить пользователя */
    public function delete(string $username): bool
    {
        $username = strtolower($username);
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $username)) return false;

        $ok = false;
        foreach (['.php', '.json'] as $ext) {
            $file = $this->usersDir . $username . $ext;
            if (is_file($file)) $ok = @unlink($file) || $ok;
        }
        return $ok;
    }

    /** Обновить время последнего входа */
    public function touchLastLogin(string $username): void
    {
        $user = $this->find($username);
        if ($user !== null) {
            $user['last_login'] = date('c');
            $this->save($user);
        }
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    /**
     * Записать новый пароль в запись пользователя вместе с отметкой времени.
     *
     * Отметку читает Security::startSession(): сессии, открытые до смены
     * пароля, завершаются. Смысл — «сессию увели, владелец сменил пароль»:
     * чужой доступ обязан прекратиться сразу, а не жить до суточного потолка.
     * Поэтому пароль меняется только через этот метод — иначе где-нибудь
     * забудется отметка, и старые сессии переживут смену.
     */
    public static function withNewPassword(array $user, string $password): array
    {
        $user['password']            = self::hashPassword($password);
        $user['password_changed_at'] = time();
        return $user;
    }

    /** Проверка пароля на минимальные требования. Возвращает текст ошибки или null. */
    public static function passwordError(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return 'Пароль короче ' . self::MIN_PASSWORD . ' символов.';
        }
        return null;
    }
}
