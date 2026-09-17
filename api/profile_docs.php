<?php
/**
 * Profile Documents API — user-uploaded authoritative identity documents (CV etc.)
 *
 * GET    /api/profile_docs                 — list
 * POST   /api/profile_docs                 — multipart upload { file, kind?, title? }
 * GET    /api/profile_docs/{id}/download   — stream file (?inline=1 to view)
 * GET    /api/profile_docs/{id}/text       — extracted text
 * PUT    /api/profile_docs/{id}            — { is_primary?, title?, kind? }
 * POST   /api/profile_docs/{id}/reextract  — re-run text extraction
 * DELETE /api/profile_docs/{id}
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/extract.php';
require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
$parts = get_path_parts();
$db = get_db();
$uid = current_user_id();

$DOC_DIR = __DIR__ . '/../uploads/profile_docs';
$ALLOWED_EXT = ['pdf', 'docx', 'txt', 'md'];
$id  = isset($parts[2]) ? (int)$parts[2] : 0;
$sub = $parts[3] ?? '';

function pdoc_get(PDO $db, int $id, int $uid): array {
    $st = $db->prepare('SELECT * FROM profile_documents WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    $row = $st->fetch();
    if (!$row) json_error('文档不存在', 404);
    return $row;
}

function pdoc_ensure_primary(PDO $db, int $uid): void {
    $st = $db->prepare('SELECT COUNT(*) FROM profile_documents WHERE user_id = ? AND is_primary = 1');
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() === 0) {
        $db->prepare('UPDATE profile_documents SET is_primary = 1 WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1')
           ->execute([$uid]);
    }
}

if ($method === 'GET' && $id && $sub === 'download') {
    $d = pdoc_get($db, $id, $uid);
    $path = $DOC_DIR . '/' . $d['stored_name'];
    if (!is_file($path)) { http_response_code(404); echo 'File missing'; exit; }
    $disp = isset($_GET['inline']) ? 'inline' : 'attachment';
    header('Content-Type: ' . ($d['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header("Content-Disposition: $disp; filename*=UTF-8''" . rawurlencode($d['file_name']));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($method === 'GET' && $id && $sub === 'text') {
    $d = pdoc_get($db, $id, $uid);
    json_success(['id' => (int)$d['id'], 'title' => $d['title'], 'file_name' => $d['file_name'], 'text' => (string)$d['extracted_text']]);
}

if ($method === 'GET') {
    $st = $db->prepare('SELECT id, kind, title, file_name, mime, size, is_primary, created_at, updated_at,
                               CHAR_LENGTH(COALESCE(extracted_text, "")) AS chars
                        FROM profile_documents WHERE user_id = ? ORDER BY is_primary DESC, updated_at DESC');
    $st->execute([$uid]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['is_primary'] = (int)$r['is_primary']; $r['chars'] = (int)$r['chars']; }
    json_success($rows);
}

if ($method === 'POST' && $id && $sub === 'reextract') {
    $d = pdoc_get($db, $id, $uid);
    $path = $DOC_DIR . '/' . $d['stored_name'];
    if (!is_file($path)) json_error('文件缺失，无法重新提取');
    $ext = strtolower(pathinfo($d['file_name'], PATHINFO_EXTENSION));
    $text = extract_file_text($path, $ext);
    if (strlen($text) > 500000) $text = mb_substr($text, 0, 500000) . "\n...(内容已截断)";
    $db->prepare('UPDATE profile_documents SET extracted_text = ? WHERE id = ?')->execute([$text, $id]);
    json_success(['chars' => mb_strlen($text)], $text !== '' ? '已重新提取文本' : '未能提取到文本');
}

if ($method === 'POST') {
    if (!isset($_FILES['file'])) json_error('未选择文件');
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE ? '文件超过服务器上传上限' : ('上传错误: ' . $file['error']);
        json_error($msg);
    }
    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp)) json_error('无效上传');

    $origName = (string)$file['name'];
    $size = (int)$file['size'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_EXT, true)) json_error('仅支持 PDF / DOCX / TXT / MD');
    if ($size > 20 * 1024 * 1024) json_error('文件过大，最大 20MB');

    if (!is_dir($DOC_DIR)) @mkdir($DOC_DIR, 0775, true);
    if (!is_dir($DOC_DIR) || !is_writable($DOC_DIR)) json_error('上传目录不可写，请检查 uploads/ 权限');

    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $DOC_DIR . '/' . $stored;
    if (!move_uploaded_file($tmp, $dest)) json_error('保存文件失败');
    @chmod($dest, 0664);

    $mime = function_exists('mime_content_type') ? (@mime_content_type($dest) ?: '') : '';
    $text = extract_file_text($dest, $ext);
    if (strlen($text) > 500000) $text = mb_substr($text, 0, 500000) . "\n...(内容已截断)";

    $kind = optional_string($_POST, 'kind', 'cv');
    if (!in_array($kind, ['cv', 'other'], true)) $kind = 'other';
    $title = trim(optional_string($_POST, 'title', ''));
    if ($title === '') $title = pathinfo($origName, PATHINFO_FILENAME);

    $st = $db->prepare('SELECT COUNT(*) FROM profile_documents WHERE user_id = ?');
    $st->execute([$uid]);
    $isFirst = (int)$st->fetchColumn() === 0;

    $db->prepare('INSERT INTO profile_documents (user_id, kind, title, file_name, stored_name, mime, size, extracted_text, is_primary)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$uid, $kind, $title, $origName, $stored, $mime, $size, $text, $isFirst ? 1 : 0]);
    $newId = (int)$db->lastInsertId();

    json_success([
        'id' => $newId, 'title' => $title, 'file_name' => $origName, 'size' => $size,
        'is_primary' => $isFirst ? 1 : 0, 'chars' => mb_strlen($text),
    ], $text !== '' ? '文档已上传并提取文本' : '文档已上传，但未能提取文本（可尝试重新提取）');
}

if ($method === 'PUT' && $id) {
    $d = pdoc_get($db, $id, $uid);
    $data = get_json_input();
    if (array_key_exists('is_primary', $data) && (int)$data['is_primary'] === 1) {
        $db->prepare('UPDATE profile_documents SET is_primary = 0 WHERE user_id = ?')->execute([$uid]);
        $db->prepare('UPDATE profile_documents SET is_primary = 1 WHERE id = ?')->execute([$id]);
    }
    if (array_key_exists('title', $data)) {
        $db->prepare('UPDATE profile_documents SET title = ? WHERE id = ?')->execute([trim((string)$data['title']), $id]);
    }
    if (array_key_exists('kind', $data) && in_array($data['kind'], ['cv', 'other'], true)) {
        $db->prepare('UPDATE profile_documents SET kind = ? WHERE id = ?')->execute([$data['kind'], $id]);
    }
    json_success(null, '已更新');
}

if ($method === 'DELETE' && $id) {
    $d = pdoc_get($db, $id, $uid);
    $path = $DOC_DIR . '/' . $d['stored_name'];
    if (is_file($path)) @unlink($path);
    $db->prepare('DELETE FROM profile_documents WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    pdoc_ensure_primary($db, $uid);
    json_success(null, '文档已删除');
}

json_error('Method not allowed', 405);
