<?php
/**
 * JSON response helpers
 */

function json_response(mixed $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function json_error(string $message, int $code = 400): void {
    json_response(['error' => true, 'message' => $message], $code);
}

function json_success(mixed $data = null, string $message = 'ok'): void {
    json_response(['error' => false, 'message' => $message, 'data' => $data]);
}
