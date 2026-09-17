<?php
/**
 * MailSync — incremental IMAP → MySQL synchronisation for one account.
 *
 * Per folder we keep two watermarks:
 *   last_uid      : highest UID stored; new mail is UID > last_uid (forward)
 *   backfill_uid  : lowest UID stored so far; history is UID < backfill_uid,
 *                   fetched newest-first in batches until backfill_done=1
 * The first sync of a folder sets last_uid = uidnext-1 so the newest mail
 * shows up immediately and older mail streams in over subsequent runs.
 *
 * Concurrency: MySQL GET_LOCK('mail_sync_{account_id}') — shared between the
 * Apache request path and the CLI worker, auto-released if a process dies.
 *
 * Works without a session: pass $uid explicitly.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../crypto.php';
require_once __DIR__ . '/../extract.php';
require_once __DIR__ . '/../attachments_util.php';
require_once __DIR__ . '/MailHelpers.php';

class MailSync {
    public const BACKFILL_BATCH = 50;
    public const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;
    public const FLAGS_INTERVAL_SEC = 600;
    public const DELETED_INTERVAL_SEC = 3600;

    private PDO $db;
    private int $uid;
    private array $account;
    /** @var callable|null */
    private $logger;
    private float $deadline = 0;
    private ?MailProviderInterface $provider = null;
    private array $stats = ['folders' => 0, 'new' => 0, 'backfilled' => 0, 'more' => false];

    public function __construct(PDO $db, int $uid, array $account, ?callable $logger = null) {
        $this->db = $db;
        $this->uid = $uid;
        $this->account = $account;
        $this->logger = $logger;
    }

    private function log(string $msg): void {
        if ($this->logger) ($this->logger)("[uid={$this->uid} acct={$this->account['id']}] " . $msg);
    }

    private function outOfTime(): bool {
        return microtime(true) >= $this->deadline;
    }

    /** True while another process holds this account's sync lock. */
    public static function isRunning(PDO $db, int $accountId): bool {
        $st = $db->prepare('SELECT IS_USED_LOCK(?)');
        $st->execute(['mail_sync_' . $accountId]);
        return $st->fetchColumn() !== null;
    }

    /**
     * @return array{folders:int,new:int,backfilled:int,more:bool,skipped?:string,error?:string}
     */
    public function syncAccount(int $budgetSec = 25): array {
        $accountId = (int)$this->account['id'];
        $this->deadline = microtime(true) + max(5, $budgetSec);

        $lock = $this->db->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute(['mail_sync_' . $accountId]);
        if ((int)$lock->fetchColumn() !== 1) {
            $this->log('skipped: another sync is running');
            return $this->stats + ['skipped' => 'running'];
        }

        try {
            try {
                $this->account['password'] = crypto_decrypt((string)($this->account['password_enc'] ?? ''));
            } catch (Throwable $e) {
                throw new MailException($e->getMessage());
            }
            if ($this->account['password'] === '') throw new MailException('账户未设置密码');

            $this->provider = mail_provider_for($this->account);
            $this->provider->connect($this->account);
            $this->log('connected');

            $folders = $this->syncFolderList();
            foreach ($folders as $folder) {
                if ($this->outOfTime()) { $this->stats['more'] = true; break; }
                try {
                    $this->syncFolder($folder);
                    $this->stats['folders']++;
                } catch (MailException $e) {
                    $this->log("folder {$folder['path']} error: " . $e->getMessage());
                }
            }

            $this->db->prepare('UPDATE mail_accounts SET last_sync_at = NOW(), last_error = NULL WHERE id = ?')->execute([$accountId]);
            $this->log(sprintf('done folders=%d new=%d backfilled=%d more=%s', $this->stats['folders'], $this->stats['new'], $this->stats['backfilled'], $this->stats['more'] ? 'yes' : 'no'));
            return $this->stats;
        } catch (Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 1000);
            $this->db->prepare('UPDATE mail_accounts SET last_error = ? WHERE id = ?')->execute([$msg, $accountId]);
            $this->log('error: ' . $msg);
            return $this->stats + ['error' => $msg];
        } finally {
            if ($this->provider) { try { $this->provider->close(); } catch (Throwable $e) {} }
            $rel = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute(['mail_sync_' . $accountId]);
            $rel->fetchColumn();
        }
    }

    /** Upsert folder rows from the server and return the DB rows to sync (ordered). */
    private function syncFolderList(): array {
        $accountId = (int)$this->account['id'];
        $remote = $this->provider->listFolders();
        $up = $this->db->prepare('INSERT INTO mail_folders (user_id, account_id, path, display_name, delimiter, kind, sort)
                                  VALUES (?, ?, ?, ?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), delimiter = VALUES(delimiter), kind = VALUES(kind), sort = VALUES(sort)');
        $paths = [];
        foreach ($remote as $i => $f) {
            $up->execute([$this->uid, $accountId, $f['path'], $f['display_name'], $f['delimiter'], $f['kind'], $i]);
            $paths[] = $f['path'];
        }
        $st = $this->db->prepare('SELECT * FROM mail_folders WHERE account_id = ? ORDER BY sort ASC, id ASC');
        $st->execute([$accountId]);
        $rows = $st->fetchAll();
        $onlyMain = empty($this->account['sync_all_folders']);
        $out = [];
        foreach ($rows as $r) {
            if (!in_array($r['path'], $paths, true)) continue;          // folder vanished on server: leave data, skip
            if ($onlyMain && !in_array($r['kind'], ['inbox', 'sent'], true)) continue;
            $out[] = $r;
        }
        usort($out, fn($a, $b) => mail_kind_order($a['kind']) <=> mail_kind_order($b['kind']) ?: ((int)$a['sort'] <=> (int)$b['sort']));
        return $out;
    }

    private function syncFolder(array $folder): void {
        $fid = (int)$folder['id'];
        $path = $folder['path'];
        $status = $this->provider->folderStatus($path);

        // UIDVALIDITY changed → local UIDs are meaningless; purge and start over
        if ((int)$folder['uidvalidity'] !== 0 && $status['uidvalidity'] !== (int)$folder['uidvalidity']) {
            $this->log("uidvalidity changed for {$path}, purging");
            $ids = $this->db->prepare('SELECT id FROM mail_messages WHERE folder_id = ?');
            $ids->execute([$fid]);
            $idList = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
            if ($idList) delete_attachments($this->db, 'mail_message', $idList);
            $this->db->prepare('DELETE FROM mail_messages WHERE folder_id = ?')->execute([$fid]);
            $folder['last_uid'] = 0; $folder['backfill_uid'] = 0; $folder['backfill_done'] = 0;
        }
        if ((int)$folder['uidvalidity'] !== $status['uidvalidity']) {
            $this->db->prepare('UPDATE mail_folders SET uidvalidity = ? WHERE id = ?')->execute([$status['uidvalidity'], $fid]);
        }

        $lastUid = (int)$folder['last_uid'];
        $backfillUid = (int)$folder['backfill_uid'];
        $backfillDone = (int)$folder['backfill_done'] === 1;

        // First contact with this folder: anchor at "now"; history comes via backfill
        if ($lastUid === 0 && $backfillUid === 0) {
            $lastUid = max(0, $status['uidnext'] - 1);
            $backfillUid = $lastUid + 1;
            $backfillDone = $status['messages'] === 0;
            $this->db->prepare('UPDATE mail_folders SET last_uid = ?, backfill_uid = ?, backfill_done = ? WHERE id = ?')
                     ->execute([$lastUid, $backfillUid, $backfillDone ? 1 : 0, $fid]);
        }

        // 1) Forward: new mail
        if ($status['uidnext'] - 1 > $lastUid) {
            $newUids = $this->provider->searchUids($path, 'UID ' . ($lastUid + 1) . ':*');
            foreach ($newUids as $u) {
                if ($this->outOfTime()) { $this->stats['more'] = true; break; }
                if ($this->storeMessage($folder, $u)) $this->stats['new']++;
                $lastUid = max($lastUid, $u);
                $this->db->prepare('UPDATE mail_folders SET last_uid = ? WHERE id = ?')->execute([$lastUid, $fid]);
            }
        }

        // 2) Backfill history (newest first) + 3) deletion detection share the ALL uid list
        $allUids = null;
        if (!$backfillDone && !$this->outOfTime()) {
            $allUids = $this->provider->searchUids($path, 'ALL');
            $older = array_filter($allUids, fn($u) => $u < $backfillUid);
            rsort($older);
            if (!$older) {
                $this->db->prepare('UPDATE mail_folders SET backfill_done = 1 WHERE id = ?')->execute([$fid]);
                $backfillDone = true;
            } else {
                $batch = array_slice($older, 0, self::BACKFILL_BATCH);
                $completedBatch = true;
                foreach ($batch as $u) {
                    if ($this->outOfTime()) { $this->stats['more'] = true; $completedBatch = false; break; }
                    if ($this->storeMessage($folder, $u)) $this->stats['backfilled']++;
                    $backfillUid = $u;
                    $this->db->prepare('UPDATE mail_folders SET backfill_uid = ? WHERE id = ?')->execute([$backfillUid, $fid]);
                }
                if (count($older) > count($batch)) {
                    $this->stats['more'] = true;              // more history remains for a later run
                } elseif ($completedBatch) {
                    $this->db->prepare('UPDATE mail_folders SET backfill_done = 1 WHERE id = ?')->execute([$fid]);
                    $backfillDone = true;
                }
            }
        }

        // 3) Flags (seen/flagged) for recent messages
        $flagsAge = $folder['flags_synced_at'] ? time() - strtotime($folder['flags_synced_at']) : PHP_INT_MAX;
        if ($flagsAge > self::FLAGS_INTERVAL_SEC && !$this->outOfTime()) {
            $this->syncFlags($fid, $path);
        }

        // 4) Deleted-on-server detection (only when the full uid list is known)
        $delAge = $folder['deleted_checked_at'] ? time() - strtotime($folder['deleted_checked_at']) : PHP_INT_MAX;
        if ($backfillDone && $delAge > self::DELETED_INTERVAL_SEC && !$this->outOfTime()) {
            if ($allUids === null) $allUids = $this->provider->searchUids($path, 'ALL');
            $this->detectDeleted($fid, $allUids);
        }

        // Counters from the server (authoritative, cheap)
        $this->db->prepare('UPDATE mail_folders SET message_count = ?, unread_count = ?, last_synced_at = NOW() WHERE id = ?')
                 ->execute([$status['messages'], $status['unseen'], $fid]);
    }

    /** Fetch + store one message. Returns true when a new row was inserted. */
    private function storeMessage(array $folder, int $uid): bool {
        $fid = (int)$folder['id'];
        try {
            $m = $this->provider->fetchMessage($folder['path'], $uid);
        } catch (MailException $e) {
            $this->log("uid {$uid} fetch failed: " . $e->getMessage());
            return false;
        }

        $html = $m['body_html'] !== '' ? mail_sanitize_html($m['body_html']) : '';
        $text = $m['body_text'];
        $snippet = mail_snippet($text !== '' ? $text : mail_html_to_text($html));

        $st = $this->db->prepare('INSERT INTO mail_messages
            (user_id, account_id, folder_id, uid, message_id, in_reply_to, references_txt, from_name, from_email, to_json, cc_json,
             subject, msg_date, size, is_seen, is_flagged, is_answered, has_attachments, snippet, body_text, body_html)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_seen = VALUES(is_seen), is_flagged = VALUES(is_flagged), is_answered = VALUES(is_answered), is_deleted = 0');
        $st->execute([
            $this->uid, (int)$this->account['id'], $fid, $uid,
            $m['message_id'], $m['in_reply_to'], $m['references'],
            $m['from_name'], $m['from_email'],
            json_encode($m['to'], JSON_UNESCAPED_UNICODE), json_encode($m['cc'], JSON_UNESCAPED_UNICODE),
            $m['subject'], $m['date'], $m['size'],
            $m['flags']['seen'] ? 1 : 0, $m['flags']['flagged'] ? 1 : 0, $m['flags']['answered'] ? 1 : 0,
            count($m['attachments']) ? 1 : 0, $snippet, $text, $html,
        ]);
        $inserted = $st->rowCount() === 1;   // 1 = insert, 2 = update, 0 = no change
        if (!$inserted) return false;
        $msgId = (int)$this->db->lastInsertId();

        foreach ($m['attachments'] as $att) {
            $this->storeAttachment($folder['path'], $uid, $msgId, $att);
        }
        return true;
    }

    private function storeAttachment(string $path, int $uid, int $msgId, array $att): void {
        $stored = '';
        $text = '';
        $size = (int)$att['size'];
        $mime = (string)$att['mime'];
        if ($size <= self::MAX_ATTACHMENT_BYTES) {
            try {
                $bytes = $this->provider->fetchAttachment($path, $uid, $att['part']);
            } catch (Throwable $e) {
                $bytes = '';
                $this->log("attachment {$att['filename']} fetch failed: " . $e->getMessage());
            }
            if ($bytes !== '') {
                $dir = attachments_dir();
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $ext = strtolower(pathinfo($att['filename'], PATHINFO_EXTENSION));
                $safeExt = preg_replace('/[^a-z0-9]+/', '', $ext);
                $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . ($safeExt ? '.' . $safeExt : '');
                $dest = $dir . '/' . $stored;
                if (@file_put_contents($dest, $bytes) === false) {
                    $this->log("attachment {$att['filename']} write failed (uploads/ 权限?)");
                    $stored = '';
                } else {
                    @chmod($dest, 0664);
                    $size = strlen($bytes);
                    if (function_exists('mime_content_type')) $mime = @mime_content_type($dest) ?: $mime;
                    if (!empty($safeExt) && !$att['inline']) {
                        $text = extract_file_text($dest, $ext);
                        if (strlen($text) > 500000) $text = mb_substr($text, 0, 500000) . "\n...(内容已截断)";
                    }
                }
            }
        }
        $this->db->prepare('INSERT INTO attachments (user_id, entity_type, entity_id, file_name, stored_name, mime, content_id, size, extracted_text)
                            VALUES (?, "mail_message", ?, ?, ?, ?, ?, ?, ?)')
                 ->execute([$this->uid, $msgId, $att['filename'], $stored, $mime, $att['content_id'], $size, $text]);
    }

    private function syncFlags(int $fid, string $path): void {
        try {
            $unseen = array_flip($this->provider->searchUids($path, 'UNSEEN'));
            $flagged = array_flip($this->provider->searchUids($path, 'FLAGGED'));
        } catch (MailException $e) {
            $this->log("flags search failed: " . $e->getMessage());
            return;
        }
        // Limit to recent mail to keep this cheap on huge folders
        $st = $this->db->prepare('SELECT id, uid, is_seen, is_flagged FROM mail_messages WHERE folder_id = ? AND is_deleted = 0 AND (msg_date IS NULL OR msg_date >= DATE_SUB(NOW(), INTERVAL 90 DAY))');
        $st->execute([$fid]);
        $upd = $this->db->prepare('UPDATE mail_messages SET is_seen = ?, is_flagged = ? WHERE id = ?');
        $changed = 0;
        foreach ($st->fetchAll() as $r) {
            $seen = isset($unseen[(int)$r['uid']]) ? 0 : 1;
            $flag = isset($flagged[(int)$r['uid']]) ? 1 : 0;
            if ($seen !== (int)$r['is_seen'] || $flag !== (int)$r['is_flagged']) {
                $upd->execute([$seen, $flag, (int)$r['id']]);
                $changed++;
            }
        }
        $this->db->prepare('UPDATE mail_folders SET flags_synced_at = NOW() WHERE id = ?')->execute([$fid]);
        if ($changed) $this->log("flags updated: {$changed}");
    }

    private function detectDeleted(int $fid, array $allUids): void {
        $remote = array_flip($allUids);
        $st = $this->db->prepare('SELECT id, uid FROM mail_messages WHERE folder_id = ? AND is_deleted = 0');
        $st->execute([$fid]);
        $gone = [];
        foreach ($st->fetchAll() as $r) {
            if (!isset($remote[(int)$r['uid']])) $gone[] = (int)$r['id'];
        }
        if ($gone) {
            $ph = implode(',', array_fill(0, count($gone), '?'));
            $this->db->prepare("UPDATE mail_messages SET is_deleted = 1 WHERE id IN ($ph)")->execute($gone);
            $this->log('marked deleted: ' . count($gone));
        }
        $this->db->prepare('UPDATE mail_folders SET deleted_checked_at = NOW() WHERE id = ?')->execute([$fid]);
    }
}
