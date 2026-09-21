<?php
/**
 * SecAIQ Watch collector.
 *   php bin/collect.php              # runs continuously (3 s interval)
 *   php bin/collect.php --once       # single pass (test)
 *   php bin/collect.php --interval=5
 */
if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}
require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/Permissions.php';
require dirname(__DIR__) . '/src/Actions.php';
require dirname(__DIR__) . '/src/Usage.php';
require dirname(__DIR__) . '/src/Collector.php';

$once = in_array('--once', $argv, true);
$interval = 3;
foreach ($argv as $a) {
    if (preg_match('/^--interval=(\d+)$/', $a, $m)) {
        $interval = max(1, (int) $m[1]);
    }
}
// Force the C locale: ps/nettop/lsof print numbers by locale (tr_TR gives "0,3" instead of "0.3"), which broke parsing
putenv('LC_ALL=C');
if (getenv('AIWATCH_SHARED_WEB_USER') !== '1') {
    umask(0077); // database, logs, backups: owner only (the panel runs as the same user)
    $root = dirname(__DIR__);
    foreach (['db', 'var'] as $d) {
        @chmod("$root/$d", 0700);
        foreach (glob("$root/$d/*.{sqlite,sqlite-wal,sqlite-shm,log}", GLOB_BRACE) ?: [] as $f) {
            @chmod($f, 0600);
        }
    }
}
putenv('LANG=C');
require_once dirname(__DIR__) . '/src/Platform.php';
Platform::applyTimezone();
Platform::lockDown([dirname(__DIR__) . '/db', dirname(__DIR__) . '/var']); // Windows: chmod() is a no-op, use ACLs
if (!getenv('HOME')) {
    putenv('HOME=' . Platform::home());
}

echo "SecAIQ Watch collector started (interval {$interval}s). Press Ctrl+C to stop.\n";
(new Collector(Db::connect()))->run($interval, $once);
