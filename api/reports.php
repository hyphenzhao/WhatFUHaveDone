<?php
/**
 * Periodic Reports API — daily / weekly / monthly / yearly.
 *
 * GET    /api/reports                 — list saved reports (metadata only)
 * GET    /api/reports/{id}            — get one full report (content_md + stats)
 * POST   /api/reports                 — generate/regenerate { period_type, period_key, note }
 * DELETE /api/reports/{id}            — delete a saved report
 *
 * A report = computed stats tables (real data) + an AI narrative that also
 * references text extracted from files attached to the period's notes/results
 * and to the report itself (entity_type='report').
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/attachments_util.php';
require_once __DIR__ . '/../includes/ai_client.php';
require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
$parts  = get_path_parts();
$db     = get_db();
$uid    = current_user_id();

// ---------- list / get ----------
if ($method === 'GET') {
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if ($id) {
        $stmt = $db->prepare('SELECT * FROM reports WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $uid]);
        $r = $stmt->fetch();
        if (!$r) json_error('Not found', 404);
        // attachment count for this report
        $cs = $db->prepare('SELECT COUNT(*) FROM attachments WHERE entity_type = "report" AND entity_id = ? AND user_id = ?');
        $cs->execute([$id, $uid]);
        $r['attachment_count'] = (int)$cs->fetchColumn();
        json_success($r);
    }

    // Lookup by period (normalizes the key). Returns {exists:false, ...computed} if none yet.
    if (isset($_GET['period_type'], $_GET['period_key'])) {
        $pt = (string)$_GET['period_type'];
        if (!in_array($pt, ['daily', 'weekly', 'monthly', 'yearly'], true)) json_error('bad period_type');
        [$pk, $start, $end, $title] = compute_period($pt, (string)$_GET['period_key']);
        $st = $db->prepare('SELECT * FROM reports WHERE period_type = ? AND period_key = ? AND user_id = ?');
        $st->execute([$pt, $pk, $uid]);
        $r = $st->fetch();
        if ($r) {
            $cs = $db->prepare('SELECT COUNT(*) FROM attachments WHERE entity_type = "report" AND entity_id = ? AND user_id = ?');
            $cs->execute([$r['id'], $uid]);
            $r['attachment_count'] = (int)$cs->fetchColumn();
            $r['exists'] = true;
            json_success($r);
        }
        json_success(['exists' => false, 'period_type' => $pt, 'period_key' => $pk, 'period_start' => $start, 'period_end' => $end, 'title' => $title]);
    }

    $stmt = $db->prepare('SELECT id, period_type, period_key, period_start, period_end, title, model, created_at, updated_at FROM reports WHERE user_id = ? ORDER BY period_start DESC, id DESC');
    $stmt->execute([$uid]);
    json_success($stmt->fetchAll());
}

// ---------- delete ----------
if ($method === 'DELETE') {
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');
    $chk = $db->prepare('SELECT 1 FROM reports WHERE id = ? AND user_id = ?');
    $chk->execute([$id, $uid]);
    if (!$chk->fetchColumn()) json_error('Not found', 404);
    // Clean up this report's reference attachments (rows + files on disk).
    delete_attachments($db, 'report', [$id]);
    $db->prepare('DELETE FROM reports WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    json_success(null, '报告已删除');
}

// ---------- generate ----------
if ($method === 'POST') {
    $data        = get_json_input();
    $period_type = optional_string($data, 'period_type');
    $period_in   = optional_string($data, 'period_key');
    $note        = optional_string($data, 'note');
    if (!in_array($period_type, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
        json_error('period_type must be daily|weekly|monthly|yearly');
    }

    [$period_key, $start, $end, $title] = compute_period($period_type, $period_in);

    // Existing report id (so its own reference attachments can be included)
    $es = $db->prepare('SELECT id FROM reports WHERE period_type = ? AND period_key = ? AND user_id = ?');
    $es->execute([$period_type, $period_key, $uid]);
    $existingId = (int)($es->fetchColumn() ?: 0);

    $stats = compute_stats($db, $start, $end, $uid);
    $notes = collect_notes($db, $start, $end, $uid);
    $refs  = collect_reference_text($db, $start, $end, $existingId, $uid);

    $statsMd = build_stats_markdown($title, $start, $end, $stats, $notes);

    // AI narrative (best-effort; report still saves if AI fails)
    $narrative = '';
    $model = '';
    $aiError = '';
    try {
        $config = report_ai_config($db, $uid);
        $model = $config['model'] ?? '';
        $prompt = build_ai_prompt($title, $start, $end, $stats, $notes, $refs, $note);
        $messages = [
            ['role' => 'system', 'content' => '你是一名资深的个人效能与工作复盘教练。请用简体中文、Markdown 格式，基于用户提供的真实数据写一份精炼的周期复盘，包含：本期概览小结、主要成果与亮点、值得注意的问题或投入不足之处、以及对下一周期的 3-5 条具体建议。不要编造数据中没有的内容，可结合参考资料。语气务实。'],
            ['role' => 'user', 'content' => $prompt],
        ];
        $narrative = report_call_llm($config, $messages, 90);
    } catch (Throwable $e) {
        $aiError = $e->getMessage();
    }

    $content_md = $statsMd;
    if ($narrative !== '') {
        $content_md .= "\n\n---\n\n## 🤖 AI 分析与建议\n\n" . $narrative . "\n";
    } elseif ($aiError !== '') {
        $content_md .= "\n\n---\n\n> ⚠️ AI 分析未生成（" . $aiError . "）。已保存统计部分，可在配置好 AI 后重新生成。\n";
    }

    $stats_json = json_encode(['stats' => $stats, 'note_count' => count($notes), 'ref_count' => count($refs)], JSON_UNESCAPED_UNICODE);

    // Upsert by (period_type, period_key)
    $up = $db->prepare(
        'INSERT INTO reports (user_id, period_type, period_key, period_start, period_end, title, content_md, stats_json, model)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE period_start=VALUES(period_start), period_end=VALUES(period_end),
             title=VALUES(title), content_md=VALUES(content_md), stats_json=VALUES(stats_json), model=VALUES(model)'
    );
    $up->execute([$uid, $period_type, $period_key, $start, $end, $title, $content_md, $stats_json, $model]);

    $rs = $db->prepare('SELECT * FROM reports WHERE period_type = ? AND period_key = ? AND user_id = ?');
    $rs->execute([$period_type, $period_key, $uid]);
    $report = $rs->fetch();
    $report['ai_error'] = $aiError;
    json_success($report, $aiError ? '报告已生成（AI 部分失败）' : '报告已生成');
}

json_error('Method not allowed', 405);

// ================= helpers =================

function compute_period(string $type, string $key): array {
    $ts = $key !== '' ? strtotime($type === 'monthly' && preg_match('/^\d{4}-\d{2}$/', $key) ? $key . '-01' : ($type === 'yearly' && preg_match('/^\d{4}$/', $key) ? $key . '-01-01' : $key)) : time();
    if ($ts === false) $ts = time();
    switch ($type) {
        case 'daily':
            $s = date('Y-m-d', $ts);
            return [$s, $s, $s, date('Y年n月j日', $ts) . ' 日报'];
        case 'weekly':
            $mon = strtotime('monday this week', $ts);
            $sun = strtotime('sunday this week', $ts);
            $s = date('Y-m-d', $mon);
            return [$s, $s, date('Y-m-d', $sun), date('Y-m-d', $mon) . ' ~ ' . date('Y-m-d', $sun) . ' 周报'];
        case 'monthly':
            return [date('Y-m', $ts), date('Y-m-01', $ts), date('Y-m-t', $ts), date('Y年n月', $ts) . ' 月报'];
        case 'yearly':
        default:
            $y = date('Y', $ts);
            return [$y, "$y-01-01", "$y-12-31", "{$y}年 年报"];
    }
}

function compute_stats(PDO $db, string $start, string $end, int $uid): array {
    $q = function (string $sql, array $p) use ($db) {
        $st = $db->prepare($sql);
        $st->execute($p);
        return $st;
    };

    $total_workload = (int)$q('SELECT COUNT(*) FROM work_logs wl JOIN tasks tk ON wl.task_id = tk.id WHERE wl.log_date BETWEEN ? AND ? AND tk.user_id = ?', [$start, $end, $uid])->fetchColumn();
    $active_days    = (int)$q('SELECT COUNT(DISTINCT wl.log_date) FROM work_logs wl JOIN tasks tk ON wl.task_id = tk.id WHERE wl.log_date BETWEEN ? AND ? AND tk.user_id = ?', [$start, $end, $uid])->fetchColumn();
    $tasks_worked   = (int)$q('SELECT COUNT(DISTINCT wl.task_id) FROM work_logs wl JOIN tasks tk ON wl.task_id = tk.id WHERE wl.log_date BETWEEN ? AND ? AND tk.user_id = ?', [$start, $end, $uid])->fetchColumn();
    $total_results  = (int)$q('SELECT COUNT(*) FROM result_logs rl JOIN tasks tk ON rl.task_id = tk.id WHERE rl.log_date BETWEEN ? AND ? AND tk.user_id = ?', [$start, $end, $uid])->fetchColumn();
    $plans_count    = (int)$q('SELECT COUNT(*) FROM plans pl JOIN tasks tk ON pl.task_id = tk.id WHERE pl.planned_date BETWEEN ? AND ? AND tk.user_id = ?', [$start, $end, $uid])->fetchColumn();
    $completed      = (int)$q("SELECT COUNT(*) FROM tasks WHERE stage IN ('completed','stage_complete') AND stage_changed_at IS NOT NULL AND DATE(stage_changed_at) BETWEEN ? AND ? AND user_id = ?", [$start, $end, $uid])->fetchColumn();

    $by_tag_workload = $q(
        "SELECT t.name, t.color, COUNT(wl.id) AS n
         FROM tags t JOIN task_tags tt ON t.id = tt.tag_id
         JOIN work_logs wl ON tt.task_id = wl.task_id
         WHERE wl.log_date BETWEEN ? AND ? AND t.user_id = ?
         GROUP BY t.id, t.name, t.color ORDER BY n DESC", [$start, $end, $uid]
    )->fetchAll();

    $by_tag_results = $q(
        "SELECT t.name, t.color, COUNT(rl.id) AS n
         FROM tags t JOIN task_tags tt ON t.id = tt.tag_id
         JOIN result_logs rl ON tt.task_id = rl.task_id
         WHERE rl.log_date BETWEEN ? AND ? AND t.user_id = ?
         GROUP BY t.id, t.name, t.color ORDER BY n DESC", [$start, $end, $uid]
    )->fetchAll();

    $by_task = $q(
        "SELECT t.name,
                COUNT(DISTINCT wl.id) AS work_days,
                (SELECT COUNT(*) FROM result_logs rl WHERE rl.task_id = t.id AND rl.log_date BETWEEN ? AND ?) AS results,
                GROUP_CONCAT(DISTINCT tg.name ORDER BY tg.name SEPARATOR '、') AS tags,
                GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR '、') AS people
         FROM tasks t
         JOIN work_logs wl ON t.id = wl.task_id AND wl.log_date BETWEEN ? AND ?
         LEFT JOIN task_tags tt ON t.id = tt.task_id
         LEFT JOIN tags tg ON tt.tag_id = tg.id
         LEFT JOIN task_people tp ON t.id = tp.task_id
         LEFT JOIN people p ON tp.people_id = p.id
         WHERE t.user_id = ?
         GROUP BY t.id, t.name ORDER BY work_days DESC", [$start, $end, $start, $end, $uid]
    )->fetchAll();

    $results_produced = $q(
        "SELECT r.name, r.level, COUNT(rl.id) AS n
         FROM result_logs rl JOIN results r ON rl.result_id = r.id
         WHERE rl.log_date BETWEEN ? AND ? AND r.user_id = ?
         GROUP BY r.id, r.name, r.level ORDER BY n DESC", [$start, $end, $uid]
    )->fetchAll();

    return [
        'total_workload'  => $total_workload,
        'active_days'     => $active_days,
        'tasks_worked'    => $tasks_worked,
        'total_results'   => $total_results,
        'plans_count'     => $plans_count,
        'completed_tasks' => $completed,
        'by_tag_workload' => $by_tag_workload,
        'by_tag_results'  => $by_tag_results,
        'by_task'         => $by_task,
        'results_produced'=> $results_produced,
    ];
}

function collect_notes(PDO $db, string $start, string $end, int $uid): array {
    $st = $db->prepare(
        "SELECT wn.id, wn.content, wl.log_date, t.name AS task_name
         FROM worklog_notes wn
         JOIN work_logs wl ON wn.worklog_id = wl.id
         JOIN tasks t ON wl.task_id = t.id
         WHERE wl.log_date BETWEEN ? AND ? AND t.user_id = ?
         ORDER BY wl.log_date ASC, wn.id ASC"
    );
    $st->execute([$start, $end, $uid]);
    return $st->fetchAll();
}

/**
 * Collect extracted text from attachments relevant to this period:
 *  - files on the period's worklog notes
 *  - files on results produced in the period
 *  - files attached directly to this report
 */
