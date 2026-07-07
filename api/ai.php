<?php
/**
 * AI Assistant API — chat, config, tool execution
 *
 * POST /api/ai/chat          — send conversation, get response or pending confirmations
 * POST /api/ai/confirm       — execute confirmed write tools
 * GET  /api/ai/config         — get AI config (api_key masked)
 * POST /api/ai/config         — save AI config
 * POST /api/ai/config/test    — test LLM connection
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
$parts = get_path_parts();
$db = get_db();
$action = $parts[2] ?? 'chat';

// --- Config helpers ---

function load_ai_config(PDO $db): array {
    $stmt = $db->query('SELECT * FROM ai_config ORDER BY id DESC LIMIT 1');
    $row = $stmt->fetch();
    if (!$row) {
        $db->prepare('INSERT INTO ai_config (provider, endpoint, api_key, model) VALUES (?, ?, ?, ?)')
           ->execute([AI_DEFAULT_PROVIDER, AI_DEFAULT_ENDPOINT, AI_DEFAULT_API_KEY, AI_DEFAULT_MODEL]);
        return [
            'provider' => AI_DEFAULT_PROVIDER,
            'endpoint' => AI_DEFAULT_ENDPOINT,
            'api_key' => AI_DEFAULT_API_KEY,
            'model' => AI_DEFAULT_MODEL,
        ];
    }
    return $row;
}

function call_llm(array $config, array $messages, array $tools, int $timeout = 60): array {
    $url = rtrim($config['endpoint'], '/') . '/chat/completions';

    $body = [
        'model' => $config['model'],
        'messages' => $messages,
        'tools' => $tools,
        'max_tokens' => AI_MAX_TOKENS,
        'temperature' => AI_TEMPERATURE,
        'stream' => false,
    ];

    $headers = ['Content-Type: application/json'];
    if (!empty($config['api_key'])) {
        $headers[] = 'Authorization: Bearer ' . $config['api_key'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => max(5, $timeout),
        CURLOPT_CONNECTTIMEOUT => min(10, max(3, $timeout)),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) throw new Exception('LLM connection failed: ' . $error);
    if ($httpCode !== 200) {
        $body = json_decode($response, true);
        $msg = $body['error']['message'] ?? "HTTP $httpCode";
        throw new Exception("LLM error: $msg");
    }

    $data = json_decode($response, true);
    $choice = $data['choices'][0]['message'] ?? [];
    $msg = [
        'role' => 'assistant',
        'content' => $choice['content'] ?? '',
        '_usage' => $data['usage'] ?? [],
        '_model' => $data['model'] ?? '',
        '_finish_reason' => $data['choices'][0]['finish_reason'] ?? '',
    ];
    if (!empty($choice['tool_calls'])) {
        $msg['tool_calls'] = $choice['tool_calls'];
    }
    return $msg;
}

/**
 * Browser conversation history is persisted for display, not as a complete
 * tool transcript. Remove historical tool calls/results so an old assistant
 * tool_calls message can never be sent without matching tool responses.
 * The current confirmation message is appended separately and keeps its
 * tool_calls intact.
 */
function normalize_conversation_history(array $history): array {
    $normalized = [];
    foreach ($history as $message) {
        if (!is_array($message)) continue;
        $role = $message['role'] ?? '';
        if ($role !== 'user' && $role !== 'assistant') continue;

        $content = $message['content'] ?? '';
        if (!is_string($content)) $content = '';
        if ($content === '' && $role === 'assistant') continue;

        $normalized[] = [
            'role' => $role,
            'content' => $content,
        ];
    }
    return $normalized;
}

// --- Tool system ---

