<?php
/**
 * File Upload API
 *
 * POST /api/upload — upload a text file, returns its content
 */

require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();

if ($method === 'POST') {
    if (!isset($_FILES['file'])) json_error('No file uploaded');

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) json_error('Upload error: ' . $file['error']);

    // Read text content
    $content = file_get_contents($file['tmp_name']);
    if ($content === false) json_error('Failed to read file');

    // Limit to 100KB
    if (strlen($content) > 102400) json_error('File too large (max 100KB)');

    $name = $file['name'];
    $size = $file['size'];

    json_success([
        'name' => $name,
        'size' => $size,
        'content' => $content,
    ], 'File uploaded');
}

json_error('Method not allowed', 405);
