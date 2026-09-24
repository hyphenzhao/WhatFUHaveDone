<?php
/**
 * Mail repository helpers shared by api/mail.php, AI tools and the analyzer.
 * All functions take an explicit $uid.
 */

require_once __DIR__ . '/MailHelpers.php';

/**
 * Query messages with filters + pagination.
 * $filters: folder_id, account_id, kind, q, unread, flagged, highlighted, has_attachments, date_from, date_to, ids
 * Returns ['items' => [...], 'page', 'per_page', 'total', 'has_more']
 */
function mail_query_messages(PDO $db, int $uid, array $filters, int $page = 1, int $perPage = 50): array {
    $page = max(1, $page);
    $perPage = max(1, min(200, $perPage));
    $where = ['m.user_id = ?', 'm.is_deleted = 0'];
    $params = [$uid];

    if (!empty($filters['folder_id'])) { $where[] = 'm.folder_id = ?'; $params[] = (int)$filters['folder_id']; }
    if (!empty($filters['account_id'])) { $where[] = 'm.account_id = ?'; $params[] = (int)$filters['account_id']; }
    if (!empty($filters['kind'])) {
        $kinds = is_array($filters['kind']) ? $filters['kind'] : explode(',', (string)$filters['kind']);
        $kinds = array_values(array_filter(array_map('trim', $kinds)));
        if ($kinds) { $where[] = 'f.kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')'; $params = array_merge($params, $kinds); }
    } else {
        // default: hide junk/trash unless explicitly requested by folder
        if (empty($filters['folder_id'])) { $where[] = "f.kind NOT IN ('junk','trash')"; }
    }
    if (!empty($filters['unread'])) $where[] = 'm.is_seen = 0';
    if (!empty($filters['flagged'])) $where[] = 'm.is_flagged = 1';
    if (!empty($filters['highlighted'])) $where[] = 'm.is_highlighted = 1';
    if (!empty($filters['has_attachments'])) $where[] = 'm.has_attachments = 1';
    if (!empty($filters['date_from'])) { $where[] = 'm.msg_date >= ?'; $params[] = $filters['date_from'] . ' 00:00:00'; }
    if (!empty($filters['date_to'])) { $where[] = 'm.msg_date <= ?'; $params[] = $filters['date_to'] . ' 23:59:59'; }
    if (!empty($filters['q'])) {
        $q = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string)$filters['q'])) . '%';
        $where[] = '(m.subject LIKE ? OR m.from_name LIKE ? OR m.from_email LIKE ? OR m.snippet LIKE ?)';
        array_push($params, $q, $q, $q, $q);
    }
    if (!empty($filters['ids']) && is_array($filters['ids'])) {
        $ids = array_map('intval', $filters['ids']);
        $where[] = 'm.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_merge($params, $ids);
    }

    $whereSql = implode(' AND ', $where);
    $cnt = $db->prepare("SELECT COUNT(*) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE $whereSql");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $sql = "SELECT m.id, m.account_id, m.folder_id, f.kind AS folder_kind, m.from_name, m.from_email, m.subject, m.msg_date,
                   m.is_seen, m.is_flagged, m.is_highlighted, m.is_answered, m.has_attachments, m.snippet, m.size,
                   a.brief_title, a.summary, a.priority, a.relevance, a.category, a.needs_reply, a.status AS analysis_status
            FROM mail_messages m
            JOIN mail_folders f ON f.id = m.folder_id
            LEFT JOIN mail_analysis a ON a.message_id = m.id
            WHERE $whereSql
            ORDER BY m.msg_date DESC, m.id DESC
            LIMIT $perPage OFFSET $offset";
    $st = $db->prepare($sql);
    $st->execute($params);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[] = mail_row_public($r);
    }
    return ['items' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'has_more' => $offset + count($items) < $total];
}

/**
 * A mail belongs to a day by when it was READ, not when it was sent:
 *   unread → always "today" (however old it is)
 *   read   → pinned to read_at (falling back to msg_date for rows synced before migration 006)
 * Returns a SQL condition taking the target date twice.
 */