function get_tool_definitions(): array {
    return [
        // ===== TASKS =====
        [
            'name' => 'list_tasks',
            'description' => '列出所有任务，可按归档状态和阶段筛选',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'archived' => ['type' => 'integer', 'description' => '0=活跃(默认), 1=已归档'],
                    'stage' => ['type' => 'string', 'enum' => ['in_progress', 'stage_complete', 'completed', 'failed'], 'description' => '按阶段筛选'],
                ],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_list_tasks',
        ],
        [
            'name' => 'get_task',
            'description' => '获取单个任务的详细信息，包含受益人、标签、成果',
            'parameters' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => '任务ID']],
                'required' => ['id'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_get_task',
        ],
        [
            'name' => 'create_task',
            'description' => '创建新任务',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => '任务名称（必填）'],
                    'description' => ['type' => 'string', 'description' => '任务描述'],
                    'stage' => ['type' => 'string', 'enum' => ['in_progress', 'stage_complete', 'completed', 'failed']],
                    'people_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'tag_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ],
                'required' => ['name'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_create_task',
        ],
        [
            'name' => 'update_task',
            'description' => '更新任务（可部分更新）',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => '任务ID（必填）'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'stage' => ['type' => 'string', 'enum' => ['in_progress', 'stage_complete', 'completed', 'failed']],
                    'stage_number' => ['type' => 'integer'],
                    'archived' => ['type' => 'integer'],
                    'people_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'tag_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ],
                'required' => ['id'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_update_task',
        ],
        [
            'name' => 'delete_task',
            'description' => '永久删除任务',
            'parameters' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => '任务ID（必填）']],
                'required' => ['id'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_delete_task',
        ],
        // ===== PEOPLE =====
        [
            'name' => 'list_people',
            'description' => '列出所有人物',
            'parameters' => ['type' => 'object', 'properties' => ['archived' => ['type' => 'integer']]],
            'requires_confirmation' => false,
            'handler' => 'handle_list_people',
        ],
        [
            'name' => 'get_person',
            'description' => '获取单个人物信息',
            'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
            'requires_confirmation' => false,
            'handler' => 'handle_get_person',
        ],
        [
            'name' => 'create_person',
            'description' => '创建新人物',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => '姓名（必填）'],
                    'relationship' => ['type' => 'string', 'description' => '关系'],
                    'importance' => ['type' => 'integer', 'description' => '重要性 0-5'],
                    'usefulness' => ['type' => 'integer', 'description' => '有用程度 0-5'],
                ],
                'required' => ['name'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_create_person',
        ],
        [
            'name' => 'update_person',
            'description' => '更新人物信息',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => '人物ID（必填）'],
                    'name' => ['type' => 'string'],
                    'relationship' => ['type' => 'string'],
                    'importance' => ['type' => 'integer'],
                    'usefulness' => ['type' => 'integer'],
                    'archived' => ['type' => 'integer'],
                ],
                'required' => ['id'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_update_person',
        ],
        // ===== TAGS =====
        [
            'name' => 'list_tags',
            'description' => '列出所有标签',
            'parameters' => ['type' => 'object', 'properties' => ['archived' => ['type' => 'integer']]],
            'requires_confirmation' => false,
            'handler' => 'handle_list_tags',
        ],
        [
            'name' => 'create_tag',
            'description' => '创建新标签',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => '标签名（必填）'],
                    'color' => ['type' => 'string', 'description' => '颜色 hex，如 #3B82F6'],
                ],
                'required' => ['name'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_create_tag',
        ],
        [
            'name' => 'update_tag',
            'description' => '更新标签',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => '标签ID（必填）'],
                    'name' => ['type' => 'string'],
                    'color' => ['type' => 'string'],
                    'archived' => ['type' => 'integer'],
                ],
                'required' => ['id'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_update_tag',
        ],
        // ===== RESULTS =====
        [
            'name' => 'list_results',
            'description' => '列出所有成果',
            'parameters' => ['type' => 'object', 'properties' => ['archived' => ['type' => 'integer']]],
            'requires_confirmation' => false,
            'handler' => 'handle_list_results',
        ],
        // ===== WORK LOGS =====
        [
            'name' => 'toggle_worklog',
            'description' => '切换某任务在某日期的工作量记录（有则删，无则加）',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => ['type' => 'integer', 'description' => '任务ID（必填）'],
                    'date' => ['type' => 'string', 'description' => '日期 YYYY-MM-DD'],
                ],
                'required' => ['task_id'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_toggle_worklog',
        ],
        [
            'name' => 'get_worklogs_by_date',
            'description' => '获取某日期的所有工作量记录',
            'parameters' => [
                'type' => 'object',
                'properties' => ['date' => ['type' => 'string', 'description' => '日期 YYYY-MM-DD（必填）']],
                'required' => ['date'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_get_worklogs_by_date',
        ],
        // ===== PLANS =====
        [
            'name' => 'add_plan',
            'description' => '为任务添加计划日期',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => ['type' => 'integer', 'description' => '任务ID（必填）'],
                    'planned_date' => ['type' => 'string', 'description' => '计划日期 YYYY-MM-DD（必填）'],
                ],
                'required' => ['task_id', 'planned_date'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_add_plan',
        ],
        // ===== RESULT LOGS =====
        [
            'name' => 'add_result_log',
            'description' => '为任务记录一条成果',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => ['type' => 'integer', 'description' => '任务ID（必填）'],
                    'result_id' => ['type' => 'integer', 'description' => '成果ID（必填）'],
                    'date' => ['type' => 'string', 'description' => '日期 YYYY-MM-DD'],
                ],
                'required' => ['task_id', 'result_id'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_add_result_log',
        ],
        // ===== STATS =====
        [
            'name' => 'get_workload_stats',
            'description' => '获取工作量排行榜（按标签汇总）',
            'parameters' => ['type' => 'object', 'properties' => []],
            'requires_confirmation' => false,
            'handler' => 'handle_get_workload_stats',
        ],
        [
            'name' => 'get_results_stats',
            'description' => '获取成果排行榜（按标签汇总）',
            'parameters' => ['type' => 'object', 'properties' => []],
            'requires_confirmation' => false,
            'handler' => 'handle_get_results_stats',
        ],
        [
            'name' => 'get_calendar_data',
            'description' => '获取某月的日历数据（含任务标记点）',
            'parameters' => [
                'type' => 'object',
                'properties' => ['month' => ['type' => 'string', 'description' => '月份 YYYY-MM（必填）']],
                'required' => ['month'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_get_calendar_data',
        ],
        [
            'name' => 'get_daily_status',
            'description' => '获取某日的详细状态（工作量、成果、计划）',
            'parameters' => [
                'type' => 'object',
                'properties' => ['date' => ['type' => 'string', 'description' => '日期 YYYY-MM-DD（必填）']],
                'required' => ['date'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_get_daily_status',
        ],
        [
            'name' => 'get_relationships',
            'description' => '获取人际关系图谱数据',
            'parameters' => ['type' => 'object', 'properties' => []],
            'requires_confirmation' => false,
            'handler' => 'handle_get_relationships',
        ],
        // ===== WEATHER =====
        [
            'name' => 'get_weather',
            'description' => '获取指定日期的天气数据（温度、天气状况、湿度、风速）',
            'parameters' => [
                'type' => 'object',
                'properties' => ['date' => ['type' => 'string', 'description' => '日期 YYYY-MM-DD（必填）']],
                'required' => ['date'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_get_weather',
        ],
        // ===== WORKLOG NOTES =====
        [
            'name' => 'get_worklog_notes',
            'description' => '获取某个工作量记录的备注列表',
            'parameters' => [
                'type' => 'object',
                'properties' => ['worklog_id' => ['type' => 'integer', 'description' => '工作量记录ID（必填）']],
                'required' => ['worklog_id'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_get_worklog_notes',
        ],
        [
            'name' => 'add_worklog_note',
            'description' => '为某个工作量记录添加备注',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'worklog_id' => ['type' => 'integer', 'description' => '工作量记录ID（必填）'],
                    'content' => ['type' => 'string', 'description' => '备注内容（必填）'],
                ],
                'required' => ['worklog_id', 'content'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_add_worklog_note',
        ],
        // ===== BAZI & CALENDAR =====
        [
            'name' => 'get_user_profile',
            'description' => '获取用户的个人侧写，包含八字四柱、紫微命盘、简历、目标等完整信息',
            'parameters' => ['type' => 'object', 'properties' => []],
            'requires_confirmation' => false,
            'handler' => 'handle_get_user_profile',
        ],
        [
            'name' => 'get_bazi_analysis',
            'description' => '获取指定日期的八字大运/流年/流月/流日分析。type可选: dayun/liunian/liuyue/liuri。分析包含干支、十神和AI解析文字。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'date' => ['type' => 'string', 'description' => '日期 YYYY-MM-DD（必填）'],
                    'type' => ['type' => 'string', 'description' => '分析类型: dayun/liunian/liuyue/liuri'],
                ],
                'required' => ['date'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_get_bazi_analysis',
        ],
        [
            'name' => 'save_bazi_analysis',
            'description' => '保存或更新指定日期的八字分析。用于AI重新分析后更新流日/流年/流月/大运的解析文字。type必须是dayun/liunian/liuyue/liuri之一。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'date' => ['type' => 'string', 'description' => '日期 YYYY-MM-DD（必填）'],
                    'type' => ['type' => 'string', 'description' => '分析类型: dayun/liunian/liuyue/liuri（必填）'],
                    'period_label' => ['type' => 'string', 'description' => '时期标签，如"大运""流年"'],
                    'gan_zhi' => ['type' => 'string', 'description' => '干支，如"丙午"'],
                    'shi_shen' => ['type' => 'string', 'description' => '十神'],
                    'analysis' => ['type' => 'string', 'description' => 'AI解析文字（必填）'],
                ],
                'required' => ['date', 'type', 'analysis'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_save_bazi_analysis',
        ],
        [
            'name' => 'get_calendar_meta',
            'description' => '获取指定日期的农历、节气、节假日等黄历信息',
            'parameters' => [
                'type' => 'object',
                'properties' => ['date' => ['type' => 'string', 'description' => '日期 YYYY-MM-DD（必填）']],
                'required' => ['date'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_get_calendar_meta',
        ],
    ];
}

// ===== TOOL HANDLERS =====

function handle_list_tasks(PDO $db, array $args): array {
    $archived = $args['archived'] ?? 0;
    $stage = $args['stage'] ?? null;
    $sql = 'SELECT id, name, description, stage, stage_number, archived FROM tasks WHERE archived = ?';
    $params = [$archived];
    if ($stage) { $sql .= ' AND stage = ?'; $params[] = $stage; }
    $sql .= ' ORDER BY updated_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function handle_get_task(PDO $db, array $args): ?array {
    return get_task_full($db, (int)$args['id']);
}

function handle_create_task(PDO $db, array $args): array {
    $stmt = $db->prepare('INSERT INTO tasks (name, description, stage) VALUES (?, ?, ?)');
    $stmt->execute([$args['name'], $args['description'] ?? '', $args['stage'] ?? 'in_progress']);
    $id = (int)$db->lastInsertId();
    if (!empty($args['people_ids'])) attach_people($db, $id, $args['people_ids']);
    if (!empty($args['tag_ids'])) attach_task_tags($db, $id, $args['tag_ids']);
    return get_task_full($db, $id);
}

function handle_update_task(PDO $db, array $args): array {
    $id = (int)$args['id'];
    $fields = []; $params = [];
    foreach (['name', 'description', 'stage', 'stage_number', 'archived'] as $f) {
        if (array_key_exists($f, $args)) { $fields[] = "$f = ?"; $params[] = $args[$f]; }
    }
    if ($fields) { $params[] = $id; $db->prepare('UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params); }
    if (isset($args['people_ids'])) attach_people($db, $id, $args['people_ids']);
    if (isset($args['tag_ids'])) attach_task_tags($db, $id, $args['tag_ids']);
    return get_task_full($db, $id);
}

function handle_delete_task(PDO $db, array $args): array {
    $id = (int)$args['id'];
    $task = get_task_full($db, $id);
    $db->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
    return ['deleted' => $task];
}

function handle_list_people(PDO $db, array $args): array {
    $archived = $args['archived'] ?? 0;
    $stmt = $db->prepare('SELECT id, name, relationship, importance, usefulness FROM people WHERE archived = ? ORDER BY importance DESC, name ASC');
    $stmt->execute([$archived]);
    return $stmt->fetchAll();
}

function handle_get_person(PDO $db, array $args): ?array {
    $stmt = $db->prepare('SELECT * FROM people WHERE id = ?');
    $stmt->execute([(int)$args['id']]);
    return $stmt->fetch() ?: null;
}

function handle_create_person(PDO $db, array $args): array {
    $stmt = $db->prepare('INSERT INTO people (name, relationship, importance, usefulness) VALUES (?, ?, ?, ?)');
    $stmt->execute([$args['name'], $args['relationship'] ?? '', $args['importance'] ?? 0, $args['usefulness'] ?? 0]);
    $stmt = $db->prepare('SELECT * FROM people WHERE id = ?');
    $stmt->execute([(int)$db->lastInsertId()]);
    return $stmt->fetch();
}

function handle_update_person(PDO $db, array $args): array {
    $id = (int)$args['id'];
    $fields = []; $params = [];
    foreach (['name', 'relationship', 'importance', 'usefulness', 'archived'] as $f) {
        if (array_key_exists($f, $args)) { $fields[] = "$f = ?"; $params[] = $args[$f]; }
    }
    if ($fields) { $params[] = $id; $db->prepare('UPDATE people SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params); }
    $stmt = $db->prepare('SELECT * FROM people WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function handle_list_tags(PDO $db, array $args): array {
    $archived = $args['archived'] ?? 0;
    $stmt = $db->prepare('SELECT id, name, color FROM tags WHERE archived = ? ORDER BY name ASC');
    $stmt->execute([$archived]);
    return $stmt->fetchAll();
}

function handle_create_tag(PDO $db, array $args): array {
    $stmt = $db->prepare('INSERT INTO tags (name, color) VALUES (?, ?)');
    $stmt->execute([$args['name'], $args['color'] ?? '#3B82F6']);
    $stmt = $db->prepare('SELECT * FROM tags WHERE id = ?');
    $stmt->execute([(int)$db->lastInsertId()]);
    return $stmt->fetch();
}

function handle_update_tag(PDO $db, array $args): array {
    $id = (int)$args['id'];
    $fields = []; $params = [];
    foreach (['name', 'color', 'archived'] as $f) {
        if (array_key_exists($f, $args)) { $fields[] = "$f = ?"; $params[] = $args[$f]; }
    }
    if ($fields) { $params[] = $id; $db->prepare('UPDATE tags SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params); }
    $stmt = $db->prepare('SELECT * FROM tags WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function handle_list_results(PDO $db, array $args): array {
    $archived = $args['archived'] ?? 0;
    $stmt = $db->prepare('SELECT id, name, quantity, level FROM results WHERE archived = ? ORDER BY name ASC');
    $stmt->execute([$archived]);
    return $stmt->fetchAll();
}

function handle_toggle_worklog(PDO $db, array $args): array {
    $task_id = (int)$args['task_id'];
    $date = $args['date'] ?? today();
    $stmt = $db->prepare('SELECT id FROM work_logs WHERE task_id = ? AND log_date = ?');
    $stmt->execute([$task_id, $date]);
    $existing = $stmt->fetch();
    if ($existing) {
        $db->prepare('DELETE FROM work_logs WHERE id = ?')->execute([$existing['id']]);
        return ['action' => 'removed', 'task_id' => $task_id, 'date' => $date];
    }
    $db->prepare('INSERT INTO work_logs (task_id, log_date) VALUES (?, ?)')->execute([$task_id, $date]);
    return ['action' => 'added', 'task_id' => $task_id, 'date' => $date];
}

function handle_get_worklogs_by_date(PDO $db, array $args): array {
    $stmt = $db->prepare('SELECT wl.*, t.name as task_name FROM work_logs wl JOIN tasks t ON wl.task_id = t.id WHERE wl.log_date = ?');
    $stmt->execute([$args['date']]);
    return $stmt->fetchAll();
}

function handle_add_plan(PDO $db, array $args): array {
    $stmt = $db->prepare('INSERT INTO plans (task_id, planned_date) VALUES (?, ?)');
    $stmt->execute([(int)$args['task_id'], $args['planned_date']]);
    return ['id' => (int)$db->lastInsertId(), 'task_id' => (int)$args['task_id'], 'planned_date' => $args['planned_date']];
}

function handle_add_result_log(PDO $db, array $args): array {
    $stmt = $db->prepare('INSERT INTO result_logs (task_id, result_id, log_date) VALUES (?, ?, ?)');
    $stmt->execute([(int)$args['task_id'], (int)$args['result_id'], $args['date'] ?? today()]);
    return ['id' => (int)$db->lastInsertId()];
}

function handle_get_workload_stats(PDO $db, array $args): array {
    $sql = "SELECT t.id, t.name, t.color, COUNT(wl.id) as total_workload FROM tags t JOIN task_tags tt ON t.id = tt.tag_id JOIN work_logs wl ON tt.task_id = wl.task_id WHERE t.archived = 0 GROUP BY t.id, t.name, t.color ORDER BY total_workload DESC";
    return $db->query($sql)->fetchAll();
}

function handle_get_results_stats(PDO $db, array $args): array {
    $sql = "SELECT t.id, t.name, t.color, COUNT(rl.id) as total_results FROM tags t JOIN task_tags tt ON t.id = tt.tag_id JOIN result_logs rl ON tt.task_id = rl.task_id WHERE t.archived = 0 GROUP BY t.id, t.name, t.color ORDER BY total_results DESC";
    return $db->query($sql)->fetchAll();
}

function handle_get_calendar_data(PDO $db, array $args): array {
    $month = $args['month'];
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
    $sql = "SELECT DISTINCT t.id, t.name, wl.log_date as event_date, 'work' as event_type FROM tasks t JOIN work_logs wl ON t.id = wl.task_id WHERE wl.log_date BETWEEN ? AND ? AND t.archived = 0";
    $stmt = $db->prepare($sql);
    $stmt->execute([$start, $end]);
    return $stmt->fetchAll();
}

function handle_get_daily_status(PDO $db, array $args): array {
    $date = $args['date'];
    $workStmt = $db->prepare('SELECT t.* FROM tasks t JOIN work_logs wl ON t.id = wl.task_id WHERE wl.log_date = ? AND t.archived = 0');
    $workStmt->execute([$date]);
    $resultStmt = $db->prepare('SELECT t.*, rl.result_id, r.name as result_name FROM tasks t JOIN result_logs rl ON t.id = rl.task_id JOIN results r ON rl.result_id = r.id WHERE rl.log_date = ? AND t.archived = 0');
    $resultStmt->execute([$date]);
    $planStmt = $db->prepare('SELECT t.* FROM tasks t JOIN plans p ON t.id = p.task_id WHERE p.planned_date = ? AND t.archived = 0');
    $planStmt->execute([$date]);
    return ['work_tasks' => $workStmt->fetchAll(), 'result_tasks' => $resultStmt->fetchAll(), 'plan_tasks' => $planStmt->fetchAll()];
}

function handle_get_relationships(PDO $db, array $args): array {
    $people = $db->query('SELECT id, name, relationship FROM people WHERE archived = 0')->fetchAll();
    return ['people' => $people];
}

function handle_get_user_profile(PDO $db, array $args): ?array {
    $stmt = $db->query('SELECT * FROM user_profile WHERE id = 1');
    return $stmt->fetch() ?: null;
}

function handle_get_bazi_analysis(PDO $db, array $args): array {
    $date = $args['date'];
    $type = $args['type'] ?? null;
    $sql = 'SELECT * FROM bazi_analysis WHERE date_key = ?';
    $params = [$date];
    if ($type) { $sql .= ' AND type = ?'; $params[] = $type; }
    $sql .= ' ORDER BY FIELD(type,"dayun","liunian","liuyue","liuri"), id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function handle_save_bazi_analysis(PDO $db, array $args): array {
    $stmt = $db->prepare('INSERT INTO bazi_analysis (date_key, type, period_label, gan_zhi, shi_shen, analysis)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE gan_zhi=VALUES(gan_zhi), shi_shen=VALUES(shi_shen), analysis=VALUES(analysis)');
    $stmt->execute([
        $args['date'], $args['type'],
        $args['period_label'] ?? '', $args['gan_zhi'] ?? '',
        $args['shi_shen'] ?? '', $args['analysis'],
    ]);
    return ['saved' => true];
}

function handle_get_weather(PDO $db, array $args): array {
    $date = $args['date'];
    $stmt = $db->prepare("SELECT data_json FROM weather_cache WHERE date = ? AND city = (SELECT JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.city')) FROM weather_cache WHERE date = '2000-01-01' AND city = '__location__' LIMIT 1)");
    $stmt->execute([$date]);
    $row = $stmt->fetch();
    if ($row) return json_decode($row['data_json'], true) ?: [];
    // Fallback: get any city's weather for this date
    $stmt2 = $db->prepare('SELECT data_json FROM weather_cache WHERE date = ? ORDER BY updated_at DESC LIMIT 1');
    $stmt2->execute([$date]);
    $row2 = $stmt2->fetch();
    return $row2 ? (json_decode($row2['data_json'], true) ?: []) : [];
}

function handle_get_worklog_notes(PDO $db, array $args): array {
    $stmt = $db->prepare('SELECT * FROM worklog_notes WHERE worklog_id = ? ORDER BY created_at ASC');
    $stmt->execute([(int)$args['worklog_id']]);
    return $stmt->fetchAll();
}

function handle_add_worklog_note(PDO $db, array $args): array {
    $stmt = $db->prepare('INSERT INTO worklog_notes (worklog_id, content) VALUES (?, ?)');
    $stmt->execute([(int)$args['worklog_id'], $args['content']]);
    return ['id' => (int)$db->lastInsertId(), 'content' => $args['content']];
}

function handle_get_calendar_meta(PDO $db, array $args): array {
    $date = $args['date'];
    $stmt = $db->prepare('SELECT * FROM calendar_meta WHERE date = ?');
    $stmt->execute([$date]);
    return $stmt->fetchAll();
}

// --- Reuse helpers from tasks.php ---
function get_task_full(PDO $db, int $id): ?array {
    $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ?');
    $stmt->execute([$id]);
    $task = $stmt->fetch();
    if (!$task) return null;
    $stmt = $db->prepare('SELECT p.* FROM people p JOIN task_people tp ON p.id = tp.people_id WHERE tp.task_id = ?');
    $stmt->execute([$id]); $task['people'] = $stmt->fetchAll();
    $stmt = $db->prepare('SELECT t.* FROM tags t JOIN task_tags tt ON t.id = tt.tag_id WHERE tt.task_id = ?');
    $stmt->execute([$id]); $task['tags'] = $stmt->fetchAll();
    $stmt = $db->prepare('SELECT r.* FROM results r JOIN task_results tr ON r.id = tr.result_id WHERE tr.task_id = ?');
    $stmt->execute([$id]); $task['results'] = $stmt->fetchAll();
    return $task;
}
function attach_people(PDO $db, int $task_id, array $people_ids): void {
    $db->prepare('DELETE FROM task_people WHERE task_id = ?')->execute([$task_id]);
    $stmt = $db->prepare('INSERT INTO task_people (task_id, people_id) VALUES (?, ?)');
    foreach ($people_ids as $pid) $stmt->execute([$task_id, (int)$pid]);
}
function attach_task_tags(PDO $db, int $task_id, array $tag_ids): void {
    $db->prepare('DELETE FROM task_tags WHERE task_id = ?')->execute([$task_id]);
    $stmt = $db->prepare('INSERT INTO task_tags (task_id, tag_id) VALUES (?, ?)');
    foreach ($tag_ids as $tid) $stmt->execute([$task_id, (int)$tid]);
}

// ===== CONFIG ENDPOINTS =====

if ($action === 'config' && $method === 'GET') {
    $config = load_ai_config($db);
    $config['api_key'] = $config['api_key'] ? '***' . substr($config['api_key'], -4) : '';
    json_success($config);
}

if ($action === 'config' && $method === 'POST') {
    $data = get_json_input();
    $stmt = $db->prepare('UPDATE ai_config SET provider=?, endpoint=?, api_key=?, model=? WHERE id=1');
    $stmt->execute([
        optional_string($data, 'provider', AI_DEFAULT_PROVIDER),
        optional_string($data, 'endpoint', AI_DEFAULT_ENDPOINT),
        optional_string($data, 'api_key', ''),
        optional_string($data, 'model', AI_DEFAULT_MODEL),
    ]);
    json_success(null, 'AI configuration saved');
}

if ($action === 'config' && $parts[3] === 'test' && $method === 'POST') {
    $data = get_json_input();
    $testEndpoint = rtrim(optional_string($data, 'endpoint', AI_DEFAULT_ENDPOINT), '/') . '/chat/completions';
    $testModel = optional_string($data, 'model', AI_DEFAULT_MODEL);
    $testKey = optional_string($data, 'api_key', '');

    $ch = curl_init($testEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $testModel,
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 5, 'stream' => false,
        ]),
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/json',
            $testKey ? "Authorization: Bearer $testKey" : null,
        ]),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) json_error("Connection failed: $err");
    if ($httpCode !== 200) json_error("Provider returned HTTP $httpCode");
    json_success(null, "Connection successful (HTTP $httpCode)");
}

// ===== SYSTEM PROMPT =====

function limit_ai_context(?string $text, int $maxChars): string {
    $text = trim((string)$text);
    if (mb_strlen($text, 'UTF-8') <= $maxChars) return $text;
    return mb_substr($text, 0, $maxChars, 'UTF-8') . "\n...[context truncated]";
}

function needs_personal_ai_context(string $query): bool {
    return preg_match(
        '/八字|命理|紫微|运势|侧写|简历|目标|生肖|生辰|五行|流年|流月|大运|奇门|风水/u',
        $query
    ) === 1;
}

function get_system_prompt(PDO $db, string $selectedDate = '', array $almanac = [], string $userQuery = ''): array {
    $today = today();
    $viewDate = $selectedDate ?: $today;
    $needsPersonalContext = needs_personal_ai_context($userQuery);

    // Build almanac context
    $almanacContext = '';
    if ($almanac || $viewDate !== $today) {
        $almanacContext = "\n=== CURRENT VIEW CONTEXT ===\n";
        $almanacContext .= "User is viewing: $viewDate";
        if ($viewDate !== $today) $almanacContext .= " (NOT today; today is $today)";
        if ($almanac) {
            $almanacContext .= "\nLunar: {$almanac['lunar_date']}";
            if (!empty($almanac['solar_term'])) $almanacContext .= " | Solar term: {$almanac['solar_term']}";
        }
        $almanacContext .= "\nWhen answering questions about 'today' or 'this week', refer to this viewing date unless the user explicitly specifies otherwise.\n";
    }

    // Load user profile
    $profile = '';
    $stmt = $db->query('SELECT * FROM user_profile WHERE id = 1');
    $p = $stmt->fetch();
    if ($needsPersonalContext && $p && !empty($p['name'])) {
        $profile = "=== USER PROFILE ===\n";
        $profile .= "Name: {$p['name']}\n";
        if ($p['gender']) $profile .= "Gender: {$p['gender']}\n";
        if ($p['birth_date']) $profile .= "Birth: {$p['birth_date']}" . ($p['birth_time'] !== '' ? " {$p['birth_time']}时" : "") . ($p['birth_place'] ? " ({$p['birth_place']})" : "") . "\n";
        if ($p['bazi_year']) $profile .= "BaZi: 年{$p['bazi_year']} 月{$p['bazi_month']} 日{$p['bazi_day']} 时{$p['bazi_time']}\n";
        if ($p['shengxiao']) $profile .= "ShengXiao: {$p['shengxiao']}\n";
        if ($p['nayin']) {
            $ny = json_decode($p['nayin'], true);
            if ($ny) $profile .= "NaYin: 年{$ny['year']} 月{$ny['month']} 日{$ny['day']} 时{$ny['time']}\n";
        }
        if ($p['shishen']) $profile .= "ShiShen: " . limit_ai_context($p['shishen'], 3000) . "\n";
        if ($p['dayun']) $profile .= "Ziwei: " . limit_ai_context($p['dayun'], 3000) . "\n";
        if ($p['resume']) $profile .= "Resume: " . limit_ai_context($p['resume'], 4000) . "\n";
        if ($p['goals']) $profile .= "Goals: " . limit_ai_context($p['goals'], 2000) . "\n";
        $profile .= "\n";
    }

    // Load enabled skills
    $skills = '';
    $stmt2 = $db->query('SELECT name, content FROM ai_skills WHERE enabled = 1 ORDER BY name');
    $enabledSkills = $stmt2->fetchAll();
    if ($needsPersonalContext && $enabledSkills) {
        $skills = "=== ENABLED SKILLS ===\n";
        $remainingSkillChars = 12000;
        foreach ($enabledSkills as $s) {
            if ($remainingSkillChars <= 0) break;
            $skillContent = limit_ai_context((string)$s['content'], min(4000, $remainingSkillChars));
            $skills .= "--- SKILL: {$s['name']} ---\n{$skillContent}\n\n";
            $remainingSkillChars -= mb_strlen($skillContent, 'UTF-8');
        }
    }

    $prompt = <<<PROMPT
You are an intelligent assistant for the WorkLog (工作日志) application, operating with a "Plan-then-Execute" workflow.

Current date: $today

## WORKFLOW

For EVERY user request, follow this process:

### Phase 1: PLAN (think first, don't call tools yet)
1. Analyze the user's request and identify what data you need from the system
2. Determine which tools can provide each piece of data
3. If the user's BaZi profile and enabled skills are available above, consider how they apply
4. Briefly explain your plan to the user in 1-2 sentences

### Phase 2: EXECUTE (gather all data in one batch)
5. Call ALL needed read tools in parallel (the system will auto-execute them)
6. Do NOT call tools one by one — call them all at once when possible

### Phase 3: SYNTHESIZE
7. Combine all retrieved data with the user's profile and skills (if relevant)
8. Present your analysis clearly in Chinese with tables, lists, and emoji
9. If the user wants to create/update/delete, explain what you'll do, then call the write tool

## TOOLS AVAILABLE
- Read tools (auto-executed): list_tasks, get_task, list_people, get_person, list_tags, list_results,
  get_worklogs_by_date, get_workload_stats, get_results_stats, get_calendar_data, get_daily_status,
  get_relationships
- Write tools (require user confirmation): create_task, update_task, delete_task, create_person,
  update_person, create_tag, update_tag, toggle_worklog, add_plan, add_result_log

## RULES
- Always Plan before Executing. Never start with tool calls.
- When gathering data, call multiple independent read tools simultaneously.
- Present data in Chinese with appropriate emoji. Use Markdown tables for structured data.
- For fate analysis or task prioritization, reference the user's BaZi and skills.
- Be concise but thorough. Answer in Chinese.
## BAZI SYSTEM
This app has a full BaZi (八字) fortune analysis system:
- User's birth chart (四柱) is in the profile (get_user_profile)
- Daily 大运/流年/流月/流日 pillars are computed and can be read via get_bazi_analysis
- You can read existing analyses and save new/updated analyses via save_bazi_analysis
- Calendar metadata (lunar dates, solar terms) is available via get_calendar_meta
- When the user asks about 流日/流年/流月/大运, use get_bazi_analysis to check existing analysis
- If the user wants to update an analysis, use save_bazi_analysis (requires confirmation)
- The save_bazi_analysis tool stores: date, type (dayun/liunian/liuyue/liuri), period_label, gan_zhi, shi_shen, and analysis text
- Always read the user's profile first before analyzing — their BaZi pillars are needed for correct interpretation

PROMPT;

    return ['role' => 'system', 'content' => $prompt . $almanacContext . ($profile ? "\n" . $profile : '') . $skills];
}

// ===== CHAT ENDPOINT =====

// ===== CONVERSATION ENDPOINTS =====

if ($action === 'conversations' && $method === 'GET') {
    $id = isset($parts[3]) ? (int)$parts[3] : null;
    if ($id) {
        $stmt = $db->prepare('SELECT * FROM ai_conversations WHERE id = ?');
        $stmt->execute([$id]);
        $conv = $stmt->fetch();
        if (!$conv) json_error('Not found', 404);
        $conv['messages'] = json_decode($conv['messages_json'], true) ?? [];
        unset($conv['messages_json']);
        json_success($conv);
    } else {
        $stmt = $db->query('SELECT id, title, updated_at FROM ai_conversations ORDER BY updated_at DESC');
        json_success($stmt->fetchAll());
    }
}

if ($action === 'conversations' && $method === 'POST') {
    $data = get_json_input();
    $title = optional_string($data, 'title', '新对话');
    $messages = json_encode($data['messages'] ?? [], JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare('INSERT INTO ai_conversations (title, messages_json) VALUES (?, ?)');
    $stmt->execute([$title, $messages]);
    json_success(['id' => (int)$db->lastInsertId(), 'title' => $title]);
}

if ($action === 'conversations' && $method === 'PUT') {
    $id = (int)($parts[3] ?? 0);
    if (!$id) json_error('ID required');
    $data = get_json_input();
    $title = optional_string($data, 'title');
    $messages = isset($data['messages']) ? json_encode($data['messages'], JSON_UNESCAPED_UNICODE) : null;
    if ($title && $messages !== null) {
        $stmt = $db->prepare('UPDATE ai_conversations SET title=?, messages_json=? WHERE id=?');
        $stmt->execute([$title, $messages, $id]);
    } elseif ($title) {
        $stmt = $db->prepare('UPDATE ai_conversations SET title=? WHERE id=?');
        $stmt->execute([$title, $id]);
    } elseif ($messages !== null) {
        $stmt = $db->prepare('UPDATE ai_conversations SET messages_json=? WHERE id=?');
        $stmt->execute([$messages, $id]);
    }
    json_success(null, 'Conversation updated');
}

if ($action === 'conversations' && $method === 'DELETE') {
    $id = (int)($parts[3] ?? 0);
    if (!$id) json_error('ID required');
    $db->prepare('DELETE FROM ai_conversations WHERE id=?')->execute([$id]);
    json_success(null, 'Conversation deleted');
}

// ===== CHAT / CONFIRM =====

if (($action === 'chat' || $action === 'confirm') && $method === 'POST') {
    try {
    $input = get_json_input();
    $config = load_ai_config($db);

    $allTools = get_tool_definitions();
    $toolSchemas = array_map(function($t) {
        $params = $t['parameters'];
        // Ensure empty properties is an object {}, not array []
        if (isset($params['properties']) && empty($params['properties'])) {
            $params['properties'] = new stdClass();
        }
        return [
            'type' => 'function',
            'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $params],
        ];
    }, $allTools);

    // Build messages
    $selectedDate = $input['selected_date'] ?? '';
    $almanac = $input['almanac'] ?? [];
    $historyForContext = $input['messages'] ?? [];
    $userQuery = '';
    for ($historyIndex = count($historyForContext) - 1; $historyIndex >= 0; $historyIndex--) {
        if (($historyForContext[$historyIndex]['role'] ?? '') === 'user') {
            $userQuery = (string)($historyForContext[$historyIndex]['content'] ?? '');
            break;
        }
    }
    $messages = [get_system_prompt($db, $selectedDate, $almanac, $userQuery)];

    if ($action === 'confirm') {
        // Restore conversation history + confirmed tool results
        $history = normalize_conversation_history($input['messages'] ?? []);
        $messages = array_merge($messages, $history);
        $assistantMsg = $input['message'] ?? [];
        if ($assistantMsg) $messages[] = $assistantMsg;
        $confirmations = $input['confirmations'] ?? [];

        foreach ($assistantMsg['tool_calls'] ?? [] as $call) {
            $conf = null;
            foreach ($confirmations as $c) { if ($c['id'] === $call['id']) { $conf = $c; break; } }
            $tool = null;
            foreach ($allTools as $t) { if ($t['name'] === $call['function']['name']) { $tool = $t; break; } }

            if ($conf && $conf['action'] === 'confirm' && $tool) {
                try {
                    $args = json_decode($call['function']['arguments'], true) ?? [];
                    $result = $tool['handler']($db, $args);
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
                } catch (Exception $e) {
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => json_encode(['error' => $e->getMessage()])];
                }
            } else {
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => json_encode(['error' => 'User rejected this operation', 'rejected' => true])];
            }
        }
    } else {
        $userMessages = normalize_conversation_history($input['messages'] ?? []);
        $messages = array_merge($messages, $userMessages);
    }

    // Sanitize messages: strip internal fields & empty tool_calls
    foreach ($messages as &$m) {
        unset($m['_usage'], $m['_model'], $m['_finish_reason']);
        if (($m['role'] ?? '') === 'assistant' && isset($m['tool_calls']) && empty($m['tool_calls'])) {
            unset($m['tool_calls']);
        }
    }
    unset($m);

    // LLM loop — collect all steps for frontend display
    $isDeepAnalysis = needs_personal_ai_context($userQuery);
    $overallTimeout = $isDeepAnalysis ? 300 : 90;
    $perCallTimeout = $isDeepAnalysis ? 180 : 60;
    $maxIter = 6;
    $deadline = microtime(true) + $overallTimeout;
    $steps = [];
    for ($i = 0; $i < $maxIter; $i++) {
        $remaining = (int)floor($deadline - microtime(true));
        if ($remaining < 5) {
            throw new Exception("AI request timed out after {$overallTimeout} seconds");
        }
        $response = call_llm($config, $messages, $toolSchemas, min($perCallTimeout, $remaining));

        // Collect AI's text as a plan/thinking step if present
        if (!empty($response['content'])) {
            $steps[] = ['type' => 'think', 'content' => $response['content']];
        }

        if (empty($response['tool_calls'])) {
            $content = $response['content'];
            if (($response['_finish_reason'] ?? '') === 'length') {
                $notice = '⚠️ 回答达到模型输出上限。你可以回复“继续”，我会接着完成。';
                $content = trim((string)$content);
                $content = $content !== '' ? $content . "\n\n" . $notice : $notice;
                $response['content'] = $content;
            }
            // Final text response — return all steps
            json_success([
                'type' => 'steps',
                'steps' => $steps,
                'content' => $content,
                'message' => $response,
                'model' => $config['model'],
                'usage' => $response['_usage'] ?? [],
            ]);
        }

        $pendingWrites = [];
        $toolResults = [];

        foreach ($response['tool_calls'] as $call) {
            $tool = null;
            foreach ($allTools as $t) { if ($t['name'] === $call['function']['name']) { $tool = $t; break; } }
            if (!$tool) {
                $toolResults[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => json_encode(['error' => 'Unknown tool'])];
                $steps[] = ['type' => 'tool', 'name' => $call['function']['name'], 'status' => 'error'];
                continue;
            }

            $steps[] = ['type' => 'tool', 'name' => $call['function']['name'], 'status' => 'running'];

            if ($tool['requires_confirmation']) {
                $args = json_decode($call['function']['arguments'], true) ?? [];
                $pendingWrites[] = [
                    'id' => $call['id'],
                    'name' => $call['function']['name'],
                    'arguments' => $args,
                ];
                $steps[count($steps)-1]['status'] = 'confirm';
            } else {
                try {
                    $args = json_decode($call['function']['arguments'], true) ?? [];
                    $result = $tool['handler']($db, $args);
                    $toolResults[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
                    $steps[count($steps)-1]['status'] = 'done';
                } catch (Exception $e) {
                    $toolResults[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => json_encode(['error' => $e->getMessage()])];
                    $steps[count($steps)-1]['status'] = 'error';
                }
            }
        }

        if (!empty($pendingWrites)) {
            json_success([
                'type' => 'confirmation',
                'steps' => $steps,
                'pending_calls' => $pendingWrites,
                'message' => $response,
                'model' => $config['model'],
                'usage' => $response['_usage'] ?? [],
            ]);
        }

        $messages[] = $response;
        $messages = array_merge($messages, $toolResults);
    }

    json_success([
        'type' => 'steps',
        'steps' => $steps,
        'content' => '抱歉，请求步骤过多，请简化后重试。',
        'message' => null,
        'model' => $config['model'],
        'usage' => [],
    ]);

    } catch (Exception $e) {
        json_error('AI error: ' . $e->getMessage(), 500);
    }
}

json_error('Method not allowed or unknown action', 405);
