<?php
/**
 * File Upload API
 *
 * POST /api/upload — upload a file, returns extracted text content
 *   Supports: .txt, .md, .json (direct read), .pdf (pdftotext extraction)
 */

require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();

if ($method === 'POST') {
    if (!isset($_FILES['file'])) json_error('No file uploaded');

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) json_error('Upload error: ' . $file['error']);

    $name = $file['name'];
    $size = $file['size'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $content = '';

    if ($ext === 'pdf') {
        // Extract text using pdftotext
        $tmpPath = $file['tmp_name'];
        $content = shell_exec('pdftotext ' . escapeshellarg($tmpPath) . ' - 2>/dev/null');
        if ($content === null || trim($content) === '') {
            json_error('Failed to extract text from PDF. Ensure pdftotext is installed.');
        }
    } else {
        // Direct read for text files
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) json_error('Failed to read file');
    }

    // Limit to 200KB for PDFs, 100KB for text
    $limit = $ext === 'pdf' ? 204800 : 102400;
    if (strlen($content) > $limit) {
        $content = substr($content, 0, $limit) . "\n...(truncated)";
    }

    json_success([
        'name' => $name,
        'size' => $size,
        'ext' => $ext,
        'content' => $content,
    ], 'File uploaded');
}

json_error('Method not allowed', 405);
