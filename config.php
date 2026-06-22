<?php
/**
 * Application Configuration
 */

// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'worklog');
define('DB_USER', 'worklog');
define('DB_PASS', 'worklog_pass_2024');
define('DB_CHARSET', 'utf8mb4');

// App
define('APP_NAME', 'WorkLog');
define('APP_URL', '/');
define('TIMEZONE', 'Asia/Shanghai');

date_default_timezone_set(TIMEZONE);

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
