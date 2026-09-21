<?php
/**
 * Mail worker (CLI only) — run from cron.
 *
 *   php scripts/mail_worker.php sync    [--user=ID] [--account=ID] [--budget=600]
 *   php scripts/mail_worker.php analyze --today | --date=YYYY-MM-DD | --since=YYYY-MM-DD [--user=ID] [--force] [--budget=1500]
 *   php scripts/mail_worker.php status
 *
 * Suggested crontab (user haifeng):
 *   * /5 * * * * cd /path/to/WhatFUHaveDone && /usr/bin/php scripts/mail_worker.php sync --budget=240 >> ~/mail_worker.log 2>&1
 *   0 9 * * *    cd /path/to/WhatFUHaveDone && /usr/bin/php scripts/mail_worker.php analyze --today >> ~/mail_worker.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

umask(0002);
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai_client.php';
require_once __DIR__ . '/../includes/profile_context.php';
require_once __DIR__ . '/../includes/mail/MailHelpers.php';
require_once __DIR__ . '/../includes/mail/MailRepo.php';
require_once __DIR__ . '/../includes/mail/MailSync.php';
if (is_file(__DIR__ . '/../includes/mail/MailAnalyzer.php')) {
    require_once __DIR__ . '/../includes/mail/MailAnalyzer.php';
}

$argvList = array_slice($argv, 1);
$command = $argvList[0] ?? 'status';
$opts = [];
foreach (array_slice($argvList, 1) as $a) {
    if (preg_match('/^--([a-z_-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
}

function wlog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

$db = get_db();

function worker_user_ids(PDO $db, array $opts): array {
    if (!empty($opts['user'])) return [(int)$opts['user']];
    $st = $db->query('SELECT DISTINCT user_id FROM mail_accounts WHERE enabled = 1 ORDER BY user_id');
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

switch ($command) {
    case 'sync': {
        $budget = (int)($opts['budget'] ?? 600);
        $onlyAccount = (int)($opts['account'] ?? 0);
        $deadline = microtime(true) + $budget;
        foreach (worker_user_ids($db, $opts) as $uid) {
            $st = $db->prepare('SELECT * FROM mail_accounts WHERE user_id = ? AND enabled = 1 ORDER BY sort, id');
            $st->execute([$uid]);
            foreach ($st->fetchAll() as $account) {
                if ($onlyAccount && (int)$account['id'] !== $onlyAccount) continue;
                $remaining = (int)floor($deadline - microtime(true));
                if ($remaining < 5) { wlog('budget exhausted'); break 2; }
                $sync = new MailSync($db, $uid, $account, 'wlog');
                $res = $sync->syncAccount($remaining);
                wlog(sprintf('account %d (%s): %s', $account['id'], $account['email'], json_encode($res, JSON_UNESCAPED_UNICODE)));
            }
        }
        break;
    }

    case 'analyze': {
        if (!class_exists('MailAnalyzer')) { wlog('MailAnalyzer not available'); exit(1); }
        $budget = (int)($opts['budget'] ?? 1500);
        $force = !empty($opts['force']);
        // --today / --date: that day's queue (unread + read that day); --since: send-date range
        $daily = empty($opts['since']);
        if (!empty($opts['date'])) { $from = $to = (string)$opts['date']; }
        elseif (!empty($opts['since'])) { $from = (string)$opts['since']; $to = date('Y-m-d'); }
        else { $from = $to = date('Y-m-d'); }
        $deadline = microtime(true) + $budget;
        foreach (worker_user_ids($db, $opts) as $uid) {
            $cfg = ai_load_config($db, $uid);
            if (!ai_is_configured($cfg)) { wlog("user {$uid}: AI not configured, skipping"); continue; }
            $remaining = (int)floor($deadline - microtime(true));
            if ($remaining < 10) { wlog('budget exhausted'); break; }
            $analyzer = new MailAnalyzer($db, $uid, 'wlog');
            $res = $daily ? $analyzer->analyzeDaily($from, !$force, $remaining)
                          : $analyzer->analyzeRange($from, $to, !$force, $remaining);
            wlog(sprintf('user %d analyze %s..%s: %s', $uid, $from, $to, json_encode($res, JSON_UNESCAPED_UNICODE)));
        }
        break;
    }

    case 'status':
    default: {
        $st = $db->query('SELECT a.id, a.user_id, a.email, a.enabled, a.last_sync_at, a.last_error,
                                 (SELECT COUNT(*) FROM mail_messages m WHERE m.account_id = a.id AND m.is_deleted = 0) AS messages,
                                 (SELECT COUNT(*) FROM mail_folders f WHERE f.account_id = a.id AND f.backfill_done = 0) AS folders_pending
                          FROM mail_accounts a ORDER BY a.user_id, a.id');
        foreach ($st->fetchAll() as $r) {
            wlog(sprintf('account %d uid=%d %s enabled=%d messages=%d pending_folders=%d last_sync=%s running=%s error=%s',
                $r['id'], $r['user_id'], $r['email'], $r['enabled'], $r['messages'], $r['folders_pending'],
                $r['last_sync_at'] ?? '-', MailSync::isRunning($db, (int)$r['id']) ? 'yes' : 'no', $r['last_error'] ?? '-'));
        }
        break;
    }
}
