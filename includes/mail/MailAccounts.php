<?php
/**
 * Mail account helpers shared by api/mail.php and the AI tools:
 * presets, validation, create/update, connection test.
 * Throws MailException on validation errors (callers map to json_error / tool error).
 */

require_once __DIR__ . '/../crypto.php';
require_once __DIR__ . '/MailHelpers.php';
require_once __DIR__ . '/ImapProvider.php';
require_once __DIR__ . '/MailRepo.php';
require_once __DIR__ . '/SmtpClient.php';

function mail_account_presets(): array {
    return [
        'qq'      => ['label' => 'QQ 邮箱',        'domains' => ['qq.com', 'foxmail.com'], 'imap_host' => 'imap.qq.com',         'imap_port' => 993, 'imap_ssl' => 'ssl', 'smtp_host' => 'smtp.qq.com',        'smtp_port' => 465, 'smtp_ssl' => 'ssl', 'hint' => '需在 QQ 邮箱设置中开启 IMAP/SMTP 并生成授权码'],
        '163'     => ['label' => '163 邮箱',       'domains' => ['163.com'],               'imap_host' => 'imap.163.com',        'imap_port' => 993, 'imap_ssl' => 'ssl', 'smtp_host' => 'smtp.163.com',       'smtp_port' => 465, 'smtp_ssl' => 'ssl', 'hint' => '需开启 IMAP/SMTP 并获取授权码'],
        '126'     => ['label' => '126 邮箱',       'domains' => ['126.com'],               'imap_host' => 'imap.126.com',        'imap_port' => 993, 'imap_ssl' => 'ssl', 'smtp_host' => 'smtp.126.com',       'smtp_port' => 465, 'smtp_ssl' => 'ssl', 'hint' => '需开启 IMAP/SMTP 并获取授权码'],
        'gmail'   => ['label' => 'Gmail',          'domains' => ['gmail.com', 'googlemail.com'], 'imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_ssl' => 'ssl', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 465, 'smtp_ssl' => 'ssl', 'hint' => '需开启两步验证并生成应用专用密码'],
        'outlook' => ['label' => 'Outlook / Microsoft 365', 'domains' => ['outlook.com', 'hotmail.com', 'live.com', 'msn.com'], 'imap_host' => 'outlook.office365.com', 'imap_port' => 993, 'imap_ssl' => 'ssl', 'smtp_host' => 'smtp.office365.com', 'smtp_port' => 587, 'smtp_ssl' => 'tls', 'hint' => '个人账户用应用密码；组织账户若禁用 IMAP 基本认证则暂不支持'],
        'exmail'  => ['label' => '腾讯企业邮',     'domains' => ['exmail.qq.com'],         'imap_host' => 'imap.exmail.qq.com',  'imap_port' => 993, 'imap_ssl' => 'ssl', 'smtp_host' => 'smtp.exmail.qq.com', 'smtp_port' => 465, 'smtp_ssl' => 'ssl', 'hint' => '使用客户端专用密码'],
        'icloud'  => ['label' => 'iCloud',         'domains' => ['icloud.com', 'me.com', 'mac.com'], 'imap_host' => 'imap.mail.me.com', 'imap_port' => 993, 'imap_ssl' => 'ssl', 'smtp_host' => 'smtp.mail.me.com', 'smtp_port' => 587, 'smtp_ssl' => 'tls', 'hint' => '需生成 App 专用密码'],
        'sina'    => ['label' => '新浪邮箱',       'domains' => ['sina.com', 'sina.cn'],   'imap_host' => 'imap.sina.com',       'imap_port' => 993, 'imap_ssl' => 'ssl', 'smtp_host' => 'smtp.sina.com',      'smtp_port' => 465, 'smtp_ssl' => 'ssl', 'hint' => '需开启 IMAP/SMTP'],
        'sjtu'    => ['label' => '上海交大邮箱',   'domains' => ['sjtu.edu.cn'],           'imap_host' => 'mail.sjtu.edu.cn',    'imap_port' => 993, 'imap_ssl' => 'ssl', 'smtp_host' => 'mail.sjtu.edu.cn',   'smtp_port' => 465, 'smtp_ssl' => 'ssl', 'hint' => '用户名为完整邮箱地址；若开启了客户端专用密码请使用该密码'],
    ];
}

