<?php
/**
 * mail_send_message(): compose + SMTP send + copy to Sent folder + mark answered.
 * Shared by api/mail.php and the send_email AI tool.
 */

require_once __DIR__ . '/../crypto.php';
require_once __DIR__ . '/../attachments_util.php';
require_once __DIR__ . '/MailHelpers.php';
require_once __DIR__ . '/MailRepo.php';
require_once __DIR__ . '/SmtpClient.php';
require_once __DIR__ . '/MimeBuilder.php';

/**
 * @param array $opts {
 *   account_id:int, to:string[]|string, cc?:string[]|string, bcc?:string[]|string, subject:string, body:string,
 *   html?:string, in_reply_to_id?:int, forward_attachment_ids?:int[], files?: [ ['name','path','mime'] ]
 * }
 * @return array{message_id:string, recipients:int, sent_copy:bool}
 */
function mail_send_message(PDO $db, int $uid, array $opts): array {
    $account = mail_get_account($db, $uid, (int)($opts['account_id'] ?? 0));
    if (!$account) throw new MailException('邮箱账户不存在');
    if (empty($account['smtp_host'])) throw new MailException('该账户未配置 SMTP 服务器');
    try {
        $account['password'] = crypto_decrypt((string)$account['password_enc']);
    } catch (Throwable $e) {
        throw new MailException($e->getMessage());
    }

    $norm = function ($v): array {
        if (is_array($v)) { $out = []; foreach ($v as $x) $out = array_merge($out, MimeBuilder::parseAddressInput((string)$x)); return array_values(array_unique($out)); }
        return MimeBuilder::parseAddressInput((string)$v);
    };
    $to = $norm($opts['to'] ?? []);
    $cc = $norm($opts['cc'] ?? []);
    $bcc = $norm($opts['bcc'] ?? []);
    if (!$to) throw new MailException('请至少填写一个有效的收件人地址');
    $subject = trim((string)($opts['subject'] ?? ''));
    $body = (string)($opts['body'] ?? '');
    if ($subject === '' && trim($body) === '') throw new MailException('主题和正文不能同时为空');

    $inReplyTo = '';
    $references = '';
    $replyRow = null;
    if (!empty($opts['in_reply_to_id'])) {
        $replyRow = mail_get_message_full($db, $uid, (int)$opts['in_reply_to_id']);
        if ($replyRow && $replyRow['message_id'] !== '') {
            $inReplyTo = $replyRow['message_id'];
            $references = trim(($replyRow['references_txt'] ?? '') . ' ' . $replyRow['message_id']);
        }
    }

    // Attachments: uploaded files + forwarded originals (ownership checked)
    $attachments = [];
    foreach ($opts['files'] ?? [] as $f) {
        if (!empty($f['path']) && is_file($f['path'])) {
            $attachments[] = ['name' => (string)($f['name'] ?? basename($f['path'])), 'path' => $f['path'], 'mime' => (string)($f['mime'] ?? '')];
        }
    }
    $fwdIds = array_values(array_filter(array_map('intval', (array)($opts['forward_attachment_ids'] ?? []))));
    if ($fwdIds) {
        $ph = implode(',', array_fill(0, count($fwdIds), '?'));
        $st = $db->prepare("SELECT file_name, stored_name, mime FROM attachments WHERE id IN ($ph) AND user_id = ? AND stored_name <> ''");
        $st->execute(array_merge($fwdIds, [$uid]));
        foreach ($st->fetchAll() as $a) {
            $p = attachments_dir() . '/' . $a['stored_name'];
            if (is_file($p)) $attachments[] = ['name' => $a['file_name'], 'path' => $p, 'mime' => $a['mime']];
        }
    }
    $totalBytes = 0;
    foreach ($attachments as $a) $totalBytes += (int)@filesize($a['path']);
    if ($totalBytes > 25 * 1024 * 1024) throw new MailException('附件总大小超过 25MB');

    $built = MimeBuilder::build([
        'from_name'   => (string)$account['name'] !== '' ? (string)$account['name'] : '',
        'from_email'  => (string)$account['email'],
        'to' => $to, 'cc' => $cc,
        'subject' => $subject, 'text' => $body, 'html' => (string)($opts['html'] ?? ''),
        'attachments' => $attachments,
        'in_reply_to' => $inReplyTo, 'references' => $references,
    ]);

    SmtpClient::send($account, (string)$account['email'], array_values(array_unique(array_merge($to, $cc, $bcc))), $built['raw']);

    // Copy to Sent folder (best effort)
    $sentCopy = false;
    try {
        $st = $db->prepare("SELECT path FROM mail_folders WHERE account_id = ? AND kind = 'sent' ORDER BY sort LIMIT 1");
        $st->execute([(int)$account['id']]);
        $sentPath = $st->fetchColumn();
        if ($sentPath) {
            $provider = mail_provider_for($account);
            $provider->connect($account);
            $provider->append((string)$sentPath, $built['raw'], ['Seen']);
            $provider->close();
            $sentCopy = true;
        }
    } catch (Throwable $e) {
        // ignore: the mail was sent; the copy is a convenience
    }

    if ($replyRow) {
        $db->prepare('UPDATE mail_messages SET is_answered = 1 WHERE id = ? AND user_id = ?')->execute([(int)$replyRow['id'], $uid]);
        try {
            $provider = mail_provider_for($account);
            $provider->connect($account);
            $provider->setFlag((string)$replyRow['folder_path'], (int)$replyRow['uid'], 'Answered', true);
            $provider->close();
        } catch (Throwable $e) {}
    }

    return ['message_id' => $built['message_id'], 'recipients' => count(array_unique(array_merge($to, $cc, $bcc))), 'sent_copy' => $sentCopy];
}
