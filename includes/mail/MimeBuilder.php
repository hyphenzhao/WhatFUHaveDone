<?php
/**
 * Build RFC 2822 / MIME messages for sending.
 */

class MimeBuilder {
    /**
     * @param array $o {
     *   from_name, from_email, to: [[name,email]|email], cc: [...], subject, text, html?,
     *   attachments: [ ['name'=>..., 'path'=>... | 'data'=>..., 'mime'=>...] ],
     *   in_reply_to?, references?, message_id?
     * }
     * @return array{raw:string, message_id:string}
     */
    public static function build(array $o): array {
        $eol = "\r\n";
        $fromEmail = self::cleanEmail((string)($o['from_email'] ?? ''));
        if ($fromEmail === '') throw new InvalidArgumentException('发件人地址无效');
        $messageId = (string)($o['message_id'] ?? ('<' . bin2hex(random_bytes(12)) . '@' . (explode('@', $fromEmail)[1] ?? 'localhost') . '>'));

        $headers = [];
        $headers[] = 'Date: ' . date(DATE_RFC2822);
        $headers[] = 'From: ' . self::formatAddress($o['from_name'] ?? '', $fromEmail);
        $to = self::formatList($o['to'] ?? []);
        if ($to === '') throw new InvalidArgumentException('至少需要一个收件人');
        $headers[] = 'To: ' . $to;
        $cc = self::formatList($o['cc'] ?? []);
        if ($cc !== '') $headers[] = 'Cc: ' . $cc;
        $headers[] = 'Subject: ' . self::encodeHeader((string)($o['subject'] ?? ''));
        $headers[] = 'Message-ID: ' . $messageId;
        if (!empty($o['in_reply_to'])) $headers[] = 'In-Reply-To: ' . self::cleanHeader($o['in_reply_to']);
        if (!empty($o['references'])) $headers[] = 'References: ' . self::cleanHeader($o['references']);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: WorkLog Mail';

        $text = (string)($o['text'] ?? '');
        $html = (string)($o['html'] ?? '');
        $attachments = $o['attachments'] ?? [];

        // Body part (text or text+html alternative)
        if ($html !== '') {
            $altBoundary = '=_alt_' . bin2hex(random_bytes(8));
            $bodyHeaders = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
            $body = '--' . $altBoundary . $eol . self::textPart($text, 'plain') . $eol
                  . '--' . $altBoundary . $eol . self::textPart($html, 'html') . $eol
                  . '--' . $altBoundary . '--' . $eol;
        } else {
            $bodyHeaders = null;
            $body = self::textPart($text, 'plain');
        }

        if ($attachments) {
            $mixBoundary = '=_mix_' . bin2hex(random_bytes(8));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixBoundary . '"';
            $out = implode($eol, $headers) . $eol . $eol;
            $out .= '--' . $mixBoundary . $eol;
            $out .= ($bodyHeaders ? $bodyHeaders . $eol . $eol . $body : $body) . $eol;
            foreach ($attachments as $a) {
                $data = isset($a['data']) ? (string)$a['data'] : (isset($a['path']) && is_file($a['path']) ? (string)file_get_contents($a['path']) : '');
                if ($data === '') continue;
                $name = self::encodeHeader((string)($a['name'] ?? 'attachment'));
                $mime = (string)($a['mime'] ?? 'application/octet-stream');
                if ($mime === '' || !preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime)) $mime = 'application/octet-stream';
                $out .= '--' . $mixBoundary . $eol
                      . 'Content-Type: ' . $mime . '; name="' . $name . '"' . $eol
                      . 'Content-Transfer-Encoding: base64' . $eol
                      . 'Content-Disposition: attachment; filename="' . $name . '"' . $eol . $eol
                      . chunk_split(base64_encode($data), 76, $eol);
            }
            $out .= '--' . $mixBoundary . '--' . $eol;
        } else {
            if ($bodyHeaders) {
                $headers[] = $bodyHeaders;
                $out = implode($eol, $headers) . $eol . $eol . $body;
            } else {
                // textPart already includes its own headers
                $out = implode($eol, $headers) . $eol . $body;
            }
        }
        return ['raw' => $out, 'message_id' => $messageId];
    }

    private static function textPart(string $content, string $subtype): string {
        $eol = "\r\n";
        $content = preg_replace('/\r?\n/', "\r\n", $content);
        return 'Content-Type: text/' . $subtype . '; charset=UTF-8' . $eol
             . 'Content-Transfer-Encoding: base64' . $eol . $eol
             . chunk_split(base64_encode($content), 76, $eol);
    }

    public static function cleanEmail(string $email): string {
        $email = trim($email);
        if ($email === '' || preg_match('/[\r\n\s<>]/', $email)) return '';
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private static function cleanHeader(string $v): string {
        return trim(preg_replace('/[\r\n]+/', ' ', $v));
    }

    public static function encodeHeader(string $v): string {
        $v = self::cleanHeader($v);
        if ($v === '' || preg_match('/^[\x20-\x7E]*$/', $v)) return $v;
        return '=?UTF-8?B?' . base64_encode($v) . '?=';
    }

    private static function formatAddress(string $name, string $email): string {
        $name = self::cleanHeader($name);
        if ($name === '') return '<' . $email . '>';
        $enc = self::encodeHeader($name);
        if ($enc === $name) $enc = '"' . str_replace('"', '', $name) . '"';
        return $enc . ' <' . $email . '>';
    }

    /** Accepts ['a@b', ['name'=>..,'email'=>..], ...]; invalid entries are dropped. */
    private static function formatList(array $list): string {
        $parts = [];
        foreach ($list as $item) {
            if (is_array($item)) { $email = self::cleanEmail((string)($item['email'] ?? '')); $name = (string)($item['name'] ?? ''); }
            else { $email = self::cleanEmail((string)$item); $name = ''; }
            if ($email === '') continue;
            $parts[] = self::formatAddress($name, $email);
        }
        return implode(', ', $parts);
    }

    /** Split a user-typed "a@b, c@d; e@f" into bare valid addresses. */
    public static function parseAddressInput(string $input): array {
        $out = [];
        foreach (preg_split('/[,;\n]+/', $input) as $piece) {
            $piece = trim($piece);
            if ($piece === '') continue;
            if (preg_match('/<([^>]+)>/', $piece, $m)) $piece = $m[1];
            $e = self::cleanEmail($piece);
            if ($e !== '') $out[] = $e;
        }
        return array_values(array_unique($out));
    }
}