function mail_day_condition(string $alias = 'm'): string {
    return "(({$alias}.is_seen = 1 AND DATE(COALESCE({$alias}.read_at, {$alias}.msg_date)) = ?)"
         . " OR ({$alias}.is_seen = 0 AND ? = CURDATE()))";
}

/** Normalize a joined message row for JSON output (analysis nested). */
function mail_row_public(array $r): array {
    $analysis = null;
    if (array_key_exists('analysis_status', $r) && $r['analysis_status'] !== null) {
        $analysis = [
            'brief_title' => $r['brief_title'], 'summary' => $r['summary'],
            'priority' => (int)$r['priority'], 'relevance' => (int)$r['relevance'],
            'category' => $r['category'], 'needs_reply' => (int)$r['needs_reply'], 'status' => $r['analysis_status'],
        ];
    }
    foreach (['brief_title', 'summary', 'priority', 'relevance', 'category', 'needs_reply', 'analysis_status'] as $k) unset($r[$k]);
    foreach (['id', 'account_id', 'folder_id', 'is_seen', 'is_flagged', 'is_highlighted', 'is_answered', 'has_attachments', 'size'] as $k) {
        if (isset($r[$k])) $r[$k] = (int)$r[$k];
    }
    $r['analysis'] = $analysis;
    return $r;
}

