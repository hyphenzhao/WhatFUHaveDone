<?php
/**
 * User Profile API
 *
 * GET  /api/profile          — get profile
 * PUT  /api/profile          — save profile
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
$db = get_db();
$uid = current_user_id();

if ($method === 'GET') {
    $stmt = $db->prepare('SELECT * FROM user_profile WHERE user_id = ?');
    $stmt->execute([$uid]);
    $profile = $stmt->fetch();
    if (!$profile) {
        $db->prepare("INSERT INTO user_profile (user_id, name) VALUES (?, '')")->execute([$uid]);
        $stmt = $db->prepare('SELECT * FROM user_profile WHERE user_id = ?');
        $stmt->execute([$uid]);
        $profile = $stmt->fetch();
    }
    json_success($profile);
}

if ($method === 'PUT') {
    $data = get_json_input();
    $fields = [];
    $params = [];
    foreach (['name','birth_date','birth_time','birth_place','gender','resume','goals',
              'bazi_year','bazi_month','bazi_day','bazi_time','shishen','nayin','dayun','shengxiao'] as $f) {
        if (array_key_exists($f, $data)) { $fields[] = "`$f` = ?"; $params[] = $data[$f]; }
    }
    // Ensure the row exists before UPDATE (lazy init for new users)
    $chk = $db->prepare('SELECT 1 FROM user_profile WHERE user_id = ?');
    $chk->execute([$uid]);
    if (!$chk->fetchColumn()) {
        $db->prepare("INSERT INTO user_profile (user_id, name) VALUES (?, '')")->execute([$uid]);
    }
    if ($fields) {
        $params[] = $uid;
        $db->prepare('UPDATE user_profile SET ' . implode(', ', $fields) . ' WHERE user_id = ?')->execute($params);
    }
    $stmt = $db->prepare('SELECT * FROM user_profile WHERE user_id = ?');
    $stmt->execute([$uid]);
    json_success($stmt->fetch(), 'Profile saved');
}

json_error('Method not allowed', 405);
