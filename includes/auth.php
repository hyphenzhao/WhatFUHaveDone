<?php
/**
 * Authentication core
 *
 * Session + 30-day remember-me (selector:validator token pair).
 * All expiry math is done in MySQL (NOW()) to avoid PHP/MySQL timezone drift.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';

const REMEMBER_COOKIE = 'remember';
const REMEMBER_DAYS = 30;

/**
 * Start the session and attempt remember-me auto login.
 * Must be called once per request before any output (index.php).
 */
function auth_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => false, // 内网 HTTP 部署
    ]);
    session_start();
    if (empty($_SESSION['user_id'])) {
        try_remember_login();
    }
    // 会话指向的用户已被删除时，清掉会话（否则守卫放行但后续查询无主）
    if (!empty($_SESSION['user_id']) && current_user() === null) {
        unset($_SESSION['user_id']);
    }
}

function try_remember_login(): void {
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($cookie === '' || strpos($cookie, ':') === false) return;
    [$selector, $validator] = explode(':', $cookie, 2);
    if ($selector === '' || $validator === '') return;

    $db = get_db();
    $stmt = $db->prepare('SELECT id, user_id, validator_hash FROM auth_tokens WHERE selector = ? AND expires_at > NOW()');
    $stmt->execute([$selector]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        // 无效/过期 token：清 cookie
        setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        return;
    }

    $_SESSION['user_id'] = (int)$row['user_id'];
    session_regenerate_id(true);

    // 轮换 validator + 滑动续期 30 天（防 token 重放）
    $new_validator = bin2hex(random_bytes(32));
    $stmt = $db->prepare('UPDATE auth_tokens SET validator_hash = ?, expires_at = DATE_ADD(NOW(), INTERVAL ' . REMEMBER_DAYS . ' DAY) WHERE id = ?');
    $stmt->execute([hash('sha256', $new_validator), $row['id']]);
    setcookie(REMEMBER_COOKIE, $selector . ':' . $new_validator, [
        'expires' => time() + REMEMBER_DAYS * 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Verify credentials and establish a session.
 * Returns the user row on success, false on failure.
 */
function auth_login(string $username, string $password, bool $remember = true): array|false {
    $db = get_db();
    $stmt = $db->prepare('SELECT id, username, display_name, password_hash, role FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];

    if ($remember) {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $stmt = $db->prepare('INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . REMEMBER_DAYS . ' DAY))');
        $stmt->execute([$user['id'], $selector, hash('sha256', $validator)]);
        setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires' => time() + REMEMBER_DAYS * 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    unset($user['password_hash']);
    return $user;
}

function auth_logout(): void {
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($cookie !== '' && strpos($cookie, ':') !== false) {
        [$selector] = explode(':', $cookie, 2);
        $stmt = get_db()->prepare('DELETE FROM auth_tokens WHERE selector = ?');
        $stmt->execute([$selector]);
    }
    setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** Current logged-in user (id, username, display_name, role) or null. Cached per request. */
function current_user(): ?array {
    static $user = false;
    if ($user === false) {
        $uid = $_SESSION['user_id'] ?? 0;
        if (!$uid) {
            $user = null;
        } else {
            $stmt = get_db()->prepare('SELECT id, username, display_name, role FROM users WHERE id = ?');
            $stmt->execute([$uid]);
            $user = $stmt->fetch() ?: null;
        }
    }
    return $user;
}

function current_user_id(): int {
    $user = current_user();
    if (!$user) {
        json_error('Unauthorized', 401);
    }
    return (int)$user['id'];
}

function is_admin(): bool {
    $user = current_user();
    return $user !== null && $user['role'] === 'admin';
}

function require_admin(): void {
    if (!is_admin()) {
        json_error('Forbidden', 403);
    }
}
