<?php
/**
 * Symmetric encryption helpers (libsodium secretbox) for secrets at rest,
 * e.g. mail account passwords.
 *
 * Key source: MAIL_SECRET_KEY constant (base64, 32 bytes) defined in
 * config.local.php (gitignored). If missing, we try to generate it once.
 */

require_once __DIR__ . '/../config.php';

function crypto_key(): string {
    static $key = null;
    if ($key !== null) return $key;

    if (!function_exists('sodium_crypto_secretbox')) {
        throw new RuntimeException('PHP sodium 扩展未启用，无法加密邮箱密码');
    }

    if (defined('MAIL_SECRET_KEY') && MAIL_SECRET_KEY !== '') {
        $raw = base64_decode(MAIL_SECRET_KEY, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('config.local.php 中的 MAIL_SECRET_KEY 无效（需 base64 编码的 32 字节）');
        }
        return $key = $raw;
    }

    // Try to bootstrap config.local.php once.
    $localFile = __DIR__ . '/../config.local.php';
    if (!is_file($localFile)) {
        $raw = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $php = "<?php\n// Auto-generated. Keep this file private; losing it makes stored mail passwords unreadable.\n"
             . "define('MAIL_SECRET_KEY', '" . base64_encode($raw) . "');\n";
        if (@file_put_contents($localFile, $php, LOCK_EX) !== false) {
            @chmod($localFile, 0640);
            return $key = $raw;
        }
    }
    throw new RuntimeException(
        '缺少加密密钥。请在项目根目录创建 config.local.php：' .
        'php -r "echo \'<?php\', PHP_EOL, \'define(\\"MAIL_SECRET_KEY\\", \\"\', base64_encode(random_bytes(32)), \'\\");\', PHP_EOL;" > config.local.php'
    );
}

function crypto_encrypt(string $plain): string {
    $key = crypto_key();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
    return base64_encode($nonce . $cipher);
}

function crypto_decrypt(string $enc): string {
    if ($enc === '') return '';
    $key = crypto_key();
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('密文格式无效');
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    if ($plain === false) {
        throw new RuntimeException('密钥不匹配，请重新输入密码');
    }
    return $plain;
}
