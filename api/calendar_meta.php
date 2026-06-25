<?php
/**
 * Calendar Metadata API — lunar dates, solar terms, holidays
 *
 * GET  /api/calendar_meta?month=YYYY-MM  — get metadata for a month
 * POST /api/calendar_meta                 — save batch metadata { dates: [...] }
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
$db = get_db();

// GET /api/calendar_meta?month=YYYY-MM
if ($method === 'GET') {
    $month = $_GET['month'] ?? date('Y-m');
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));

    $stmt = $db->prepare('SELECT * FROM calendar_meta WHERE date BETWEEN ? AND ? ORDER BY date');
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();

    // Group by date for easy lookup
    $days = [];
    foreach ($rows as $r) {
        $days[$r['date']] = $r;
    }

    json_success([
        'month' => $month,
        'start' => $start,
        'end' => $end,
        'days' => (object)$days, // force JSON object even when empty
    ]);
}

// POST /api/calendar_meta — save batch
if ($method === 'POST') {
    $data = get_json_input();
    $dates = $data['dates'] ?? [];

    if (empty($dates)) json_error('dates array required');

    $stmt = $db->prepare(
        'INSERT INTO calendar_meta (date, lunar_month, lunar_day, solar_term, holiday_name, is_holiday, is_workday)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
         lunar_month = VALUES(lunar_month),
         lunar_day = VALUES(lunar_day),
         solar_term = VALUES(solar_term),
         holiday_name = VALUES(holiday_name),
         is_holiday = VALUES(is_holiday),
         is_workday = VALUES(is_workday)'
    );

    $inserted = 0;
    foreach ($dates as $d) {
        $stmt->execute([
            $d['date'],
            $d['lunar_month'] ?? '',
            $d['lunar_day'] ?? '',
            $d['solar_term'] ?? '',
            $d['holiday_name'] ?? '',
            (int)($d['is_holiday'] ?? 0),
            (int)($d['is_workday'] ?? 0),
        ]);
        $inserted++;
    }

    json_success(['inserted' => $inserted], "Saved $inserted dates");
}

json_error('Method not allowed', 405);
