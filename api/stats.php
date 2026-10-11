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
require_once __DIR__ . '/../includes/activity_queries.php';

require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
if ($method !== 'GET') json_error('Method not allowed', 405);

$db = get_db();
$uid = current_user_id();
$type = $_GET['type'] ?? '';
$period = $_GET['period'] ?? 'all';
$ref_date = $_GET['ref_date'] ?? date('Y-m-d');

// Helper: compute start date for period filtering based on a reference date
function get_period_start(string $period, string $ref_date): string|null {
    $ts = strtotime($ref_date);
    return match ($period) {
        'week'   => date('Y-m-d', strtotime('monday this week', $ts)),
        'month'  => date('Y-m-01', $ts),
        'year'   => date('Y-01-01', $ts),
        default  => null,  // 'all' or unknown — no filter
    };
}

// Helper: compute the END of the period (for bounded=1 requests, so stats reflect
// the *selected* period only, not everything from its start onward).
function get_period_end(string $period, string $ref_date): ?string {
    $ts = strtotime($ref_date);
    return match ($period) {
        'week'   => date('Y-m-d', strtotime('sunday this week', $ts)),
        'month'  => date('Y-m-t', $ts),
        'year'   => date('Y-12-31', $ts),
        default  => null,
    };
}

// Workload leaderboard by tag
if ($type === 'workload') {
    $start = get_period_start($period, $ref_date);
    $sql = "SELECT t.id, t.name, t.color, COUNT(wl.id) as total_workload
            FROM tags t
            JOIN task_tags tt ON t.id = tt.tag_id
            JOIN work_logs wl ON tt.task_id = wl.task_id
            WHERE t.archived = 0 AND t.user_id = ?";
    $params = [$uid];
    if ($start) {
        $sql .= " AND wl.log_date >= ?";
        $params[] = $start;
        if (isset($_GET['bounded']) && ($end = get_period_end($period, $ref_date))) {
            $sql .= " AND wl.log_date <= ?";
            $params[] = $end;
        }
    }
    $sql .= " GROUP BY t.id, t.name, t.color ORDER BY total_workload DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    json_success($stmt->fetchAll());
}

// Results leaderboard by tag
if ($type === 'results') {
    $start = get_period_start($period, $ref_date);
    $sql = "SELECT t.id, t.name, t.color, COUNT(rl.id) as total_results
            FROM tags t
            JOIN task_tags tt ON t.id = tt.tag_id
            JOIN result_logs rl ON tt.task_id = rl.task_id
            WHERE t.archived = 0 AND t.user_id = ?";
    $params = [$uid];
    if ($start) {
        $sql .= " AND rl.log_date >= ?";
        $params[] = $start;
        if (isset($_GET['bounded']) && ($end = get_period_end($period, $ref_date))) {
            $sql .= " AND rl.log_date <= ?";
            $params[] = $end;
        }
    }
    $sql .= " GROUP BY t.id, t.name, t.color ORDER BY total_results DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    json_success($stmt->fetchAll());
}

// Calendar data for a month
if ($type === 'calendar') {
    $month = $_GET['month'] ?? date('Y-m');
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));

    $events = activity_calendar_events($db, $uid, $start, $end);

    // Attach tags to each task
    foreach ($events as &$e) {
        $stmt2 = $db->prepare('SELECT t.* FROM tags t JOIN task_tags tt ON t.id = tt.tag_id WHERE tt.task_id = ? LIMIT 1');
        $stmt2->execute([$e['id']]);
        $e['tag'] = $stmt2->fetch();
    }
    unset($e); // break the reference to avoid corrupting the last element in the next foreach

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

    $daily = activity_daily_records($db, $uid, $date);
    $work_tasks = $daily['work_tasks'];
    $result_tasks = $daily['result_tasks'];
    $plan_tasks = $daily['plan_tasks'];

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

// Workload detail by tag — returns per-task breakdown
if ($type === 'workload_detail') {
    $tag_id = (int)($_GET['tag_id'] ?? 0);
    if (!$tag_id) json_error('tag_id required');
    $start = get_period_start($period, $ref_date);

    $tasks = activity_workload_detail($db, $uid, $tag_id, $start);

    json_success([
        'tag_id' => $tag_id,
        'tasks' => $tasks,
    ]);
}

// Results detail by tag — returns per-result breakdown
if ($type === 'results_detail') {
    $tag_id = (int)($_GET['tag_id'] ?? 0);
    if (!$tag_id) json_error('tag_id required');
    $start = get_period_start($period, $ref_date);

    $sql = "SELECT r.id, r.name, r.level, r.quantity, t.name as task_name,
                   rl.log_date, rl.id as log_id
            FROM result_logs rl
            JOIN results r ON rl.result_id = r.id
            JOIN tasks t ON rl.task_id = t.id
            JOIN task_tags tt ON t.id = tt.task_id
            WHERE tt.tag_id = ? AND t.user_id = ?";
    $params = [(int)$tag_id, $uid];
    if ($start) {
        $sql .= " AND rl.log_date >= ?";
        $params[] = $start;
    }
    $sql .= " ORDER BY r.level ASC, r.name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    json_success([
        'tag_id' => $tag_id,
        'results' => $results,
    ]);
}

json_error('Invalid type parameter. Use: workload, results, calendar, daily, workload_detail, results_detail');
