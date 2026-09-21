<?php
/**
 * php bin/diagnostics.php — prints environment facts for bug reports. No prompts, file contents, tokens or secrets;
 * your home folder is shown as "~". Paste the output into a GitHub issue.
 */
require dirname(__DIR__) . '/src/Version.php';
require dirname(__DIR__) . '/src/Platform.php';
require dirname(__DIR__) . '/src/Db.php';

Platform::applyTimezone();
$home = Platform::home();
$hide = fn(string $s): string => $home !== '' ? str_replace($home, '~', $s) : $s;
$line = fn(string $k, string $v) => printf("%-22s %s\n", $k . ':', $v);

echo "SecAIQ Watch diagnostics\n========================\n";
$line('version', Version::VERSION);
$line('os', Platform::label() . ' (' . php_uname('s') . ' ' . php_uname('r') . ', ' . php_uname('m') . ')');
$line('php', PHP_VERSION . ' (' . PHP_SAPI . ')  binary: ' . $hide(PHP_BINARY));
$line('extensions', implode(' ', array_map(fn($e) => $e . '=' . (extension_loaded($e) ? 'yes' : 'NO'), ['pdo_sqlite', 'sqlite3', 'json', 'mbstring'])));
$line('timezone', date_default_timezone_get());
$line('project folder', $hide(dirname(__DIR__)));

$tools = Platform::isMac() ? ['ps', 'nettop', 'lsof', 'sqlite3', 'host'] : (Platform::isLinux() ? ['ps', 'ss', 'host', 'getent', 'notify-send', 'systemctl'] : []);
if ($tools) {
    $line('commands', implode(' ', array_map(fn($t) => $t . '=' . (Platform::has($t) ? 'yes' : 'no'), $tools)));
}
if (Platform::isWindows()) {
    $line('powershell', trim((string) shell_exec('where.exe powershell.exe 2>NUL')) !== '' ? 'yes' : 'NO');
}

$root = dirname(__DIR__);
$mode = fn(string $f) => is_file($f) ? substr(sprintf('%o', fileperms($f)), -4) : 'missing';
if (Platform::isWindows()) {
    $open = [Platform::windowsFolderOpen("$root/db"), Platform::windowsFolderOpen("$root/var")];
    $line('folder access', 'db=' . ($open[0] === null ? '?' : ($open[0] ? 'READABLE BY OTHER USERS' : 'owner only')) . ' var=' . ($open[1] === null ? '?' : ($open[1] ? 'READABLE BY OTHER USERS' : 'owner only')) . '  (Windows ACLs; the collector restricts them at start)');
} else {
    $line('file modes', 'db=' . $mode("$root/db/gateway.sqlite") . ' token=' . $mode("$root/var/csrf.key") . '  (expected 0600)');
}

if (is_file(Db::path())) {
    $db = Db::connect(true);
    $kv = $db->query('SELECT k, v FROM kv')->fetchAll(PDO::FETCH_KEY_PAIR);
    $hb = (int) ($kv['heartbeat'] ?? 0);
    $line('collector', $hb ? (time() - $hb < 15 ? 'running' : 'stopped') . ' (last heartbeat ' . (time() - $hb) . ' s ago)' : 'never ran');
    $line('started by', ($kv['launcher'] ?? '?') === 'service' ? Platform::supervisorName() . ' service' : 'hand');
    $line('permission scan', $kv['tcc_status'] ?? 'off');
    foreach (['tools_seen', 'live_conns', 'files', 'grants', 'inventory', 'events'] as $t) {
        $counts[] = $t . '=' . (int) $db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    }
    $line('rows', implode(' ', $counts));
    $diag = json_decode((string) ($kv['diag'] ?? ''), true);
    if (is_array($diag)) {
        $line('last scan', json_encode($diag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
} else {
    $line('database', 'not created yet (start the collector once)');
}
echo "\nReport problems at " . Version::ISSUES . "\n";
