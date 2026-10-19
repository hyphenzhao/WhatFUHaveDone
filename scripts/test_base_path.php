<?php
/**
 * Base-path unit checks. No database. Run: php scripts/test_base_path.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/base_path.php';
require_once __DIR__ . '/../includes/helpers.php';

$checks = 0;
function bp_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
}

function bp_reset(): void {
    putenv('BASE_PATH');
    putenv('TRUSTED_PROXIES');
    putenv('SESSION_NAME');
    $_SERVER = [
        'REMOTE_ADDR' => '192.168.50.20',
        'REQUEST_URI' => '/login',
        'HTTPS' => '',
        'REQUEST_SCHEME' => 'http',
        'SERVER_PORT' => '8080',
    ];
}

bp_reset();
bp_check(app_base_path() === '', 'empty config is root');
bp_check(app_url('/login') === '/login', 'root login url');
bp_check(app_url('/') === '/', 'root home url');
bp_check(app_url('/?panel=tasks') === '/?panel=tasks', 'root query home');
bp_check(app_cookie_path() === '/', 'root cookie path');
bp_check(app_session_name() === 'PHPSESSID', 'root session name');
bp_check(app_remember_cookie_name() === 'remember', 'root remember cookie');
bp_check(app_is_https() === false, 'plain HTTP is not secure');
$sample = '<a href="/people">人</a><link rel="stylesheet" href="/assets/css/app.css?v=1"><script src="/assets/js/api.js?v=1"></script><button data-href="/?panel=tasks"></button><a href="https://example.com/docs">x</a><a href="//cdn.example/a.js">y</a>';
bp_check(app_rewrite_html_urls($sample) === $sample, 'empty base leaves HTML unchanged');

$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/calendar';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
bp_check(app_base_path() === '', 'untrusted prefix header ignored');
bp_check(app_is_https() === false, 'untrusted proto header ignored');

putenv('TRUSTED_PROXIES=10.8.0.1');
$_SERVER['REMOTE_ADDR'] = '10.8.0.1';
bp_check(app_base_path() === '/calendar', 'trusted prefix header');
bp_check(app_is_https() === true, 'trusted proto https');
bp_check(app_url('/login') === '/calendar/login', 'prefixed login');
bp_check(app_url('/') === '/calendar/', 'prefixed home');
bp_check(app_url('/mail?open=12') === '/calendar/mail?open=12', 'prefixed query');
bp_check(app_url('/?panel=tasks') === '/calendar/?panel=tasks', 'prefixed home query');
bp_check(app_url('/calendar/people') === '/calendar/people', 'does not double-prefix');
bp_check(app_cookie_path() === '/calendar', 'prefixed cookie path');
bp_check(app_session_name() === 'worklog_session', 'prefixed session name');
bp_check(app_remember_cookie_name() === 'worklog_remember', 'prefixed remember cookie');
$rewritten = app_rewrite_html_urls($sample);
bp_check(str_contains($rewritten, 'href="/calendar/people"'), 'html href prefixed');
bp_check(str_contains($rewritten, 'href="/calendar/assets/css/app.css?v=1"'), 'html css prefixed');
bp_check(str_contains($rewritten, 'src="/calendar/assets/js/api.js?v=1"'), 'html js prefixed');
bp_check(str_contains($rewritten, 'data-href="/calendar/?panel=tasks"'), 'data-href prefixed');
bp_check(str_contains($rewritten, 'href="https://example.com/docs"'), 'absolute url kept');
bp_check(str_contains($rewritten, 'href="//cdn.example/a.js"'), 'protocol-relative kept');
bp_check(!str_contains($rewritten, '/calendar/calendar'), 'html not double-prefixed');

$_SERVER['REQUEST_URI'] = '/calendar/api/external/mcp?x=1';
bp_check(app_request_path() === '/api/external/mcp', 'kept prefix stripped for routing');
bp_check(get_path_parts() === ['api', 'external', 'mcp'], 'external mcp parts');
$_SERVER['REQUEST_URI'] = '/api/external/mcp';
bp_check(app_request_path() === '/api/external/mcp', 'already-stripped proxy path');
$_SERVER['REQUEST_URI'] = '/calendar';
bp_check(app_request_path() === '/', 'bare prefix is home');
$_SERVER['REQUEST_URI'] = '/calendar/';
bp_check(app_request_path() === '/', 'prefix slash is home');

$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/calendar/';
bp_check(app_base_path() === '/calendar', 'trailing slash normalized');
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = 'calendar';
bp_check(app_base_path() === '/calendar', 'missing slash normalized');
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/../etc, /calendar';
bp_check(app_base_path() === '', 'invalid header falls through when unset');
putenv('BASE_PATH=/calendar');
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/../etc';
bp_check(app_base_path() === '/calendar', 'invalid header keeps configured base');
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/other';
bp_check(app_base_path() === '/other', 'trusted header overrides configured base');
$_SERVER['REMOTE_ADDR'] = '192.168.50.6';
unset($_SERVER['HTTP_X_FORWARDED_PREFIX']);
bp_check(app_base_path() === '/calendar', 'configured base without header');
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/evil';
bp_check(app_base_path() === '/calendar', 'untrusted header cannot override configured base');

putenv('BASE_PATH');
putenv('TRUSTED_PROXIES=10.8.0.0/24, ::1');
$_SERVER['REMOTE_ADDR'] = '10.8.0.15';
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/calendar';
bp_check(app_base_path() === '/calendar', 'ipv4 cidr trusted');
$_SERVER['REMOTE_ADDR'] = '10.8.1.15';
bp_check(app_base_path() === '', 'outside cidr untrusted');
$_SERVER['REMOTE_ADDR'] = '::ffff:10.8.0.15';
bp_check(app_base_path() === '/calendar', 'ipv4-mapped address matches cidr');
$_SERVER['REMOTE_ADDR'] = '::1';
bp_check(app_base_path() === '/calendar', 'ipv6 exact match');
bp_check(app_ip_matches('192.168.50.6', '192.168.50.0/24'), 'lan cidr helper');
bp_check(!app_ip_matches('192.168.51.6', '192.168.50.0/24'), 'lan cidr miss');

$_SERVER['REMOTE_ADDR'] = '10.8.0.1';
putenv('TRUSTED_PROXIES=10.8.0.1');
unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_PREFIX']);
$_SERVER['HTTPS'] = 'on';
bp_check(app_is_https() === true, 'direct https');
$_SERVER['HTTPS'] = '';
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['SERVER_PORT'] = '443';
bp_check(app_is_https() === true, 'port 443');
$_SERVER['SERVER_PORT'] = '8080';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'HTTPS,http';
bp_check(app_is_https() === true, 'proto list uses first value');
putenv('SESSION_NAME=custom_session');
bp_check(app_session_name() === 'custom_session', 'session name override');

$api = app_public_file('/assets/js/api.js');
bp_check(is_string($api) && is_file($api), 'public asset resolves');
bp_check(app_public_file('/assets/../config.php') === null, 'traversal blocked');
bp_check(app_public_file('/config.php') === null, 'php config not public');
bp_check(app_public_file('/includes/auth.php') === null, 'includes not public');
bp_check(is_string(app_public_file('/docs/external-api.md')), 'docs markdown is public');

$const = escapeshellarg(
    'define("BASE_PATH","/from-constant"); define("TRUSTED_PROXIES","10.9.0.1");'
    . '$_SERVER["REMOTE_ADDR"]="192.168.50.6"; $_SERVER["REQUEST_URI"]="/";'
    . 'require ' . var_export(__DIR__ . '/../includes/base_path.php', true) . ';'
    . 'if (app_base_path()!=="/from-constant") fwrite(STDERR,"constant base failed\n");'
    . 'else echo "constant-ok\n";'
);
$constOut = shell_exec(PHP_BINARY . ' -r ' . $const);
bp_check(trim((string)$constOut) === 'constant-ok', 'config constant used when env empty');

echo "OK {$checks} base-path checks\n";
