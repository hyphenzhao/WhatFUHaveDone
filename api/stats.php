<?php
/**
 * Stats API — leaderboards, calendar data, daily status
 *
 * GET /api/stats?type=workload        — workload leaderboard by tag
 * GET /api/stats?type=results         — results leaderboard by tag
 * GET /api/stats?type=calendar&month=YYYY-MM  — calendar data
 * GET /api/stats?type=daily&date=YYYY-MM-DD   — daily status
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
if ($method !== 'GET') json_error('Method not allowed', 405);

$db = get_db();
$type = $_GET['type'] ?? '';

// Workload leaderboard by tag
if ($type === 'workload') {
    $sql = "SELECT t.id, t.name, t.color, COUNT(wl.id) as total_workload
            FROM tags t
            JOIN task_tags tt ON t.id = tt.tag_id
            JOIN work_logs wl ON tt.task_id = wl.task_id
            WHERE t.archived = 0
            GROUP BY t.id, t.name, t.color
            ORDER BY total_workload DESC";
    json_success($db->query($sql)->fetchAll());
}

// Results leaderboard by tag
if ($type === 'results') {
    $sql = "SELECT t.id, t.name, t.color, COUNT(rl.id) as total_results
            FROM tags t
            JOIN task_tags tt ON t.id = tt.tag_id
            JOIN result_logs rl ON tt.task_id = rl.task_id
            WHERE t.archived = 0
            GROUP BY t.id, t.name, t.color
            ORDER BY total_results DESC";
    json_success($db->query($sql)->fetchAll());
}

// Calendar data for a month
if ($type === 'calendar') {
    $month = $_GET['month'] ?? date('Y-m');
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));

    // Get all tasks that have work logs or plans in this month range
    $sql = "SELECT DISTINCT t.id, t.name, wl.log_date as event_date, 'work' as event_type
            FROM tasks t
            JOIN work_logs wl ON t.id = wl.task_id
            WHERE wl.log_date BETWEEN ? AND ? AND t.archived = 0
            UNION ALL
            SELECT DISTINCT t.id, t.name, p.planned_date as event_date, 'plan' as event_type
            FROM tasks t
            JOIN plans p ON t.id = p.task_id
            WHERE p.planned_date BETWEEN ? AND ? AND t.archived = 0
            UNION ALL
            SELECT DISTINCT t.id, t.name, rl.log_date as event_date, 'result' as event_type
            FROM tasks t
            JOIN result_logs rl ON t.id = rl.task_id
            WHERE rl.log_date BETWEEN ? AND ? AND t.archived = 0
            ORDER BY event_date, id";
    $stmt = $db->prepare($sql);
    $stmt->execute([$start, $end, $start, $end, $start, $end]);
    $events = $stmt->fetchAll();

    // Attach tags to each task
    foreach ($events as &$e) {
        $stmt2 = $db->prepare('SELECT t.* FROM tags t JOIN task_tags tt ON t.id = tt.tag_id WHERE tt.task_id = ? LIMIT 1');
        $stmt2->execute([$e['id']]);
        $e['tag'] = $stmt2->fetch();
    }

    // Group by date
    $calendar = [];
    foreach ($events as $e) {
        $d = $e['event_date'];
        if (!isset($calendar[$d])) $calendar[$d] = [];
        $calendar[$d][] = $e;
    }

    json_success([
        'month' => $month,
        'start' => $start,
        'end' => $end,
        'days' => $calendar,
    ]);
}

// Daily status
if ($type === 'daily') {
    $date = $_GET['date'] ?? today();

    // Tasks with work logged on this date
    $sql = "SELECT t.*, wl.id as work_log_id
            FROM tasks t
            JOIN work_logs wl ON t.id = wl.task_id
            WHERE wl.log_date = ? AND t.archived = 0";
    $stmt = $db->prepare($sql);
    $stmt->execute([$date]);
    $work_tasks = $stmt->fetchAll();

    // Tasks with results logged on this date
    $sql = "SELECT t.*, rl.id as result_log_id, rl.result_id, r.name as result_name
            FROM tasks t
            JOIN result_logs rl ON t.id = rl.task_id
            JOIN results r ON rl.result_id = r.id
            WHERE rl.log_date = ? AND t.archived = 0";
    $stmt = $db->prepare($sql);
    $stmt->execute([$date]);
    $result_tasks = $stmt->fetchAll();

    // Tasks planned for this date
    $sql = "SELECT t.*, p.id as plan_id
            FROM tasks t
            JOIN plans p ON t.id = p.task_id
            WHERE p.planned_date = ? AND t.archived = 0";
    $stmt = $db->prepare($sql);
    $stmt->execute([$date]);
    $plan_tasks = $stmt->fetchAll();

    // Attach tags to each task
    $attach_tags = function(array &$tasks) use ($db) {
        foreach ($tasks as &$t) {
            $stmt2 = $db->prepare('SELECT t2.* FROM tags t2 JOIN task_tags tt ON t2.id = tt.tag_id WHERE tt.task_id = ?');
            $stmt2->execute([$t['id']]);
            $t['tags'] = $stmt2->fetchAll();
        }
    };
    $attach_tags($work_tasks);
    $attach_tags($result_tasks);
    $attach_tags($plan_tasks);

    json_success([
        'date' => $date,
        'work_tasks' => $work_tasks,
        'result_tasks' => $result_tasks,
        'plan_tasks' => $plan_tasks,
    ]);
}

json_error('Invalid type parameter. Use: workload, results, calendar, daily');
