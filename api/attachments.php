<?php
/**
 * Attachments API — persistent file uploads attached to entities.
 *
 * POST   /api/attachments               — multipart upload { file, entity_type, entity_id }
 * GET    /api/attachments?entity_type=&entity_id=  — list attachments for an entity
 * GET    /api/attachments/{id}/download  — stream the stored file (add ?inline=1 to view inline)
 * DELETE /api/attachments/{id}           — delete row + file on disk
 *
 * entity_type ∈ worklog_note | result | report | task
 * Files are stored on disk under /uploads/attachments and text is extracted
 * (best-effort) into `extracted_text` so periodic reports can reference them.
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

$ALLOWED_ENTITIES = ['worklog_note', 'result', 'report', 'task', 'mail_message'];
// mail_message attachments are written by the mail sync, never uploaded via this endpoint
$UPLOADABLE_ENTITIES = ['worklog_note', 'result', 'report', 'task'];
$UPLOAD_DIR = __DIR__ . '/../uploads/attachments';

// GET /api/attachments/{id}/download  OR  GET /api/attachments?entity_type=&entity_id=
if ($method === 'GET') {
    $id  = isset($parts[2]) ? (int)$parts[2] : 0;
    $sub = $parts[3] ?? '';

    if ($id && $sub === 'download') {
        $stmt = $db->prepare('SELECT * FROM attachments WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $uid]);
        $a = $stmt->fetch();
        if (!$a) { http_response_code(404); echo 'Not found'; exit; }
        $path = $UPLOAD_DIR . '/' . $a['stored_name'];
        if (!is_file($path)) { http_response_code(404); echo 'File missing'; exit; }

        $disp = isset($_GET['inline']) ? 'inline' : 'attachment';
        header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: $disp; filename*=UTF-8''" . rawurlencode($a['file_name']));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    $entity_type = $_GET['entity_type'] ?? '';
    $entity_id   = (int)($_GET['entity_id'] ?? 0);
    if (!in_array($entity_type, $ALLOWED_ENTITIES, true) || !$entity_id) {
        json_error('entity_type and entity_id required');
    }
    $stmt = $db->prepare(
        'SELECT id, entity_type, entity_id, file_name, mime, size, created_at,
                (extracted_text IS NOT NULL AND extracted_text <> "") AS has_text
         FROM attachments WHERE entity_type = ? AND entity_id = ? AND user_id = ? ORDER BY created_at DESC'
    );
    $stmt->execute([$entity_type, $entity_id, $uid]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) { $r['has_text'] = (int)$r['has_text']; }
    json_success($rows);
}

// POST /api/attachments — multipart upload
if ($method === 'POST') {
    $entity_type = $_POST['entity_type'] ?? '';
    $entity_id   = (int)($_POST['entity_id'] ?? 0);
    if (!in_array($entity_type, $UPLOADABLE_ENTITIES, true)) json_error('Invalid entity_type');
    if (!$entity_id) json_error('entity_id required');
    if (!isset($_FILES['file'])) json_error('No file uploaded');

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) json_error('Upload error: ' . $file['error']);
    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp)) json_error('Invalid upload');

    $origName = (string)$file['name'];
    $size     = (int)$file['size'];
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($size > 20 * 1024 * 1024) json_error('文件过大，最大 20MB');

    if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0775, true);
    if (!is_dir($UPLOAD_DIR) || !is_writable($UPLOAD_DIR)) json_error('上传目录不可写，请检查 uploads/ 权限');

    $safeExt = preg_replace('/[^a-z0-9]+/', '', $ext);
    $stored  = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . ($safeExt ? '.' . $safeExt : '');
    $dest    = $UPLOAD_DIR . '/' . $stored;
    if (!move_uploaded_file($tmp, $dest)) json_error('保存文件失败');

    $mime = function_exists('mime_content_type') ? (@mime_content_type($dest) ?: '') : '';

    // Best-effort text extraction (never fatal) for report referencing.
    $text = extract_file_text($dest, $ext);
    if (strlen($text) > 500000) $text = mb_substr($text, 0, 500000) . "\n...(内容已截断)";

    $stmt = $db->prepare(
        'INSERT INTO attachments (user_id, entity_type, entity_id, file_name, stored_name, mime, size, extracted_text)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$uid, $entity_type, $entity_id, $origName, $stored, $mime, $size, $text]);

    json_success([
        'id'        => (int)$db->lastInsertId(),
        'file_name' => $origName,
        'mime'      => $mime,
        'size'      => $size,
        'has_text'  => $text !== '' ? 1 : 0,
    ], '附件已上传');
}

// DELETE /api/attachments/{id}
if ($method === 'DELETE') {
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');
    $stmt = $db->prepare('SELECT stored_name FROM attachments WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $uid]);
    $a = $stmt->fetch();
    if ($a) {
        $path = $UPLOAD_DIR . '/' . $a['stored_name'];
        if (is_file($path)) @unlink($path);
        $db->prepare('DELETE FROM attachments WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    }
    json_success(null, '附件已删除');
}

json_error('Method not allowed', 405);
