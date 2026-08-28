<?php
require_once __DIR__ . '/db.php';

/**
 * Secure password storage.
 * Primary algorithm: Argon2id (64 MiB, 4 iterations, 2 lanes).
 * Fallback: PHP PASSWORD_DEFAULT if Argon2id is unavailable in the current build.
 *
 * Legacy password_md5 is read only when password_hash is empty and is upgraded
 * immediately after a successful login/current-password check.
 */

function auth_password_algo()
{
    if (defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true)) {
        return PASSWORD_ARGON2ID;
    }
    return PASSWORD_DEFAULT;
}

function auth_password_options(): array
{
    if (defined('PASSWORD_ARGON2ID') && auth_password_algo() === PASSWORD_ARGON2ID) {
        return [
            'memory_cost' => 65536, // KiB = 64 MiB
            'time_cost' => 4,
            'threads' => 2,
        ];
    }
    // PASSWORD_DEFAULT is currently bcrypt on PHP 8.x. Use an explicit cost.
    return ['cost' => 12];
}

function auth_hash_password(string $password): string
{
    $hash = password_hash($password, auth_password_algo(), auth_password_options());
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Не удалось создать безопасный хэш пароля.');
    }
    return $hash;
}

function auth_column_exists(string $column): bool
{
    $stmt = db()->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute(['app_auth', $column]);
    return (bool)$stmt->fetchColumn();
}

function auth_ensure_schema(): void
{
    static $done = false;
    if ($done) return;

    db()->exec(
        "CREATE TABLE IF NOT EXISTS app_auth (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            username VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NULL,
            password_md5 CHAR(32) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Upgrade older installations created with MD5-only authentication.
    if (!auth_column_exists('password_hash')) {
        db()->exec('ALTER TABLE app_auth ADD COLUMN password_hash VARCHAR(255) NULL AFTER username');
    }
    if (auth_column_exists('password_md5')) {
        // Allow clearing the legacy MD5 value after automatic upgrade.
        try {
            db()->exec('ALTER TABLE app_auth MODIFY COLUMN password_md5 CHAR(32) NULL');
        } catch (Throwable $e) {
            // If a very unusual MySQL/MariaDB build refuses the metadata-only alter,
            // login can still work; password_hash always has priority over MD5.
        }
    } else {
        // Defensive case for a hand-created table.
        db()->exec('ALTER TABLE app_auth ADD COLUMN password_md5 CHAR(32) NULL AFTER password_hash');
    }

    $exists = (bool)db()->query('SELECT 1 FROM app_auth WHERE id = 1 LIMIT 1')->fetchColumn();
    if (!$exists) {
        $stmt = db()->prepare(
            'INSERT INTO app_auth (id, username, password_hash, password_md5) VALUES (1, ?, ?, NULL)'
        );
        $stmt->execute(['admin', auth_hash_password('admin')]);
    }
    $done = true;
}

function auth_is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    if (defined('TRUST_PROXY_HEADERS') && TRUST_PROXY_HEADERS) {
        $proto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($proto !== '') return explode(',', $proto)[0] === 'https';
    }
    return false;
}

function auth_send_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; object-src 'none'; img-src 'self' data: blob:; media-src 'self' blob:; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'");
}

