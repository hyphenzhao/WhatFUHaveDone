<?php
/**
 * MailAnalyzer — AI triage of emails against the user's identity.
 *
 * Produces mail_analysis rows: brief_title, summary, priority(1-5), relevance(0-100),
 * category, needs_reply, deadline_hint, detailed_md (materials/steps), actions.
 * Failures are stored with status='error' so a broken email is not retried
 * forever; pass $force to retry.
 */

require_once __DIR__ . '/../ai_client.php';
require_once __DIR__ . '/../profile_context.php';
require_once __DIR__ . '/MailRepo.php';

class MailAnalyzer {
    private PDO $db;
    private int $uid;
    /** @var callable|null */
    private $logger;
    private ?array $config = null;

    public function __construct(PDO $db, int $uid, ?callable $logger = null) {
        $this->db = $db;
        $this->uid = $uid;
        $this->logger = $logger;
    }

    private function log(string $m): void { if ($this->logger) ($this->logger)("[uid={$this->uid}] analyzer: " . $m); }

    private function config(): array {
        if ($this->config === null) {
            $this->config = ai_load_config($this->db, $this->uid);
            if (!ai_is_configured($this->config)) throw new MailException('请先配置AI');
        }
        return $this->config;
    }

    /** Analyze one message. Returns the stored analysis (public shape). */
    public function analyze(int $messageId, bool $force = false, int $timeout = 90): array {
        $existing = $this->db->prepare('SELECT * FROM mail_analysis WHERE message_id = ? AND user_id = ?');
        $existing->execute([$messageId, $this->uid]);
        $row = $existing->fetch();
        if ($row && !$force && $row['status'] === 'ok') return $this->publicRow($row);

        $msg = mail_get_message_full($this->db, $this->uid, $messageId);
        if (!$msg) throw new MailException('邮件不存在');
        $config = $this->config();

        $messages = $this->buildPrompt($msg);
        try {
            $reply = ai_complete_text($config, $messages, $timeout);
            $data = ai_extract_json($reply);
            if (!$data) throw new Exception('AI 输出不是有效 JSON');
            $clean = $this->normalize($data);
            $this->upsert($messageId, $clean, 'ok', '', (string)($config['model'] ?? ''));
        } catch (Throwable $e) {
            $err = mb_substr($e->getMessage(), 0, 1000);
            $this->log("message {$messageId} failed: {$err}");
            $fallback = ['brief_title' => mb_substr((string)$msg['subject'], 0, 200), 'summary' => '', 'priority' => 3, 'relevance' => 0,
                         'category' => 'other', 'needs_reply' => 0, 'deadline_hint' => '', 'detailed_md' => '', 'actions' => []];
            $this->upsert($messageId, $fallback, 'error', $err, (string)($config['model'] ?? ''));
            throw new MailException('AI 分析失败: ' . $err);
        }
        $existing->execute([$messageId, $this->uid]);
        return $this->publicRow($existing->fetch());
    }

    /**
     * Analyze messages in a date range (inbox-like folders).
     * @return array{done:int,failed:int,skipped:int,remaining:int,items:array}
     */
    public function analyzeRange(string $from, string $to, bool $onlyMissing = true, int $budgetSec = 0, int $limit = 200): array {
        $config = $this->config();
        $deadline = $budgetSec > 0 ? microtime(true) + $budgetSec : 0;
        $sql = "SELECT m.id, m.subject FROM mail_messages m
                JOIN mail_folders f ON f.id = m.folder_id
                LEFT JOIN mail_analysis a ON a.message_id = m.id
                WHERE m.user_id = ? AND m.is_deleted = 0 AND f.kind IN ('inbox','other','archive')
                  AND m.msg_date >= ? AND m.msg_date <= ?" .
               ($onlyMissing ? " AND a.id IS NULL" : "") .
               " ORDER BY m.msg_date DESC, m.id DESC LIMIT " . (int)$limit;
        $st = $this->db->prepare($sql);
        $st->execute([$this->uid, $from . ' 00:00:00', $to . ' 23:59:59']);
        $rows = $st->fetchAll();

        $res = ['done' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0, 'items' => []];
        foreach ($rows as $i => $r) {
            if ($deadline && microtime(true) > $deadline - 5) { $res['remaining'] = count($rows) - $i; break; }
            try {
                $a = $this->analyze((int)$r['id'], !$onlyMissing, 90);
                $res['done']++;
                $res['items'][] = ['id' => (int)$r['id'], 'brief_title' => $a['brief_title'], 'priority' => $a['priority'], 'relevance' => $a['relevance']];
            } catch (Throwable $e) {
                $res['failed']++;
                $res['items'][] = ['id' => (int)$r['id'], 'error' => $e->getMessage()];
            }
        }
        return $res;
    }

