<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/external_auth.php';
$uid = current_user_id(); $db = get_db(); $method = get_method();
header('Cache-Control: no-store');
$_SESSION['external_keys_csrf'] ??= bin2hex(random_bytes(24));
try {
    if ($method === 'GET') {
        $st = $db->prepare('SELECT id,name,token_prefix,scopes_json,expires_at,(expires_at<=NOW()) AS expired,revoked_at,last_used_at,created_at FROM external_api_tokens WHERE user_id=? ORDER BY id DESC');
        $st->execute([$uid]);
        json_success(['keys' => $st->fetchAll(), 'scopes' => external_scopes(), 'csrf' => $_SESSION['external_keys_csrf']]);
    }
    if (!hash_equals($_SESSION['external_keys_csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) throw new ExternalApiError('请刷新页面后重试', 403);
    if ($method === 'POST') {
        $d = external_input();
        if (!is_array($d['scopes'] ?? null) || !is_string($d['name'] ?? null) || !is_int($d['days'] ?? 90)) throw new ExternalApiError('名称、权限或有效期格式无效');
        json_success(external_create_token($db, $uid, $d['name'], $d['scopes'], $d['days'] ?? 90));
    }
    if ($method === 'DELETE') {
        $id = (int)(get_path_parts()[2] ?? 0);
        $st = $db->prepare('UPDATE external_api_tokens SET revoked_at=COALESCE(revoked_at,NOW()) WHERE id=? AND user_id=?');
        $st->execute([$id, $uid]);
        json_success(null, '已撤销');
    }
    throw new ExternalApiError('Method not allowed', 405);
} catch (ExternalApiError $e) { json_error($e->getMessage(), $e->status); }
