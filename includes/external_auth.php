<?php
require_once __DIR__ . '/db.php';

class ExternalApiError extends RuntimeException {
    public function __construct(string $message, public int $status = 400) { parent::__construct($message); }
}

function external_scopes(): array {
    return ['work' => '任务、日志、计划、成果和标签', 'mail' => '邮件正文与已有 AI 分析',
        'reports' => '周期报告', 'profile' => '身份文档与 AI 印象', 'contacts' => '人物资料',
        'attachments' => '附件提取文本（同时需要所属资料权限）', 'conversations' => 'AI 对话与对话摘要'];
}

function external_json(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function external_create_token(PDO $db, int $uid, string $name, array $scopes, int $days): array {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 100) throw new ExternalApiError('名称需为 1–100 字');
    if (!$scopes || !array_is_list($scopes) || array_filter($scopes, fn($s) => !is_string($s)) || array_diff($scopes, array_keys(external_scopes()))) throw new ExternalApiError('请选择有效的资料权限');
    if ($days < 1 || $days > 365) throw new ExternalApiError('有效期需为 1–365 天');
    $scopes = array_values(array_unique($scopes)); sort($scopes);
    $token = 'wlr_' . bin2hex(random_bytes(32));
    $st = $db->prepare('INSERT INTO external_api_tokens (user_id,name,token_hash,token_prefix,scopes_json,expires_at) VALUES (?,?,?,?,?,DATE_ADD(NOW(), INTERVAL ? DAY))');
    $st->execute([$uid, $name, hash('sha256', $token), substr($token, 0, 12), json_encode($scopes), $days]);
    return ['id' => (int)$db->lastInsertId(), 'token' => $token];
}

/** Bearer credentials only: never extend browser sessions or existing write APIs. */
function external_authenticate(PDO $db, string $authorization): array {
    if (!preg_match('/^Bearer (wlr_[a-f0-9]{64})$/i', trim($authorization), $m)) throw new ExternalApiError('Bearer token required', 401);
    $st = $db->prepare('SELECT k.id,k.user_id,k.scopes_json FROM external_api_tokens k JOIN users u ON u.id=k.user_id WHERE k.token_hash=? AND k.revoked_at IS NULL AND k.expires_at>NOW()');
    $st->execute([hash('sha256', $m[1])]);
    $row = $st->fetch();
    if (!$row) throw new ExternalApiError('Invalid, expired or revoked token', 401);
    $st = $db->prepare('UPDATE external_api_tokens SET request_count=IF(window_at IS NULL OR window_at<=NOW()-INTERVAL 1 MINUTE,1,request_count+1), window_at=IF(window_at IS NULL OR window_at<=NOW()-INTERVAL 1 MINUTE,NOW(),window_at),last_used_at=NOW() WHERE id=? AND revoked_at IS NULL AND expires_at>NOW() AND (window_at IS NULL OR window_at<=NOW()-INTERVAL 1 MINUTE OR request_count<120)');
    $st->execute([$row['id']]);
    if (!$st->rowCount()) throw new ExternalApiError('Request limit reached or token revoked; retry later', 429);
    return ['user_id' => (int)$row['user_id'], 'scopes' => json_decode($row['scopes_json'], true) ?: []];
}

function external_input(): array {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) throw new ExternalApiError('Request body exceeds 64 KiB', 413);
    try { $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new ExternalApiError('Invalid JSON'); }
    if (!is_array($value) || !str_starts_with(ltrim($raw), '{')) throw new ExternalApiError('Expected a JSON object');
    return $value;
}
