<?php
/**
 * AI tools: mailbox access (search / read / analyze / mark / send) and the
 * "current email" context block for the system prompt.
 * Included by api/ai.php; handlers rely on current_user_id().
 */

require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/mail/MailHelpers.php';
require_once __DIR__ . '/mail/ImapProvider.php';
require_once __DIR__ . '/mail/MailRepo.php';
require_once __DIR__ . '/mail/MailAnalyzer.php';
require_once __DIR__ . '/mail/MailSend.php';
require_once __DIR__ . '/mail/MailAccounts.php';
require_once __DIR__ . '/mail/MailSync.php';
require_once __DIR__ . '/crypto.php';

function ai_tools_mail_definitions(): array {
    return [
        // ===== MAIL =====
        [
            'name' => 'list_mail_accounts',
            'description' => '列出用户已配置的邮箱账户及同步状态。',
            'parameters' => ['type' => 'object', 'properties' => []],
            'requires_confirmation' => false,
            'handler' => 'handle_list_mail_accounts',
        ],
        [
            'name' => 'search_emails',
            'description' => '搜索已同步的邮件（默认排除垃圾箱/已删除）。可按日期范围、关键词、账户、文件夹类型、未读、含附件过滤。返回摘要列表（含已有的 AI 分析简报）。要读全文请再调用 get_email。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'date_from' => ['type' => 'string', 'description' => '起始日期 YYYY-MM-DD（可选）'],
                    'date_to' => ['type' => 'string', 'description' => '结束日期 YYYY-MM-DD（可选，默认同 date_from）'],
                    'query' => ['type' => 'string', 'description' => '关键词，匹配主题/发件人/摘要（可选）'],
                    'account_id' => ['type' => 'integer', 'description' => '限定邮箱账户ID（可选）'],
                    'folder_kind' => ['type' => 'string', 'description' => '文件夹类型: inbox/sent/drafts/archive/other/junk/trash（可选）'],
                    'unread_only' => ['type' => 'boolean', 'description' => '仅未读'],
                    'highlighted_only' => ['type' => 'boolean', 'description' => '仅用户/你高亮过的邮件——用户说"我高亮的那些"时用这个'],
                    'has_attachments' => ['type' => 'boolean', 'description' => '仅含附件'],
                    'limit' => ['type' => 'integer', 'description' => '最多返回条数，默认 30，上限 50'],
                    'page' => ['type' => 'integer', 'description' => '页码，默认 1'],
                ],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_search_emails',
        ],
        [
            'name' => 'get_email',
            'description' => '读取一封邮件的完整内容：头部、正文（最多 8000 字）、附件提取文本（每个最多 3000 字）以及已有的 AI 分析。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => '邮件ID（必填）'],
                    'include_attachments_text' => ['type' => 'boolean', 'description' => '是否包含附件文本，默认 true'],
                ],
                'required' => ['id'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_get_email',
        ],
        [
            'name' => 'get_email_analysis',
            'description' => '获取某封邮件已保存的 AI 分析（标题、摘要、优先级、相关度、详细分析、建议行动）。',
            'parameters' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => '邮件ID（必填）']],
                'required' => ['id'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_get_email_analysis',
        ],
        [
            'name' => 'analyze_email',
            'description' => '对一封邮件运行 AI 分析并保存结果（简明标题、摘要、优先级 1-5、相关度 0-100、类别、需准备材料等）。已有分析时直接返回，force=true 可重新分析。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => '邮件ID（必填）'],
                    'force' => ['type' => 'boolean', 'description' => '强制重新分析'],
                ],
                'required' => ['id'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_analyze_email',
        ],
        [
            'name' => 'analyze_emails_by_date',
            'description' => '批量分析日期范围内（收件类文件夹）尚未分析的邮件，最多约 2 分钟。返回完成/失败/剩余数量与简报列表。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'date_from' => ['type' => 'string', 'description' => '起始日期 YYYY-MM-DD（必填）'],
                    'date_to' => ['type' => 'string', 'description' => '结束日期 YYYY-MM-DD（可选，默认同 date_from）'],
                    'force' => ['type' => 'boolean', 'description' => '重新分析已分析的邮件'],
                ],
                'required' => ['date_from'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_analyze_emails_by_date',
        ],
        [
            'name' => 'open_email',
            'description' => '在用户界面中打开某封邮件（弹出邮件窗口，含正文、附件与 AI 分析）。'
                . '当用户说"打开那封"、"给我看看"、或你提到某封邮件希望用户当场查看时使用。只影响界面，不修改任何数据。',
            'parameters' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => '邮件ID（必填）']],
                'required' => ['id'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_open_email',
        ],
        [
            'name' => 'highlight_text',
            'description' => '用荧光笔高亮邮件正文里的重点字句（可一次传多段）。snippets 必须是正文中**逐字出现**的原文片段，'
                . '不要改写、不要跨段落拼接，否则无法在正文中定位（仍会作为摘录列出，但不会在正文里变黄）。'
                . '用户说"高亮与我相关的部分"时用这个；每段可附一句 note 说明为什么重要。'
                . '读取已有高亮用 get_email 或 list_text_highlights。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => '邮件ID（必填）'],
                    'snippets' => [
                        'type' => 'array',
                        'description' => '要高亮的原文片段（必填）',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => ['type' => 'string', 'description' => '正文中逐字出现的片段'],
                                'note' => ['type' => 'string', 'description' => '为什么重要（可选，≤50 字）'],
                            ],
                            'required' => ['text'],
                        ],
                    ],
                ],
                'required' => ['id', 'snippets'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_highlight_text',
        ],
        [
            'name' => 'list_text_highlights',
            'description' => '列出某封邮件正文中已高亮的片段（用户划的和你标的都在内）。',
            'parameters' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => '邮件ID（必填）']],
                'required' => ['id'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_list_text_highlights',
        ],
        [
            'name' => 'clear_text_highlights',
            'description' => '清除某封邮件正文的全部文字高亮。',
            'parameters' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => '邮件ID（必填）']],
                'required' => ['id'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_clear_text_highlights',
        ],
        [
            'name' => 'highlight_email',
            'description' => '高亮或取消高亮邮件。高亮是你与用户之间的共享标记：用户可以说"看我高亮的那几封"，'
                . '你也可以把需要处理的邮件高亮出来给用户指认。高亮只存在本地，不会同步到邮件服务器（与星标不同）。'
                . '用 search_emails 的 highlighted_only 可以列出所有高亮邮件。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => '邮件ID（必填）'],
                    'on' => ['type' => 'boolean', 'description' => 'true 高亮（默认），false 取消高亮'],
                ],
                'required' => ['id'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_highlight_email',
        ],
        [
            'name' => 'update_email_analysis',
            'description' => '修改某封邮件的 AI 分析结论（相关度、优先级、截止时间、简明标题、摘要、类别、是否需回复、详细分析、建议行动）。'
                . '用于用户指出判断有误时的人工校正，例如"这封其实跟我很相关，相关度调到 90"或"截止是下周五，不是这个"。'
                . '只传需要修改的字段，其余保持不变。改前先用 get_email_analysis 读出当前值，并在提议时说明"从 X 改为 Y"。'
                . '校正后该分析会被标记为人工校正，不会被日常自动分析覆盖。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => '邮件ID（必填）'],
                    'relevance' => ['type' => 'integer', 'description' => '相关度 0-100'],
                    'priority' => ['type' => 'integer', 'description' => '优先级 1-5（1 最高）'],
                    'deadline_hint' => ['type' => 'string', 'description' => '截止时间说明；传空字符串表示清除'],
                    'brief_title' => ['type' => 'string', 'description' => '简明标题（≤25 字）'],
                    'summary' => ['type' => 'string', 'description' => '摘要'],
                    'category' => ['type' => 'string', 'enum' => ['work', 'personal', 'notification', 'marketing', 'spam', 'other'], 'description' => '类别'],
                    'needs_reply' => ['type' => 'boolean', 'description' => '是否需要回复'],
                    'detailed_md' => ['type' => 'string', 'description' => '详细分析（Markdown）'],
                    'actions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '建议行动列表'],
                ],
                'required' => ['id'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_update_email_analysis',
        ],
        [
            'name' => 'mark_email',
            'description' => '标记邮件为已读/未读、加星/取消星标。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => '邮件ID（必填）'],
                    'seen' => ['type' => 'boolean', 'description' => '已读 true / 未读 false'],
                    'flagged' => ['type' => 'boolean', 'description' => '星标 true / 取消 false'],
                ],
                'required' => ['id'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_mark_email',
        ],
        [
            'name' => 'send_email',
            'description' => '通过用户的邮箱账户发送邮件（或回复某封邮件）。发送前必须先向用户展示完整草稿。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'account_id' => ['type' => 'integer', 'description' => '发件账户ID（必填，可用 list_mail_accounts 查询；回复时通常用原邮件的 account_id）'],
                    'to' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '收件人邮箱列表（必填）'],
                    'cc' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '抄送列表（可选）'],
                    'subject' => ['type' => 'string', 'description' => '主题（必填）'],
                    'body' => ['type' => 'string', 'description' => '纯文本正文（必填）'],
                    'in_reply_to_id' => ['type' => 'integer', 'description' => '若为回复，填原邮件ID（可选）'],
                ],
                'required' => ['account_id', 'to', 'subject', 'body'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_send_email',
        ],
        // ===== MAIL ACCOUNT SETUP =====
        [
            'name' => 'get_mail_presets',
            'description' => '列出内置的邮箱服务商预设（QQ/163/126/Gmail/Outlook/腾讯企业邮/iCloud/新浪）及其 IMAP/SMTP 参数与授权码提示。',
            'parameters' => ['type' => 'object', 'properties' => []],
            'requires_confirmation' => false,
            'handler' => 'handle_get_mail_presets',
        ],
        [
            'name' => 'add_mail_account',
            'description' => '添加一个邮箱账户。只需给出邮箱地址，常见服务商的 IMAP/SMTP 参数会自动按预设填充；学校/企业邮箱需提供 imap_host、smtp_host 等。**密码/授权码不要在对话中询问或填写**：用户会在确认卡片的密码框中直接输入，密码不会经过你。保存后系统会自动测试连接并首次收取。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'email' => ['type' => 'string', 'description' => '邮箱地址（必填）'],
                    'name' => ['type' => 'string', 'description' => '显示名称，如"工作邮箱"（可选）'],
                    'preset' => ['type' => 'string', 'description' => '预设键: qq/163/126/gmail/outlook/exmail/icloud/sina（可选，默认按域名推断）'],
                    'imap_host' => ['type' => 'string', 'description' => 'IMAP 服务器（无预设时必填）'],
                    'imap_port' => ['type' => 'integer', 'description' => 'IMAP 端口，默认 993'],
                    'imap_ssl' => ['type' => 'string', 'description' => 'ssl / tls / none，默认 ssl'],
                    'smtp_host' => ['type' => 'string', 'description' => 'SMTP 服务器（可选，不填则仅收信）'],
                    'smtp_port' => ['type' => 'integer', 'description' => 'SMTP 端口，默认 465（tls 时 587）'],
                    'smtp_ssl' => ['type' => 'string', 'description' => 'ssl / tls / none'],
                    'username' => ['type' => 'string', 'description' => '登录用户名，默认同邮箱地址'],
                    'validate_cert' => ['type' => 'boolean', 'description' => '是否校验服务器证书，默认 true'],
                    'sync_all_folders' => ['type' => 'boolean', 'description' => '是否同步所有文件夹，默认 true'],
                ],
                'required' => ['email'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_add_mail_account',
        ],
        [
            'name' => 'update_mail_account',
            'description' => '修改已有邮箱账户的参数（服务器、端口、加密、显示名、启用状态等）。如需更换密码/授权码，用户会在确认卡片中输入，不要在对话中询问。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => '账户ID（必填）'],
                    'name' => ['type' => 'string'], 'email' => ['type' => 'string'],
                    'imap_host' => ['type' => 'string'], 'imap_port' => ['type' => 'integer'], 'imap_ssl' => ['type' => 'string'],
                    'smtp_host' => ['type' => 'string'], 'smtp_port' => ['type' => 'integer'], 'smtp_ssl' => ['type' => 'string'],
                    'username' => ['type' => 'string'],
                    'enabled' => ['type' => 'boolean'], 'validate_cert' => ['type' => 'boolean'], 'sync_all_folders' => ['type' => 'boolean'],
                    'change_password' => ['type' => 'boolean', 'description' => '为 true 时确认卡片会显示密码框让用户输入新授权码'],
                ],
                'required' => ['id'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_update_mail_account',
        ],
        [
            'name' => 'test_mail_account',
            'description' => '测试已保存账户的 IMAP/SMTP 连接（使用已保存的密码）。',
            'parameters' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => '账户ID（必填）']],
                'required' => ['id'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_test_mail_account',
        ],
        [
            'name' => 'sync_mail_account',
            'description' => '立即收取某个账户（或全部账户）的新邮件，最多运行约 40 秒；历史邮件由后台定时任务继续回填。',
            'parameters' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => '账户ID，省略或 0 表示全部']],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_sync_mail_account',
        ],
        [
            'name' => 'remove_mail_account',
            'description' => '删除邮箱账户及本地已同步的邮件、附件和分析（服务器上的邮件不受影响）。',
            'parameters' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => '账户ID（必填）']],
                'required' => ['id'],
            ],
            'requires_confirmation' => true,
            'handler' => 'handle_remove_mail_account',
        ],
    ];
}

