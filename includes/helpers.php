<?php
/**
 * Shared helper utilities
 */

function get_json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function get_method(): string {
    return $_SERVER['REQUEST_METHOD'];
}

function get_path_parts(): array {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $uri = parse_url($uri, PHP_URL_PATH);
    $uri = trim($uri, '/');
    return $uri ? explode('/', $uri) : [];
}

function sanitize_string(?string $s): string {
    return htmlspecialchars(trim($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function validate_int(mixed $val, int $default = 0, int $min = null, int $max = null): int {
    $val = filter_var($val, FILTER_VALIDATE_INT);
    if ($val === false) return $default;
    if ($min !== null && $val < $min) $val = $min;
    if ($max !== null && $val > $max) $val = $max;
    return $val;
}

function validate_date(string $date): bool {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function today(): string {
    return date('Y-m-d');
}

function optional_string(array $arr, string $key, string $default = ''): string {
    return isset($arr[$key]) ? trim((string)$arr[$key]) : $default;
}

function optional_int(array $arr, string $key, int $default = 0, int $min = null, int $max = null): int {
    return isset($arr[$key]) ? validate_int($arr[$key], $default, $min, $max) : $default;
}

function optional_array(array $arr, string $key): array {
    return isset($arr[$key]) && is_array($arr[$key]) ? $arr[$key] : [];
}
