<?php
/**
 * User identity context for AI prompts.
 *
 *   - profile_documents  : user-uploaded authoritative documents (CV etc.)
 *   - profile_impressions: AI-inferred (secondary) facts, editable by the user
 *
 * Shared by api/ai.php (system prompt), MailAnalyzer and the CLI worker.
 * All functions take an explicit $uid so they work outside a session.
 */

require_once __DIR__ . '/ai_client.php';

function profile_structured_fields(): array {
    return [
        'title'         => '职称',
        'position'      => '职位',
        'organization'  => '单位',
        'work_focus'    => '工作重心',
        'research_area' => '研究方向',
        'skills'        => '技能',
        'projects'      => '在研项目',
        'preferences'   => '偏好',
    ];
}

function profile_field_label(string $field): string {
    $map = profile_structured_fields();
    if (isset($map[$field])) return $map[$field];
    return $field === 'observation' ? '观察' : $field;
}

/** Primary document row (or latest one) for the user, or null. */
function profile_primary_document(PDO $db, int $uid): ?array {
    $st = $db->prepare('SELECT * FROM profile_documents WHERE user_id = ? ORDER BY is_primary DESC, updated_at DESC LIMIT 1');
    $st->execute([$uid]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Structured fields (latest per field) + recent observations. */
function profile_impressions_snapshot(PDO $db, int $uid, int $maxObservations = 10): array {
    $st = $db->prepare('SELECT * FROM profile_impressions WHERE user_id = ? ORDER BY updated_at DESC, id DESC');
    $st->execute([$uid]);
    $fields = [];
    $observations = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['field'] === 'observation') {
            if (count($observations) < $maxObservations) $observations[] = $r;
        } elseif (!isset($fields[$r['field']])) {
            $fields[$r['field']] = $r;
        }
    }
    return ['fields' => $fields, 'observations' => $observations];
}

/**
 * Build the identity block for a system prompt. Never throws (tables may be
 * missing before migration 002 is applied) — returns '' in that case.
 */
function profile_identity_block(PDO $db, int $uid, int $docBudget = 1500, int $impBudget = 800): string {
    $out = '';
    try {
        $doc = profile_primary_document($db, $uid);
        if ($doc && trim((string)$doc['extracted_text']) !== '') {
            $label = $doc['title'] !== '' ? $doc['title'] : $doc['file_name'];
            $out .= "=== USER IDENTITY (authoritative, from uploaded document: {$label}) ===\n";
            $out .= ai_truncate($doc['extracted_text'], $docBudget) . "\n\n";
        }

        // Compacted ledger: latest stage/base summary + latest incremental (if newer)
        $summary = profile_snapshot_summary($db, $uid);
        $summaryCutoff = null;
        if ($summary['text'] !== '') {
            $out .= "=== AI IMPRESSION SUMMARY (compacted profile; secondary to documents) ===\n";
            $out .= ai_truncate($summary['text'], max(600, (int)round($impBudget * 1.5))) . "\n\n";
            $summaryCutoff = $summary['latest_at'];
        }

        $snap = profile_impressions_snapshot($db, $uid, 10);
        if ($snap['fields'] || $snap['observations']) {
            $block = "=== AI IMPRESSIONS (secondary; the document above wins on conflict) ===\n";
            foreach ($snap['fields'] as $field => $r) {
                $block .= profile_field_label($field) . "({$field}): " . trim($r['value'])
                       . " [conf {$r['confidence']}, {$r['source']}, " . substr($r['updated_at'], 0, 10) . "]\n";
            }
            $obsShown = 0;
            foreach ($snap['observations'] as $r) {
                // Once a summary exists, only observations newer than it are worth repeating verbatim
                if ($summaryCutoff && $r['updated_at'] <= $summaryCutoff) continue;
                if ($obsShown++ >= 6) break;
                $block .= '- ' . trim($r['value']) . ' [' . substr($r['updated_at'], 0, 10) . "]\n";
            }
            $out .= ai_truncate($block, $impBudget) . "\n\n";
        }
    } catch (Throwable $e) {
        // Missing tables / DB hiccup: identity context is optional.
        return $out;
    }
    return $out;
}

