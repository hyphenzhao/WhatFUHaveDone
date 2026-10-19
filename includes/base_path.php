<?php
/**
 * Public URL prefix for reverse-proxy mounts.
 *
 * The same process can serve the LAN at "/" and a proxied prefix such as
 * "/calendar". An empty base path keeps historical root URLs, cookie paths,
 * and cookie names. See docs/reverse-proxy.md.
 */

/**
 * Configured prefix from the environment, then config.local.php.
 * A non-empty BASE_PATH environment variable wins over the constant.
 * Empty or invalid values are ignored so the default stays "/".
 */
function app_configured_base_path(): string {
    $env = getenv('BASE_PATH');
    if ($env !== false && $env !== '') {
        $normalized = app_normalize_base_path($env);
        if ($normalized !== null) return $normalized;
    }
    if (defined('BASE_PATH') && (string)BASE_PATH !== '') {
        $normalized = app_normalize_base_path((string)BASE_PATH);
        if ($normalized !== null) return $normalized;
    }
    return '';
}

/** @return list<string> */
function app_trusted_proxy_rules(): array {
    $raw = getenv('TRUSTED_PROXIES');
    if ($raw === false || $raw === '') {
        $raw = defined('TRUSTED_PROXIES') ? (string)TRUSTED_PROXIES : '';
    }
    if ($raw === '') return [];
    $rules = [];
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $rule) {
        $rule = trim($rule);
        if ($rule !== '') $rules[] = $rule;
    }
    return $rules;
}

function app_normalize_ip(string $ip): string {
    $ip = trim($ip);
    if ($ip === '') return '';
    if (stripos($ip, '::ffff:') === 0) {
        $v4 = substr($ip, 7);
        if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $v4;
    }
    $packed = @inet_pton($ip);
    if ($packed === false) return $ip;
    $normalized = inet_ntop($packed);
    return $normalized === false ? $ip : $normalized;
}

function app_ip_matches(string $ip, string $rule): bool {
    $ip = app_normalize_ip($ip);
    $rule = trim($rule);
    if ($ip === '' || $rule === '') return false;
    if (!str_contains($rule, '/')) {
        return $ip === app_normalize_ip($rule);
    }
    [$net, $bits] = explode('/', $rule, 2);
    if (!ctype_digit($bits)) return false;
    $bits = (int)$bits;
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton(app_normalize_ip($net));
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
    $max = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $max) return false;
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    if ($rem === 0) return true;
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

function app_request_from_trusted_proxy(): bool {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote === '') return false;
    foreach (app_trusted_proxy_rules() as $rule) {
        if (app_ip_matches($remote, $rule)) return true;
    }
    return false;
}

/**
 * Normalize a base path.
 * Returns '' for the site root, a path like '/calendar', or null when invalid.
 */
function app_normalize_base_path(string $raw): ?string {
    $raw = trim($raw);
    if (str_contains($raw, ',')) {
        $raw = trim(explode(',', $raw, 2)[0]);
    }
    if ($raw === '' || $raw === '/') return '';
    $raw = str_replace('\\', '/', $raw);
    $raw = preg_replace('#/+#', '/', $raw) ?? $raw;
    if ($raw !== '' && $raw[0] !== '/') $raw = '/' . $raw;
    $parts = [];
    foreach (explode('/', $raw) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..' || !preg_match('#^[A-Za-z0-9._~-]+$#', $seg)) return null;
        $parts[] = $seg;
    }
    if ($parts === []) return '';
    return '/' . implode('/', $parts);
}

function app_resolve_base_path(): string {
    if (app_request_from_trusted_proxy()) {
        $header = $_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? null;
        if (is_string($header) && $header !== '') {
            $normalized = app_normalize_base_path($header);
            if ($normalized !== null) return $normalized;
        }
    }
    return app_configured_base_path();
}

