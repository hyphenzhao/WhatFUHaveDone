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

// Aggregate once per metric; task archiving preserves historical activity.
$stmt = $db->prepare('SELECT tp.people_id,COUNT(wl.id) AS cnt FROM task_people tp JOIN tasks t ON t.id=tp.task_id JOIN work_logs wl ON wl.task_id=t.id WHERE t.user_id=? GROUP BY tp.people_id');
$stmt->execute([$uid]);
$workloads = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$stmt = $db->prepare('SELECT tp.people_id,COUNT(rl.id) AS cnt FROM task_people tp JOIN tasks t ON t.id=tp.task_id JOIN result_logs rl ON rl.task_id=t.id WHERE t.user_id=? GROUP BY tp.people_id');
$stmt->execute([$uid]);
$resultCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

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
    $workload = (int)($workloads[$person['id']] ?? 0);
    $result_count = (int)($resultCounts[$person['id']] ?? 0);

    $nodes[] = [
        'id' => $person['id'],
        'name' => $person['name'],
        'type' => 'person',
        'relationship' => $person['relationship'],
        'importance' => (int)$person['importance'],
        'usefulness' => (int)$person['usefulness'],
        'closeness' => (int)$person['closeness'],
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