/**
 * Record or update an impression.
 * Structured fields upsert by (user_id, field); observations append (deduped by value).
 * Returns ['action' => created|updated|unchanged, 'id' => int, 'field' => ..., 'value' => ...]
 */
function profile_remember(PDO $db, int $uid, string $field, string $value, int $confidence = 70,
                          string $evidence = '', ?int $conversationId = null, string $source = 'ai'): array {
    $field = trim($field);
    $value = trim($value);
    $allowed = array_keys(profile_structured_fields());
    $allowed[] = 'observation';
    if (!in_array($field, $allowed, true)) {
        throw new InvalidArgumentException('未知字段: ' . $field . '，可选: ' . implode('/', $allowed));
    }
    if ($value === '') throw new InvalidArgumentException('value 不能为空');
    $confidence = max(0, min(100, $confidence));
    $evidence = mb_substr($evidence, 0, 500, 'UTF-8');
    $value = mb_substr($value, 0, 2000, 'UTF-8');

    if ($field === 'observation') {
        $st = $db->prepare('SELECT id FROM profile_impressions WHERE user_id = ? AND field = ? AND value = ? LIMIT 1');
        $st->execute([$uid, $field, $value]);
        $existing = $st->fetchColumn();
        if ($existing) {
            $db->prepare('UPDATE profile_impressions SET confidence = GREATEST(confidence, ?), updated_at = NOW() WHERE id = ?')
               ->execute([$confidence, (int)$existing]);
            return ['action' => 'unchanged', 'id' => (int)$existing, 'field' => $field, 'value' => $value];
        }
        $db->prepare('INSERT INTO profile_impressions (user_id, field, value, confidence, source, conversation_id, evidence) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$uid, $field, $value, $confidence, $source, $conversationId, $evidence]);
        return ['action' => 'created', 'id' => (int)$db->lastInsertId(), 'field' => $field, 'value' => $value];
    }

    $st = $db->prepare('SELECT id, value, source FROM profile_impressions WHERE user_id = ? AND field = ? ORDER BY updated_at DESC, id DESC LIMIT 1');
    $st->execute([$uid, $field]);
    $row = $st->fetch();
    if ($row) {
        if (trim($row['value']) === $value) {
            return ['action' => 'unchanged', 'id' => (int)$row['id'], 'field' => $field, 'value' => $value];
        }
        // A user-entered value is authoritative; AI may not silently overwrite it.
        if ($row['source'] === 'user' && $source === 'ai') {
            return ['action' => 'unchanged', 'id' => (int)$row['id'], 'field' => $field, 'value' => $row['value'], 'note' => '用户手动设置的值优先，未覆盖'];
        }
        $db->prepare('UPDATE profile_impressions SET value = ?, confidence = ?, source = ?, conversation_id = ?, evidence = ?, updated_at = NOW() WHERE id = ?')
           ->execute([$value, $confidence, $source, $conversationId, $evidence, (int)$row['id']]);
        return ['action' => 'updated', 'id' => (int)$row['id'], 'field' => $field, 'value' => $value, 'previous' => $row['value']];
    }
    $db->prepare('INSERT INTO profile_impressions (user_id, field, value, confidence, source, conversation_id, evidence) VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([$uid, $field, $value, $confidence, $source, $conversationId, $evidence]);
    return ['action' => 'created', 'id' => (int)$db->lastInsertId(), 'field' => $field, 'value' => $value];
}

// ===================================================================
// Impression snapshots — a ledger that is periodically compacted:
//   base        : full profile generated from documents + all impressions (+ recent activity)
//   stage       : base + previous stage + updates since → consolidated profile
//   incremental : latest stage (or base) + previous incremental + updates since → short delta
// ===================================================================

function profile_snapshot_kinds(): array {
    return ['base' => '基础印象', 'stage' => '阶段性印象', 'incremental' => '增量印象'];
}

function profile_snapshot_latest(PDO $db, int $uid, string $kind): ?array {
    $st = $db->prepare('SELECT * FROM profile_impression_snapshots WHERE user_id = ? AND kind = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$uid, $kind]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Text used in prompts: latest stage (or base) + latest incremental newer than it. */
function profile_snapshot_summary(PDO $db, int $uid): array {
    try {
        $stage = profile_snapshot_latest($db, $uid, 'stage');
        $base = profile_snapshot_latest($db, $uid, 'base');
        $anchor = $stage ?: $base;
        if (!$anchor) return ['text' => '', 'latest_at' => null];
        $text = "[" . profile_snapshot_kinds()[$anchor['kind']] . " · " . substr($anchor['created_at'], 0, 10) . "]\n" . trim((string)$anchor['content_md']);
        $latestAt = $anchor['created_at'];
        $inc = profile_snapshot_latest($db, $uid, 'incremental');
        if ($inc && $inc['created_at'] > $anchor['created_at']) {
            $text .= "\n\n[增量印象 · " . substr($inc['created_at'], 0, 10) . "]\n" . trim((string)$inc['content_md']);
            $latestAt = $inc['created_at'];
        }
        return ['text' => $text, 'latest_at' => $latestAt];
    } catch (Throwable $e) {
        return ['text' => '', 'latest_at' => null];
    }
}

/**
 * Collect what changed since $since (Y-m-d H:i:s) — impressions, tasks, work logs, analysed mail.
 * @return array{text:string, counts:array}
 */
function profile_collect_updates(PDO $db, int $uid, ?string $since, int $budget = 6000): array {
    $since = $since ?: date('Y-m-d H:i:s', strtotime('-60 days'));
    $counts = ['impressions' => 0, 'tasks' => 0, 'worklogs' => 0, 'mails' => 0];
    $text = "（统计起点：{$since}）\n";

    $st = $db->prepare('SELECT field, value, source, confidence, updated_at FROM profile_impressions WHERE user_id = ? AND updated_at > ? ORDER BY updated_at DESC LIMIT 60');
    $st->execute([$uid, $since]);
    $rows = $st->fetchAll();
    $counts['impressions'] = count($rows);
    if ($rows) {
        $text .= "\n## 新增/更新的印象记录\n";
        foreach ($rows as $r) $text .= "- [" . profile_field_label($r['field']) . "] " . trim($r['value']) . " ({$r['source']}, " . substr($r['updated_at'], 0, 10) . ")\n";
    }

    $st = $db->prepare('SELECT name, stage, stage_number, deadline, updated_at FROM tasks WHERE user_id = ? AND archived = 0 AND updated_at > ? ORDER BY updated_at DESC LIMIT 40');
    $st->execute([$uid, $since]);
    $rows = $st->fetchAll();
    $counts['tasks'] = count($rows);
    if ($rows) {
        $stageMap = ['in_progress' => '进行中', 'stage_complete' => '阶段完成', 'completed' => '已完成', 'failed' => '失败/放弃'];
        $text .= "\n## 有变动的任务\n";
        foreach ($rows as $r) $text .= "- {$r['name']}（" . ($stageMap[$r['stage']] ?? $r['stage']) . " 第{$r['stage_number']}阶段" . ($r['deadline'] ? "，截止 {$r['deadline']}" : '') . "）\n";
    }

    $st = $db->prepare('SELECT t.name, COUNT(*) AS days FROM work_logs w JOIN tasks t ON t.id = w.task_id WHERE t.user_id = ? AND w.created_at > ? GROUP BY t.id ORDER BY days DESC LIMIT 20');
    $st->execute([$uid, $since]);
    $rows = $st->fetchAll();
    $counts['worklogs'] = (int)array_sum(array_column($rows, 'days'));
    if ($rows) {
        $text .= "\n## 工作量投入（记录天数）\n";
        foreach ($rows as $r) $text .= "- {$r['name']}: {$r['days']} 天\n";
    }

    try {
        $st = $db->prepare('SELECT a.brief_title, a.relevance, a.priority, a.category, m.msg_date FROM mail_analysis a JOIN mail_messages m ON m.id = a.message_id
                            WHERE a.user_id = ? AND a.status = "ok" AND a.analyzed_at > ? AND a.relevance >= 50 ORDER BY a.relevance DESC, m.msg_date DESC LIMIT 20');
        $st->execute([$uid, $since]);
        $rows = $st->fetchAll();
        $counts['mails'] = count($rows);
        if ($rows) {
            $text .= "\n## 高相关邮件\n";
            foreach ($rows as $r) $text .= "- {$r['brief_title']}（相关度 {$r['relevance']}，优先级 P{$r['priority']}，" . substr((string)$r['msg_date'], 0, 10) . "）\n";
        }
    } catch (Throwable $e) { /* mail tables optional */ }

    return ['text' => ai_truncate($text, $budget), 'counts' => $counts];
}

/**
 * Whole-history digest since the user's first record — aggregated so it fits a prompt:
 * task counts by stage, most-invested tasks, tag distribution, results, collaborators,
 * monthly workload trend, and the gist of recent periodic reports.
 */
function profile_collect_history(PDO $db, int $uid, int $budget = 7000): array {
    $q = function (string $sql, array $params) use ($db) { $st = $db->prepare($sql); $st->execute($params); return $st->fetchAll(); };
    $text = '';
    $first = $q('SELECT LEAST(COALESCE(MIN(t.created_at), NOW()), COALESCE((SELECT MIN(w.log_date) FROM work_logs w JOIN tasks tt ON tt.id = w.task_id WHERE tt.user_id = ?), NOW())) AS first_at FROM tasks t WHERE t.user_id = ?', [$uid, $uid]);
    $firstAt = substr((string)($first[0]['first_at'] ?? ''), 0, 10);
    $text .= "（系统使用起点：{$firstAt}，统计至今）\n";

    $stages = $q('SELECT stage, COUNT(*) AS n FROM tasks WHERE user_id = ? GROUP BY stage', [$uid]);
    $stageMap = ['in_progress' => '进行中', 'stage_complete' => '阶段完成', 'completed' => '已完成', 'failed' => '失败/放弃'];
    if ($stages) {
        $text .= "\n## 任务总览\n" . implode('，', array_map(fn($r) => ($stageMap[$r['stage']] ?? $r['stage']) . " {$r['n']}", $stages)) . "\n";
    }

    $top = $q('SELECT t.name, t.stage, t.stage_number, t.created_at, COUNT(w.id) AS days, MIN(w.log_date) AS first_day, MAX(w.log_date) AS last_day,
                      (SELECT GROUP_CONCAT(g.name SEPARATOR "/") FROM task_tags tg JOIN tags g ON g.id = tg.tag_id WHERE tg.task_id = t.id) AS tags
               FROM tasks t LEFT JOIN work_logs w ON w.task_id = t.id WHERE t.user_id = ? GROUP BY t.id ORDER BY days DESC, t.updated_at DESC LIMIT 25', [$uid]);
    if ($top) {
        $text .= "\n## 投入最多的任务（累计工作量天数）\n";
        foreach ($top as $r) {
            if ((int)$r['days'] === 0) continue;
            $text .= "- {$r['name']}：{$r['days']} 天（{$r['first_day']} ~ {$r['last_day']}，" . ($stageMap[$r['stage']] ?? $r['stage']) . "）" . ($r['tags'] ? " [{$r['tags']}]" : '') . "\n";
        }
    }

    $tagDist = $q('SELECT g.name, COUNT(w.id) AS days, COUNT(DISTINCT t.id) AS tasks FROM tags g JOIN task_tags tg ON tg.tag_id = g.id JOIN tasks t ON t.id = tg.task_id
                   LEFT JOIN work_logs w ON w.task_id = t.id WHERE g.user_id = ? GROUP BY g.id ORDER BY days DESC LIMIT 12', [$uid]);
    if ($tagDist) {
        $text .= "\n## 工作方向分布（标签）\n" . implode('；', array_map(fn($r) => "{$r['name']} {$r['days']} 天/{$r['tasks']} 任务", $tagDist)) . "\n";
    }

    $results = $q('SELECT r.name, r.level, r.quantity, COUNT(rl.id) AS logs FROM results r LEFT JOIN result_logs rl ON rl.result_id = r.id WHERE r.user_id = ? AND r.archived = 0 GROUP BY r.id ORDER BY logs DESC, r.updated_at DESC LIMIT 15', [$uid]);
    if ($results) {
        $text .= "\n## 成果\n" . implode('；', array_map(fn($r) => $r['name'] . ($r['level'] ? "（{$r['level']}）" : '') . " ×{$r['logs']}", $results)) . "\n";
    }

    $people = $q('SELECT p.name, p.relationship, p.importance, COUNT(tp.task_id) AS tasks FROM people p JOIN task_people tp ON tp.people_id = p.id
                  WHERE p.user_id = ? AND p.is_me = 0 AND p.archived = 0 GROUP BY p.id ORDER BY tasks DESC, p.importance DESC LIMIT 15', [$uid]);
    if ($people) {
        $text .= "\n## 主要合作/服务对象\n" . implode('；', array_map(fn($r) => $r['name'] . ($r['relationship'] ? "（{$r['relationship']}）" : '') . " {$r['tasks']} 任务", $people)) . "\n";
    }

    $months = $q('SELECT DATE_FORMAT(w.log_date, "%Y-%m") AS ym, COUNT(*) AS days, COUNT(DISTINCT w.task_id) AS tasks FROM work_logs w JOIN tasks t ON t.id = w.task_id
                  WHERE t.user_id = ? GROUP BY ym ORDER BY ym', [$uid]);
    if ($months) {
        $text .= "\n## 逐月工作量趋势（记录天数/涉及任务数）\n" . implode('，', array_map(fn($r) => "{$r['ym']}: {$r['days']}/{$r['tasks']}", $months)) . "\n";
    }

    try {
        $reports = $q('SELECT period_type, period_key, title, content_md FROM reports WHERE user_id = ? AND period_type IN ("monthly","weekly") ORDER BY period_start DESC LIMIT 4', [$uid]);
        if ($reports) {
            $text .= "\n## 最近的周期报告要点\n";
            foreach ($reports as $r) {
                $body = (string)$r['content_md'];
                $aiPos = mb_strpos($body, '## 🤖 AI 分析与建议');
                $gist = $aiPos !== false ? mb_substr($body, $aiPos + 12) : $body;
                $text .= "### {$r['period_key']} {$r['title']}\n" . ai_truncate(trim($gist), 700) . "\n";
            }
        }
    } catch (Throwable $e) { /* optional */ }

    return ['text' => ai_truncate($text, $budget), 'first_at' => $firstAt];
}

/** Status for the UI: latest of each kind (with content) + pending update counts. */
function profile_snapshot_status(PDO $db, int $uid): array {
    $latest = [];
    foreach (array_keys(profile_snapshot_kinds()) as $k) {
        $row = profile_snapshot_latest($db, $uid, $k);
        if ($row) { $row['sources'] = json_decode((string)$row['sources_json'], true) ?: []; unset($row['sources_json'], $row['user_id']); }
        $latest[$k] = $row;
    }
    $stageAnchor = $latest['stage'] ?: $latest['base'];
    $incAnchor = ($latest['incremental'] && $stageAnchor && $latest['incremental']['created_at'] > $stageAnchor['created_at']) ? $latest['incremental'] : $stageAnchor;
    $sinceStage = $stageAnchor ? $stageAnchor['created_at'] : null;
    $sinceInc = $incAnchor ? $incAnchor['created_at'] : null;
    $pending = [
        'stage' => $sinceStage ? profile_collect_updates($db, $uid, $sinceStage, 200)['counts'] : null,
        'incremental' => $sinceInc ? profile_collect_updates($db, $uid, $sinceInc, 200)['counts'] : null,
    ];
    $st = $db->prepare('SELECT id, kind, model, created_at, CHAR_LENGTH(COALESCE(content_md, "")) AS chars FROM profile_impression_snapshots WHERE user_id = ? ORDER BY id DESC LIMIT 30');
    $st->execute([$uid]);
    return ['latest' => $latest, 'pending' => $pending, 'history' => $st->fetchAll(), 'kinds' => profile_snapshot_kinds()];
}

/** Generate + store a snapshot of the given kind. Throws on missing prerequisites / AI failure. */
function profile_generate_snapshot(PDO $db, int $uid, string $kind, int $timeout = 150): array {
    if (!isset(profile_snapshot_kinds()[$kind])) throw new InvalidArgumentException('未知的印象类型');
    $config = ai_load_config($db, $uid);
    if (!ai_is_configured($config)) throw new RuntimeException('请先配置AI');

    $base = profile_snapshot_latest($db, $uid, 'base');
    $stage = profile_snapshot_latest($db, $uid, 'stage');
    $inc = profile_snapshot_latest($db, $uid, 'incremental');
    $sources = [];
    $sections = [];

    // Authoritative identity + structured fields (always)
    $doc = profile_primary_document($db, $uid);
    $identity = $doc && trim((string)$doc['extracted_text']) !== '' ? ai_truncate($doc['extracted_text'], $kind === 'base' ? 6000 : 2500) : '（用户未上传身份文档）';
    $pst = $db->prepare('SELECT name, gender, resume, goals FROM user_profile WHERE user_id = ?');
    $pst->execute([$uid]);
    $p = $pst->fetch() ?: [];
    $basicInfo = '';
    if (!empty($p['name'])) $basicInfo .= "姓名: {$p['name']}\n";
    if (!empty($p['resume'])) $basicInfo .= "个人简介: " . ai_truncate($p['resume'], 1500) . "\n";
    if (!empty($p['goals'])) $basicInfo .= "阶段目标: " . ai_truncate($p['goals'], 1000) . "\n";
    $snap = profile_impressions_snapshot($db, $uid, $kind === 'base' ? 40 : 15);
    $fieldsText = '';
    foreach ($snap['fields'] as $f => $r) $fieldsText .= '- ' . profile_field_label($f) . ": " . trim($r['value']) . " (置信 {$r['confidence']}, " . substr($r['updated_at'], 0, 10) . ")\n";
    if ($kind === 'base') foreach ($snap['observations'] as $r) $fieldsText .= '- 观察: ' . trim($r['value']) . ' (' . substr($r['updated_at'], 0, 10) . ")\n";

    $sections[] = "# 身份文档（权威来源）\n" . $identity;
    if ($basicInfo) $sections[] = "# 侧写页基本信息\n" . $basicInfo;
    if ($fieldsText) $sections[] = "# 结构化印象" . ($kind === 'base' ? "与观察记录" : "（当前值）") . "\n" . $fieldsText;

    if ($kind === 'base') {
        // Whole history since day one (aggregated) + the last 90 days in detail
        $hist = profile_collect_history($db, $uid, 7000);
        $sections[] = "# 系统使用以来的全部历史（自 {$hist['first_at']} 起，统计摘要）\n" . $hist['text'];
        $upd = profile_collect_updates($db, $uid, date('Y-m-d H:i:s', strtotime('-90 days')), 5000);
        $sections[] = "# 近 90 天活动明细\n" . $upd['text'];
        $sources = ['since' => $hist['first_at'], 'recent_since' => date('Y-m-d H:i:s', strtotime('-90 days')), 'counts' => $upd['counts'], 'regenerated' => $base ? (int)$base['id'] : null];
        $task = "请生成一份**基础印象**：对用户的全面侧写，覆盖从系统使用第一天到现在的全部历史。要求 600-1000 字，Markdown，包含以下小节：\n"
              . "## 身份与职位\n## 研究方向与工作重心\n## 长期投入与阶段演变（按时间线概括历史工作重心的变化）\n## 在研项目与近期任务\n## 工作方式与偏好\n## 人际与合作\n## 近期动态\n## 值得关注的事项\n"
              . "以身份文档为准；历史统计、印象记录和活动明细作补充；没有依据的地方明确写“暂无信息”，不要编造。";
    } elseif ($kind === 'stage') {
        if (!$base) throw new RuntimeException('请先生成基础印象');
        $since = $stage ? $stage['created_at'] : $base['created_at'];
        $upd = profile_collect_updates($db, $uid, $since, 6000);
        $sections[] = "# 基础印象（" . substr($base['created_at'], 0, 10) . "）\n" . ai_truncate($base['content_md'], 5000);
        if ($stage) $sections[] = "# 上一阶段印象（" . substr($stage['created_at'], 0, 10) . "）\n" . ai_truncate($stage['content_md'], 4000);
        $incsSince = $db->prepare('SELECT content_md, created_at FROM profile_impression_snapshots WHERE user_id = ? AND kind = "incremental" AND created_at > ? ORDER BY id ASC LIMIT 10');
        $incsSince->execute([$uid, $since]);
        $incText = '';
        foreach ($incsSince->fetchAll() as $r) $incText .= "### " . substr($r['created_at'], 0, 10) . "\n" . ai_truncate($r['content_md'], 1200) . "\n\n";
        if ($incText) $sections[] = "# 本阶段内的增量印象\n" . $incText;
        $sections[] = "# 自上一阶段以来的更新\n" . $upd['text'];
        $sources = ['base_id' => (int)$base['id'], 'stage_id' => $stage ? (int)$stage['id'] : null, 'since' => $since, 'counts' => $upd['counts']];
        $task = "请生成一份**阶段性印象**：把基础印象、上一阶段印象、本阶段增量印象和近期更新**合并压缩**为一份最新的完整侧写（类似账本定期压缩）。要求 500-900 字，Markdown，小节同基础印象（身份与职位 / 研究方向与工作重心 / 在研项目与近期任务 / 工作方式与偏好 / 人际与合作 / 近期动态 / 值得关注的事项），"
              . "最后加一节“## 与上一阶段相比的变化”。过时的信息要更新或删除，不要重复罗列原文。";
    } else {
        $anchor = $stage ?: $base;
        if (!$anchor) throw new RuntimeException('请先生成基础印象');
        $prevInc = ($inc && $inc['created_at'] > $anchor['created_at']) ? $inc : null;
        $since = $prevInc ? $prevInc['created_at'] : $anchor['created_at'];
        $upd = profile_collect_updates($db, $uid, $since, 5000);
        $sections[] = "# 当前" . profile_snapshot_kinds()[$anchor['kind']] . "（" . substr($anchor['created_at'], 0, 10) . "）\n" . ai_truncate($anchor['content_md'], 4000);
        if ($prevInc) $sections[] = "# 上一增量印象（" . substr($prevInc['created_at'], 0, 10) . "）\n" . ai_truncate($prevInc['content_md'], 2000);
        $sections[] = "# 自上次以来的更新\n" . $upd['text'];
        $sources = ['anchor_id' => (int)$anchor['id'], 'anchor_kind' => $anchor['kind'], 'incremental_id' => $prevInc ? (int)$prevInc['id'] : null, 'since' => $since, 'counts' => $upd['counts']];
        $task = "请生成一份**增量印象**：只写相对于当前阶段印象与上一增量印象的**变化与新信息**（新的关注点、任务进展、态度或偏好的变化、需要跟进的邮件/事项）。要求 150-400 字，Markdown 列表为主，不要复述已有内容；若几乎没有变化就明确说明。";
    }

    $system = "你是用户的长期科研工作助理，负责维护对用户的“侧写”（impression ledger）。写作对象是你自己（供之后的对话参考），用简洁、客观、可核实的中文；身份文档优先级最高，其次是用户手动填写的信息，再次是 AI 印象与活动数据。";
    $user = implode("\n\n", $sections) . "\n\n---\n" . $task;
    $content = ai_complete_text($config, [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], $timeout);

    $db->prepare('INSERT INTO profile_impression_snapshots (user_id, kind, content_md, sources_json, model) VALUES (?, ?, ?, ?, ?)')
       ->execute([$uid, $kind, $content, json_encode($sources, JSON_UNESCAPED_UNICODE), (string)($config['model'] ?? '')]);
    $row = profile_snapshot_latest($db, $uid, $kind);
    $row['sources'] = $sources;
    unset($row['sources_json'], $row['user_id']);
    return $row;
}
