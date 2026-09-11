<?php
/**
 * File Upload API
 *
 * POST /api/upload — upload a file, returns extracted text content
 *   Supports: .txt, .md, .json (direct read), .pdf (pdftotext extraction),
 *             .docx (ZipArchive XML extraction), .png/.jpg/.jpeg (tesseract OCR)
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
    $allowed = ['txt', 'md', 'json', 'pdf', 'docx', 'png', 'jpg', 'jpeg'];
    if (!in_array($ext, $allowed, true)) {
        json_error('Unsupported file type. Supported: PDF, DOCX, TXT, MD, JSON, PNG, JPG');
    }

    // Limit file size: 10MB for images/docs, 1MB for text
    $maxSize = in_array($ext, ['png', 'jpg', 'jpeg']) ? 10 * 1024 * 1024 : 2 * 1024 * 1024;
    if ($size > $maxSize) {
        json_error('File too large. Max: ' . round($maxSize / 1024 / 1024, 1) . 'MB');
    }

    $tmpPath = $file['tmp_name'];
    if (!is_uploaded_file($tmpPath)) json_error('Invalid upload');

    // --- PDF extraction ---
    if ($ext === 'pdf') {
        $content = extract_pdf($tmpPath);
        $limit = 500000; // 500KB
    }
    // --- DOCX extraction ---
    elseif ($ext === 'docx') {
        $content = extract_docx($tmpPath);
        $limit = 500000;
    }
    // --- Image OCR ---
    elseif (in_array($ext, ['png', 'jpg', 'jpeg'])) {
        $content = extract_image_ocr($tmpPath, $ext);
        $limit = 500000;
    }
    // --- Direct read for text files ---
    else {
        $content = file_get_contents($tmpPath);
        if ($content === false) json_error('Failed to read file');
        $limit = 200000; // 200KB
    }

    if ($content === null || trim($content) === '') {
        json_error('无法从该文件提取文字，请确认文件包含文本内容。');
    }

    if (strlen($content) > $limit) {
        $content = mb_substr($content, 0, $limit) . "\n...(内容已截断)";
    }

    json_success([
        'name' => $name,
        'size' => $size,
        'ext' => $ext,
        'content' => $content,
    ], 'File uploaded');
}

json_error('Method not allowed', 405);

// ===== Helpers =====

function find_pdftotext(): ?string {
    foreach (['/usr/bin/pdftotext', '/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext'] as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }
    return null;
}

function find_pdftoppm(): ?string {
    foreach (['/usr/bin/pdftoppm', '/opt/homebrew/bin/pdftoppm', '/usr/local/bin/pdftoppm'] as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }
    return null;
}

function find_tesseract(): ?string {
    foreach (['/usr/bin/tesseract', '/opt/homebrew/bin/tesseract', '/usr/local/bin/tesseract'] as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }
    return null;
}

function extract_pdf(string $tmpPath): string {
    // Method 1: pdftotext (works for text-based PDFs)
    $pdftotext = find_pdftotext();
    if ($pdftotext) {
        $content = shell_exec(escapeshellarg($pdftotext) . ' ' . escapeshellarg($tmpPath) . ' - 2>/dev/null');
        if ($content !== null && trim($content) !== '') return $content;
    }

    // Method 2: Smalot PDF Parser
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        try {
            $parser = new Smalot\PdfParser\Parser();
            $content = $parser->parseFile($tmpPath)->getText();
            if ($content !== null && trim($content) !== '') return $content;
        } catch (Throwable $e) {
            // Fall through to OCR
        }
    }

    // Method 3: OCR for scanned/image-based PDFs
    $pdftoppm = find_pdftoppm();
    $tesseract = find_tesseract();
    if ($pdftoppm && $tesseract) {
        $outDir = sys_get_temp_dir() . '/pdfocr_' . uniqid();
        mkdir($outDir, 0700, true);

        // Convert PDF pages to PPM images (150 DPI is a good balance)
        $cmd = escapeshellarg($pdftoppm) . ' -r 200 -png ' . escapeshellarg($tmpPath) . ' ' . escapeshellarg($outDir . '/page') . ' 2>/dev/null';
        shell_exec($cmd);

        $images = glob($outDir . '/page-*.png');
        if (!empty($images)) {
            // Sort pages naturally
            natsort($images);
            $allText = '';
            $lang = 'chi_sim+eng';
            foreach ($images as $i => $img) {
                $outBase = $outDir . '/ocr_page_' . $i;
                $ocrCmd = escapeshellarg($tesseract) . ' ' . escapeshellarg($img) . ' ' . escapeshellarg($outBase) . ' -l ' . escapeshellarg($lang) . ' 2>/dev/null';
                shell_exec($ocrCmd);
                $txtFile = $outBase . '.txt';
                if (is_file($txtFile)) {
                    $pageText = file_get_contents($txtFile);
                    if ($pageText !== false && trim($pageText) !== '') {
                        $allText .= "--- 第 " . ($i + 1) . " 页 ---\n" . $pageText . "\n";
                    }
                    unlink($txtFile);
                }
            }
            // Cleanup
            foreach (glob($outDir . '/*') as $f) unlink($f);
            rmdir($outDir);

            if (!empty(trim($allText))) return $allText;
        }
        // Cleanup on failure
        foreach (glob($outDir . '/*') as $f) unlink($f);
        rmdir($outDir);
    }

    json_error('无法从该 PDF 提取文字。可能是扫描图片且 OCR 引擎 (tesseract) 未安装。请运行: sudo apt install tesseract-ocr tesseract-ocr-chi-sim');
}

function extract_docx(string $tmpPath): string {
    // Method 1: ZipArchive — direct XML extraction
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml !== false) {
                // Remove XML namespaces and extract text from <w:t> elements
                $xml = preg_replace('/<w:([^> ]+)[^>]*>/', '<w:$1>', $xml);
                $xml = preg_replace('/<\/w:([^> ]+)>/', '</w:$1>', $xml);
                $text = strip_tags($xml, '<w:t>');
                // Extract only <w:t> content
                preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/', $xml, $matches);
                if (!empty($matches[1])) {
                    $text = '';
                    $lastWasSpace = false;
                    foreach ($matches[1] as $part) {
                        // <w:t xml:space="preserve"> keeps whitespace
                        $text .= $part;
                    }
                    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                    $text = preg_replace('/\n{3,}/', "\n\n", trim($text));
                    if (!empty(trim($text))) return $text;
                }
            }
        }
    }

    // Method 2: libreoffice → pdf → pdftotext
    $soffice = null;
    foreach (['/usr/bin/soffice', '/usr/bin/libreoffice'] as $candidate) {
        if (is_executable($candidate)) { $soffice = $candidate; break; }
    }
    if ($soffice) {
        $outDir = sys_get_temp_dir() . '/docx2pdf_' . uniqid();
        mkdir($outDir, 0700, true);
        $cmd = escapeshellarg($soffice) . ' --headless --convert-to pdf --outdir ' . escapeshellarg($outDir) . ' ' . escapeshellarg($tmpPath) . ' 2>/dev/null';
        shell_exec($cmd);
        $pdfs = glob($outDir . '/*.pdf');
        if (!empty($pdfs)) {
            $content = extract_pdf($pdfs[0]);
            // Cleanup
            array_map('unlink', glob($outDir . '/*'));
            rmdir($outDir);
            if (!empty(trim($content))) return $content;
        }
        // Cleanup on failure
        array_map('unlink', glob($outDir . '/*'));
        rmdir($outDir);
    }

    json_error('无法解析该 Word 文档。请确保安装了 ZipArchive 或 LibreOffice。');
}

function extract_image_ocr(string $tmpPath, string $ext): string {
    $tesseract = find_tesseract();
    if (!$tesseract) {
        json_error('OCR 引擎 (tesseract) 未安装。请运行: sudo apt install tesseract-ocr tesseract-ocr-chi-sim');
    }

    // tesseract needs an output base name (it appends .txt)
    $outBase = sys_get_temp_dir() . '/ocr_' . uniqid();
    $lang = 'chi_sim+eng';
    $cmd = escapeshellarg($tesseract) . ' ' . escapeshellarg($tmpPath) . ' ' . escapeshellarg($outBase) . ' -l ' . escapeshellarg($lang) . ' 2>/dev/null';
    shell_exec($cmd);

    $outFile = $outBase . '.txt';
    if (is_file($outFile)) {
        $content = file_get_contents($outFile);
        unlink($outFile);
        if ($content !== false && trim($content) !== '') return $content;
    }

    // Also cleanup any .tsv/.pdf outputs
    foreach (glob($outBase . '.*') as $f) unlink($f);

    json_error('OCR 识别失败，请确认图片清晰且包含文字。');
}