function collect_reference_text(PDO $db, string $start, string $end, int $reportId, int $uid): array {
    $refs = [];

    $noteAtt = $db->prepare(
        "SELECT a.file_name, a.extracted_text
         FROM attachments a
         WHERE a.entity_type = 'worklog_note' AND a.extracted_text <> '' AND a.user_id = ? AND a.entity_id IN (
            SELECT wn.id FROM worklog_notes wn JOIN work_logs wl ON wn.worklog_id = wl.id
            WHERE wl.log_date BETWEEN ? AND ?
         )"
    );
    $noteAtt->execute([$uid, $start, $end]);
    foreach ($noteAtt->fetchAll() as $r) $refs[] = ['source' => '备注附件', 'name' => $r['file_name'], 'text' => $r['extracted_text']];

    $resAtt = $db->prepare(
        "SELECT a.file_name, a.extracted_text
         FROM attachments a
         WHERE a.entity_type = 'result' AND a.extracted_text <> '' AND a.user_id = ? AND a.entity_id IN (
            SELECT DISTINCT rl.result_id FROM result_logs rl WHERE rl.log_date BETWEEN ? AND ?
         )"
    );
    $resAtt->execute([$uid, $start, $end]);
    foreach ($resAtt->fetchAll() as $r) $refs[] = ['source' => '成果附件', 'name' => $r['file_name'], 'text' => $r['extracted_text']];

    if ($reportId) {
        $repAtt = $db->prepare("SELECT file_name, extracted_text FROM attachments WHERE entity_type = 'report' AND entity_id = ? AND extracted_text <> '' AND user_id = ?");
        $repAtt->execute([$reportId, $uid]);
        foreach ($repAtt->fetchAll() as $r) $refs[] = ['source' => '报告参考', 'name' => $r['file_name'], 'text' => $r['extracted_text']];
    }
    return $refs;
}

