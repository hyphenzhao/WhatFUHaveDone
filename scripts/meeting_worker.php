<?php
/** Run once per minute; no browser or session is needed. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meeting_tasks.php';
$opts = getopt('', ['user:']);
$uid = isset($opts['user']) ? filter_var($opts['user'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) : null;
if ($uid === false) { fwrite(STDERR, "Invalid --user\n"); exit(1); }
$out = complete_due_meetings(get_db(), $uid);
if ($out['completed'] || $out['errors']) echo '['.date('Y-m-d H:i:s').'] '.json_encode($out, JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($out['errors'] ? 1 : 0);
