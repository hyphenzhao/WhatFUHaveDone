<?php
/**
 * IMAP provider built on PHP ext-imap (c-client).
 *
 * Notes on ext-imap quirks handled here:
 *  - imap_search() does not support the UID search key, so UID ranges are
 *    resolved with imap_fetch_overview("N:*", FT_UID) instead.
 *  - imap_errors() is drained after operations so warnings don't accumulate
 *    and get emitted at shutdown.
 *  - All fetches use FT_PEEK so reading mail here never marks it \Seen.
 */

require_once __DIR__ . '/MailProviderInterface.php';
require_once __DIR__ . '/MailHelpers.php';

class ImapProvider implements MailProviderInterface {
    /** @var resource|IMAP\Connection|null */
    private $stream = null;
    private string $base = '';          // "{host:port/imap/ssl}"
    private string $currentPath = '';
    private array $structCache = [];    // uid => structure (per selected folder)

    public static function available(): bool {
        return function_exists('imap_open');
    }

    public static function unavailableMessage(): string {
        return 'PHP imap 扩展未安装：请在服务器执行 sudo apt install -y php8.1-imap && sudo systemctl restart apache2';
    }

    public function connect(array $account): void {
        if (!self::available()) throw new MailException(self::unavailableMessage());

        $host = (string)($account['imap_host'] ?? '');
        $port = (int)($account['imap_port'] ?? 993);
        $ssl  = strtolower((string)($account['imap_ssl'] ?? 'ssl'));
        if ($host === '' || !preg_match('/^[A-Za-z0-9.-]{1,255}$/', $host)) throw new MailException('IMAP 服务器地址无效');
        if ($port < 1 || $port > 65535) throw new MailException('IMAP 端口无效');

        $flags = '/imap';
        if ($ssl === 'ssl') $flags .= '/ssl';
        elseif ($ssl === 'tls') $flags .= '/tls';
        else $flags .= '/notls';
        if (empty($account['validate_cert'])) $flags .= '/novalidate-cert';

        $this->base = '{' . $host . ':' . $port . $flags . '}';
        $user = (string)($account['username'] ?? $account['email'] ?? '');
        $pass = (string)($account['password'] ?? '');

        $this->drainErrors();
        $stream = @imap_open($this->base . 'INBOX', $user, $pass, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        if ($stream === false) {
            $err = $this->lastError() ?: '无法连接 IMAP 服务器';
            throw new MailException('IMAP 连接失败: ' . $err);
        }
        $this->stream = $stream;
        $this->currentPath = 'INBOX';
        $this->structCache = [];
    }

    public function close(): void {
        if ($this->stream) {
            @imap_close($this->stream);
            $this->stream = null;
        }
        $this->drainErrors();
    }

    public function __destruct() { $this->close(); }

    // ---------- folders ----------

    public function listFolders(): array {
        $this->ensure();
        $this->drainErrors();
        $list = @imap_getmailboxes($this->stream, $this->base, '*');
        if (!is_array($list)) {
            throw new MailException('读取文件夹列表失败: ' . ($this->lastError() ?: 'unknown'));
        }
        $out = [];
        $seen = [];
        foreach ($list as $mb) {
            $full = (string)$mb->name;
            $path = strpos($full, $this->base) === 0 ? substr($full, strlen($this->base)) : $full;
            if ($path === '' || isset($seen[$path])) continue;
            $attrs = (int)$mb->attributes;
            if (defined('LATT_NOSELECT') && ($attrs & LATT_NOSELECT)) continue;
            $seen[$path] = true;
            $display = function_exists('imap_mutf7_to_utf8') ? (@imap_mutf7_to_utf8($path) ?: $path) : $path;
            $out[] = [
                'path'         => $path,
                'display_name' => $display,
                'delimiter'    => (string)($mb->delimiter ?: '/'),
                'kind'         => mail_folder_kind($path, $attrs, $display),
            ];
        }
        // Always make sure INBOX exists even if the server omitted it from LIST *
        $hasInbox = false;
        foreach ($out as $f) if (strcasecmp($f['path'], 'INBOX') === 0) { $hasInbox = true; break; }
        if (!$hasInbox) array_unshift($out, ['path' => 'INBOX', 'display_name' => 'INBOX', 'delimiter' => '/', 'kind' => 'inbox']);
        usort($out, fn($a, $b) => mail_kind_order($a['kind']) <=> mail_kind_order($b['kind']) ?: strcmp($a['display_name'], $b['display_name']));
        return $out;
    }

    public function folderStatus(string $path): array {
        $this->ensure();
        $this->drainErrors();
        $st = @imap_status($this->stream, $this->base . $path, SA_ALL);
        if (!$st) throw new MailException("读取文件夹状态失败 ({$path}): " . ($this->lastError() ?: 'unknown'));
        return [
            'uidvalidity' => (int)($st->uidvalidity ?? 0),
            'uidnext'     => (int)($st->uidnext ?? 0),
            'messages'    => (int)($st->messages ?? 0),
            'unseen'      => (int)($st->unseen ?? 0),
        ];
    }

    private function select(string $path): void {
        $this->ensure();
        if ($this->currentPath === $path) return;
        $this->drainErrors();
        if (!@imap_reopen($this->stream, $this->base . $path)) {
            throw new MailException("无法打开文件夹 {$path}: " . ($this->lastError() ?: 'unknown'));
        }
        $this->currentPath = $path;
        $this->structCache = [];
    }

    // ---------- search ----------

    public function searchUids(string $path, string $criteria): array {
        $this->select($path);
        $this->drainErrors();
        $criteria = trim($criteria);

        if (preg_match('/^UID\s+(\d+):(\*|\d+)$/i', $criteria, $m)) {
            $from = (int)$m[1];
            $range = $m[1] . ':' . $m[2];
            $ov = @imap_fetch_overview($this->stream, $range, FT_UID);
            $uids = [];
            if (is_array($ov)) {
                foreach ($ov as $o) {
                    $u = (int)$o->uid;
                    if ($u >= $from) $uids[] = $u;   // "N:*" returns the last message even when N > max
                }
            }
            sort($uids);
            $this->drainErrors();
            return array_values(array_unique($uids));
        }

        $res = @imap_search($this->stream, $criteria, SE_UID);
        $this->drainErrors();
        if (!is_array($res)) return [];
        $res = array_map('intval', $res);
        sort($res);
        return $res;
    }

    // ---------- messages ----------

    public function fetchMessage(string $path, int $uid): array {
        $this->select($path);
        $this->drainErrors();

        $ovArr = @imap_fetch_overview($this->stream, (string)$uid, FT_UID);
        $ov = is_array($ovArr) && isset($ovArr[0]) ? $ovArr[0] : null;
        if (!$ov || (int)$ov->uid !== $uid) throw new MailException("邮件 UID {$uid} 不存在");

        // imap_fetchheader only accepts FT_UID / FT_PREFETCHTEXT / FT_INTERNAL (headers never set \Seen)
        $rawHeader = (string)@imap_fetchheader($this->stream, $uid, FT_UID);
        $h = @imap_rfc822_parse_headers($rawHeader);

        $from = $this->firstAddress($h->from ?? []);
        $msg = [
            'uid'         => $uid,
            'message_id'  => mail_clean_utf8(trim((string)($h->message_id ?? '')), 255),
            'in_reply_to' => mail_clean_utf8(trim((string)($h->in_reply_to ?? '')), 255),
            'references'  => mail_clean_utf8(trim((string)($h->references ?? '')), 5000),
            'from_name'   => mail_clean_utf8($from['name'], 255),
            'from_email'  => mail_clean_utf8($from['email'], 255),
            'to'          => $this->addressList($h->to ?? []),
            'cc'          => $this->addressList($h->cc ?? []),
            'subject'     => mail_clean_utf8(mail_decode_header($h->subject ?? ($ov->subject ?? '')), 998),
            'date'        => !empty($ov->udate) ? date('Y-m-d H:i:s', (int)$ov->udate) : $this->parseDate((string)($h->date ?? '')),
            'size'        => (int)($ov->size ?? 0),
            'flags'       => [
                'seen'     => !empty($ov->seen),
                'flagged'  => !empty($ov->flagged),
                'answered' => !empty($ov->answered),
            ],
            'body_text'   => '',
            'body_html'   => '',
            'attachments' => [],
        ];

        $struct = @imap_fetchstructure($this->stream, $uid, FT_UID);
        $this->drainErrors();
        if ($struct) {
            $this->structCache[$uid] = $struct;
            $acc = ['text' => '', 'html' => '', 'attachments' => []];
            $this->walkStructure($uid, $struct, '', $acc, 0);
            $msg['body_text'] = mail_clean_utf8($acc['text']);
            $msg['body_html'] = mail_clean_utf8($acc['html']);
            $msg['attachments'] = $acc['attachments'];
        }
        if ($msg['body_text'] === '' && $msg['body_html'] !== '') {
            $msg['body_text'] = mail_html_to_text($msg['body_html']);
        }
        return $msg;
    }

    /**
     * Recursive MIME walk. $prefix is the parent part number ('' at top level).
     */
    private function walkStructure(int $uid, object $part, string $prefix, array &$acc, int $depth): void {
        if ($depth > 12) return;
        $type = (int)($part->type ?? 0);          // 0 text, 1 multipart, 2 message, 3 application, 4 audio, 5 image, 6 video, 7 other
        $subtype = strtoupper((string)($part->subtype ?? ''));

        if ($type === 1 && !empty($part->parts)) {   // multipart container
            $isAlternative = $subtype === 'ALTERNATIVE';
            foreach ($part->parts as $i => $sub) {
                $partNo = $prefix === '' ? (string)($i + 1) : $prefix . '.' . ($i + 1);
                $this->walkStructure($uid, $sub, $partNo, $acc, $depth + 1);
                // In multipart/alternative we still gather both text and html (first of each).
            }
            return;
        }

        // Nested message/rfc822 with parsed body parts: descend
        if ($type === 2 && !empty($part->parts)) {
            foreach ($part->parts as $i => $sub) {
                $partNo = $prefix === '' ? (string)($i + 1) : $prefix . '.' . ($i + 1);
                $this->walkStructure($uid, $sub, $partNo, $acc, $depth + 1);
            }
            return;
        }

        $partNo = $prefix === '' ? '1' : $prefix;
        $params = $this->paramsOf($part);
        $filename = $params['filename'] ?? $params['name'] ?? '';
        $disposition = strtoupper((string)($part->disposition ?? ''));
        $contentId = trim((string)($part->id ?? ''), '<> ');
        $isAttachment = $disposition === 'ATTACHMENT' || ($filename !== '' && $type !== 0) || ($type !== 0 && $type !== 1);

        if (!$isAttachment && $type === 0 && ($subtype === 'PLAIN' || $subtype === 'HTML')) {
            $body = $this->fetchPartDecoded($uid, $partNo, $part);
            $body = mail_convert_charset($body, (string)($params['charset'] ?? 'utf-8'));
            if ($subtype === 'PLAIN') {
                if ($acc['text'] === '') $acc['text'] = $body; else $acc['text'] .= "\n\n" . $body;
            } else {
                if ($acc['html'] === '') $acc['html'] = $body; else $acc['html'] .= "\n<hr>\n" . $body;
            }
            return;
        }

        // Everything else is an attachment (inline images included, so cid: refs can be resolved)
        if ($filename === '') {
            $ext = $this->guessExt($type, $subtype);
            $filename = ($contentId !== '' ? 'inline_' . substr(md5($contentId), 0, 8) : 'part_' . str_replace('.', '_', $partNo)) . $ext;
        }
        $mime = $this->mimeOf($type, $subtype);
        $acc['attachments'][] = [
            'part'       => $partNo,
            'filename'   => mail_clean_utf8(mail_decode_header($filename), 255),
            'mime'       => $mime,
            'size'       => $this->decodedSize((int)($part->bytes ?? 0), (int)($part->encoding ?? 0)),
            'content_id' => mail_clean_utf8($contentId, 255),
            'inline'     => $disposition === 'INLINE' || $contentId !== '',
        ];
    }

    public function fetchAttachment(string $path, int $uid, string $partId): string {
        $this->select($path);
        $struct = $this->structCache[$uid] ?? null;
        if (!$struct) {
            $struct = @imap_fetchstructure($this->stream, $uid, FT_UID);
            if ($struct) $this->structCache[$uid] = $struct;
        }
        $part = $struct ? $this->findPart($struct, $partId) : null;
        return $this->fetchPartDecoded($uid, $partId, $part);
    }

    private function findPart(object $struct, string $partId): ?object {
        $idx = explode('.', $partId);
        $node = $struct;
        // Top-level non-multipart message: part "1" is the structure itself
        if (empty($node->parts)) return $partId === '1' ? $node : null;
        foreach ($idx as $i) {
            $n = (int)$i - 1;
            if (empty($node->parts[$n])) return null;
            $node = $node->parts[$n];
            // message/rfc822 wrappers: c-client nests parts one level deeper
            while (((int)($node->type ?? 0)) === 2 && !empty($node->parts) && count($node->parts) === 1 && ((int)($node->parts[0]->type ?? 0)) === 1) {
                $node = $node->parts[0];
            }
        }
        return $node;
    }

    private function fetchPartDecoded(int $uid, string $partNo, ?object $part): string {
        $this->drainErrors();
        $raw = @imap_fetchbody($this->stream, $uid, $partNo, FT_UID | FT_PEEK);
        $this->drainErrors();
        if ($raw === false) return '';
        $enc = (int)($part->encoding ?? 0);
        switch ($enc) {
            case 3: return (string)imap_base64($raw);      // ENCBASE64
            case 4: return (string)imap_qprint($raw);      // ENCQUOTEDPRINTABLE
            default: return (string)$raw;                  // 7bit / 8bit / binary
        }
    }

    // ---------- flags / move / append ----------

    public function setFlag(string $path, int $uid, string $flag, bool $on): void {
        $this->select($path);
        $this->drainErrors();
        $f = '\\' . ucfirst($flag);
        $ok = $on ? @imap_setflag_full($this->stream, (string)$uid, $f, ST_UID)
                  : @imap_clearflag_full($this->stream, (string)$uid, $f, ST_UID);
        $this->drainErrors();
        if (!$ok) throw new MailException("设置标记失败 ({$flag})");
    }

    public function moveTo(string $path, int $uid, string $destPath): void {
        $this->select($path);
        $this->drainErrors();
        $ok = @imap_mail_move($this->stream, (string)$uid, $destPath, CP_UID);
        if ($ok) @imap_expunge($this->stream);
        $this->drainErrors();
        if (!$ok) throw new MailException('移动邮件失败: ' . ($this->lastError() ?: 'unknown'));
    }

    public function append(string $path, string $rawMime, array $flags = ['Seen']): void {
        $this->ensure();
        $this->drainErrors();
        $flagStr = implode(' ', array_map(fn($f) => '\\' . ucfirst($f), $flags));
        $ok = @imap_append($this->stream, $this->base . $path, $rawMime, $flagStr ?: null);
        $this->drainErrors();
        if (!$ok) throw new MailException('保存到文件夹失败: ' . ($this->lastError() ?: 'unknown'));
    }

    // ---------- helpers ----------

    private function ensure(): void {
        if (!$this->stream) throw new MailException('IMAP 未连接');
    }

    private function drainErrors(): void {
        if (function_exists('imap_errors')) { @imap_errors(); @imap_alerts(); }
    }

    private function lastError(): string {
        $e = function_exists('imap_last_error') ? @imap_last_error() : '';
        $this->drainErrors();
        return (string)$e;
    }

    private function firstAddress($list): array {
        $l = $this->addressList($list);
        return $l[0] ?? ['name' => '', 'email' => ''];
    }

    private function addressList($list): array {
        $out = [];
        if (!is_array($list)) return $out;
        foreach ($list as $a) {
            $mailbox = (string)($a->mailbox ?? '');
            $host = (string)($a->host ?? '');
            if ($mailbox === '' || $host === '' || $host === '.SYNTAX-ERROR.' || $host === 'MISSING_DOMAIN') {
                $email = $mailbox !== '' ? $mailbox : '';
            } else {
                $email = $mailbox . '@' . $host;
            }
            $out[] = ['name' => mail_clean_utf8(mail_decode_header($a->personal ?? ''), 255), 'email' => mail_clean_utf8($email, 255)];
        }
        return $out;
    }

    private function parseDate(string $raw): ?string {
        if ($raw === '') return null;
        $raw = preg_replace('/\s*\(.*\)$/', '', $raw);
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function paramsOf(object $part): array {
        $out = [];
        foreach (['parameters', 'dparameters'] as $key) {
            if (!empty($part->$key) && is_array($part->$key)) {
                foreach ($part->$key as $p) {
                    $attr = strtolower((string)($p->attribute ?? ''));
                    if ($attr === '') continue;
                    // RFC 2231 continuations: filename*0, filename*1 ...
                    $attr = preg_replace('/\*\d+\*?$/', '', $attr);
                    $attr = rtrim($attr, '*');
                    $val = (string)($p->value ?? '');
                    $out[$attr] = isset($out[$attr]) ? $out[$attr] . $val : $val;
                }
            }
        }
        return $out;
    }

    private function mimeOf(int $type, string $subtype): string {
        $primary = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'][$type] ?? 'application';
        return $primary . '/' . strtolower($subtype ?: 'octet-stream');
    }

    private function guessExt(int $type, string $subtype): string {
        $s = strtolower($subtype);
        if ($type === 5) return '.' . ($s === 'jpeg' ? 'jpg' : ($s ?: 'img'));
        if ($type === 2) return '.eml';
        if ($type === 0) return $s === 'html' ? '.html' : '.txt';
        $map = ['pdf' => '.pdf', 'zip' => '.zip', 'msword' => '.doc', 'vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
                'vnd.ms-excel' => '.xls', 'vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
                'vnd.ms-powerpoint' => '.ppt', 'vnd.openxmlformats-officedocument.presentationml.presentation' => '.pptx'];
        return $map[$s] ?? '.bin';
    }

    private function decodedSize(int $bytes, int $encoding): int {
        return $encoding === 3 ? (int)floor($bytes * 3 / 4) : $bytes;
    }
}