/** Per-request public prefix: '' at '/', otherwise e.g. '/calendar'. */
function app_base_path(): string {
    static $cached = null;
    static $sig = null;
    $now = ($_SERVER['REMOTE_ADDR'] ?? '')
        . "\n" . ($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '')
        . "\n" . (getenv('BASE_PATH') !== false ? (string)getenv('BASE_PATH') : '')
        . "\n" . (getenv('TRUSTED_PROXIES') !== false ? (string)getenv('TRUSTED_PROXIES') : '')
        . "\n" . (defined('BASE_PATH') ? (string)BASE_PATH : '')
        . "\n" . (defined('TRUSTED_PROXIES') ? (string)TRUSTED_PROXIES : '');
    if ($cached !== null && $sig === $now) return $cached;
    $sig = $now;
    $cached = app_resolve_base_path();
    return $cached;
}

function app_raw_request_path(): string {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') return '/';
    if ($path[0] !== '/') $path = '/' . $path;
    return $path;
}

function app_strip_base_path(string $path): string {
    if ($path === '') return '/';
    $base = app_base_path();
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        $path = substr($path, strlen($base));
        if ($path === '') $path = '/';
    }
    return $path === '' ? '/' : $path;
}

/** Request path with the public prefix removed, for routing. */
function app_request_path(): string {
    return app_strip_base_path(app_raw_request_path());
}

function app_request_includes_base(): bool {
    $base = app_base_path();
    if ($base === '') return false;
    $path = app_raw_request_path();
    return $path === $base || str_starts_with($path, $base . '/');
}

/**
 * Build a root-absolute URL honouring the base path.
 * app_url('/login') is '/login' or '/calendar/login'.
 * app_url('/') is '/' or '/calendar/'.
 */
function app_url(string $path = '/'): string {
    $base = app_base_path();
    $hash = '';
    $query = '';
    if (str_contains($path, '#')) {
        [$path, $hashPart] = explode('#', $path, 2);
        $hash = '#' . $hashPart;
    }
    if (str_contains($path, '?')) {
        [$path, $queryPart] = explode('?', $path, 2);
        $query = '?' . $queryPart;
    }
    if ($path === '' || $path === '/') {
        $root = $base === '' ? '/' : $base . '/';
        return $root . $query . $hash;
    }
    if ($path[0] !== '/') $path = '/' . $path;
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        return $path . $query . $hash;
    }
    return $base . $path . $query . $hash;
}

function e_url(string $path = '/'): string {
    return htmlspecialchars(app_url($path), ENT_QUOTES, 'UTF-8');
}

function app_redirect(string $path): void {
    header('Location: ' . app_url($path));
    exit;
}

function app_is_https(): bool {
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') return true;
    if (strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (!app_request_from_trusted_proxy()) return false;
    $proto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($proto === '') return false;
    $first = strtolower(trim(explode(',', $proto, 2)[0]));
    return $first === 'https';
}

/** Cookie Path attribute. '/' at the root, otherwise the prefix (no trailing slash). */
function app_cookie_path(): string {
    $base = app_base_path();
    return $base === '' ? '/' : $base;
}

/**
 * At '/' keep PHP's historical session name so existing LAN sessions survive.
 * Under a prefix use a distinct name so this app does not share PHPSESSID with
 * other apps on the same public host (Authelia uses ainews_session).
 * SESSION_NAME env, then the constant, override either default.
 */
function app_session_name(): string {
    $override = getenv('SESSION_NAME');
    if ($override === false || $override === '') {
        $override = defined('SESSION_NAME') ? (string)SESSION_NAME : '';
    }
    if ($override !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $override)) {
        return $override;
    }
    return app_base_path() === '' ? 'PHPSESSID' : 'worklog_session';
}

function app_remember_cookie_name(): string {
    return app_base_path() === '' ? 'remember' : 'worklog_remember';
}

/**
 * Prefix root-absolute href/src/action attributes when a base path is active.
 * An empty base path returns the HTML unchanged.
 */
