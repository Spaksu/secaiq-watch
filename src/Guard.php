<?php
/**
 * The panel shows all AI activity on this machine, so every entry point goes through Guard:
 *  - loopback clients only (even if XAMPP/Apache is exposed),
 *  - Host header must be localhost / 127.0.0.1 / [::1] (blocks DNS-rebinding: a web page cannot read the API through a rebound name),
 *  - hardening response headers (no framing, no sniffing, no referrer, same-origin resources only).
 */
require_once __DIR__ . '/Platform.php';

final class Guard
{
    private const HOST_RE = '/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i';

    public static function localOnly(bool $page = false): void
    {
        // Under Apache/XAMPP the script runs as another user (e.g. "daemon") that cannot read the owner-only database, and every
        // other PHP app in htdocs would share that identity. Point people to the app's own service instead of showing an empty panel.
        if (PHP_SAPI !== 'cli-server' && getenv('AIWATCH_SHARED_WEB_USER') !== '1') {
            self::wrongServer($page);
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip !== '127.0.0.1' && $ip !== '::1') {
            http_response_code(403);
            exit('This panel is only reachable from the local machine.');
        }
        if (!preg_match(self::HOST_RE, $_SERVER['HTTP_HOST'] ?? '')) {
            http_response_code(403);
            exit('Blocked: open this panel via http://127.0.0.1 or http://localhost.');
        }
        self::headers($page);
        Platform::applyTimezone();
    }

    private static function wrongServer(bool $page): void
    {
        $url = 'http://127.0.0.1:' . ((int) (getenv('AIWATCH_PORT') ?: 8099)) . '/';
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');
        http_response_code(503);
        if (!$page) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['ready' => false, 'error' => 'SecAIQ Watch runs as its own service: open ' . $url]));
        }
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");
        header('Refresh: 3; url=' . $url);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>SecAIQ Watch</title>'
            . '<style>body{font:15px/1.6 system-ui,sans-serif;background:#f1f5f9;color:#1e293b;display:grid;place-items:center;min-height:100vh;margin:0}'
            . 'main{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px 32px;max-width:520px;box-shadow:0 4px 20px #0001}'
            . 'h1{font-size:20px;margin:0 0 8px;color:#002562}a{color:#00709a;font-weight:600}code{background:#f1f5f9;padding:1px 6px;border-radius:4px}</style></head><body><main>'
            . '<h1>SecAIQ Watch has its own address</h1>'
            . '<p>It no longer runs under Apache/XAMPP (other apps in htdocs share Apache\'s user and must not be able to read your AI activity data).</p>'
            . '<p>Open <a href="' . $url . '">' . $url . '</a> — redirecting in 3 seconds.</p>'
            . '<p style="font-size:13px;color:#64748b">Nothing there? Install the background service once: <code>bin/install-agent.sh install</code> (macOS/Linux) or <code>bin\\install-agent.ps1 install</code> (Windows).</p>'
            . '</main></body></html>';
        exit;
    }

    /** $page = true for the HTML UI (inline scripts/styles are part of the single-file page); false for API/JSON/text */
    public static function headers(bool $page = false): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()");
        if ($page) {
            header("Content-Security-Policy: default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'; object-src 'none'");
        } else {
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        }
    }

    /** State-changing requests: Host must be localhost (DNS rebinding), Origin (if present) must match the host (CSRF), and fetch metadata must say same-origin */
    public static function sameOrigin(): bool
    {
        $ok = static fn(string $h): bool => (bool) preg_match(self::HOST_RE, $h);
        if (!$ok($_SERVER['HTTP_HOST'] ?? '')) {
            return false;
        }
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
        if ($site !== '' && $site !== 'same-origin') {
            return false;
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            $h = parse_url($origin, PHP_URL_HOST);
            $p = parse_url($origin, PHP_URL_PORT);
            if (!$h || !$ok($h . ($p ? ':' . $p : '')) || ($h . ($p ? ':' . $p : '')) !== $_SERVER['HTTP_HOST']) {
                return false;
            }
        }
        return true;
    }
}