// ---- markdown builders ----

function md_table(array $header, array $rows): string {
    $out = '| ' . implode(' | ', $header) . " |\n";
    $out .= '| ' . implode(' | ', array_fill(0, count($header), '---')) . " |\n";
    foreach ($rows as $row) {
        $cells = array_map(fn($c) => str_replace(['|', "\n"], ['\\|', ' '], (string)$c), $row);
        $out .= '| ' . implode(' | ', $cells) . " |\n";
    }
    return $out;
}

function build_stats_markdown(string $title, string $start, string $end, array $s, array $notes): string {
    $md = "# 📊 $title\n\n";
    $md .= "> 统计区间：**$start ~ $end**\n\n";
    $md .= "## 概览\n\n";
    $md .= md_table(
        ['工作量(次)', '活跃天数', '涉及任务', '产出成果', '计划数', '完成/阶段完成'],
        [[$s['total_workload'], $s['active_days'], $s['tasks_worked'], $s['total_results'], $s['plans_count'], $s['completed_tasks']]]
    ) . "\n";

    if (!empty($s['by_tag_workload'])) {
        $md .= "## 各标签工作量\n\n";
        $md .= md_table(['标签', '工作量'], array_map(fn($r) => [$r['name'], $r['n']], $s['by_tag_workload'])) . "\n";
    }
    if (!empty($s['by_tag_results'])) {
        $md .= "## 各标签成果\n\n";
        $md .= md_table(['标签', '成果数'], array_map(fn($r) => [$r['name'], $r['n']], $s['by_tag_results'])) . "\n";
    }
    if (!empty($s['by_task'])) {
        $md .= "## 任务明细\n\n";
        $md .= md_table(
            ['任务', '工作量', '成果', '标签', '受益人'],
            array_map(fn($r) => [$r['name'], $r['work_days'], $r['results'], $r['tags'] ?: '-', $r['people'] ?: '-'], $s['by_task'])
        ) . "\n";
    }
    if (!empty($s['results_produced'])) {
        $md .= "## 产出成果清单\n\n";
        $md .= md_table(['成果', '等级', '次数'], array_map(fn($r) => [$r['name'], $r['level'] ?: '-', $r['n']], $s['results_produced'])) . "\n";
    }
    if (!empty($notes)) {
        $md .= "## 工作备注（" . count($notes) . " 条）\n\n";
        foreach ($notes as $n) {
            $md .= "- **{$n['log_date']}** [{$n['task_name']}] " . str_replace("\n", ' ', $n['content']) . "\n";
        }
        $md .= "\n";
    }
    return $md;
}

