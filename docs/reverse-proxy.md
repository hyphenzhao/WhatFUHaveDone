# Serving WorkLog under a URL prefix

The same deployment can answer both of these at once:

- LAN, Apache mod_php, no prefix header: `http://192.168.50.6:8080/`
- Public nginx reverse proxy: `https://grok.huayisheng-tech.com/calendar/`

An empty base path is the historical root mount: links, redirects, asset URLs, and cookie path stay `/`. Cookie names at `/` stay `PHPSESSID` and `remember`.

## How the base path is chosen

For each request, in order:

1. If `REMOTE_ADDR` is listed in `TRUSTED_PROXIES` and `X-Forwarded-Prefix` is present and valid, that value is the base path. A trusted proxy overrides a static `BASE_PATH` for that request only.
2. Otherwise the `BASE_PATH` environment variable, when it is non-empty and valid.
3. Otherwise the `BASE_PATH` constant from `config.local.php`, when it is defined and valid.
4. Otherwise empty, which means `/`.

`X-Forwarded-Prefix` and `X-Forwarded-Proto` from any other address are ignored. Do not turn on `mod_remoteip` (or anything else that replaces `REMOTE_ADDR` with the browser address) on this vhost, or nginx will no longer look like the trusted proxy.

Leave `BASE_PATH` unset for the dual-host layout above. Set it only when every request to this process is under that prefix and the proxy will not send `X-Forwarded-Prefix`.

Valid prefixes look like `/calendar`. `/`, an empty value, `..`, a scheme, or a query string are not used as a prefix. A trailing slash is stripped. If a header contains a comma, only the first item is read; nginx must set the header, not append the client value.

`TRUSTED_PROXIES` is a comma-separated list of IPs or CIDRs (IPv4 or IPv6). Use the address Apache actually sees for nginx, which is the WireGuard tunnel address when nginx connects over the tunnel.

Environment variables win over the constants when they are non-empty. `config.local.php` is gitignored.

```php
<?php
// config.local.php
define('TRUSTED_PROXIES', '10.8.0.1');
// define('BASE_PATH', '/calendar'); // only for a prefix-only process
```

Or, in the Apache/php-fpm environment:

```
TRUSTED_PROXIES=10.8.0.1
```

## nginx

nginx **strips** `/calendar` before proxying (note the trailing slash on `proxy_pass`). Apache keeps serving the application at `/`, including static files under `/assets` and `/docs`. WorkLog still writes `/calendar` into links, redirects, cookies, and `window.APP_BASE`, because the browser's address is the public one.

`proxy_set_header X-Forwarded-Prefix /calendar` replaces any client-supplied value. Do not use `$http_x_forwarded_prefix`.

```nginx
location = /calendar {
    return 301 /calendar/;
}

location /calendar/ {
    client_max_body_size 30m;

    # Trailing slash strips the /calendar/ prefix.
    proxy_pass http://192.168.50.6:8080/;
    proxy_http_version 1.1;

    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Prefix /calendar;
    # Bearer tokens for /calendar/api/external/mcp
    proxy_set_header Authorization $http_authorization;

    proxy_read_timeout 120s;
    proxy_send_timeout 120s;
}
```

Apache's `.htaccess` copies `Authorization` into PHP. Without the header above, external MCP calls arrive unauthenticated.

If `proxy_pass` is written without the trailing slash, the prefix stays on the request URI. The front controller still strips a trusted or configured base path before routing, and it will serve files under `/assets` and `/docs` in that case. The block above is the configuration this deployment should use.

## Cookies and HTTPS

| Entry | Session cookie | Remember-me (30 days) | Cookie path | Secure |
| --- | --- | --- | --- | --- |
| `/` (LAN) | `PHPSESSID` | `remember` | `/` | only when the request itself is HTTPS |
| `/calendar` | `worklog_session` | `worklog_remember` | `/calendar` | when the request is HTTPS, or a trusted proxy sends `X-Forwarded-Proto: https` |

Plain HTTP on the LAN does not get `Secure`, so existing browsers keep working. The prefixed names avoid colliding with other apps on `grok.huayisheng-tech.com` (Authelia uses `ainews_session`; another PHP app's `PHPSESSID` would otherwise be sent to `/calendar` as well). LAN remember-me cookies are unchanged. The public URL asks for a sign-in once under the new names.

`SESSION_NAME` (environment or `config.local.php` constant) overrides the session cookie name if it is letters, digits, `_`, or `-`.

## Front end and API

HTML `href`, `src`, `action`, `data-href`, and `formaction` values that start with a single `/` are prefixed when a base path is active. With an empty base path the HTML is unchanged.

Pages set `window.APP_BASE` (`""` or `"/calendar"`) and `window.appUrl(path)` before other scripts. `API.base` is that prefix plus `/api`, so existing `API.get('/tasks')` calls follow the mount. Direct `fetch` and `location` calls use `appUrl` as well.

The external read API is the same routes under the prefix:

- `https://grok.huayisheng-tech.com/calendar/api/external/mcp`
- `https://grok.huayisheng-tech.com/calendar/api/external/v1/overview`

Bearer authentication is unchanged. The external-access page prints the absolute MCP URL for the current origin and base path. Record links returned by the read API (for example `/calendar/tasks`) include the prefix of the request that produced them.

## Database default

`config.php` still has a hard-coded default database password (`worklog_pass_2024`) so local and Docker installs keep working. Override it with the `DB_PASS` environment variable. Do not commit real secrets; put them in `config.local.php` or the process environment.
