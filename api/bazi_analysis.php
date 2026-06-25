<?php
/**
 * BaZi Analysis API
 *
 * GET  /api/bazi_analysis?date=YYYY-MM-DD — get all analysis for a date
 * POST /api/bazi_analysis — save analysis { date_key, type, period_label, gan_zhi, shi_shen, analysis }
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
$db = get_db();

if ($method === 'GET') {
    $date = $_GET['date'] ?? today();
    $type = $_GET['type'] ?? null;

    $sql = 'SELECT * FROM bazi_analysis WHERE date_key = ?';
    $params = [$date];
    if ($type) { $sql .= ' AND type = ?'; $params[] = $type; }
    $sql .= ' ORDER BY FIELD(type,"dayun","liunian","liuyue","liuri"), id';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    json_success($stmt->fetchAll());
}

if ($method === 'POST') {
    $data = get_json_input();
    $date_key = optional_string($data, 'date_key', today());
    $type = optional_string($data, 'type');
    $period_label = optional_string($data, 'period_label');
    $gan_zhi = optional_string($data, 'gan_zhi');
    $shi_shen = optional_string($data, 'shi_shen');
    $analysis = optional_string($data, 'analysis');

    if (!$type || !$period_label) json_error('type and period_label required');

    $stmt = $db->prepare('INSERT INTO bazi_analysis (date_key, type, period_label, gan_zhi, shi_shen, analysis)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE gan_zhi=VALUES(gan_zhi), shi_shen=VALUES(shi_shen), analysis=VALUES(analysis)');
    $stmt->execute([$date_key, $type, $period_label, $gan_zhi, $shi_shen, $analysis]);

    json_success(['date_key' => $date_key, 'type' => $type, 'period_label' => $period_label], 'Analysis saved');
}

json_error('Method not allowed', 405);
