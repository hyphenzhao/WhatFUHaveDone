<?php
/**
 * Mail helper functions: HTML sanitizing, text conversion, folder kind
 * detection, reply templates, provider factory.
 */

require_once __DIR__ . '/MailProviderInterface.php';

/** Instantiate the provider for an account row (protocol switch). */
function mail_provider_for(array $account): MailProviderInterface {
    $protocol = strtolower((string)($account['protocol'] ?? 'imap'));
    switch ($protocol) {
        case 'imap':
        default:
            require_once __DIR__ . '/ImapProvider.php';
            return new ImapProvider();
    }
}

/**
 * Remove active content from HTML email. The browser additionally renders the
 * result inside a sandboxed iframe with a restrictive CSP, so this only needs
 * to be a reasonable first line of defense, not a full HTML parser.
 */
function mail_sanitize_html(string $html): string {
    if ($html === '') return '';
    // Drop dangerous elements with their content
    $html = preg_replace('#<(script|style|iframe|object|embed|applet|frame|frameset|noscript|form|button|input|select|textarea)\b[^>]*>.*?</\1\s*>#is', '', $html);
    // Self-closing / unclosed variants
    $html = preg_replace('#<(script|iframe|object|embed|applet|link|meta|base|form|input|button)\b[^>]*/?>#i', '', $html);
    // Event handlers and javascript:/vbscript:/data:text/html URLs
    $html = preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
    $html = preg_replace('#(href|src|action|background|formaction)\s*=\s*(["\']?)\s*(javascript|vbscript|data:text/html)[^"\'\s>]*\2#i', '$1=$2#$2', $html);
    // CSS expressions / url(javascript:) inside style attributes
    $html = preg_replace('#expression\s*\(#i', 'blocked(', $html);
    $html = preg_replace('#url\s*\(\s*(["\']?)\s*javascript:#i', 'url($1#', $html);
    return $html;
}

/** Crude HTML → text for snippets / AI prompts when no text part exists. */
function mail_html_to_text(string $html): string {
    if ($html === '') return '';
    $t = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1\s*>#is', '', $html);
    $t = preg_replace('#<br\s*/?>#i', "\n", $t);
    $t = preg_replace('#</(p|div|tr|li|h[1-6]|blockquote|table)>#i', "\n", $t);
    $t = preg_replace('#<td\b[^>]*>#i', "\t", $t);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[ \t]+\n/', "\n", $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    return trim($t);
}

/** Map an IMAP folder (path + attribute flags) to a canonical kind. */
function mail_folder_kind(string $path, int $attributes = 0, string $displayName = ''): string {
    if (defined('LATT_HASNOCHILDREN')) {
        if (defined('LATT_INBOX') && ($attributes & LATT_INBOX)) return 'inbox';
        if (defined('LATT_SENT') && ($attributes & LATT_SENT)) return 'sent';
        if (defined('LATT_DRAFTS') && ($attributes & LATT_DRAFTS)) return 'drafts';
        if (defined('LATT_TRASH') && ($attributes & LATT_TRASH)) return 'trash';
        if (defined('LATT_JUNK') && ($attributes & LATT_JUNK)) return 'junk';
        if (defined('LATT_ARCHIVE') && ($attributes & LATT_ARCHIVE)) return 'archive';
    }
    $name = $displayName !== '' ? $displayName : $path;
    $last = $name;
    foreach (['/', '.', '|'] as $d) {
        $pos = strrpos($last, $d);
        if ($pos !== false) $last = substr($last, $pos + 1);
    }
    $l = mb_strtolower($last);
    if (strcasecmp($path, 'INBOX') === 0) return 'inbox';
    if (preg_match('/^(sent|sent items|sent messages|sent mail|已发送|已发送邮件|已发邮件|寄件備份)$/u', $l)) return 'sent';
    if (preg_match('/^(drafts?|草稿|草稿箱)$/u', $l)) return 'drafts';
    if (preg_match('/^(trash|deleted|deleted items|deleted messages|bin|已删除|已删除邮件|垃圾桶|回收站)$/u', $l)) return 'trash';
    if (preg_match('/^(junk|junk e-?mail|spam|bulk mail|垃圾邮件|垃圾郵件)$/u', $l)) return 'junk';
    if (preg_match('/^(archive|archives|归档|存档)$/u', $l)) return 'archive';
    return 'other';
}

/** Sort weight for folder kinds (inbox first). */
function mail_kind_order(string $kind): int {
    static $o = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'other' => 4, 'junk' => 5, 'trash' => 6];
    return $o[$kind] ?? 4;
}

