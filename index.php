<?php
/**
 * Front Controller / Router
 *
 * Routes:
 *   /api/people        → api/people.php
 *   /api/tasks         → api/tasks.php
 *   /api/results       → api/results.php
 *   /api/tags          → api/tags.php
 *   /api/worklogs      → api/worklogs.php
 *   /api/plans         → api/plans.php
 *   /api/stats         → api/stats.php
 *   /api/relationships → api/relationships.php
 *   /                  → pages/home.php
 *   /people            → pages/people.php
 *   /tasks             → pages/tasks.php
 *   /results           → pages/results.php
 *   /tags              → pages/tags.php
 *   /relationships     → pages/relationships.php
 */

require_once __DIR__ . '/config.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = trim($uri, '/');
$parts = $uri ? explode('/', $uri) : [];

// AI routes (handles sub-paths: /api/ai/chat, /api/ai/config, etc.)
if (($parts[0] ?? '') === 'api' && ($parts[1] ?? '') === 'ai') {
    require __DIR__ . '/api/ai.php';
    exit;
}

// API routes
if (isset($parts[0]) && $parts[0] === 'api' && isset($parts[1])) {
    $api_file = __DIR__ . '/api/' . $parts[1] . '.php';
    if (file_exists($api_file)) {
        require $api_file;
        exit;
    }
    // If API file not found
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => true, 'message' => 'API endpoint not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Page routes
$page_map = [
    ''              => 'home',
    'people'        => 'people',
    'tasks'         => 'tasks',
    'results'       => 'results',
    'tags'          => 'tags',
    'relationships' => 'relationships',
    'calendar-admin' => 'calendar-admin',
    'ai-admin' => 'ai-admin',
    'profile' => 'profile',
    'skills' => 'skills',
    'immersive' => 'immersive',
];

$page = $page_map[$uri] ?? null;
if ($page) {
    require __DIR__ . '/pages/' . $page . '.php';
    exit;
}

// 404
http_response_code(404);
require __DIR__ . '/pages/home.php'; // Fallback to home