/** Guess a preset key from an email address / explicit preset name. */
function mail_account_guess_preset(string $email, string $preset = ''): ?string {
    $presets = mail_account_presets();
    $preset = strtolower(trim($preset));
    if ($preset !== '' && isset($presets[$preset])) return $preset;
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    foreach ($presets as $key => $p) {
        if (in_array($domain, $p['domains'], true)) return $key;
    }
    return null;
}

/**
 * Validate + normalize account input. Missing hosts are filled from a preset
 * (explicit or guessed from the email domain). $existing = current row for updates.
 */
function mail_account_normalize(array $data, ?array $existing = null): array {
    $get = fn($k, $d = '') => array_key_exists($k, $data) && $data[$k] !== null ? $data[$k] : ($existing[$k] ?? $d);
    $email = trim((string)$get('email'));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new MailException('邮箱地址无效');

    $imapHost = trim((string)$get('imap_host'));
    $smtpHost = trim((string)$get('smtp_host'));
    $imapPort = (int)$get('imap_port', 0);
    $smtpPort = (int)$get('smtp_port', 0);
    $imapSsl = strtolower(trim((string)$get('imap_ssl', '')));
    $smtpSsl = strtolower(trim((string)$get('smtp_ssl', '')));

    $presetKey = mail_account_guess_preset($email, (string)($data['preset'] ?? ''));
    if ($presetKey) {
        $p = mail_account_presets()[$presetKey];
        if ($imapHost === '') { $imapHost = $p['imap_host']; if (!$imapPort) $imapPort = $p['imap_port']; if ($imapSsl === '') $imapSsl = $p['imap_ssl']; }
        if ($smtpHost === '') { $smtpHost = $p['smtp_host']; if (!$smtpPort) $smtpPort = $p['smtp_port']; if ($smtpSsl === '') $smtpSsl = $p['smtp_ssl']; }
    }
    if ($imapHost === '') throw new MailException('IMAP 服务器地址必填（该邮箱域名没有预设，请提供 imap_host / smtp_host）');
    foreach ([['IMAP', $imapHost], ['SMTP', $smtpHost]] as [$label, $h]) {
        if ($h !== '' && !preg_match('/^[A-Za-z0-9.-]{1,255}$/', $h)) throw new MailException("{$label} 服务器地址无效");
    }
    if (!$imapPort) $imapPort = 993;
    if (!$smtpPort) $smtpPort = $smtpSsl === 'tls' ? 587 : 465;
    if ($imapSsl === '') $imapSsl = 'ssl';
    if ($smtpSsl === '') $smtpSsl = 'ssl';
    if ($imapPort < 1 || $imapPort > 65535 || $smtpPort < 1 || $smtpPort > 65535) throw new MailException('端口无效');
    if (!in_array($imapSsl, ['ssl', 'tls', 'none'], true) || !in_array($smtpSsl, ['ssl', 'tls', 'none'], true)) throw new MailException('加密方式无效（ssl/tls/none）');

    $username = trim((string)$get('username'));
    if ($username === '') $username = $email;
    $name = trim((string)$get('name'));
    if ($name === '') $name = $email;
    $bool = fn($k, $d) => (int)!!(array_key_exists($k, $data) && $data[$k] !== null ? $data[$k] : ($existing[$k] ?? $d));
    return [
        'name' => mb_substr($name, 0, 100), 'email' => $email, 'protocol' => 'imap',
        'imap_host' => $imapHost, 'imap_port' => $imapPort, 'imap_ssl' => $imapSsl,
        'smtp_host' => $smtpHost, 'smtp_port' => $smtpPort, 'smtp_ssl' => $smtpSsl,
        'username' => mb_substr($username, 0, 255),
        'validate_cert' => $bool('validate_cert', 1),
        'enabled' => $bool('enabled', 1),
        'sync_all_folders' => $bool('sync_all_folders', 1),
        'sort' => (int)$get('sort', 0),
        'preset' => $presetKey,
    ];
}