/** Full message + attachments + analysis, with cid: references rewritten to attachment URLs. */
function mail_get_message_full(PDO $db, int $uid, int $id): ?array {
    $st = $db->prepare('SELECT m.*, f.kind AS folder_kind, f.display_name AS folder_name, f.path AS folder_path, acc.email AS account_email, acc.name AS account_name
                        FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id JOIN mail_accounts acc ON acc.id = m.account_id
                        WHERE m.id = ? AND m.user_id = ?');
    $st->execute([$id, $uid]);
    $m = $st->fetch();
    if (!$m) return null;
    foreach (['id', 'account_id', 'folder_id', 'uid', 'size', 'is_seen', 'is_flagged', 'is_highlighted', 'is_answered', 'has_attachments', 'is_deleted'] as $k) $m[$k] = (int)$m[$k];
    $m['to'] = json_decode((string)$m['to_json'], true) ?: [];
    $m['cc'] = json_decode((string)$m['cc_json'], true) ?: [];
    unset($m['to_json'], $m['cc_json']);

    $at = $db->prepare('SELECT id, file_name, mime, content_id, size, (stored_name <> "") AS is_stored,
                               (extracted_text IS NOT NULL AND extracted_text <> "") AS has_text
                        FROM attachments WHERE entity_type = "mail_message" AND entity_id = ? AND user_id = ? ORDER BY id ASC');
    $at->execute([$id, $uid]);
    $attachments = [];
    $cidMap = [];
    foreach ($at->fetchAll() as $a) {
        $a['id'] = (int)$a['id']; $a['size'] = (int)$a['size']; $a['stored'] = (int)$a['is_stored']; $a['has_text'] = (int)$a['has_text'];
        unset($a['is_stored']);
        $a['download_url'] = '/api/attachments/' . $a['id'] . '/download';
        $a['inline_url'] = $a['download_url'] . '?inline=1';
        if ($a['content_id'] !== '' && $a['stored']) $cidMap[$a['content_id']] = $a['inline_url'];
        $attachments[] = $a;
    }
    $m['attachments'] = $attachments;
    if ($cidMap && $m['body_html']) {
        $m['body_html'] = preg_replace_callback('/(["\'(])\s*cid:([^"\')\s>]+)/i', function ($mm) use ($cidMap) {
            $cid = trim($mm[2]);
            return isset($cidMap[$cid]) ? $mm[1] . $cidMap[$cid] : $mm[0];
        }, $m['body_html']);
    }

    $an = $db->prepare('SELECT * FROM mail_analysis WHERE message_id = ?');
    $an->execute([$id]);
    $analysis = $an->fetch();
    if ($analysis) {
        $analysis['priority'] = (int)$analysis['priority'];
        $analysis['relevance'] = (int)$analysis['relevance'];
        $analysis['needs_reply'] = (int)$analysis['needs_reply'];
        $analysis['user_edited'] = (int)($analysis['user_edited'] ?? 0);
        $analysis['actions'] = json_decode((string)$analysis['actions_json'], true) ?: [];
        unset($analysis['actions_json']);
    }
    $m['analysis'] = $analysis ?: null;
    $m['highlights'] = mail_get_highlights($db, $uid, $id);
    return $m;
}

/** Highlighted passages inside a mail body, oldest first. */
function mail_get_highlights(PDO $db, int $uid, int $messageId): array {
    $st = $db->prepare('SELECT id, snippet, note, source, created_at FROM mail_highlights
                        WHERE message_id = ? AND user_id = ? ORDER BY id ASC');
    $st->execute([$messageId, $uid]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    return $rows;
}

/**
 * Keep mail_messages.is_highlighted in step with "has at least one passage",
 * so the list marker, the 🔆 filter and search_emails(highlighted_only) all
 * keep meaning the same thing.
 */
function mail_sync_highlight_flag(PDO $db, int $uid, int $messageId): int {
    $st = $db->prepare('SELECT COUNT(*) FROM mail_highlights WHERE message_id = ? AND user_id = ?');
    $st->execute([$messageId, $uid]);
    $n = (int)$st->fetchColumn();
    $db->prepare('UPDATE mail_messages SET is_highlighted = ?, highlighted_at = ' . ($n ? 'COALESCE(highlighted_at, NOW())' : 'NULL')
                 . ' WHERE id = ? AND user_id = ?')->execute([$n ? 1 : 0, $messageId, $uid]);
    return $n;
}

/** Attachment texts for AI prompts (per-item and total budgets). */
function mail_attachment_texts(PDO $db, int $uid, int $messageId, int $perItem = 2500, int $total = 8000): array {
    $st = $db->prepare('SELECT id, file_name, mime, size, extracted_text FROM attachments WHERE entity_type = "mail_message" AND entity_id = ? AND user_id = ? ORDER BY id');
    $st->execute([$messageId, $uid]);
    $out = [];
    $budget = $total;
    foreach ($st->fetchAll() as $a) {
        $text = trim((string)$a['extracted_text']);
        $chunk = '';
        if ($text !== '' && $budget > 0) {
            $chunk = ai_truncate($text, min($perItem, $budget));
            $budget -= mb_strlen($chunk, 'UTF-8');
        }
        $out[] = ['id' => (int)$a['id'], 'file_name' => $a['file_name'], 'mime' => $a['mime'], 'size' => (int)$a['size'], 'text' => $chunk];
    }
    return $out;
}

/** Account row (without secrets) for the user, or null. */
function mail_get_account(PDO $db, int $uid, int $accountId): ?array {
    $st = $db->prepare('SELECT * FROM mail_accounts WHERE id = ? AND user_id = ?');
    $st->execute([$accountId, $uid]);
    $row = $st->fetch();
    return $row ?: null;
}

function mail_account_public(array $a): array {
    unset($a['password_enc']);
    foreach (['id', 'user_id', 'imap_port', 'smtp_port', 'validate_cert', 'enabled', 'sync_all_folders', 'sort'] as $k) {
        if (isset($a[$k])) $a[$k] = (int)$a[$k];
    }
    return $a;
}

/** Recalculate unread_count for a folder from local rows (after local flag changes). */
function mail_refresh_folder_unread(PDO $db, int $folderId): void {
    $db->prepare('UPDATE mail_folders SET unread_count = (SELECT COUNT(*) FROM mail_messages WHERE folder_id = ? AND is_seen = 0 AND is_deleted = 0) WHERE id = ?')
       ->execute([$folderId, $folderId]);
}
