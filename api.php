<?php
/** JSON API — polled by the UI every 3 seconds. Only reachable from the local machine. */
require_once __DIR__ . '/src/Guard.php';
Guard::localOnly();
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/Actions.php';
require_once __DIR__ . '/src/Usage.php';
require_once __DIR__ . '/src/Findings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$demo = ($_GET['demo'] ?? '') === '1'; // synthetic database (bin/seed-demo.php); never touches real data
if ($demo) {
    Db::useDemo();
}
if (!is_file(Db::path())) {
    echo json_encode(['ready' => false, 'demo_missing' => $demo]);
    exit;
}
$db = Db::connect(true);
$sig = require __DIR__ . '/config/signatures.php';
$now = time();
$all = function (string $sql, array $p = []) use ($db): array {
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
};

// What this OS can measure. Without per-connection byte counters (Windows) the charts show OPEN CONNECTIONS instead of bytes.
$caps = $demo ? ['os' => 'mac', 'label' => 'macOS', 'bytes' => true, 'files' => true, 'tcc' => true, 'udp' => true] : Platform::caps();
if ($demo && ($_GET['os'] ?? '') === 'windows') { // demo only: preview the Windows view with synthetic data
    $caps = ['os' => 'windows', 'label' => 'Windows', 'bytes' => false, 'files' => false, 'tcc' => false, 'udp' => false];
}
$noBytes = empty($caps['bytes']) && (bool) $all("SELECT 1 FROM sqlite_master WHERE type='table' AND name='activity'"); // false until the collector has created the table
$rangeMap = ['1h' => 3600, '24h' => 86400, '7d' => 7 * 86400];
$range = $_GET['range'] ?? '24h';
$span = $rangeMap[$range] ?? 86400;

$names = [];
foreach ($sig['tools'] as $k => [$n, $cat]) {
    $names[$k] = ['name' => $n, 'category' => $cat];
}

// Tool cards: live process info + traffic for the selected range
$tools = [];
foreach ($all('SELECT * FROM tools_seen ORDER BY last_seen DESC') as $t) {
    $tools[$t['tool']] = $t + ['live' => false, 'procs' => 0, 'cpu' => 0, 'rss' => 0, 'conns' => 0, 'bin' => 0, 'bout' => 0];
}
foreach ($all('SELECT * FROM tool_live') as $l) {
    if (isset($tools[$l['tool']])) {
        $tools[$l['tool']] = array_merge($tools[$l['tool']], ['live' => true, 'procs' => $l['procs'], 'cpu' => $l['cpu'], 'rss' => $l['rss']]);
    }
}
foreach ($all('SELECT tool, COUNT(*) c FROM live_conns GROUP BY tool') as $c) {
    if (isset($tools[$c['tool']])) {
        $tools[$c['tool']]['conns'] = $c['c'];
    }
}
$otherTraffic = [];
foreach ($all('SELECT tool, SUM(bin) bin, SUM(bout) bout FROM traffic WHERE minute >= ? GROUP BY tool', [$now - $span]) as $t) {
    if (isset($tools[$t['tool']])) {
        $tools[$t['tool']]['bin'] = (int) $t['bin'];
        $tools[$t['tool']]['bout'] = (int) $t['bout'];
    } else {
        $otherTraffic[] = ['tool' => $t['tool'], 'name' => substr($t['tool'], 6), 'bin' => (int) $t['bin'], 'bout' => (int) $t['bout']];
    }
}