function mail_account_create(PDO $db, int $uid, array $data, string $password): array {
    $acc = mail_account_normalize($data);
    if ($password === '') throw new MailException('请输入密码/授权码');
    $enc = crypto_encrypt($password);
    $dup = $db->prepare('SELECT id FROM mail_accounts WHERE user_id = ? AND email = ?');
    $dup->execute([$uid, $acc['email']]);
    if ($dup->fetchColumn()) throw new MailException('该邮箱地址已存在');
    $st = $db->prepare('INSERT INTO mail_accounts (user_id, name, email, protocol, imap_host, imap_port, imap_ssl, smtp_host, smtp_port, smtp_ssl, username, password_enc, validate_cert, enabled, sync_all_folders, sort)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$uid, $acc['name'], $acc['email'], $acc['protocol'], $acc['imap_host'], $acc['imap_port'], $acc['imap_ssl'],
                  $acc['smtp_host'], $acc['smtp_port'], $acc['smtp_ssl'], $acc['username'], $enc, $acc['validate_cert'], $acc['enabled'], $acc['sync_all_folders'], $acc['sort']]);
    return mail_account_public(mail_get_account($db, $uid, (int)$db->lastInsertId()));
}

function mail_account_update(PDO $db, int $uid, int $id, array $data, string $password = ''): array {
    $existing = mail_get_account($db, $uid, $id);
    if (!$existing) throw new MailException('账户不存在');
    $acc = mail_account_normalize($data, $existing);
    $sets = ['name=?', 'email=?', 'imap_host=?', 'imap_port=?', 'imap_ssl=?', 'smtp_host=?', 'smtp_port=?', 'smtp_ssl=?', 'username=?', 'validate_cert=?', 'enabled=?', 'sync_all_folders=?', 'sort=?'];
    $params = [$acc['name'], $acc['email'], $acc['imap_host'], $acc['imap_port'], $acc['imap_ssl'], $acc['smtp_host'], $acc['smtp_port'], $acc['smtp_ssl'], $acc['username'], $acc['validate_cert'], $acc['enabled'], $acc['sync_all_folders'], $acc['sort']];
    if ($password !== '') {
        $sets[] = 'password_enc=?'; $params[] = crypto_encrypt($password);
        $sets[] = 'last_error=NULL';
    }
    $params[] = $id; $params[] = $uid;
    $db->prepare('UPDATE mail_accounts SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?')->execute($params);
    return mail_account_public(mail_get_account($db, $uid, $id));
}

/**
 * Test IMAP (+ SMTP when configured). $acc must contain 'password'.
 * @return array{imap_ok:int, smtp_ok:int, folders:int, imap_error:string, smtp_error:string}
 */
function mail_account_test(array $acc): array {
    if (!ImapProvider::available()) throw new MailException(ImapProvider::unavailableMessage());
    if (($acc['password'] ?? '') === '') throw new MailException('请输入密码/授权码');
    $out = ['imap_ok' => 0, 'smtp_ok' => 0, 'folders' => 0, 'imap_error' => '', 'smtp_error' => ''];
    try {
        $p = mail_provider_for($acc);
        $p->connect($acc);
        $out['folders'] = count($p->listFolders());
        $p->close();
        $out['imap_ok'] = 1;
    } catch (Throwable $e) { $out['imap_error'] = $e->getMessage(); }
    if (($acc['smtp_host'] ?? '') !== '') {
        try { SmtpClient::test($acc); $out['smtp_ok'] = 1; }
        catch (Throwable $e) { $out['smtp_error'] = $e->getMessage(); }
    } else {
        $out['smtp_error'] = '未配置 SMTP（仅收信）';
    }
    return $out;
}

/** Load an account with its decrypted password (throws when the key does not match). */
function mail_account_with_password(PDO $db, int $uid, int $id): array {
    $acc = mail_get_account($db, $uid, $id);
    if (!$acc) throw new MailException('账户不存在');
    try { $acc['password'] = crypto_decrypt((string)$acc['password_enc']); }
    catch (Throwable $e) { throw new MailException($e->getMessage()); }
    return $acc;
}
