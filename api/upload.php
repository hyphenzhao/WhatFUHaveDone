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
    $allowed = ['txt', 'md', 'json', 'pdf'];
    if (!in_array($ext, $allowed, true)) {
        json_error('Unsupported file type. Use PDF, TXT, MD, or JSON.');
    }

    if ($ext === 'pdf') {
        // Extract text using pdftotext
        $tmpPath = $file['tmp_name'];
        $pdftotext = null;
        foreach (['/usr/bin/pdftotext', '/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext', '/Applications/MAMP/Library/bin/pdftotext'] as $candidate) {
            if (is_executable($candidate)) {
                $pdftotext = $candidate;
                break;
            }
        }
        if ($pdftotext) {
            $content = shell_exec(escapeshellarg($pdftotext) . ' ' . escapeshellarg($tmpPath) . ' - 2>/dev/null');
        } else {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (!is_file($autoload)) {
                json_error('PDF 解析组件尚未安装；命盘 TXT/MD/JSON 文件可正常上传。');
            }
            require_once $autoload;
            try {
                $parser = new Smalot\PdfParser\Parser();
                $content = $parser->parseFile($tmpPath)->getText();
            } catch (Throwable $e) {
                json_error('无法解析该 PDF，请确认文件完整且未加密。');
            }
        }
        if ($content === null || trim($content) === '') {
            json_error('无法从该 PDF 提取文字，请确认文件不是扫描图片。');
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
