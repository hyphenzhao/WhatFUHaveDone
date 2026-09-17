<?php
/**
 * Minimal SMTP client (no Composer): SSL / STARTTLS, AUTH LOGIN / PLAIN.
 */

require_once __DIR__ . '/MailProviderInterface.php';

class SmtpClient {
    /** @var resource|null */
    private $sock = null;
    private array $log = [];
    private int $timeout = 30;

    public static function test(array $account): void {
        $c = new self();
        try {
            $c->connect($account);
            $c->auth($account);
            $c->cmd('QUIT', [221, 250]);
        } finally {
            $c->close();
        }
    }

    /**
     * @param string[] $recipients bare email addresses (to + cc + bcc)
     */
    public static function send(array $account, string $fromEmail, array $recipients, string $rawMime): void {
        $c = new self();
        try {
            $c->connect($account);
            $c->auth($account);
            $c->cmd('MAIL FROM:<' . $fromEmail . '>', [250]);
            foreach ($recipients as $r) {
                $c->cmd('RCPT TO:<' . $r . '>', [250, 251]);
            }
            $c->cmd('DATA', [354]);
            $c->writeData($rawMime);
            $c->cmd('.', [250]);
            $c->cmd('QUIT', [221, 250]);
        } finally {
            $c->close();
        }
    }

    private function connect(array $account): void {
        $host = (string)($account['smtp_host'] ?? '');
        $port = (int)($account['smtp_port'] ?? 465);
        $ssl = strtolower((string)($account['smtp_ssl'] ?? 'ssl'));
        if ($host === '' || !preg_match('/^[A-Za-z0-9.-]{1,255}$/', $host)) throw new MailException('SMTP 服务器地址无效');
        if ($port < 1 || $port > 65535) throw new MailException('SMTP 端口无效');

        $verify = !empty($account['validate_cert']);
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify,
            'SNI_enabled' => true, 'peer_name' => $host,
        ]]);
        $target = ($ssl === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0; $errstr = '';
        $sock = @stream_socket_client($target, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) throw new MailException("SMTP 连接失败: {$errstr} ({$errno})");
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;

        $this->expect([220]);
        $this->cmd('EHLO ' . $this->ehloName(), [250]);
        if ($ssl === 'tls') {
            $this->cmd('STARTTLS', [220]);
            $ok = @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) throw new MailException('STARTTLS 握手失败');
            $this->cmd('EHLO ' . $this->ehloName(), [250]);
        }
    }

    private function auth(array $account): void {
        $user = (string)($account['username'] ?? $account['email'] ?? '');
        $pass = (string)($account['password'] ?? '');
        if ($user === '') return;
        try {
            $this->cmd('AUTH LOGIN', [334]);
            $this->cmd(base64_encode($user), [334]);
            $this->cmd(base64_encode($pass), [235]);
        } catch (MailException $e) {
            // Fallback to PLAIN
            $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235]);
        }
    }

    private function ehloName(): string {
        $h = gethostname();
        return $h && preg_match('/^[A-Za-z0-9.-]+$/', $h) ? $h : 'localhost';
    }

    private function cmd(string $line, array $okCodes): string {
        if (!$this->sock) throw new MailException('SMTP 未连接');
        $safe = preg_replace('/[\r\n]+/', ' ', $line);
        fwrite($this->sock, $safe . "\r\n");
        return $this->expect($okCodes, $safe);
    }

    private function expect(array $okCodes, string $context = ''): string {
        $lines = [];
        $code = 0;
        while (true) {
            $line = fgets($this->sock, 4096);
            if ($line === false) {
                $meta = stream_get_meta_data($this->sock);
                throw new MailException('SMTP 无响应' . (!empty($meta['timed_out']) ? '（超时）' : '') . ($context ? " after {$this->redact($context)}" : ''));
            }
            $lines[] = rtrim($line);
            if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
                $code = (int)$m[1];
                if ($m[2] === ' ') break;
            }
        }
        $text = implode("\n", $lines);
        if (!in_array($code, $okCodes, true)) {
            throw new MailException('SMTP 错误' . ($context ? "（{$this->redact($context)}）" : '') . ': ' . $text);
        }
        return $text;
    }

    private function writeData(string $raw): void {
        $raw = preg_replace('/\r?\n/', "\r\n", $raw);
        // dot-stuffing
        $raw = preg_replace('/(^|\r\n)\./', '$1..', $raw);
        if (substr($raw, -2) !== "\r\n") $raw .= "\r\n";
        fwrite($this->sock, $raw);
    }

    private function redact(string $ctx): string {
        if (preg_match('/^[A-Za-z0-9+\/=]{8,}$/', $ctx)) return '[credentials]';
        if (stripos($ctx, 'AUTH PLAIN') === 0) return 'AUTH PLAIN';
        return $ctx;
    }

    private function close(): void {
        if ($this->sock) { @fclose($this->sock); $this->sock = null; }
    }
}