/** Decode a MIME-encoded header (=?UTF-8?B?...?=) to UTF-8. */
function mail_decode_header(?string $value): string {
    $value = (string)$value;
    if ($value === '') return '';
    if (function_exists('imap_mime_header_decode')) {
        $parts = @imap_mime_header_decode($value);
        if ($parts) {
            $out = '';
            foreach ($parts as $p) {
                $cs = strtolower($p->charset ?? 'default');
                $txt = (string)$p->text;
                if ($cs !== 'default' && $cs !== 'utf-8') $txt = mail_convert_charset($txt, $cs);
                $out .= $txt;
            }
            return trim($out);
        }
    }
    $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    return trim($decoded !== false ? $decoded : $value);
}

/** Convert text from a MIME charset to UTF-8 (tolerant). */
function mail_convert_charset(string $text, string $charset): string {
    $cs = strtolower(trim($charset));
    if ($cs === '' || $cs === 'utf-8' || $cs === 'utf8' || $cs === 'us-ascii' || $cs === 'default') {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    $map = ['gb2312' => 'GBK', 'gb_2312-80' => 'GBK', 'gbk' => 'GBK', 'gb18030' => 'GB18030', 'big5' => 'BIG-5',
            'iso-8859-1' => 'ISO-8859-1', 'windows-1252' => 'Windows-1252', 'x-gbk' => 'GBK', 'ks_c_5601-1987' => 'EUC-KR'];
    $from = $map[$cs] ?? strtoupper($cs);
    $out = @mb_convert_encoding($text, 'UTF-8', $from);
    if ($out === false || $out === '') {
        $out = @iconv($from, 'UTF-8//IGNORE', $text);
    }
    return $out !== false && $out !== '' ? $out : $text;
}

/** Build a plain-text snippet from body text. */
function mail_snippet(string $text, int $max = 300): string {
    $t = preg_replace('/\s+/u', ' ', trim($text));
    return mb_substr((string)$t, 0, $max, 'UTF-8');
}

/** Ensure a UTF-8 string is valid (drop invalid bytes) and trim to a byte-safe length for VARCHAR. */
function mail_clean_utf8(string $s, int $maxChars = 0): string {
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    if ($maxChars > 0) $s = mb_substr($s, 0, $maxChars, 'UTF-8');
    return $s;
}

/**
 * Quoted reply / forward template for the compose form.
 * $mode: reply | reply_all | forward
 */
function mail_build_reply_template(array $msg, string $mode, string $ownEmail): array {
    $to = [];
    $cc = [];
    $subject = (string)$msg['subject'];
    $from = trim(($msg['from_name'] ? $msg['from_name'] . ' ' : '') . '<' . $msg['from_email'] . '>');
    $date = (string)($msg['msg_date'] ?? '');
    $body = (string)($msg['body_text'] ?? '');
    if ($body === '' && !empty($msg['body_html'])) $body = mail_html_to_text($msg['body_html']);

    if ($mode === 'forward') {
        if (!preg_match('/^\s*fw(d)?\s*:/i', $subject)) $subject = 'Fwd: ' . $subject;
        $toList = json_decode((string)($msg['to_json'] ?? '[]'), true) ?: [];
        $toStr = implode(', ', array_map(fn($a) => trim(($a['name'] ?? '') . ' <' . ($a['email'] ?? '') . '>'), $toList));
        $quoted = "\n\n---------- 转发邮件 ----------\n发件人: {$from}\n日期: {$date}\n主题: {$msg['subject']}\n收件人: {$toStr}\n\n" . $body;
    } else {
        if (!preg_match('/^\s*re\s*:/i', $subject)) $subject = 'Re: ' . $subject;
        $to[] = $msg['from_email'];
        if ($mode === 'reply_all') {
            $own = strtolower($ownEmail);
            foreach (json_decode((string)($msg['to_json'] ?? '[]'), true) ?: [] as $a) {
                $e = strtolower((string)($a['email'] ?? ''));
                if ($e !== '' && $e !== $own && $e !== strtolower($msg['from_email'])) $to[] = $a['email'];
            }
            foreach (json_decode((string)($msg['cc_json'] ?? '[]'), true) ?: [] as $a) {
                $e = strtolower((string)($a['email'] ?? ''));
                if ($e !== '' && $e !== $own) $cc[] = $a['email'];
            }
        }
        $lines = explode("\n", $body);
        $quotedBody = implode("\n", array_map(fn($l) => '> ' . $l, $lines));
        $quoted = "\n\n\n在 {$date}，{$from} 写道：\n" . $quotedBody;
    }
    return [
        'to' => array_values(array_unique($to)),
        'cc' => array_values(array_unique($cc)),
        'subject' => $subject,
        'quoted_text' => $quoted,
    ];
}
