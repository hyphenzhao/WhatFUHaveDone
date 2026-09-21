<?php
/**
 * Mail API — accounts, sync, folders, messages, AI analysis, sending.
 *
 * GET    /api/mail/status                         — { imap_ext, ai_configured, accounts:[...] }
 * GET    /api/mail/accounts                       — list (no secrets)
 * POST   /api/mail/accounts                       — create
 * POST   /api/mail/accounts/test                  — test IMAP + SMTP ({id} or full form; empty password = stored)
 * PUT    /api/mail/accounts/{id}                  — update (empty password = keep)
 * DELETE /api/mail/accounts/{id}
 * POST   /api/mail/sync                           — { account_id?, budget_sec? } → { results, more }
 * GET    /api/mail/folders?account_id=            — folder tree
 * GET    /api/mail/messages?...                   — paginated list (folder_id | account_id | kind, q, unread, flagged, has_attachments, date_from, date_to, page, per_page)
 * GET    /api/mail/messages/{id}                  — full message + attachments + analysis
 * GET    /api/mail/messages/{id}/reply-template?mode=reply|reply_all|forward
 * PUT    /api/mail/messages/{id}                  — { is_seen?, is_flagged? }
 * DELETE /api/mail/messages/{id}                  — soft delete (+ move to trash on server, best effort)
 * POST   /api/mail/send                           — multipart: account_id,to,cc,bcc,subject,body_text,in_reply_to_id?,forward_attachment_ids?,files[]
 * POST   /api/mail/analyze                        — { message_id, force? } | { date } | { date_from, date_to }
 * GET    /api/mail/daily?date=                    — today's emails ranked by AI (for the home panel)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/attachments_util.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/ai_client.php';
require_once __DIR__ . '/../includes/mail/MailHelpers.php';
require_once __DIR__ . '/../includes/mail/ImapProvider.php';
require_once __DIR__ . '/../includes/mail/MailRepo.php';
require_once __DIR__ . '/../includes/mail/MailSync.php';
require_once __DIR__ . '/../includes/mail/MailAnalyzer.php';
require_once __DIR__ . '/../includes/mail/MailSend.php';
require_once __DIR__ . '/../includes/mail/MailAccounts.php';
require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
$parts = get_path_parts();
$db = get_db();
$uid = current_user_id();

$res = $parts[2] ?? '';
$id  = isset($parts[3]) ? (int)$parts[3] : 0;
$sub = $parts[4] ?? '';

$imapAvailable = function_exists('imap_open');

// ---------- status ----------
if ($res === 'status' && $method === 'GET') {
    $cfg = ai_load_config($db, $uid);
    $st = $db->prepare('SELECT * FROM mail_accounts WHERE user_id = ? ORDER BY sort, id');
    $st->execute([$uid]);
    $accounts = [];
    foreach ($st->fetchAll() as $a) {
        $pub = mail_account_public($a);
        $pub['running'] = MailSync::isRunning($db, (int)$a['id']) ? 1 : 0;
        $accounts[] = $pub;
    }
    json_success([
        'imap_ext' => $imapAvailable ? 1 : 0,
        'imap_hint' => $imapAvailable ? '' : ImapProvider::unavailableMessage(),
        'ai_configured' => ai_is_configured($cfg) ? 1 : 0,
        'accounts' => $accounts,
    ]);
}

// ---------- accounts ----------
if ($res === 'accounts' && ($parts[3] ?? '') === 'presets' && $method === 'GET') {
    json_success(mail_account_presets());
}

if ($res === 'accounts' && $method === 'GET') {
    $st = $db->prepare('SELECT * FROM mail_accounts WHERE user_id = ? ORDER BY sort, id');
    $st->execute([$uid]);
    json_success(array_map('mail_account_public', $st->fetchAll()));
}

if ($res === 'accounts' && ($parts[3] ?? '') === 'test' && $method === 'POST') {
    if (!$imapAvailable) json_error(ImapProvider::unavailableMessage());
    $data = get_json_input();
    try {
        $existing = !empty($data['id']) ? mail_get_account($db, $uid, (int)$data['id']) : null;
        $acc = mail_account_normalize($data, $existing);
        $password = (string)($data['password'] ?? '');
        if ($password === '' && $existing) $password = crypto_decrypt((string)$existing['password_enc']);
        $acc['password'] = $password;
        $out = mail_account_test($acc);
    } catch (Throwable $e) {
        json_error($e->getMessage());
    }
    json_success($out, $out['imap_ok'] ? 'IMAP 连接成功' : 'IMAP 连接失败');
}

if ($res === 'accounts' && $method === 'POST') {
    $data = get_json_input();
    try {
        $acc = mail_account_create($db, $uid, $data, (string)($data['password'] ?? ''));
    } catch (Throwable $e) {
        json_error($e->getMessage());
    }
    json_success($acc, '邮箱账户已添加');
}

if ($res === 'accounts' && $id && $method === 'PUT') {
    $data = get_json_input();
    try {
        $acc = mail_account_update($db, $uid, $id, $data, (string)($data['password'] ?? ''));
    } catch (Throwable $e) {
        json_error($e->getMessage());
    }
    json_success($acc, '已保存');
}

if ($res === 'accounts' && $id && $method === 'DELETE') {
    $existing = mail_get_account($db, $uid, $id);
    if (!$existing) json_error('账户不存在', 404);
    $ids = $db->prepare('SELECT id FROM mail_messages WHERE account_id = ? AND user_id = ?');
    $ids->execute([$id, $uid]);
    $idList = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
    if ($idList) {
        foreach (array_chunk($idList, 500) as $chunk) delete_attachments($db, 'mail_message', $chunk);
    }
    $db->prepare('DELETE FROM mail_accounts WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    json_success(null, '邮箱账户已删除');
}


// ---------- sync ----------
if ($res === 'sync' && $method === 'POST') {
    if (!$imapAvailable) json_error(ImapProvider::unavailableMessage());
    $data = get_json_input();
    $budget = max(5, min(120, (int)($data['budget_sec'] ?? 25)));
    @set_time_limit($budget + 20);
    $onlyId = (int)($data['account_id'] ?? 0);
    $st = $db->prepare('SELECT * FROM mail_accounts WHERE user_id = ? AND enabled = 1 ' . ($onlyId ? 'AND id = ? ' : '') . 'ORDER BY sort, id');
    $st->execute($onlyId ? [$uid, $onlyId] : [$uid]);
    $accounts = $st->fetchAll();
    if (!$accounts) json_error('没有已启用的邮箱账户');
    $deadline = microtime(true) + $budget;
    $results = [];
    $more = false;
    foreach ($accounts as $a) {
        $remaining = (int)floor($deadline - microtime(true));
        if ($remaining < 5) { $more = true; break; }
        $sync = new MailSync($db, $uid, $a, null);
        $r = $sync->syncAccount($remaining);
        $r['account_id'] = (int)$a['id'];
        $results[] = $r;
        if (!empty($r['more']) || !empty($r['skipped'])) $more = $more || !empty($r['more']);
    }
    json_success(['results' => $results, 'more' => $more ? 1 : 0]);
}

// ---------- folders ----------
if ($res === 'folders' && $method === 'GET') {
    $accId = (int)($_GET['account_id'] ?? 0);
    $st = $db->prepare('SELECT * FROM mail_accounts WHERE user_id = ? ' . ($accId ? 'AND id = ? ' : '') . 'ORDER BY sort, id');
    $st->execute($accId ? [$uid, $accId] : [$uid]);
    $tree = [];
    $fs = $db->prepare('SELECT id, path, display_name, kind, message_count, unread_count, backfill_done, last_synced_at FROM mail_folders WHERE account_id = ? ORDER BY sort, id');
    foreach ($st->fetchAll() as $a) {
        $fs->execute([(int)$a['id']]);
        $folders = $fs->fetchAll();
        foreach ($folders as &$f) { foreach (['id', 'message_count', 'unread_count', 'backfill_done'] as $k) $f[$k] = (int)$f[$k]; }
        usort($folders, fn($x, $y) => mail_kind_order($x['kind']) <=> mail_kind_order($y['kind']) ?: strcmp($x['display_name'], $y['display_name']));
        $tree[] = ['account' => mail_account_public($a), 'folders' => $folders];
    }
    json_success($tree);
}

// ---------- messages ----------
if ($res === 'messages' && $method === 'GET' && !$id) {
    $filters = [
        'folder_id' => (int)($_GET['folder_id'] ?? 0), 'account_id' => (int)($_GET['account_id'] ?? 0),
        'kind' => $_GET['kind'] ?? '', 'q' => $_GET['q'] ?? '', 'unread' => !empty($_GET['unread']), 'flagged' => !empty($_GET['flagged']),
        'has_attachments' => !empty($_GET['has_attachments']),
        'date_from' => validate_date((string)($_GET['date_from'] ?? '')) ? $_GET['date_from'] : '',
        'date_to' => validate_date((string)($_GET['date_to'] ?? '')) ? $_GET['date_to'] : '',
    ];
    json_success(mail_query_messages($db, $uid, $filters, (int)($_GET['page'] ?? 1), (int)($_GET['per_page'] ?? 50)));
}

if ($res === 'messages' && $id && $sub === 'reply-template' && $method === 'GET') {
    $m = mail_get_message_full($db, $uid, $id);
    if (!$m) json_error('邮件不存在', 404);
    $mode = in_array($_GET['mode'] ?? 'reply', ['reply', 'reply_all', 'forward'], true) ? $_GET['mode'] : 'reply';
    $t = mail_build_reply_template($m, $mode, (string)$m['account_email']);
    $t['account_id'] = $m['account_id'];
    $t['in_reply_to_id'] = $mode === 'forward' ? 0 : $m['id'];
    $t['attachment_ids'] = $mode === 'forward' ? array_values(array_map(fn($a) => $a['id'], array_filter($m['attachments'], fn($a) => $a['stored']))) : [];
    json_success($t);
}

if ($res === 'messages' && $id && $method === 'GET') {
    $m = mail_get_message_full($db, $uid, $id);
    if (!$m) json_error('邮件不存在', 404);
    json_success($m);
}

if ($res === 'messages' && $id && $method === 'PUT') {
    $m = mail_get_message_full($db, $uid, $id);
    if (!$m) json_error('邮件不存在', 404);
    $data = get_json_input();
    $sets = []; $params = []; $flags = [];
    if (array_key_exists('is_seen', $data)) {
        $sets[] = 'is_seen = ?'; $params[] = (int)!!$data['is_seen']; $flags['seen'] = !!$data['is_seen'];
        // Pin to the first read; marking unread puts it back in today's queue
        $sets[] = $flags['seen'] ? 'read_at = COALESCE(read_at, NOW())' : 'read_at = NULL';
    }
    if (array_key_exists('is_flagged', $data)) { $sets[] = 'is_flagged = ?'; $params[] = (int)!!$data['is_flagged']; $flags['flagged'] = !!$data['is_flagged']; }
    if (!$sets) json_error('没有要更新的字段');
    $params[] = $id; $params[] = $uid;
    $db->prepare('UPDATE mail_messages SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?')->execute($params);
    mail_refresh_folder_unread($db, $m['folder_id']);
    if ($imapAvailable && !empty($data['push'] ?? 1)) {
        // best-effort push to server, keep the request snappy
        try {
            $account = mail_get_account($db, $uid, $m['account_id']);
            $account['password'] = crypto_decrypt((string)$account['password_enc']);
            $p = mail_provider_for($account);
            $p->connect($account);
            if (isset($flags['seen'])) $p->setFlag($m['folder_path'], $m['uid'], 'Seen', $flags['seen']);
            if (isset($flags['flagged'])) $p->setFlag($m['folder_path'], $m['uid'], 'Flagged', $flags['flagged']);
            $p->close();
        } catch (Throwable $e) { /* ignore */ }
    }
    json_success(['id' => $id] + $flags, '已更新');
}

