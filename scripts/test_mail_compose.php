<?php
/** Offline regressions: php scripts/test_mail_compose.php (no DB/SMTP). */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/mail/MailHelpers.php';
require_once __DIR__ . '/../includes/mail/MailAccounts.php';

function expect_same($expected, $actual, string $label): void {
    if ($expected !== $actual) throw new RuntimeException($label . ': ' . json_encode($actual));
}
function address(string $email): array { return ['name' => '', 'email' => $email]; }

// This is the decoded shape returned by mail_get_message_full(), not a DB row.
$message = [
    'from_name' => 'Sender', 'from_email' => 'Sender@example.com', 'subject' => 'Meeting',
    'msg_date' => '2026-10-03 10:00:00', 'body_text' => "First line\nSecond line",
    'to' => array_map('address', ['ME@example.com', 'peer@example.com', 'sender@example.com', 'PEER@example.com', 'other-me@example.com']),
    'cc' => array_map('address', ['cc@example.com', 'peer@example.com', 'CC@example.com', 'me@example.com', 'sender@example.com']),
];
$own = [' me@example.com ', 'other-me@example.com'];
$reply = mail_build_reply_template($message, 'reply_all', $own);
expect_same(['Sender@example.com', 'peer@example.com'], $reply['to'], 'All sender/To peers retained once');
expect_same(['cc@example.com'], $reply['cc'], 'CC retained, deduped against To and self');
expect_same('Re: Meeting', $reply['subject'], 'Reply subject');
$single = mail_build_reply_template($message, 'reply', $own);
expect_same(['Sender@example.com'], $single['to'], 'Single reply');
expect_same([], $single['cc'], 'Single reply excludes CC');

$raw = $message;
$raw['to_json'] = json_encode($raw['to']); $raw['cc_json'] = json_encode($raw['cc']);
unset($raw['to'], $raw['cc']);
expect_same($reply, mail_build_reply_template($raw, 'reply_all', $own), 'Raw DB row compatibility');
$forward = mail_build_reply_template($message, 'forward', $own);
expect_same([], $forward['to'], 'Forward does not preselect recipients');
expect_same([], $forward['cc'], 'Forward does not preselect CC');
expect_same(true, str_contains($forward['quoted_text'], 'cc@example.com'), 'Forward quote preserves CC');

$message['from_email'] = 'ME@example.com';
$sent = mail_build_reply_template($message, 'reply_all', $own);
expect_same(['peer@example.com', 'sender@example.com'], $sent['to'], 'Reply to own sent mail excludes self');
expect_same(['cc@example.com'], $sent['cc'], 'Reply to own sent mail retains CC');
$message['to'] = [address('me@example.com')];
$message['cc'] = [address('cc@example.com')];
expect_same(['cc@example.com'], mail_build_reply_template($message, 'reply_all', $own)['to'], 'CC-only peers remain sendable');

$account = mail_account_normalize(['email' => 'me@example.com', 'imap_host' => 'imap.example.com',
    'signature_text' => "Researcher\r\nLab", 'signature_enabled' => false]);
expect_same("Researcher\nLab", $account['signature_text'], 'Normalize signature line endings');
expect_same(0, $account['signature_enabled'], 'Disabled signature');
expect_same($account['signature_text'], mail_account_normalize(['name' => 'Work'], $account)['signature_text'], 'Unrelated edits preserve signature');
expect_same('', mail_account_normalize(['signature_text' => ''], $account)['signature_text'], 'Clear signature');
try {
    mail_account_normalize(['signature_text' => str_repeat('字', 4001)], $account);
    throw new RuntimeException('Expected oversized signature rejection');
} catch (MailException $e) { /* expected */ }
echo "Mail reply and signature regressions passed.\n";
