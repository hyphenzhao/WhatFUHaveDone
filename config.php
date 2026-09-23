<?php
/**
 * Application Configuration
 */

// Database (支持 Docker 环境变量覆盖)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'worklog');
define('DB_USER', getenv('DB_USER') ?: 'worklog');
define('DB_PASS', getenv('DB_PASS') ?: 'worklog_pass_2024');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_CHARSET', 'utf8mb4');

// App
define('APP_NAME', 'WorkLog');
define('APP_URL', '/');
define('TIMEZONE', 'Asia/Shanghai');

// Static asset cache-buster. Bump on every CSS/JS change.
// Shared by components/layout.php and pages/immersive.php (which has its own <head>),
// so the two can never drift and serve a half-cached mix.
define('ASSET_VER', '20260923c');

date_default_timezone_set(TIMEZONE);

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// AI Assistant defaults
define('AI_DEFAULT_PROVIDER', 'ollama');
define('AI_DEFAULT_ENDPOINT', 'http://localhost:11434/v1');
define('AI_DEFAULT_API_KEY', '');
define('AI_DEFAULT_MODEL', 'qwen2.5:7b');
define('AI_MAX_TOKENS', 8192);
define('AI_TEMPERATURE', 0.7);

// Local overrides / secrets (gitignored): MAIL_SECRET_KEY etc.
if (is_file(__DIR__ . "/config.local.php")) {
    require_once __DIR__ . "/config.local.php";
}
