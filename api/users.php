<?php
/**
 * Users API (admin only)
 *
 * GET    /api/users          → list users
 * POST   /api/users          → create user {username, display_name, password, role}
 * PUT    /api/users/{id}     → update {display_name?, password?, role?}
 * DELETE /api/users/{id}     → delete user (cascades all data)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$db = get_db();
$method = get_method();
$parts = get_path_parts();
$id = isset($parts[2]) ? (int)$parts[2] : 0;
$self_id = current_user_id();

/**
 * 新用户预置数据：个人侧写、AI 配置默认值、is_me 中心人物。
 */
function seed_new_user(PDO $db, int $user_id, string $display_name): void {
    $db->prepare('INSERT INTO user_profile (user_id, name) VALUES (?, ?)')
       ->execute([$user_id, $display_name]);
    $db->prepare('INSERT INTO ai_config (user_id, provider, endpoint, api_key, model) VALUES (?, ?, ?, ?, ?)')
       ->execute([$user_id, AI_DEFAULT_PROVIDER, AI_DEFAULT_ENDPOINT, AI_DEFAULT_API_KEY, AI_DEFAULT_MODEL]);
    $db->prepare('INSERT INTO people (user_id, name, relationship, is_me) VALUES (?, ?, ?, 1)')
       ->execute([$user_id, $display_name !== '' ? $display_name : '我', '本人']);
}

if ($method === 'GET' && !$id) {
    $rows = $db->query('SELECT id, username, display_name, role, created_at, updated_at FROM users ORDER BY id ASC')->fetchAll();
    json_success($rows);
}

if ($method === 'POST' && !$id) {
    $input = get_json_input();
    $username = trim((string)($input['username'] ?? ''));
    $display_name = trim((string)($input['display_name'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $role = ($input['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

    if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{2,64}$/', $username)) {
        json_error('用户名需为 2-64 位字母、数字、_ . -');
    }
    if (strlen($password) < 6) {
        json_error('密码至少 6 位');
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        json_error('用户名已存在');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$username, $display_name, password_hash($password, PASSWORD_DEFAULT), $role]);
        $new_id = (int)$db->lastInsertId();
        seed_new_user($db, $new_id, $display_name !== '' ? $display_name : $username);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    json_success(['id' => $new_id], '用户已创建');
}

if ($method === 'PUT' && $id) {
    $stmt = $db->prepare('SELECT id, role FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) {
        json_error('用户不存在', 404);
    }

    $input = get_json_input();
    $fields = [];
    $params = [];

    if (isset($input['display_name'])) {
        $fields[] = 'display_name = ?';
        $params[] = trim((string)$input['display_name']);
    }
    if (isset($input['role'])) {
        $role = $input['role'] === 'admin' ? 'admin' : 'user';
        if ($id === $self_id && $role !== 'admin') {
            json_error('不能修改自己的角色');
        }
        $fields[] = 'role = ?';
        $params[] = $role;
    }
    if (isset($input['password']) && $input['password'] !== '') {
        if (strlen((string)$input['password']) < 6) {
            json_error('密码至少 6 位');
        }
        $fields[] = 'password_hash = ?';
        $params[] = password_hash((string)$input['password'], PASSWORD_DEFAULT);
        // 改密后失效该用户所有 remember token
        $db->prepare('DELETE FROM auth_tokens WHERE user_id = ?')->execute([$id]);
    }

    if (!$fields) {
        json_error('没有需要更新的字段');
    }
    $params[] = $id;
    $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    json_success(null, '用户已更新');
}

if ($method === 'DELETE' && $id) {
    if ($id === $self_id) {
        json_error('不能删除自己');
    }
    $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_error('用户不存在', 404);
    }
    json_success(null, '用户及其全部数据已删除');
}

json_error('Method not allowed', 405);