// Active minutes (minutes with traffic in the range) — a "how much is it used" indicator
foreach ($all('SELECT tool, COUNT(DISTINCT minute) m FROM traffic WHERE minute >= ? GROUP BY tool', [$now - $span]) as $r) {
    if (isset($tools[$r['tool']])) {
        $tools[$r['tool']]['active_min'] = (int) $r['m'];
    }
}
foreach ($tools as &$t) {
    $t['active_min'] ??= 0;
}
unset($t);
if ($noBytes) {
    // values are open connections (peak per minute), delivered in the same fields the byte charts use; the UI labels them (metric)
    $providers = $all('SELECT provider, 0 bin, SUM(conns) bout FROM activity WHERE minute >= ? GROUP BY provider ORDER BY SUM(conns) DESC', [$now - $span]);
    $toolProviders = $all('SELECT tool, provider, 0 bin, SUM(conns) bout FROM activity WHERE minute >= ? GROUP BY tool, provider', [$now - $span]);
    $hourly = $all('SELECT (minute/3600)*3600 h, tool, SUM(conns) b FROM activity WHERE minute >= ? GROUP BY h, tool', [$now - 86400]);
    foreach ($all('SELECT tool, COUNT(DISTINCT minute) m FROM activity WHERE minute >= ? GROUP BY tool', [$now - $span]) as $r) {
        if (isset($tools[$r['tool']])) {
            $tools[$r['tool']]['active_min'] = (int) $r['m'];
        }
    }
} else {
    $providers = $all('SELECT provider, SUM(bin) bin, SUM(bout) bout FROM traffic WHERE minute >= ? GROUP BY provider ORDER BY SUM(bin)+SUM(bout) DESC', [$now - $span]);
    $toolProviders = $all('SELECT tool, provider, SUM(bin) bin, SUM(bout) bout FROM traffic WHERE minute >= ? GROUP BY tool, provider', [$now - $span]);
    // Last 24 hours, hourly heatmap
    $hourly = $all('SELECT (minute/3600)*3600 h, tool, SUM(bin+bout) b FROM traffic WHERE minute >= ? GROUP BY h, tool', [$now - 86400]);
}
$procs = $all('SELECT * FROM procs_live ORDER BY rss DESC');

// Logos: first existing file from the candidate list
$icons = [];
foreach ($sig['icons'] as $k => $cands) {
    foreach ($cands as $c) {
        if (is_file(__DIR__ . '/' . $c)) {
            $icons[$k] = $c;
            break;
        }
    }
}

// Last 60 minutes, per-minute breakdown by tool
$chart = $noBytes
    ? $all('SELECT minute, tool, SUM(conns) bout, 0 bin FROM activity WHERE minute >= ? GROUP BY minute, tool ORDER BY minute', [$now - 3600])
    : $all('SELECT minute, tool, SUM(bout) bout, SUM(bin) bin FROM traffic WHERE minute >= ? GROUP BY minute, tool ORDER BY minute', [$now - 3600]);