function app_rewrite_html_urls(string $html): string {
    $base = app_base_path();
    if ($base === '') return $html;
    $rewritten = preg_replace(
        '#\b((?:href|src|action|data-href|formaction)\s*=\s*)([\'"])/(?!/)#i',
        '$1$2' . $base . '/',
        $html
    );
    return is_string($rewritten) ? $rewritten : $html;
}

function app_begin_html(): void {
    if (PHP_SAPI === 'cli') return;
    ob_start('app_rewrite_html_urls');
}

/** Inline bootstrap. Defines window.APP_BASE and window.appUrl before other scripts. */
function app_base_script(): string {
    $json = json_encode(
        app_base_path(),
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    return '<script>window.APP_BASE=' . $json . ';' . <<<'JS'
window.appUrl=function(path){
    var base=window.APP_BASE||'';
    if(path==null||path===''||path==='/') return base===''?'/':base+'/';
    path=String(path);
    var hash='',query='',hi=path.indexOf('#'),qi;
    if(hi>=0){hash=path.slice(hi);path=path.slice(0,hi);}
    qi=path.indexOf('?');
    if(qi>=0){query=path.slice(qi);path=path.slice(0,qi);}
    if(path===''||path==='/') return (base===''?'/':base+'/')+query+hash;
    if(path.charAt(0)!=='/') path='/'+path;
    if(base&&(path===base||path.indexOf(base+'/')===0)) return path+query+hash;
    return base+path+query+hash;
};
(function(){
    if(!window.fetch||window.fetch.__worklogBase) return;
    var orig=window.fetch;
    var wrapped=function(input,init){
        if(typeof input==='string'&&input.charAt(0)==='/'&&input.charAt(1)!=='/') input=window.appUrl(input);
        return orig.call(this,input,init);
    };
    wrapped.__worklogBase=true;
    window.fetch=wrapped;
})();
</script>
JS;
}

/**
 * Resolve a public file for a prefix-kept request. Null unless the stripped
 * path is a real file under assets/ or docs/ (no PHP, no traversal).
 */
function app_public_file(string $strippedPath): ?string {
    if (!preg_match('#^/(assets|docs)/#', $strippedPath)) return null;
    if (str_contains($strippedPath, '..') || str_contains($strippedPath, "\0")) return null;
    $root = realpath(dirname(__DIR__));
    if ($root === false) return null;
    $candidate = $root . $strippedPath;
    $full = realpath($candidate);
    if ($full === false || !is_file($full)) return null;
    $assets = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;
    $docs = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR;
    if (!str_starts_with($full, $assets) && !str_starts_with($full, $docs)) return null;
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $allowed = ['css', 'js', 'mjs', 'map', 'json', 'md', 'txt', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2'];
    if (!in_array($ext, $allowed, true)) return null;
    return $full;
}

/**
 * When the proxy keeps the prefix, Apache/php -S will not find /calendar/assets
 * on disk. Serve those public files from the stripped path. No-op at '/'.
 */
function app_serve_prefixed_static(): bool {
    if (!app_request_includes_base()) return false;
    $full = app_public_file(app_request_path());
    if ($full === null) return false;
    $types = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'mjs' => 'application/javascript; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'md' => 'text/markdown; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    if (!isset($types[$ext])) return false;
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . (string)filesize($full));
    header('X-Content-Type-Options: nosniff');
    readfile($full);
    return true;
}

/** php's built-in server sometimes omits Authorization; Apache sets it via .htaccess. */
function app_ensure_authorization_header(): void {
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return;
    if (!function_exists('getallheaders')) return;
    $headers = getallheaders();
    if (!is_array($headers)) return;
    foreach ($headers as $name => $value) {
        if (strcasecmp((string)$name, 'Authorization') === 0 && $value !== '') {
            $_SERVER['HTTP_AUTHORIZATION'] = $value;
            return;
        }
    }
}