    private function buildPrompt(array $msg): array {
        $identity = profile_identity_block($this->db, $this->uid, 3000, 800);
        if ($identity === '') $identity = "(用户尚未上传身份文档，也没有 AI 印象。相关度请基于邮件本身判断，并保守打分。)\n";

        $system = <<<SYS
你是一名科研工作者的邮件助理。你的任务是阅读一封邮件（含附件文本），结合"用户身份资料"判断它对用户的意义，并输出**严格的 JSON**（不要 Markdown 代码块以外的任何文字，最好直接输出 JSON）。

用户身份资料（USER IDENTITY 为权威来源，AI IMPRESSIONS 为次级参考）：
{$identity}
输出 JSON 格式：
{
  "brief_title": "≤25 字的简明标题，说清这封邮件是什么事",
  "summary": "2-3 句中文摘要：谁、要什么、何时、关键条件",
  "priority": 1-5 的整数（1=必须今天处理，2=本周内，3=一般，4=可稍后，5=无需处理/仅供参考）,
  "relevance": 0-100 的整数（与用户身份、研究方向、职位、项目的相关程度；群发通知/营销一般 ≤30）,
  "category": "work | personal | notification | marketing | spam | other",
  "needs_reply": true/false,
  "deadline_hint": "邮件中出现的截止时间/日期，如无则空字符串",
  "detailed_md": "Markdown 详细分析：① 这封邮件的背景与要点；② 与用户的关系（为什么相关/不相关）；③ 若需处理，需要准备的材料、步骤、注意事项；④ 建议的回复要点（如需回复）",
  "actions": ["建议的具体行动 1", "行动 2"]
}
个性化要求：
- relevance 要对照用户的职位、研究方向、在研项目、合作对象和近期关注点（见 AI IMPRESSION SUMMARY）来打分；发件人或内容涉及其合作对象、在研项目、正在申报的基金/项目时应明显加分，并在 detailed_md 中点明关联的是哪一项。
- priority 要结合用户当前阶段的重心和已知截止时间判断，而不只看邮件措辞是否紧急。
- 需准备的材料、回复要点要贴合用户的身份（例如其职称、单位、已有成果），避免通用模板。
规则：不要编造邮件中没有的信息，也不要编造用户资料中没有的关联；附件内容也要纳入分析；用中文；relevance 与 priority 必须是整数。
SYS;

        $to = implode(', ', array_map(fn($a) => trim(($a['name'] ?? '') . ' <' . ($a['email'] ?? '') . '>'), $msg['to'] ?? []));
        $cc = implode(', ', array_map(fn($a) => trim(($a['name'] ?? '') . ' <' . ($a['email'] ?? '') . '>'), $msg['cc'] ?? []));
        $body = trim((string)$msg['body_text']);
        if ($body === '' && !empty($msg['body_html'])) $body = mail_html_to_text($msg['body_html']);
        $user = "邮件信息：\n主题: {$msg['subject']}\n发件人: {$msg['from_name']} <{$msg['from_email']}>\n收件人: {$to}\n"
              . ($cc !== '' ? "抄送: {$cc}\n" : '')
              . "日期: {$msg['msg_date']}\n文件夹: {$msg['folder_name']}\n\n正文：\n" . ai_truncate($body, 6000) . "\n";
        $atts = mail_attachment_texts($this->db, $this->uid, (int)$msg['id'], 2500, 8000);
        if ($atts) {
            $user .= "\n附件：\n";
            foreach ($atts as $a) {
                $user .= "《{$a['file_name']}》（{$a['mime']}，" . round($a['size'] / 1024) . " KB）";
                $user .= $a['text'] !== '' ? "：\n{$a['text']}\n\n" : "：（无法提取文本）\n";
            }
        }
        return [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
    }

    private function normalize(array $d): array {
        $cat = strtolower(trim((string)($d['category'] ?? 'other')));
        if (!in_array($cat, ['work', 'personal', 'notification', 'marketing', 'spam', 'other'], true)) $cat = 'other';
        $actions = $d['actions'] ?? [];
        if (!is_array($actions)) $actions = [(string)$actions];
        $actions = array_values(array_filter(array_map(fn($x) => trim(is_scalar($x) ? (string)$x : json_encode($x, JSON_UNESCAPED_UNICODE)), $actions)));
        $needsReply = $d['needs_reply'] ?? false;
        if (is_string($needsReply)) $needsReply = in_array(strtolower($needsReply), ['true', 'yes', '是', '1'], true);
        return [
            'brief_title'   => mb_substr(trim((string)($d['brief_title'] ?? '')), 0, 200),
            'summary'       => trim((string)($d['summary'] ?? '')),
            'priority'      => max(1, min(5, (int)($d['priority'] ?? 3))),
            'relevance'     => max(0, min(100, (int)($d['relevance'] ?? 0))),
            'category'      => $cat,
            'needs_reply'   => $needsReply ? 1 : 0,
            'deadline_hint' => mb_substr(trim((string)($d['deadline_hint'] ?? '')), 0, 100),
            'detailed_md'   => trim((string)($d['detailed_md'] ?? '')),
            'actions'       => $actions,
        ];
    }

    private function upsert(int $messageId, array $a, string $status, string $error, string $model): void {
        $st = $this->db->prepare('INSERT INTO mail_analysis
            (user_id, message_id, brief_title, summary, priority, relevance, category, needs_reply, deadline_hint, detailed_md, actions_json, status, error, model, analyzed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE brief_title=VALUES(brief_title), summary=VALUES(summary), priority=VALUES(priority), relevance=VALUES(relevance),
              category=VALUES(category), needs_reply=VALUES(needs_reply), deadline_hint=VALUES(deadline_hint), detailed_md=VALUES(detailed_md),
              actions_json=VALUES(actions_json), status=VALUES(status), error=VALUES(error), model=VALUES(model), analyzed_at=NOW()');
        $st->execute([$this->uid, $messageId, $a['brief_title'], $a['summary'], $a['priority'], $a['relevance'], $a['category'],
                      $a['needs_reply'], $a['deadline_hint'], $a['detailed_md'], json_encode($a['actions'], JSON_UNESCAPED_UNICODE),
                      $status, $error !== '' ? $error : null, $model]);
    }

    private function publicRow(array $row): array {
        $row['priority'] = (int)$row['priority'];
        $row['relevance'] = (int)$row['relevance'];
        $row['needs_reply'] = (int)$row['needs_reply'];
        $row['actions'] = json_decode((string)$row['actions_json'], true) ?: [];
        unset($row['actions_json']);
        return $row;
    }
}
