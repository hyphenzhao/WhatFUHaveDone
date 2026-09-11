<?php
/**
 * Relationships API — graph data for visualization
 *
 * GET /api/relationships — nodes (people) + edges (workload/results from Me to person)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
if ($method !== 'GET') json_error('Method not allowed', 405);

$db = get_db();
$uid = current_user_id();

// Get all non-archived people (excluding the "me" record from person nodes)
$stmt = $db->prepare('SELECT * FROM people WHERE archived = 0 AND is_me = 0 AND user_id = ? ORDER BY name');
$stmt->execute([$uid]);
$people = $stmt->fetchAll();

// Get the "me" record for the center node name
$stmt = $db->prepare('SELECT * FROM people WHERE is_me = 1 AND user_id = ? LIMIT 1');
$stmt->execute([$uid]);
$me = $stmt->fetch();

$nodes = [];
$edges = [];

// "Me" node (center) — use the "me" record's name if set
$meName = $me ? $me['name'] : '我';
$nodes[] = [
    'id' => 0,
    'name' => '我',
    'subtitle' => ($meName && $meName !== '我') ? $meName : '',
    'type' => 'me',
    'workload' => 0,
    'results' => 0,
];

foreach ($people as $person) {
    // Count workload: work_logs for tasks where this person is a beneficiary
    $stmt = $db->prepare('
        SELECT COUNT(wl.id) as cnt
        FROM work_logs wl
        JOIN task_people tp ON wl.task_id = tp.task_id
        JOIN tasks t ON wl.task_id = t.id
        WHERE tp.people_id = ? AND t.user_id = ?
    ');
    $stmt->execute([$person['id'], $uid]);
    $workload = (int)$stmt->fetchColumn();

    // Count results: result_logs for tasks where this person is a beneficiary
    $stmt = $db->prepare('
        SELECT COUNT(rl.id) as cnt
        FROM result_logs rl
        JOIN task_people tp ON rl.task_id = tp.task_id
        JOIN tasks t ON rl.task_id = t.id
        WHERE tp.people_id = ? AND t.user_id = ?
    ');
    $stmt->execute([$person['id'], $uid]);
    $result_count = (int)$stmt->fetchColumn();

    $nodes[] = [
        'id' => $person['id'],
        'name' => $person['name'],
        'type' => 'person',
        'relationship' => $person['relationship'],
        'importance' => (int)$person['importance'],
        'usefulness' => (int)$person['usefulness'],
        'workload' => $workload,
        'results' => $result_count,
    ];

    if ($workload > 0 || $result_count > 0) {
        $edges[] = [
            'source' => 0,
            'target' => $person['id'],
            'workload' => $workload,
            'results' => $result_count,
        ];
    }
}

// Sort nodes by workload (except Me)
usort($nodes, function ($a, $b) {
    if ($a['type'] === 'me') return -1;
    if ($b['type'] === 'me') return 1;
    return $b['workload'] - $a['workload'];
});

json_success([
    'nodes' => $nodes,
    'edges' => $edges,
]);