function auth_csrf_token(): string
{
    auth_start_session();
    if (empty($_SESSION['solanace_csrf']) || !is_string($_SESSION['solanace_csrf'])) {
        $_SESSION['solanace_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['solanace_csrf'];
}

function auth_verify_csrf_request(): bool
{
    // Require the token even for JSON GET requests. Some historical GET actions
    // perform cache/root initialization, so treating every JSON endpoint as same-origin
    // is safer than relying on HTTP method semantics alone.
    auth_start_session();
    $expected = (string)($_SESSION['solanace_csrf'] ?? '');
    $provided = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function auth_default_password_active(): bool
{
    auth_ensure_schema();
    $stmt = db()->query('SELECT password_hash, password_md5 FROM app_auth WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch();
    if (!$row) return false;
    $hash = trim((string)($row['password_hash'] ?? ''));
    if ($hash !== '') return password_verify('admin', $hash);
    $legacy = strtolower(trim((string)($row['password_md5'] ?? '')));
    return preg_match('/^[a-f0-9]{32}$/', $legacy) && hash_equals($legacy, md5('admin'));
}

function auth_start_session(): void
{
    if (PHP_SAPI === 'cli') return;
    if (session_status() === PHP_SESSION_ACTIVE) return;

    auth_send_security_headers();
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    session_name('SOLANACE_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => auth_is_https_request(),
        'samesite' => 'Lax',
    ]);
    session_start();
}


function auth_slider_captcha_token(): string
{
    auth_start_session();
    if (empty($_SESSION['solanace_slider_captcha']) || !is_string($_SESSION['solanace_slider_captcha'])) {
        $_SESSION['solanace_slider_captcha'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['solanace_slider_captcha'];
}

function auth_verify_slider_captcha(string $token): bool
{
    auth_start_session();
    $expected = (string)($_SESSION['solanace_slider_captcha'] ?? '');
    $valid = $expected !== '' && $token !== '' && hash_equals($expected, $token);
    // Rotate after every attempt so the same solved token cannot be replayed indefinitely.
    $_SESSION['solanace_slider_captcha'] = bin2hex(random_bytes(24));
    return $valid;
}

function auth_is_logged_in(): bool
{
    auth_ensure_schema();
    auth_start_session();
    return !empty($_SESSION['video_catalog_authenticated']);
}

function auth_current_username(): string
{
    auth_ensure_schema();
    $stmt = db()->query('SELECT username FROM app_auth WHERE id = 1');
    return (string)($stmt->fetchColumn() ?: 'admin');
}

/**
 * Returns true if the password is valid. $upgrade is set when a legacy MD5
 * password or an old password_hash parameter set should be upgraded.
 */
function auth_verify_stored_password(string $password, array $row, bool &$upgrade = false): bool
{
    $upgrade = false;
    $secureHash = trim((string)($row['password_hash'] ?? ''));

    if ($secureHash !== '') {
        if (!password_verify($password, $secureHash)) return false;
        if (password_needs_rehash($secureHash, auth_password_algo(), auth_password_options())) {
            $upgrade = true;
        }
        return true;
    }

    // Legacy fallback only for installations that have not yet been upgraded.
    $legacy = strtolower(trim((string)($row['password_md5'] ?? '')));
    if (!preg_match('/^[a-f0-9]{32}$/', $legacy)) return false;
    if (!hash_equals($legacy, md5($password))) return false;
    $upgrade = true;
    return true;
}

function auth_upgrade_password_hash(string $password): void
{
    $hash = auth_hash_password($password);
    try {
        db()->prepare('UPDATE app_auth SET password_hash = ?, password_md5 = NULL WHERE id = 1')
            ->execute([$hash]);
    } catch (Throwable $e) {
        // Legacy NOT NULL fallback. The MD5 value becomes unusable random data,
        // while password_hash remains authoritative.
        db()->prepare('UPDATE app_auth SET password_hash = ?, password_md5 = ? WHERE id = 1')
            ->execute([$hash, bin2hex(random_bytes(16))]);
    }
}

function auth_login(string $username, string $password): bool
{
    auth_ensure_schema();
    auth_start_session();

    $stmt = db()->query('SELECT username, password_hash, password_md5 FROM app_auth WHERE id = 1');
    $row = $stmt->fetch();
    if (!$row) return false;

    $validUser = hash_equals((string)$row['username'], trim($username));
    if (!$validUser) {
        usleep(350000);
        return false;
    }

    $upgrade = false;
    if (!auth_verify_stored_password($password, $row, $upgrade)) {
        usleep(350000);
        return false;
    }
    if ($upgrade) auth_upgrade_password_hash($password);

    session_regenerate_id(true);
    $_SESSION['video_catalog_authenticated'] = true;
    $_SESSION['video_catalog_username'] = (string)$row['username'];
    return true;
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function auth_require_json(): void
{
    if (!auth_is_logged_in()) {
        json_response(['ok' => false, 'error' => 'Требуется вход в приложение.', 'auth_required' => true], 401);
    }
    if (!auth_verify_csrf_request()) {
        json_response(['ok' => false, 'error' => 'Недействительный CSRF-токен. Обновите страницу и повторите действие.'], 403);
    }
}

function auth_require_stream(): void
{
    if (auth_is_logged_in()) return;
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Требуется вход в приложение.';
    exit;
}

function auth_update_credentials(string $currentPassword, string $newUsername, string $newPassword): array
{
    auth_ensure_schema();
    auth_start_session();

    $stmt = db()->query('SELECT username, password_hash, password_md5 FROM app_auth WHERE id = 1');
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Настройки авторизации не найдены.');

    $upgrade = false;
    if (!auth_verify_stored_password($currentPassword, $row, $upgrade)) {
        throw new RuntimeException('Текущий пароль указан неверно.');
    }

    $username = trim($newUsername);
    if ($username === '') $username = (string)$row['username'];
    if (mb_strlen($username, 'UTF-8') > 190) {
        throw new RuntimeException('Логин слишком длинный.');
    }

    if ($newPassword !== '') {
        $newHash = auth_hash_password($newPassword);
        db()->prepare('UPDATE app_auth SET username = ?, password_hash = ?, password_md5 = NULL WHERE id = 1')
            ->execute([$username, $newHash]);
    } else {
        db()->prepare('UPDATE app_auth SET username = ? WHERE id = 1')->execute([$username]);
        if ($upgrade) auth_upgrade_password_hash($currentPassword);
    }

    $_SESSION['video_catalog_username'] = $username;
    return ['username' => $username, 'password_changed' => $newPassword !== ''];
}