// ===== account setup handlers =====

function handle_get_mail_presets(PDO $db, array $args): array {
    $out = [];
    foreach (mail_account_presets() as $k => $p) {
        $out[] = ['preset' => $k, 'label' => $p['label'], 'domains' => $p['domains'], 'imap' => "{$p['imap_host']}:{$p['imap_port']}/{$p['imap_ssl']}",
                  'smtp' => "{$p['smtp_host']}:{$p['smtp_port']}/{$p['smtp_ssl']}", 'hint' => $p['hint']];
    }
    return $out;
}

/** After create/update: test connection and (if OK) run a short first sync. */
function mail_account_post_save(PDO $db, int $uid, int $id, bool $doSync = true): array {
    $result = ['test' => null, 'sync' => null];
    try {
        $acc = mail_account_with_password($db, $uid, $id);
        $result['test'] = mail_account_test($acc);
        if ($doSync && $result['test']['imap_ok'] && !empty($acc['enabled'])) {
            @set_time_limit(90);
            $sync = new MailSync($db, $uid, $acc, null);
            $result['sync'] = $sync->syncAccount(30);
        }
    } catch (Throwable $e) {
        $result['test'] = ['imap_ok' => 0, 'smtp_ok' => 0, 'folders' => 0, 'imap_error' => $e->getMessage(), 'smtp_error' => ''];
    }
    return $result;
}