if ($res === 'messages' && $id && $method === 'DELETE') {
    $m = mail_get_message_full($db, $uid, $id);
    if (!$m) json_error('邮件不存在', 404);
    $db->prepare('UPDATE mail_messages SET is_deleted = 1 WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    mail_refresh_folder_unread($db, $m['folder_id']);
    $moved = 0;
    if ($imapAvailable && $m['folder_kind'] !== 'trash') {
        try {
            $account = mail_get_account($db, $uid, $m['account_id']);
            $account['password'] = crypto_decrypt((string)$account['password_enc']);
            $tf = $db->prepare("SELECT path FROM mail_folders WHERE account_id = ? AND kind = 'trash' ORDER BY sort LIMIT 1");
            $tf->execute([$m['account_id']]);
            $trash = $tf->fetchColumn();
            if ($trash) {
                $p = mail_provider_for($account);
                $p->connect($account);
                $p->moveTo($m['folder_path'], $m['uid'], (string)$trash);
                $p->close();
                $moved = 1;
            }
        } catch (Throwable $e) { /* ignore */ }
    }
    json_success(['id' => $id, 'moved_to_trash' => $moved], '邮件已删除');
}

// ---------- send ----------
if ($res === 'send' && $method === 'POST') {
    @set_time_limit(120);
    $files = [];
    if (!empty($_FILES['files'])) {
        $f = $_FILES['files'];
        $names = is_array($f['name']) ? $f['name'] : [$f['name']];
        foreach ($names as $i => $n) {
            $err = is_array($f['error']) ? $f['error'][$i] : $f['error'];
            $tmp = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
            $size = is_array($f['size']) ? $f['size'][$i] : $f['size'];
            if ($size > 20 * 1024 * 1024) json_error("附件 {$n} 超过 20MB");
            $files[] = ['name' => (string)$n, 'path' => $tmp, 'mime' => function_exists('mime_content_type') ? (@mime_content_type($tmp) ?: '') : ''];
        }
    }
    $fwd = [];
    if (!empty($_POST['forward_attachment_ids'])) {
        $fwd = array_map('intval', is_array($_POST['forward_attachment_ids']) ? $_POST['forward_attachment_ids'] : explode(',', (string)$_POST['forward_attachment_ids']));
    }
    try {
        $r = mail_send_message($db, $uid, [
            'account_id' => (int)($_POST['account_id'] ?? 0),
            'to' => (string)($_POST['to'] ?? ''), 'cc' => (string)($_POST['cc'] ?? ''), 'bcc' => (string)($_POST['bcc'] ?? ''),
            'subject' => (string)($_POST['subject'] ?? ''), 'body' => (string)($_POST['body_text'] ?? ''),
            'in_reply_to_id' => (int)($_POST['in_reply_to_id'] ?? 0),
            'forward_attachment_ids' => $fwd, 'files' => $files,
        ]);
    } catch (Throwable $e) {
        json_error('发送失败: ' . $e->getMessage());
    }
    json_success($r, '邮件已发送');
}

// ---------- analyze ----------
if ($res === 'analyze' && $method === 'POST') {
    $data = get_json_input();
    $analyzer = new MailAnalyzer($db, $uid);
    try {
        if (!empty($data['message_id'])) {
            @set_time_limit(150);
            $a = $analyzer->analyze((int)$data['message_id'], !empty($data['force']), 90);
            json_success($a, '分析完成');
        }
        @set_time_limit(200);
        // A single `date` means that day's queue (unread + read that day); an explicit
        // date_from/date_to range still refers to send dates.
        if (!empty($data['date']) && empty($data['date_from'])) {
            if (!validate_date((string)$data['date'])) json_error('date 格式应为 YYYY-MM-DD');
            $r = $analyzer->analyzeDaily((string)$data['date'], empty($data['force']), 120);
        } else {
            $from = (string)($data['date_from'] ?? '');
            $to = (string)($data['date_to'] ?? $from);
            if (!validate_date($from) || !validate_date($to)) json_error('请提供 message_id 或 date / date_from+date_to');
            $r = $analyzer->analyzeRange($from, $to, empty($data['force']), 120);
        }
        json_success($r, "已分析 {$r['done']} 封" . ($r['remaining'] ? "，剩余 {$r['remaining']} 封" : ''));
    } catch (MailException $e) {
        json_error($e->getMessage());
    }
}

// ---------- daily (home panel) ----------
if ($res === 'daily' && $method === 'GET') {
    $date = validate_date((string)($_GET['date'] ?? '')) ? $_GET['date'] : today();
    $cfg = ai_load_config($db, $uid);
    $acc = $db->prepare('SELECT COUNT(*) FROM mail_accounts WHERE user_id = ? AND enabled = 1');
    $acc->execute([$uid]);
    $hasAccounts = (int)$acc->fetchColumn() > 0;
    $st = $db->prepare("SELECT m.id, m.account_id, m.folder_id, f.kind AS folder_kind, m.from_name, m.from_email, m.subject, m.msg_date,
                               m.is_seen, m.read_at, m.is_flagged, m.is_answered, m.has_attachments, m.snippet, m.size,
                               a.brief_title, a.summary, a.priority, a.relevance, a.category, a.needs_reply, a.status AS analysis_status
                        FROM mail_messages m
                        JOIN mail_folders f ON f.id = m.folder_id
                        LEFT JOIN mail_analysis a ON a.message_id = m.id
                        WHERE m.user_id = ? AND m.is_deleted = 0 AND f.kind IN ('inbox','other','archive')
                          AND " . mail_day_condition('m') . "
                        ORDER BY (a.id IS NULL) ASC, m.is_seen ASC, ((6 - COALESCE(a.priority, 3)) * 20 + COALESCE(a.relevance, 0)) DESC, m.msg_date DESC
                        LIMIT 100");
    $st->execute([$uid, $date, $date]);
    $items = array_map('mail_row_public', $st->fetchAll());
    $analyzed = 0;
    $unread = 0;
    foreach ($items as $it) {
        if ($it['analysis'] && $it['analysis']['status'] === 'ok') $analyzed++;
        if (!$it['is_seen']) $unread++;
    }
    json_success([
        'date' => $date,
        'unread' => $unread,
        'is_today' => $date === today() ? 1 : 0,
        'ai_configured' => ai_is_configured($cfg) ? 1 : 0,
        'has_accounts' => $hasAccounts ? 1 : 0,
        'imap_ext' => $imapAvailable ? 1 : 0,
        'total' => count($items),
        'analyzed' => $analyzed,
        'items' => $items,
    ]);
}

json_error('Method not allowed', 405);