$live = $all('SELECT * FROM live_conns ORDER BY (tool LIKE "other:%"), tool, bytes_out DESC LIMIT 300');
$dest = $all('SELECT * FROM dest WHERE last_seen >= ? ORDER BY bout DESC, last_seen DESC LIMIT 250', [$now - $span]);
$files = $all('SELECT tool, path, kind, sensitive, area, first_seen, last_seen, hits FROM files ORDER BY sensitive DESC, last_seen DESC LIMIT 800');
$grants = $all('SELECT tool, area, level, source, detail, meta FROM grants');
foreach ($grants as &$g) {
    $g['meta'] = $g['meta'] ? json_decode($g['meta'], true) : null;
    $g['acts'] = Actions::describe($g);
    // Exact manual command for macOS permission-database grants (client/service are validated like in Actions)
    $g['hint'] = null;
    $t = $g['meta']['tcc'] ?? null;
    if ($t && preg_match('/^kTCCService[A-Za-z]+$/', (string) $t['service']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{2,120}$/', (string) $t['client'])) {
        $g['hint'] = 'tccutil reset ' . substr($t['service'], 11) . ' ' . $t['client'];
    }
    unset($g['meta']); // do not ship file paths to the UI
}
unset($g);
// "Not classified": other processes with outbound connections (never dropped silently) + how much the grade actually covers
try {
    $unclassified = $all('SELECT proc, kind, path, conns, dests, bout, bin, first_seen, last_seen FROM unclassified WHERE last_seen >= ? ORDER BY conns DESC, last_seen DESC LIMIT 150', [$now - 7 * 86400]);
} catch (Throwable $e) {
    $unclassified = []; // database written by an older collector: the table appears after the collector restarts
}
foreach ($unclassified as &$u) {
    $u['dests'] = json_decode((string) $u['dests'], true) ?: [];
}
unset($u);
$coverage = [
    'recognized_seen' => (int) ($all('SELECT COUNT(*) n FROM tools_seen')[0]['n'] ?? 0),
    'recognized_active' => (int) ($all('SELECT COUNT(*) n FROM tool_live')[0]['n'] ?? 0),
    'unclassified_apps' => count(array_filter($unclassified, fn($u) => $u['kind'] === 'app' && (int) $u['conns'] > 0)),
    'unclassified_other' => count(array_filter($unclassified, fn($u) => $u['kind'] !== 'app' && (int) $u['conns'] > 0)),
    'signatures' => count($sig['tools']),
];
$actionLog = $all('SELECT id, ts, type, label, status, result, backups, undone FROM actions ORDER BY ts DESC LIMIT 40');
foreach ($actionLog as &$a) {
    $a['undoable'] = $a['status'] === 'done' && !$a['undone'] && $a['type'] !== 'tcc.reset' && trim((string) $a['backups']) !== '[]';
    unset($a['backups']);
}
unset($a);
$areas = [];
foreach ($sig['areas'] as $k => [$label, $sev, $icon, , $group]) {
    $areas[] = ['key' => $k, 'label' => $label, 'sev' => $sev, 'icon' => $icon, 'group' => $group];
}
$settings = is_file(__DIR__ . '/config/settings.php') ? (require __DIR__ . '/config/settings.php') : [];
require_once __DIR__ . '/src/Platform.php';
require_once __DIR__ . '/src/Version.php';
$homeDir = Platform::home();
if ($demo) {
    $settings = ['scan_system' => true, 'scan_usage' => true, 'notify_critical' => false, 'notify_idle' => false, 'anomaly_alerts' => true, 'daily_token_alert_k' => 0, 'upload_alert_mb' => 100];
    $homeDir = '/Users/demo';
}
$settingValues = [];
foreach (Actions::SETTINGS as $k => $d) {
    $v = $settings[$k] ?? $d['default'];
    $settingValues[$k] = $d['type'] === 'bool' ? (bool) $v : (int) $v;
}
$kv = [];
foreach ($all('SELECT k, v FROM kv') as $r) {
    $kv[$r['k']] = $r['v'];
}
$sessions = $all('SELECT tool, pid, name, cwd, start_ts, last_seen, bout, bin, active FROM sessions ORDER BY active DESC, last_seen DESC LIMIT 100');
$inv = $all('SELECT kind, tool, name, detail FROM inventory ORDER BY kind, name');
$events = $all('SELECT ts, level, tool, msg FROM events ORDER BY id DESC LIMIT 200');
$hb = $demo ? $now : (int) ($all('SELECT v FROM kv WHERE k="heartbeat"')[0]['v'] ?? 0);

$usage = !empty($settings['scan_usage']) ? Usage::report($db, $sig['pricing'], $now) : null;

$audit = Actions::verifyChain($db);
$findings = Findings::compute($db, $sig, $homeDir, $settings);
$protection = Findings::protection($db, $sig);
$presets = Findings::presets($db, $sig);
$posture = Findings::posture($findings);

$sumBout = array_sum(array_column($tools, 'bout')) + array_sum(array_column($otherTraffic, 'bout'));
$sumBin = array_sum(array_column($tools, 'bin')) + array_sum(array_column($otherTraffic, 'bin'));

echo json_encode([
    'ready' => true,
    'now' => $now,
    'range' => $range,
    'collector_alive' => $now - $hb <= 15,
    'heartbeat' => $hb,
    'kpi' => [
        'active_tools' => count(array_filter($tools, fn($t) => $t['live'])),
        'known_tools' => count($tools),
        'conns' => count($live),
        'bout' => $sumBout,
        'bin' => $sumBin,
        'sensitive' => (int) ($all('SELECT COUNT(*) c FROM files WHERE sensitive=1')[0]['c'] ?? 0),
    ],
    'tools' => array_values($tools),
    'other_traffic' => $otherTraffic,
    'chart' => $chart,
    'providers' => $providers,
    'tool_providers' => $toolProviders,
    'hourly' => $hourly,
    'procs' => $procs,
    'icons' => $icons,
    'live' => $live,
    'dest' => $dest,
    'files' => $files,
    'grants' => $grants,
    'action_log' => $actionLog,
    'token' => $demo ? null : Actions::token(),
    'demo' => $demo,
    'areas' => $areas,
    'scan_system' => !empty($settings['scan_system']),
    'settings' => $settingValues,
    'settings_schema' => Actions::SETTINGS,
    'findings' => $findings,
    'protection' => $protection,
    'presets' => $presets,
    'audit' => $audit,
    'posture' => $posture,
    'unclassified' => $unclassified,
    'coverage' => $coverage,
    'usage' => $usage,
    'tcc_status' => $kv['tcc_status'] ?? 'off',
    'launcher' => $kv['launcher'] ?? 'manual',
    'supervisor' => Platform::supervisorName(),
    'version' => Version::VERSION,
    'issues_url' => Version::ISSUES,
    'platform' => $caps,
    'metric' => $noBytes ? 'connections' : 'bytes',
    'sessions' => $sessions,
    'inventory' => $inv,
    'events' => $events,
    'home' => $homeDir,
    'howto' => is_file(__DIR__ . '/config/howto.php') ? (require __DIR__ . '/config/howto.php') : [],
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
