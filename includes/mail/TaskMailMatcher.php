<?php
/**
 * TaskMailMatcher — links tasks to emails using the emails' EXISTING AI analysis
 * (brief title + summary), never the raw mail. Manual trigger only.
 *
 * Storage: only matches (and user verdicts) are stored in task_mail_links; pairs that
 * do not match are not stored. tasks.mail_match_upto is a watermark over mail_analysis.id,
 * so each click only compares analyses created since the previous run.
 */

require_once __DIR__ . '/../ai_client.php';

class TaskMailMatcher {
    public const BATCH = 40;          // analyses per LLM call
    public const MIN_SCORE = 60;      // below this nothing is stored
    public const LOOKBACK_DAYS = 60;  // mails older than task creation minus this are never candidates

    private PDO $db;
    private int $uid;

    public function __construct(PDO $db, int $uid) { $this->db = $db; $this->uid = $uid; }

    private function task(int $taskId): array {
        $st = $this->db->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
        $st->execute([$taskId, $this->uid]);
        $t = $st->fetch();
        if (!$t) throw new RuntimeException('任务不存在');
        return $t;
    }

    private function candidateSql(string $select, string $tail = ''): string {
        return "SELECT $select FROM mail_analysis a
                JOIN mail_messages m ON m.id = a.message_id
                WHERE a.user_id = ? AND a.status = 'ok' AND a.id > ? AND a.category NOT IN ('spam','marketing')
                  AND m.is_deleted = 0 AND (m.msg_date IS NULL OR m.msg_date >= ?)
                  AND NOT EXISTS (SELECT 1 FROM task_mail_links l WHERE l.task_id = ? AND l.message_id = m.id) $tail";
    }

    private function candidateParams(array $task): array {
        $from = date('Y-m-d H:i:s', strtotime($task['created_at']) - self::LOOKBACK_DAYS * 86400);
        return [$this->uid, (int)$task['mail_match_upto'], $from, (int)$task['id']];
    }

    /** Number of analysed mails not yet compared with this task. */
    public function pending(int $taskId): int {
        $task = $this->task($taskId);
        $st = $this->db->prepare($this->candidateSql('COUNT(*)'));
        $st->execute($this->candidateParams($task));
        return (int)$st->fetchColumn();
    }

    /**
     * Compare pending analyses with the task, in batches, within a time budget.
     * @return array{checked:int, matched:int, remaining:int}
     */
    public function run(int $taskId, int $budgetSec = 50): array {
        $config = ai_load_config($this->db, $this->uid);
        if (!ai_is_configured($config)) throw new RuntimeException('请先配置AI');
        $deadline = microtime(true) + $budgetSec;
        $taskBlock = $this->describeTask($taskId);
        $checked = 0; $matched = 0;

        while (true) {
            $task = $this->task($taskId);
            $st = $this->db->prepare($this->candidateSql(
                'a.id AS aid, m.id AS mid, a.brief_title, a.summary, a.category, a.deadline_hint, m.from_name, m.from_email, m.subject, m.msg_date',
                'ORDER BY a.id ASC LIMIT ' . self::BATCH));
            $st->execute($this->candidateParams($task));
            $rows = $st->fetchAll();
            if (!$rows) {
                // Nothing left: move the watermark past skipped (spam / too old) analyses as well
                $mx = $this->db->prepare('SELECT COALESCE(MAX(id), 0) FROM mail_analysis WHERE user_id = ?');
                $mx->execute([$this->uid]);
                $this->db->prepare('UPDATE tasks SET mail_match_upto = GREATEST(mail_match_upto, ?), updated_at = updated_at WHERE id = ?')
                         ->execute([(int)$mx->fetchColumn(), $taskId]);
                break;
            }
            $remainingTime = (int)floor($deadline - microtime(true));
            if ($checked > 0 && $remainingTime < 15) break;

            $matches = $this->askLlm($config, $taskBlock, $rows, max(20, min(60, $remainingTime)));
            $valid = [];
            foreach ($rows as $r) $valid[(int)$r['mid']] = true;
            $ins = $this->db->prepare('INSERT IGNORE INTO task_mail_links (user_id, task_id, message_id, score, reason, status) VALUES (?, ?, ?, ?, ?, "ai")');
            foreach ($matches as $mt) {
                $mid = (int)($mt['id'] ?? 0);
                $score = max(0, min(100, (int)($mt['score'] ?? 0)));
                if (!isset($valid[$mid]) || $score < self::MIN_SCORE) continue;   // ignore ids the model invented
                $ins->execute([$this->uid, $taskId, $mid, $score, mb_substr(trim((string)($mt['reason'] ?? '')), 0, 300, 'UTF-8')]);
                $matched += $ins->rowCount();
            }
            $maxAid = max(array_map(fn($r) => (int)$r['aid'], $rows));
            $this->db->prepare('UPDATE tasks SET mail_match_upto = GREATEST(mail_match_upto, ?), updated_at = updated_at WHERE id = ?')->execute([$maxAid, $taskId]);
            $checked += count($rows);
        }
        return ['checked' => $checked, 'matched' => $matched, 'remaining' => $this->pending($taskId)];
    }

