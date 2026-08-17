<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
$db = get_db();

// Existing local databases are upgraded automatically on first use.
$db->exec(
    'CREATE TABLE IF NOT EXISTS daily_mood_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        note_date DATE NOT NULL,
        mood VARCHAR(20) NOT NULL DEFAULT \'\',
        content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_mood_note_date (note_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

if ($method === 'GET') {
    $date = (string)($_GET['date'] ?? '');
    if (!validate_date($date)) json_error('日期格式无效');
    $stmt = $db->prepare(
        'SELECT note_date AS date, mood, content, updated_at
         FROM daily_mood_notes WHERE note_date = ? LIMIT 1'
    );
    $stmt->execute([$date]);
    $note = $stmt->fetch();
    json_success($note ?: ['date' => $date, 'mood' => '', 'content' => '', 'updated_at' => null]);
}

if ($method === 'POST') {
    $data = get_json_input();
    $date = optional_string($data, 'date');
    $mood = optional_string($data, 'mood');
    $content = optional_string($data, 'content');
    $allowed_moods = ['开心', '平静', '一般', '疲惫', '难过', ''];

    if (!validate_date($date)) json_error('日期格式无效');
    if (!in_array($mood, $allowed_moods, true)) json_error('心情选项无效');
    if (mb_strlen($content) > 5000) json_error('便签不能超过 5000 个字');

    $stmt = $db->prepare(
        'INSERT INTO daily_mood_notes (note_date, mood, content)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE mood = VALUES(mood), content = VALUES(content)'
    );
    $stmt->execute([$date, $mood, $content]);
    json_success(['date' => $date, 'mood' => $mood, 'content' => $content], '便签已保存');
}

json_error('Method not allowed', 405);
