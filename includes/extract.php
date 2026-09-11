<?php
/**
 * Shared file text extraction.
 *
 * Extracts plain text from uploaded files (PDF/DOCX/images/text). Every function
 * here is NON-FATAL: on failure it returns an empty string rather than emitting a
 * JSON error, so callers decide what to do (upload.php requires text; attachments
 * store the file regardless and keep whatever text could be extracted).
 *
 *   extract_file_text(string $tmpPath, string $ext): string
 */

function ex_find_bin(array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }
    return null;
}

function ex_find_pdftotext(): ?string {
    return ex_find_bin(['/usr/bin/pdftotext', '/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext']);
}
function ex_find_pdftoppm(): ?string {
    return ex_find_bin(['/usr/bin/pdftoppm', '/opt/homebrew/bin/pdftoppm', '/usr/local/bin/pdftoppm']);
}
function ex_find_tesseract(): ?string {
    return ex_find_bin(['/usr/bin/tesseract', '/opt/homebrew/bin/tesseract', '/usr/local/bin/tesseract']);
}

/**
 * Extract text from a file. Returns '' if nothing could be extracted.
 */
function extract_file_text(string $tmpPath, string $ext): string {
    $ext = strtolower($ext);
    try {
        if ($ext === 'pdf')  return ex_extract_pdf($tmpPath);
        if ($ext === 'docx') return ex_extract_docx($tmpPath);
        if (in_array($ext, ['png', 'jpg', 'jpeg'], true)) return ex_extract_image_ocr($tmpPath);
        if (in_array($ext, ['txt', 'md', 'json', 'csv', 'log'], true)) {
            $c = @file_get_contents($tmpPath);
            return $c === false ? '' : $c;
        }
    } catch (Throwable $e) {
        return '';
    }
    return '';
}

function ex_extract_pdf(string $tmpPath): string {
    // Method 1: pdftotext (text-based PDFs)
    $pdftotext = ex_find_pdftotext();
    if ($pdftotext) {
        $content = shell_exec(escapeshellarg($pdftotext) . ' ' . escapeshellarg($tmpPath) . ' - 2>/dev/null');
        if ($content !== null && trim($content) !== '') return $content;
    }

    // Method 2: Smalot PDF Parser (Composer)
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        try {
            $parser = new Smalot\PdfParser\Parser();
            $content = $parser->parseFile($tmpPath)->getText();
            if ($content !== null && trim($content) !== '') return $content;
        } catch (Throwable $e) { /* fall through */ }
    }

    // Method 3: OCR for scanned PDFs
    $pdftoppm = ex_find_pdftoppm();
    $tesseract = ex_find_tesseract();
    if ($pdftoppm && $tesseract) {
        $outDir = sys_get_temp_dir() . '/pdfocr_' . uniqid();
        @mkdir($outDir, 0700, true);
        shell_exec(escapeshellarg($pdftoppm) . ' -r 200 -png ' . escapeshellarg($tmpPath) . ' ' . escapeshellarg($outDir . '/page') . ' 2>/dev/null');
        $images = glob($outDir . '/page-*.png');
        $allText = '';
        if (!empty($images)) {
            natsort($images);
            foreach ($images as $i => $img) {
                $outBase = $outDir . '/ocr_page_' . $i;
                shell_exec(escapeshellarg($tesseract) . ' ' . escapeshellarg($img) . ' ' . escapeshellarg($outBase) . ' -l chi_sim+eng 2>/dev/null');
                $txtFile = $outBase . '.txt';
                if (is_file($txtFile)) {
                    $pageText = file_get_contents($txtFile);
                    if ($pageText !== false && trim($pageText) !== '') {
                        $allText .= "--- 第 " . ($i + 1) . " 页 ---\n" . $pageText . "\n";
                    }
                    @unlink($txtFile);
                }
            }
        }
        foreach (glob($outDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($outDir);
        if (trim($allText) !== '') return $allText;
    }
    return '';
}

function ex_extract_docx(string $tmpPath): string {
    // Method 1: ZipArchive — direct XML extraction
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml !== false) {
                preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/', $xml, $matches);
                if (!empty($matches[1])) {
                    $text = html_entity_decode(implode('', $matches[1]), ENT_QUOTES, 'UTF-8');
                    $text = preg_replace('/\n{3,}/', "\n\n", trim($text));
                    if (trim($text) !== '') return $text;
                }
            }
        }
    }

    // Method 2: libreoffice → pdf → pdftotext
    $soffice = ex_find_bin(['/usr/bin/soffice', '/usr/bin/libreoffice']);
    if ($soffice) {
        $outDir = sys_get_temp_dir() . '/docx2pdf_' . uniqid();
        @mkdir($outDir, 0700, true);
        shell_exec(escapeshellarg($soffice) . ' --headless --convert-to pdf --outdir ' . escapeshellarg($outDir) . ' ' . escapeshellarg($tmpPath) . ' 2>/dev/null');
        $pdfs = glob($outDir . '/*.pdf');
        $content = '';
        if (!empty($pdfs)) $content = ex_extract_pdf($pdfs[0]);
        foreach (glob($outDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($outDir);
        if (trim($content) !== '') return $content;
    }
    return '';
}

function ex_extract_image_ocr(string $tmpPath): string {
    $tesseract = ex_find_tesseract();
    if (!$tesseract) return '';
    $outBase = sys_get_temp_dir() . '/ocr_' . uniqid();
    shell_exec(escapeshellarg($tesseract) . ' ' . escapeshellarg($tmpPath) . ' ' . escapeshellarg($outBase) . ' -l chi_sim+eng 2>/dev/null');
    $outFile = $outBase . '.txt';
    $content = '';
    if (is_file($outFile)) {
        $c = file_get_contents($outFile);
        if ($c !== false) $content = $c;
    }
    foreach (glob($outBase . '.*') ?: [] as $f) @unlink($f);
    return trim($content) !== '' ? $content : '';
}