    private function describeTask(int $taskId): string {
        $t = $this->task($taskId);
        $q = function (string $sql) use ($taskId) { $st = $this->db->prepare($sql); $st->execute([$taskId]); return $st->fetchAll(PDO::FETCH_COLUMN); };
        $tags = $q('SELECT g.name FROM tags g JOIN task_tags tt ON tt.tag_id = g.id WHERE tt.task_id = ?');
        $people = $q('SELECT CONCAT(p.name, IF(p.relationship <> "", CONCAT("（", p.relationship, "）"), "")) FROM people p JOIN task_people tp ON tp.people_id = p.id WHERE tp.task_id = ?');
        $results = $q('SELECT r.name FROM results r JOIN task_results tr ON tr.result_id = r.id WHERE tr.task_id = ?');
        $notes = $q('SELECT wn.content FROM worklog_notes wn JOIN work_logs wl ON wl.id = wn.worklog_id WHERE wl.task_id = ? ORDER BY wn.created_at DESC LIMIT 12');
        $out = "任务名称: {$t['name']}\n";
        if (trim((string)$t['description']) !== '') $out .= "描述: " . ai_truncate($t['description'], 600) . "\n";
        if ($tags) $out .= "标签: " . implode('、', $tags) . "\n";
        if ($people) $out .= "相关人物: " . implode('、', $people) . "\n";
        if (!empty($t['location'])) $out .= "地点: {$t['location']}\n";
        if (!empty($t['deadline'])) $out .= "截止: {$t['deadline']}\n";
        if ($results) $out .= "成果: " . implode('、', $results) . "\n";
        if ($notes) $out .= "近期备注:\n- " . implode("\n- ", array_map(fn($n) => ai_truncate($n, 160), $notes)) . "\n";
        $out .= "创建于: " . substr((string)$t['created_at'], 0, 10) . "\n";
        return $out;
    }

    private function askLlm(array $config, string $taskBlock, array $rows, int $timeout): array {
        $lines = '';
        foreach ($rows as $r) {
            $lines .= "[{$r['mid']}] " . substr((string)$r['msg_date'], 0, 10) . " | 发件人: " . trim($r['from_name'] . ' <' . $r['from_email'] . '>')
                    . " | " . ai_truncate($r['brief_title'], 60) . " | " . str_replace("\n", ' ', ai_truncate((string)$r['summary'], 220))
                    . ($r['deadline_hint'] ? " | 截止: {$r['deadline_hint']}" : '') . "\n";
        }
        $system = "你在判断哪些邮件与用户的某一个任务**具体相关**。只依据给出的任务信息和每封邮件的 AI 摘要判断。\n"
                . "判定标准（从严）：邮件谈的就是这个任务本身——同一个项目/事件/申报/文章/会议，或任务相关人物就该事项的往来。仅仅属于同一领域、同一单位、同一类事务（例如都是“基金”“培训”）不算相关。拿不准就不要列出。\n"
                . "只输出 JSON：{\"matches\":[{\"id\":邮件方括号里的数字,\"score\":60-100 的整数,\"reason\":\"≤40 字，点明具体关联点\"}]}；没有相关邮件就输出 {\"matches\":[]}。不要输出任何其他文字，不要编造列表里没有的 id。\n"
                . "评分：90+ 明确是该任务的往来；75-89 高度可能；60-74 可能相关但证据有限；低于 60 不要列出。";
        $user = "# 任务\n{$taskBlock}\n# 候选邮件（每行一封）\n{$lines}";
        $reply = ai_complete_text($config, [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], $timeout);
        $data = ai_extract_json($reply);
        if (!is_array($data)) throw new RuntimeException('AI 返回的匹配结果无法解析，请重试');
        $m = $data['matches'] ?? (array_is_list($data) ? $data : []);
        return is_array($m) ? $m : [];
    }
}