function build_ai_prompt(string $title, string $start, string $end, array $s, array $notes, array $refs, string $userNote): string {
    $p = "以下是我在【{$title}】（{$start} 至 {$end}）的真实工作数据。\n\n";
    $p .= "概览：工作量 {$s['total_workload']} 次，活跃 {$s['active_days']} 天，涉及任务 {$s['tasks_worked']} 个，产出成果 {$s['total_results']} 个，完成/阶段完成 {$s['completed_tasks']} 个，计划 {$s['plans_count']} 个。\n\n";

    if (!empty($s['by_tag_workload'])) {
        $p .= "各标签工作量：" . implode('；', array_map(fn($r) => "{$r['name']} {$r['n']}", $s['by_tag_workload'])) . "。\n";
    }
    if (!empty($s['by_task'])) {
        $p .= "主要任务：" . implode('；', array_map(fn($r) => "{$r['name']}(工作量{$r['work_days']},成果{$r['results']})", array_slice($s['by_task'], 0, 15))) . "。\n";
    }
    if (!empty($s['results_produced'])) {
        $p .= "产出成果：" . implode('；', array_map(fn($r) => "{$r['name']}×{$r['n']}", array_slice($s['results_produced'], 0, 20))) . "。\n";
    }
    if (!empty($notes)) {
        $noteLines = array_slice(array_map(fn($n) => "[{$n['log_date']}] {$n['content']}", $notes), 0, 60);
        $p .= "\n工作备注：\n" . report_truncate(implode("\n", $noteLines), 6000) . "\n";
    }
    if (!empty($refs)) {
        $p .= "\n参考资料（来自上传的附件）：\n";
        $budget = 8000;
        foreach ($refs as $r) {
            if ($budget <= 0) break;
            $chunk = report_truncate($r['text'], min(3000, $budget));
            $p .= "《{$r['name']}》（{$r['source']}）：\n" . $chunk . "\n\n";
            $budget -= mb_strlen($chunk);
        }
    }
    if ($userNote !== '') {
        $p .= "\n我的额外说明/关注点：" . $userNote . "\n";
    }
    $p .= "\n请据此写复盘。";
    return $p;
}

function report_truncate(string $text, int $maxChars): string {
    $text = trim($text);
    if (mb_strlen($text) <= $maxChars) return $text;
    return mb_substr($text, 0, $maxChars) . '…(截断)';
}

// ---- AI helpers now delegate to includes/ai_client.php (shared; reports must not require ai.php) ----

function report_ai_config(PDO $db, int $uid): array {
    return ai_load_config($db, $uid);
}

function report_call_llm(array $config, array $messages, int $timeout = 90): string {
    try {
        return ai_complete_text($config, $messages, $timeout);
    } catch (Exception $e) {
        // Keep the historical, user-facing wording for connection failures.
        throw new Exception(str_replace('LLM connection failed: ', '连接失败: ', $e->getMessage()));
    }
}
