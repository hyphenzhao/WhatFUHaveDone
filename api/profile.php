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

$method = get_method();
$db = get_db();

if ($method === 'GET') {
    $stmt = $db->query('SELECT * FROM user_profile WHERE id = 1');
    $profile = $stmt->fetch();
    if (!$profile) {
        $db->exec("INSERT INTO user_profile (id, name) VALUES (1, '')");
        $stmt = $db->query('SELECT * FROM user_profile WHERE id = 1');
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
    if ($fields) {
        $params[] = 1;
        $db->prepare('UPDATE user_profile SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    }
    $stmt = $db->query('SELECT * FROM user_profile WHERE id = 1');
    json_success($stmt->fetch(), 'Profile saved');
}

json_error('Method not allowed', 405);
