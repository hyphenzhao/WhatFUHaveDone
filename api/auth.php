<?php
/**
 * Auth API
 *
 * POST /api/auth/login   {username, password, remember} → user info
 * POST /api/auth/logout
 * GET  /api/auth/me      → current user or 401
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
$parts = get_path_parts();
$action = $parts[2] ?? '';

if ($method === 'POST' && $action === 'login') {
    $input = get_json_input();
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $remember = !isset($input['remember']) || (bool)$input['remember'];

    if ($username === '' || $password === '') {
        json_error('请输入用户名和密码', 400);
    }

    // 简单节流：同一会话连续失败 5 次后延时
    $fails = $_SESSION['login_fails'] ?? 0;
    if ($fails >= 5) {
        sleep(2);
    }

    $user = auth_login($username, $password, $remember);
    if ($user === false) {
        $_SESSION['login_fails'] = $fails + 1;
        json_error('用户名或密码错误', 401);
    }
    unset($_SESSION['login_fails']);
    json_success($user, '登录成功');
}

if ($method === 'POST' && $action === 'logout') {
    auth_logout();
    json_success(null, '已登出');
}

if ($method === 'GET' && $action === 'me') {
    $user = current_user();
    if (!$user) {
        json_error('Unauthorized', 401);
    }
    json_success($user);
}

json_error('Method not allowed', 405);