function handle_add_mail_account(PDO $db, array $args): array {
    $uid = current_user_id();
    $password = (string)($args['_password'] ?? '');   // injected from the confirmation card, never from the LLM
    unset($args['_password']);
    if ($password === '') return ['error' => '未提供密码/授权码：请在确认卡片的密码框中输入后再确认'];
    try {
        $acc = mail_account_create($db, $uid, $args, $password);
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
    $post = mail_account_post_save($db, $uid, (int)$acc['id'], true);
    $notice = '📮 已添加邮箱：' . $acc['email'];
    return ['created' => true, 'account' => $acc, 'connection_test' => $post['test'], 'first_sync' => $post['sync'], '_notice' => $notice];
}

function handle_update_mail_account(PDO $db, array $args): array {
    $uid = current_user_id();
    $id = (int)($args['id'] ?? 0);
    $password = (string)($args['_password'] ?? '');
    unset($args['_password'], $args['id'], $args['change_password']);
    try {
        $acc = mail_account_update($db, $uid, $id, $args, $password);
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
    $post = mail_account_post_save($db, $uid, $id, false);
    return ['updated' => true, 'account' => $acc, 'connection_test' => $post['test'], '_notice' => '📮 已更新邮箱：' . $acc['email']];
}

function handle_test_mail_account(PDO $db, array $args): array {
    try {
        $acc = mail_account_with_password($db, current_user_id(), (int)($args['id'] ?? 0));
        return mail_account_test($acc);
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

function handle_sync_mail_account(PDO $db, array $args): array {
    $uid = current_user_id();
    if (!ImapProvider::available()) return ['error' => ImapProvider::unavailableMessage()];
    $id = (int)($args['id'] ?? 0);
    $st = $db->prepare('SELECT * FROM mail_accounts WHERE user_id = ? AND enabled = 1 ' . ($id ? 'AND id = ? ' : '') . 'ORDER BY sort, id');
    $st->execute($id ? [$uid, $id] : [$uid]);
    $accounts = $st->fetchAll();
    if (!$accounts) return ['error' => '没有已启用的邮箱账户'];
    @set_time_limit(120);
    $deadline = microtime(true) + 40;
    $results = [];
    foreach ($accounts as $a) {
        $remaining = (int)floor($deadline - microtime(true));
        if ($remaining < 5) break;
        $r = (new MailSync($db, $uid, $a, null))->syncAccount($remaining);
        $r['account_id'] = (int)$a['id'];
        $r['email'] = $a['email'];
        $results[] = $r;
    }
    $total = array_sum(array_map(fn($r) => ($r['new'] ?? 0) + ($r['backfilled'] ?? 0), $results));
    return ['results' => $results, '_notice' => "📥 已收取 {$total} 封新邮件"];
}

function handle_remove_mail_account(PDO $db, array $args): array {
    $uid = current_user_id();
    $id = (int)($args['id'] ?? 0);
    $existing = mail_get_account($db, $uid, $id);
    if (!$existing) return ['error' => '账户不存在'];
    $ids = $db->prepare('SELECT id FROM mail_messages WHERE account_id = ? AND user_id = ?');
    $ids->execute([$id, $uid]);
    $idList = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
    foreach (array_chunk($idList, 500) as $chunk) delete_attachments($db, 'mail_message', $chunk);
    $db->prepare('DELETE FROM mail_accounts WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    return ['deleted' => true, 'email' => $existing['email'], '_notice' => '📮 已删除邮箱：' . $existing['email']];
}

/** System-prompt block describing the email the user is currently viewing. */
function mail_context_block(PDO $db, int $uid, int $messageId): string {
    if ($messageId <= 0) return '';
    try {
        $m = mail_get_message_full($db, $uid, $messageId);
    } catch (Throwable $e) { return ''; }
    if (!$m) return '';
    $to = implode(', ', array_map(fn($a) => trim(($a['name'] ?? '') . ' <' . ($a['email'] ?? '') . '>'), $m['to']));
    $body = trim((string)$m['body_text']);
    if ($body === '' && !empty($m['body_html'])) $body = mail_html_to_text($m['body_html']);
    $out = "=== CURRENT EMAIL (the user is viewing this; \"这封邮件\" refers to it) ===\n";
    $out .= "id: {$m['id']} | account_id: {$m['account_id']} | folder: {$m['folder_name']}\n";
    $out .= "Subject: {$m['subject']}\nFrom: {$m['from_name']} <{$m['from_email']}>\nTo: {$to}\nDate: {$m['msg_date']}\n";
    $out .= "Body:\n" . ai_truncate($body, 6000) . "\n";
    $atts = mail_attachment_texts($db, $uid, $messageId, 2000, 4000);
    if ($atts) {
        $out .= "Attachments:\n";
        foreach ($atts as $a) {
            $out .= "- [{$a['id']}] {$a['file_name']} ({$a['mime']}, " . round($a['size'] / 1024) . " KB)";
            $out .= $a['text'] !== '' ? ":\n" . $a['text'] . "\n" : " (no text extracted)\n";
        }
    }
    if (!empty($m['analysis'])) {
        $a = $m['analysis'];
        $out .= "Existing analysis: title={$a['brief_title']} | priority={$a['priority']} | relevance={$a['relevance']} | category={$a['category']}\n";
        if (!empty($a['summary'])) $out .= "Summary: {$a['summary']}\n";
    }
    return $out . "\n";
}

// ===== handlers =====

function handle_list_mail_accounts(PDO $db, array $args): array {
    $st = $db->prepare('SELECT id, name, email, enabled, last_sync_at, last_error FROM mail_accounts WHERE user_id = ? ORDER BY sort, id');
    $st->execute([current_user_id()]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['enabled'] = (int)$r['enabled']; }
    return $rows;
}

function handle_search_emails(PDO $db, array $args): array {
    $filters = [];
    if (!empty($args['date_from'])) {
        $filters['date_from'] = $args['date_from'];
        $filters['date_to'] = !empty($args['date_to']) ? $args['date_to'] : $args['date_from'];
    } elseif (!empty($args['date_to'])) {
        $filters['date_to'] = $args['date_to'];
    }
    if (!empty($args['query'])) $filters['q'] = $args['query'];
    if (!empty($args['account_id'])) $filters['account_id'] = (int)$args['account_id'];
    if (!empty($args['folder_kind'])) $filters['kind'] = $args['folder_kind'];
    if (!empty($args['unread_only'])) $filters['unread'] = 1;
    if (!empty($args['highlighted_only'])) $filters['highlighted'] = 1;
    if (!empty($args['has_attachments'])) $filters['has_attachments'] = 1;
    $limit = max(1, min(50, (int)($args['limit'] ?? 30)));
    $page = max(1, (int)($args['page'] ?? 1));
    $res = mail_query_messages($db, current_user_id(), $filters, $page, $limit);
    $items = [];
    foreach ($res['items'] as $it) {
        $items[] = [
            'id' => $it['id'], 'account_id' => $it['account_id'], 'folder' => $it['folder_kind'],
            'from' => trim($it['from_name'] . ' <' . $it['from_email'] . '>'), 'subject' => $it['subject'],
            'date' => $it['msg_date'], 'unread' => $it['is_seen'] ? 0 : 1, 'has_attachments' => $it['has_attachments'],
            'highlighted' => $it['is_highlighted'] ?? 0,
            'snippet' => $it['snippet'],
            'analysis' => $it['analysis'] ? ['brief_title' => $it['analysis']['brief_title'], 'priority' => $it['analysis']['priority'], 'relevance' => $it['analysis']['relevance'], 'category' => $it['analysis']['category']] : null,
        ];
    }
    return ['total' => $res['total'], 'page' => $res['page'], 'has_more' => $res['has_more'], 'items' => $items];
}

function handle_get_email(PDO $db, array $args): array {
    $uid = current_user_id();
    $m = mail_get_message_full($db, $uid, (int)($args['id'] ?? 0));
    if (!$m) return ['error' => '邮件不存在'];
    $body = trim((string)$m['body_text']);
    if ($body === '' && !empty($m['body_html'])) $body = mail_html_to_text($m['body_html']);
    $out = [
        'id' => $m['id'], 'account_id' => $m['account_id'], 'folder' => $m['folder_name'], 'folder_kind' => $m['folder_kind'],
        'subject' => $m['subject'], 'from' => ['name' => $m['from_name'], 'email' => $m['from_email']],
        'to' => $m['to'], 'cc' => $m['cc'], 'date' => $m['msg_date'], 'unread' => $m['is_seen'] ? 0 : 1, 'flagged' => $m['is_flagged'],
        'highlighted' => $m['is_highlighted'],
        'text_highlights' => array_map(fn($h) => ['id' => $h['id'], 'snippet' => $h['snippet'], 'note' => $h['note'], 'source' => $h['source']], $m['highlights'] ?? []),
        'body' => ai_truncate($body, 8000),
        'attachments' => [],
        'analysis' => $m['analysis'] ? ['brief_title' => $m['analysis']['brief_title'], 'summary' => $m['analysis']['summary'], 'priority' => $m['analysis']['priority'],
                                        'relevance' => $m['analysis']['relevance'], 'category' => $m['analysis']['category'], 'actions' => $m['analysis']['actions']] : null,
    ];
    $includeText = !array_key_exists('include_attachments_text', $args) || !empty($args['include_attachments_text']);
    $atts = mail_attachment_texts($db, $uid, $m['id'], 3000, 8000);
    foreach ($atts as $a) {
        $out['attachments'][] = ['id' => $a['id'], 'file_name' => $a['file_name'], 'mime' => $a['mime'], 'size' => $a['size'],
                                 'text' => $includeText ? $a['text'] : ($a['text'] !== '' ? '(available)' : '')];
    }
    return $out;
}

function handle_get_email_analysis(PDO $db, array $args): array {
    $st = $db->prepare('SELECT * FROM mail_analysis WHERE message_id = ? AND user_id = ?');
    $st->execute([(int)($args['id'] ?? 0), current_user_id()]);
    $row = $st->fetch();
    if (!$row) return ['analyzed' => false];
    $row['actions'] = json_decode((string)$row['actions_json'], true) ?: [];
    unset($row['actions_json'], $row['user_id']);
    $row['priority'] = (int)$row['priority']; $row['relevance'] = (int)$row['relevance']; $row['needs_reply'] = (int)$row['needs_reply'];
    return $row + ['analyzed' => true];
}

/** Pure UI action: tell the browser to pop the mail modal open. Changes no data. */
function handle_open_email(PDO $db, array $args): array {
    $uid = current_user_id();
    $id = (int)($args['id'] ?? 0);
    $st = $db->prepare('SELECT id, subject, from_name, from_email, msg_date FROM mail_messages WHERE id = ? AND user_id = ? AND is_deleted = 0');
    $st->execute([$id, $uid]);
    $m = $st->fetch();
    if (!$m) return ['error' => '邮件不存在'];
    return [
        'opened' => true, 'id' => (int)$m['id'], 'subject' => $m['subject'],
        'from' => trim($m['from_name'] . ' <' . $m['from_email'] . '>'), 'date' => $m['msg_date'],
        '_action' => ['type' => 'open_mail', 'id' => (int)$m['id']],
        '_notice' => '📂 已打开：' . mb_substr((string)$m['subject'], 0, 30, 'UTF-8'),
    ];
}

/** Highlighter pen over passages of the body. Verifies each snippet really occurs. */
function handle_highlight_text(PDO $db, array $args): array {
    $uid = current_user_id();
    $id = (int)($args['id'] ?? 0);
    $m = mail_get_message_full($db, $uid, $id);
    if (!$m) return ['error' => '邮件不存在'];

    $snippets = $args['snippets'] ?? [];
    if (!is_array($snippets) || !$snippets) return ['error' => '请提供要高亮的原文片段'];

    // Match against the plain text AND a tag-stripped version of the HTML, so a
    // snippet the user can see is accepted regardless of which body we rendered.
    $hay = (string)$m['body_text'];
    if (!empty($m['body_html'])) $hay .= "\n" . mail_html_to_text((string)$m['body_html']);
    $norm = fn($s) => preg_replace('/\s+/u', '', (string)$s);
    $hayNorm = $norm($hay);

    $ins = $db->prepare('INSERT INTO mail_highlights (user_id, message_id, snippet, note, source) VALUES (?, ?, ?, ?, "ai")');
    $dup = $db->prepare('SELECT id FROM mail_highlights WHERE message_id = ? AND user_id = ? AND snippet = ?');
    $added = []; $missing = []; $skipped = 0;
    foreach ($snippets as $s) {
        $text = trim(is_array($s) ? (string)($s['text'] ?? '') : (string)$s);
        $note = trim(is_array($s) ? (string)($s['note'] ?? '') : '');
        if (mb_strlen($text, 'UTF-8') < 2) continue;
        $text = mb_substr($text, 0, 1000, 'UTF-8');
        // Whitespace-insensitive check: wrapped lines in the source shouldn't reject it
        if ($hayNorm !== '' && mb_strpos($hayNorm, $norm($text)) === false) { $missing[] = $text; continue; }
        $dup->execute([$id, $uid, $text]);
        if ($dup->fetchColumn()) { $skipped++; continue; }
        $ins->execute([$uid, $id, $text, mb_substr($note, 0, 500, 'UTF-8')]);
        $added[] = $text;
    }
    mail_sync_highlight_flag($db, $uid, $id);

    $out = ['ok' => true, 'id' => $id, 'added' => count($added), 'already' => $skipped,
            'highlights' => mail_get_highlights($db, $uid, $id)];
    if ($missing) {
        $out['not_found'] = $missing;
        $out['hint'] = '这些片段在正文中找不到逐字匹配，未高亮。请从 get_email 返回的正文中原样复制。';
    }
    if ($added) $out['_notice'] = '🖍 已高亮 ' . count($added) . ' 处';
    // Show the result immediately
    $out['_action'] = ['type' => 'open_mail', 'id' => $id];
    return $out;
}

function handle_list_text_highlights(PDO $db, array $args): array {
    $uid = current_user_id();
    $id = (int)($args['id'] ?? 0);
    $own = $db->prepare('SELECT subject FROM mail_messages WHERE id = ? AND user_id = ?');
    $own->execute([$id, $uid]);
    $subject = $own->fetchColumn();
    if ($subject === false) return ['error' => '邮件不存在'];
    return ['id' => $id, 'subject' => $subject, 'highlights' => mail_get_highlights($db, $uid, $id)];
}

function handle_clear_text_highlights(PDO $db, array $args): array {
    $uid = current_user_id();
    $id = (int)($args['id'] ?? 0);
    $own = $db->prepare('SELECT 1 FROM mail_messages WHERE id = ? AND user_id = ?');
    $own->execute([$id, $uid]);
    if (!$own->fetchColumn()) return ['error' => '邮件不存在'];
    $st = $db->prepare('DELETE FROM mail_highlights WHERE message_id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    mail_sync_highlight_flag($db, $uid, $id);
    return ['ok' => true, 'removed' => $st->rowCount(), '_notice' => '🖍 已清除全部高亮'];
}

/** Whole-mail marker — a coarser pointer than the text highlighter above. */
function handle_highlight_email(PDO $db, array $args): array {
    $uid = current_user_id();
    $id = (int)($args['id'] ?? 0);
    $on = !array_key_exists('on', $args) || !empty($args['on']);
    $st = $db->prepare('SELECT id, subject, is_highlighted FROM mail_messages WHERE id = ? AND user_id = ? AND is_deleted = 0');
    $st->execute([$id, $uid]);
    $m = $st->fetch();
    if (!$m) return ['error' => '邮件不存在'];
    if ((int)$m['is_highlighted'] === ($on ? 1 : 0)) {
        return ['ok' => true, 'id' => $id, 'highlighted' => $on ? 1 : 0, 'unchanged' => true];
    }
    $db->prepare('UPDATE mail_messages SET is_highlighted = ?, highlighted_at = ' . ($on ? 'NOW()' : 'NULL') . ' WHERE id = ? AND user_id = ?')
       ->execute([$on ? 1 : 0, $id, $uid]);
    return [
        'ok' => true, 'id' => $id, 'highlighted' => $on ? 1 : 0, 'subject' => $m['subject'],
        '_notice' => ($on ? '🔆 已高亮：' : '⭘ 已取消高亮：') . mb_substr((string)$m['subject'], 0, 30, 'UTF-8'),
    ];
}

/** Human correction of an AI verdict. Requires confirmation; only the given fields change. */
function handle_update_email_analysis(PDO $db, array $args): array {
    $uid = current_user_id();
    $id = (int)($args['id'] ?? 0);

    $st = $db->prepare('SELECT a.* FROM mail_analysis a JOIN mail_messages m ON m.id = a.message_id
                        WHERE a.message_id = ? AND a.user_id = ? AND m.user_id = ?');
    $st->execute([$id, $uid, $uid]);
    $cur = $st->fetch();
    if (!$cur) return ['error' => '该邮件还没有分析结果，请先用 analyze_email 分析'];

    $sets = [];
    $params = [];
    $changed = [];
    $put = function (string $col, $value, string $label, $before) use (&$sets, &$params, &$changed) {
        $sets[] = "$col = ?";
        $params[] = $value;
        $changed[$label] = ['from' => $before, 'to' => $value];
    };

    if (array_key_exists('relevance', $args)) {
        $v = max(0, min(100, (int)$args['relevance']));
        if ($v !== (int)$cur['relevance']) $put('relevance', $v, '相关度', (int)$cur['relevance']);
    }
    if (array_key_exists('priority', $args)) {
        $v = max(1, min(5, (int)$args['priority']));
        if ($v !== (int)$cur['priority']) $put('priority', $v, '优先级', (int)$cur['priority']);
    }
    if (array_key_exists('deadline_hint', $args)) {
        $v = mb_substr(trim((string)$args['deadline_hint']), 0, 100, 'UTF-8');
        if ($v !== (string)$cur['deadline_hint']) $put('deadline_hint', $v, '截止时间', (string)$cur['deadline_hint']);
    }
    if (array_key_exists('brief_title', $args)) {
        $v = mb_substr(trim((string)$args['brief_title']), 0, 200, 'UTF-8');
        if ($v !== '' && $v !== (string)$cur['brief_title']) $put('brief_title', $v, '简明标题', (string)$cur['brief_title']);
    }
    if (array_key_exists('summary', $args)) {
        $v = trim((string)$args['summary']);
        if ($v !== (string)$cur['summary']) $put('summary', $v, '摘要', (string)$cur['summary']);
    }
    if (array_key_exists('category', $args)) {
        $v = strtolower(trim((string)$args['category']));
        if (!in_array($v, ['work', 'personal', 'notification', 'marketing', 'spam', 'other'], true)) {
            return ['error' => 'category 无效（work/personal/notification/marketing/spam/other）'];
        }
        if ($v !== (string)$cur['category']) $put('category', $v, '类别', (string)$cur['category']);
    }
    if (array_key_exists('needs_reply', $args)) {
        $v = !empty($args['needs_reply']) ? 1 : 0;
        if ($v !== (int)$cur['needs_reply']) $put('needs_reply', $v, '需要回复', (int)$cur['needs_reply'] ? '是' : '否');
    }
    if (array_key_exists('detailed_md', $args)) {
        $v = trim((string)$args['detailed_md']);
        if ($v !== (string)$cur['detailed_md']) $put('detailed_md', $v, '详细分析', '(已更新)');
    }
    if (array_key_exists('actions', $args) && is_array($args['actions'])) {
        $v = array_values(array_filter(array_map(fn($x) => trim((string)$x), $args['actions'])));
        $encoded = json_encode($v, JSON_UNESCAPED_UNICODE);
        if ($encoded !== (string)$cur['actions_json']) $put('actions_json', $encoded, '建议行动', '(已更新)');
    }

    if (!$sets) return ['ok' => true, 'unchanged' => true, 'message' => '没有需要修改的字段（给出的值与当前一致）'];

    // A corrected row is never silently overwritten: analyze() already skips rows
    // with status='ok' unless force is passed, and this flag makes the UI say so.
    $sets[] = 'user_edited = 1';
    $sets[] = 'user_edited_at = NOW()';
    $sets[] = "status = 'ok'";
    $params[] = $id;
    $params[] = $uid;
    $db->prepare('UPDATE mail_analysis SET ' . implode(', ', $sets) . ' WHERE message_id = ? AND user_id = ?')->execute($params);

    $st->execute([$id, $uid, $uid]);
    $row = $st->fetch();
    $row['actions'] = json_decode((string)$row['actions_json'], true) ?: [];
    unset($row['actions_json'], $row['user_id']);
    foreach (['priority', 'relevance', 'needs_reply', 'user_edited'] as $k) $row[$k] = (int)$row[$k];

    $summary = implode('、', array_map(
        fn($label, $d) => is_scalar($d['from']) && is_scalar($d['to']) && mb_strlen((string)$d['to'], 'UTF-8') <= 24
            ? "{$label} {$d['from']} → {$d['to']}" : $label,
        array_keys($changed), $changed));
    return ['updated' => true, 'changed' => $changed, 'analysis' => $row,
            '_notice' => '✏️ 已校正邮件分析：' . mb_substr($summary, 0, 60, 'UTF-8')];
}

function handle_analyze_email(PDO $db, array $args): array {
    $analyzer = new MailAnalyzer($db, current_user_id());
    try {
        $a = $analyzer->analyze((int)($args['id'] ?? 0), !empty($args['force']), 90);
    } catch (MailException $e) {
        return ['error' => $e->getMessage()];
    }
    unset($a['user_id']);
    $a['_notice'] = '📧 已分析邮件：' . mb_substr($a['brief_title'], 0, 30, 'UTF-8');
    return $a;
}

function handle_analyze_emails_by_date(PDO $db, array $args): array {
    $from = (string)($args['date_from'] ?? '');
    $to = (string)($args['date_to'] ?? $from);
    if (!validate_date($from) || !validate_date($to)) return ['error' => '日期格式应为 YYYY-MM-DD'];
    @set_time_limit(200);
    $analyzer = new MailAnalyzer($db, current_user_id());
    try {
        $res = $analyzer->analyzeRange($from, $to, empty($args['force']), 120);
    } catch (MailException $e) {
        return ['error' => $e->getMessage()];
    }
    if ($res['done'] > 0) $res['_notice'] = "📧 已分析 {$res['done']} 封邮件";
    return $res;
}

function handle_mark_email(PDO $db, array $args): array {
    $uid = current_user_id();
    $m = mail_get_message_full($db, $uid, (int)($args['id'] ?? 0));
    if (!$m) return ['error' => '邮件不存在'];
    $sets = []; $params = [];
    if (array_key_exists('seen', $args)) {
        $sets[] = 'is_seen = ?'; $params[] = !empty($args['seen']) ? 1 : 0;
        $sets[] = !empty($args['seen']) ? 'read_at = COALESCE(read_at, NOW())' : 'read_at = NULL';
    }
    if (array_key_exists('flagged', $args)) { $sets[] = 'is_flagged = ?'; $params[] = !empty($args['flagged']) ? 1 : 0; }
    if (!$sets) return ['error' => '未指定要修改的标记'];
    $params[] = $m['id']; $params[] = $uid;
    $db->prepare('UPDATE mail_messages SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?')->execute($params);
    mail_refresh_folder_unread($db, $m['folder_id']);
    mail_push_flags_best_effort($db, $uid, $m, $args);
    return ['ok' => true, 'id' => $m['id'], 'seen' => array_key_exists('seen', $args) ? (int)!empty($args['seen']) : $m['is_seen'],
            'flagged' => array_key_exists('flagged', $args) ? (int)!empty($args['flagged']) : $m['is_flagged']];
}

/** Push seen/flagged changes to the IMAP server; failures are ignored (local state wins until next flag sync). */
function mail_push_flags_best_effort(PDO $db, int $uid, array $m, array $flags): void {
    try {
        $account = mail_get_account($db, $uid, (int)$m['account_id']);
        if (!$account) return;
        $account['password'] = crypto_decrypt((string)$account['password_enc']);
        $p = mail_provider_for($account);
        $p->connect($account);
        if (array_key_exists('seen', $flags)) $p->setFlag($m['folder_path'], (int)$m['uid'], 'Seen', !empty($flags['seen']));
        if (array_key_exists('flagged', $flags)) $p->setFlag($m['folder_path'], (int)$m['uid'], 'Flagged', !empty($flags['flagged']));
        $p->close();
    } catch (Throwable $e) { /* best effort */ }
}

function handle_send_email(PDO $db, array $args): array {
    try {
        $res = mail_send_message($db, current_user_id(), [
            'account_id' => (int)($args['account_id'] ?? 0),
            'to' => $args['to'] ?? [], 'cc' => $args['cc'] ?? [],
            'subject' => (string)($args['subject'] ?? ''), 'body' => (string)($args['body'] ?? ''),
            'in_reply_to_id' => (int)($args['in_reply_to_id'] ?? 0),
        ]);
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
    return ['sent' => true] + $res;
}
