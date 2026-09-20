<?php
/**
 * Router for PHP's built-in web server (php -S 127.0.0.1:8099 -t <dir> router.php).
 * The built-in server would otherwise serve EVERY file in the folder: the SQLite database, the action token (var/csrf.key),
 * logs, backups and settings. Only the UI, the JSON/API scripts, guide.php and img/ are public.
 */
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$host = $_SERVER['HTTP_HOST'] ?? '';
if (!preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $host)) {
    http_response_code(403);
    exit('Blocked: open this panel via http://127.0.0.1 or http://localhost.');
}
if (str_contains($path, "\0") || str_contains($path, '..')
    || preg_match('#^/(var|db|config|src|bin|tests|vendor/.*\.php)(/|$)#i', $path)
    || preg_match('#(^|/)\.[^/]#', $path)                                   // dotfiles
    || preg_match('#\.(md|log|sqlite3?|sqlite-(wal|shm)|key|bak|sh|ps1|inc|ini|toml|lock)$#i', $path)) {
    http_response_code(404);
    exit('Not found');
}
return false; // serve the requested file / script normally
