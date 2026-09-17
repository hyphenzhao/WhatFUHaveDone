<?php
/**
 * Profile Impressions API — AI-inferred (secondary) facts about the user.
 *
 * GET    /api/impressions           — { fields: {field: row}, observations: [rows], labels: {field: label} }
 * POST   /api/impressions           — { field, value } (source=user, confidence=100)
 * PUT    /api/impressions/{id}      — { value?, field? } (becomes source=user)
 * DELETE /api/impressions/{id}
 * DELETE /api/impressions?all=1     — clear everything
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/profile_context.php';
require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
$parts = get_path_parts();
$db = get_db();
$uid = current_user_id();
$id = isset($parts[2]) ? (int)$parts[2] : 0;

if ($method === 'GET') {
    $st = $db->prepare('SELECT * FROM profile_impressions WHERE user_id = ? ORDER BY updated_at DESC, id DESC');
    $st->execute([$uid]);
    $fields = [];
    $observations = [];
    foreach ($st->fetchAll() as $r) {
        $r['confidence'] = (int)$r['confidence'];
        if ($r['field'] === 'observation') $observations[] = $r;
        elseif (!isset($fields[$r['field']])) $fields[$r['field']] = $r;
    }
    json_success(['fields' => $fields, 'observations' => $observations, 'labels' => profile_structured_fields()]);
}

if ($method === 'POST') {
    $data = get_json_input();
    try {
        $res = profile_remember($db, $uid, optional_string($data, 'field', 'observation'),
                                (string)($data['value'] ?? ''), 100, '', null, 'user');
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage());
    }
    json_success($res, '已记录');
}

if ($method === 'PUT' && $id) {
    $st = $db->prepare('SELECT * FROM profile_impressions WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    $row = $st->fetch();
    if (!$row) json_error('条目不存在', 404);
    $data = get_json_input();
    $value = trim((string)($data['value'] ?? $row['value']));
    if ($value === '') json_error('value 不能为空');
    $field = optional_string($data, 'field', $row['field']);
    $allowed = array_keys(profile_structured_fields());
    $allowed[] = 'observation';
    if (!in_array($field, $allowed, true)) json_error('未知字段');
    $db->prepare('UPDATE profile_impressions SET field = ?, value = ?, source = "user", confidence = 100, updated_at = NOW() WHERE id = ?')
       ->execute([$field, mb_substr($value, 0, 2000, 'UTF-8'), $id]);
    json_success(null, '已更新');
}

if ($method === 'DELETE') {
    if (isset($_GET['all'])) {
        $db->prepare('DELETE FROM profile_impressions WHERE user_id = ?')->execute([$uid]);
        json_success(null, '已清空 AI 印象');
    }
    if (!$id) json_error('ID required');
    $db->prepare('DELETE FROM profile_impressions WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    json_success(null, '已删除');
}

json_error('Method not allowed', 405);
