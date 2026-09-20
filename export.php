<?php
/**
 * Exports: a Markdown report and CSV/JSON data. Read-only; loopback only.
 *   export.php?f=report   Markdown report (last 7 days)
 *   export.php?f=json     everything the panel API returns (without the CSRF token)
 *   export.php?f=html     the same report as a standalone HTML page (print to PDF from the browser)
 *   export.php?f=aibom    CycloneDX 1.5 AI bill of materials (tools, models, MCP servers, providers, extensions)
 *   export.php?f=events   CSV  · export.php?f=files  CSV  · export.php?f=usage  CSV (per day/model/tool)
 */
require_once __DIR__ . '/src/Guard.php';
Guard::localOnly();
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/Actions.php';
require_once __DIR__ . '/src/Usage.php';
require_once __DIR__ . '/src/Findings.php';

if (!is_file(Db::path())) {
    http_response_code(404);
    exit('No data yet.');
}
$db = Db::connect(true);
$sig = require __DIR__ . '/config/signatures.php';
$settings = is_file(__DIR__ . '/config/settings.php') ? (require __DIR__ . '/config/settings.php') : [];
require_once __DIR__ . '/src/Platform.php';
require_once __DIR__ . '/src/Version.php';
$home = Platform::home();
$now = time();
$f = $_GET['f'] ?? 'report';
$stamp = date('Ymd-His', $now);
$q = function (string $sql, array $p = []) use ($db): array {
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
};
$short = fn(string $p) => $home !== '' && str_starts_with($p, $home) ? '~' . substr($p, strlen($home)) : $p;
$bytes = function (int $n): string {
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $u) {
        if ($n < 1024 || $u === 'TB') {
            return ($i ? number_format($n, 1) : $n) . ' ' . $u;
        }
        $n /= 1024;
    }
    return (string) $n;
};
$names = fn(string $k) => $sig['tools'][$k][0] ?? (str_starts_with($k, 'other:') ? substr($k, 6) : $k);
$csv = function (string $name, array $header, array $rows) use ($stamp) {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"secaiq-watch-$name-$stamp.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
    fputcsv($out, $header);
    foreach ($rows as $r) {
        // neutralize spreadsheet formula injection in text cells
        fputcsv($out, array_map(fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v, $r));
    }
    fclose($out);
    exit;
};

if ($f === 'events') {
    $csv('events', ['time', 'level', 'tool', 'message'], array_map(fn($r) => [date('c', (int) $r['ts']), $r['level'], $r['tool'], $r['msg']],
        $q('SELECT ts, level, tool, msg FROM events ORDER BY id DESC LIMIT 5000')));
}
if ($f === 'files') {
    $csv('files', ['tool', 'area', 'severity', 'kind', 'path', 'times_seen', 'first_seen', 'last_seen'], array_map(function ($r) use ($sig, $short) {
        $a = $sig['areas'][$r['area']] ?? [$r['area'], '', ''];
        return [$r['tool'], $a[0], $a[1], $r['kind'], $short($r['path']), $r['hits'], date('c', (int) $r['first_seen']), date('c', (int) $r['last_seen'])];
    }, $q('SELECT tool, area, kind, path, hits, first_seen, last_seen FROM files ORDER BY sensitive DESC, last_seen DESC')));
}
if ($f === 'usage') {
    $csv('usage', ['day', 'tool', 'model', 'messages', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens'], array_map(
        fn($r) => [$r['day'], $r['tool'], $r['model'], $r['msgs'], $r['tin'], $r['tout'], $r['cread'], $r['cwrite']],
        $q('SELECT day, tool, model, COUNT(*) msgs, SUM(tin) tin, SUM(tout) tout, SUM(cread) cread, SUM(cwrite) cwrite FROM usage_msgs GROUP BY day, tool, model ORDER BY day DESC')));
}
if ($f === 'aibom') {
    $refs = 0;
    $comp = [];
    $svc = [];
    $prop = fn(array $kv) => array_map(fn($k, $v) => ['name' => 'secaiq-watch:' . $k, 'value' => (string) $v], array_keys($kv), array_values($kv));
    foreach ($q("SELECT kind, tool, name, detail FROM inventory WHERE kind IN ('app','cli','extension') ORDER BY kind, name") as $r) {
        $comp[] = ['type' => 'application', 'bom-ref' => 'c' . ++$refs, 'name' => $r['name'], 'properties' => $prop(['kind' => $r['kind'], 'location' => $short($r['detail'])])];
    }
    foreach ($q("SELECT DISTINCT tool, model FROM usage_msgs WHERE model != '' ORDER BY model") as $r) {
        $comp[] = ['type' => 'machine-learning-model', 'bom-ref' => 'c' . ++$refs, 'name' => $r['model'], 'properties' => $prop(['used_by' => $names($r['tool'])])];
    }
    foreach ($q("SELECT tool, name, detail FROM inventory WHERE kind='mcp' ORDER BY name") as $r) {
        $s1 = ['bom-ref' => 's' . ++$refs, 'name' => $r['name'], 'properties' => $prop(['kind' => 'mcp-server', 'configured_in' => $names($r['tool'])])];
        if (preg_match('#^https?://\S+#', (string) $r['detail'], $mm)) {
            $s1['endpoints'] = [$mm[0]];
        }
        $svc[] = $s1;
    }
    foreach ($q("SELECT provider, GROUP_CONCAT(DISTINCT tool) tools FROM dest WHERE provider NOT IN ('Other','Local') GROUP BY provider ORDER BY provider") as $r) {
        $svc[] = ['bom-ref' => 's' . ++$refs, 'name' => $r['provider'], 'properties' => $prop(['kind' => 'ai-provider', 'contacted_by' => implode(', ', array_map($names, explode(',', (string) $r['tools'])))])];
    }
    $bom = ['bomFormat' => 'CycloneDX', 'specVersion' => '1.5', 'serialNumber' => 'urn:uuid:' . sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0x8000, 0xbfff), random_int(0, 0xffffffffffff)),
        'version' => 1, 'metadata' => ['timestamp' => date('c', $now), 'tools' => [['vendor' => 'SecAIQ', 'name' => 'SecAIQ Watch', 'version' => Version::VERSION]], 'component' => ['type' => 'device', 'name' => 'local machine']],
        'components' => $comp, 'services' => $svc];
    header('Content-Type: application/vnd.cyclonedx+json; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"secaiq-watch-aibom-$stamp.cdx.json\"");
    echo json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($f === 'json') {
    ob_start();
    $_GET['range'] = '7d';
    include __DIR__ . '/api.php';
    $data = json_decode((string) ob_get_clean(), true) ?: [];
    unset($data['token']);
    header('Content-Type: application/json; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"secaiq-watch-data-$stamp.json\"");
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------------ Markdown report
$since = $now - 7 * 86400;
$md = "# SecAIQ Watch report\n\n_SecAIQ Watch v" . Version::VERSION . " (beta)_\n\nGenerated " . date('Y-m-d H:i', $now) . " · covers the last 7 days\n\n";

$md .= "## AI tools\n\n| Tool | Category | Sent | Received | Active minutes |\n|---|---|---:|---:|---:|\n";
$traffic = [];
foreach ($q('SELECT tool, SUM(bout) o, SUM(bin) i, COUNT(DISTINCT minute) m FROM traffic WHERE minute >= ? GROUP BY tool', [$since]) as $r) {
    $traffic[$r['tool']] = $r;
}
foreach ($q('SELECT tool, name, category, last_seen FROM tools_seen ORDER BY last_seen DESC') as $t) {
    $tr = $traffic[$t['tool']] ?? ['o' => 0, 'i' => 0, 'm' => 0];
    $md .= "| {$t['name']} | {$t['category']} | " . $bytes((int) $tr['o']) . ' | ' . $bytes((int) $tr['i']) . " | {$tr['m']} |\n";
}

$findings = Findings::compute($db, $sig, $home, $settings);
$accepted = array_filter($findings, fn($x) => !empty($x['ack']));
$findings = array_values(array_filter($findings, fn($x) => empty($x['ack'])));
$post = Findings::posture($findings);
$md .= "\n## Findings — posture {$post['grade']} ({$post['score']}/100), " . count($findings) . " active" . ($accepted ? ', ' . count($accepted) . ' accepted risk(s) not counted' : '') . "\n\n";
if (!$findings) {
    $md .= "No findings.\n";
}
foreach ($findings as $x) {
    $md .= '- **[' . strtoupper($x['sev']) . "]** {$x['title']} — {$x['why']}";
    foreach ($x['evidence'] as $e) {
        $md .= "\n  - `" . str_replace('`', "'", $e) . '`';
    }
    if ($x['hint'] !== '') {
        $md .= "\n  - Suggestion: {$x['hint']}";
    }
    $md .= "\n";
}

$md .= "\n## Critical permissions and access\n\n";
$crit = $q("SELECT tool, area, level, detail FROM grants WHERE level IN ('granted','inherited')");
$rows = 0;
foreach ($crit as $g) {
    $a = $sig['areas'][$g['area']] ?? null;
    if ($a && in_array($a[1], ['critical', 'high'], true)) {
        $md .= '- ' . $names($g['tool']) . " → {$a[0]} ({$a[1]}, {$g['level']}): " . mb_substr($g['detail'], 0, 140) . "\n";
        $rows++;
    }
}
$md .= $rows ? '' : "No critical/high permissions granted.\n";

$u = !empty($settings['scan_usage']) ? Usage::report($db, $sig['pricing'], $now) : null;
if ($u) {
    $t = $u['totals'];
    $md .= "\n## Token usage\n\n- Today: " . number_format($t['today']['tin'] + $t['today']['tout']) . ' tokens · 7 days: ' . number_format($t['d7']['tin'] + $t['d7']['tout']) . ' tokens · 30 days: ' . number_format($t['d30']['tin'] + $t['d30']['tout']) . " tokens\n";
    $md .= '- Estimated cost (7 days, API price list' . ($u['cost_partial'] ? ', partial' : '') . '): $' . number_format($u['cost7'], 2) . " — an estimate, not a bill\n";
}

$md .= "\n## Changes and alerts (last 7 days)\n\n";
$ev = $q("SELECT ts, level, msg FROM events WHERE ts >= ? AND (msg LIKE 'Change:%' OR level IN ('warn','crit')) ORDER BY id DESC LIMIT 60", [$since]);
foreach ($ev as $e) {
    $md .= '- ' . date('m-d H:i', (int) $e['ts']) . " [{$e['level']}] {$e['msg']}\n";
}
$md .= $ev ? '' : "Nothing to report.\n";

$md .= "\n## Permission-removal actions (last 7 days)\n\n";
$act = $q('SELECT ts, label, status, result FROM actions WHERE ts >= ? ORDER BY ts DESC', [$since]);
foreach ($act as $a) {
    $md .= '- ' . date('m-d H:i', (int) $a['ts']) . " {$a['label']} — {$a['status']}: {$a['result']}\n";
}
$md .= $act ? '' : "None.\n";
$md .= "\n---\nRead-only observation: SecAIQ Watch never blocks traffic and never sees prompts, responses or file contents.\n";

if ($f === 'html') {
    $h = fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $inline = fn(string $t) => preg_replace(['/\*\*(.+?)\*\*/', '/`([^`]+)`/'], ['<b>$1</b>', '<code>$1</code>'], $h($t));
    $body = '';
    $inTable = false;
    $depth = 0;
    foreach (explode("\n", $md) as $line) {
        $isRow = str_starts_with($line, '|');
        if ($inTable && !$isRow) {
            $body .= "</tbody></table>\n";
            $inTable = false;
        }
        if (preg_match('/^(\s*)- (.*)$/', $line, $m)) {
            $d = intdiv(strlen($m[1]), 2) + 1;
            while ($depth < $d) { $body .= '<ul>'; $depth++; }
            while ($depth > $d) { $body .= '</ul>'; $depth--; }
            $body .= '<li>' . $inline($m[2]) . '</li>';
            continue;
        }
        while ($depth > 0) { $body .= '</ul>'; $depth--; }
        if (str_starts_with($line, '# ')) { $body .= '<h1>' . $inline(substr($line, 2)) . '</h1>'; }
        elseif (str_starts_with($line, '## ')) { $body .= '<h2>' . $inline(substr($line, 3)) . '</h2>'; }
        elseif ($isRow) {
            if (preg_match('/^\|[-:| ]+\|$/', $line)) { continue; }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            if (!$inTable) { $body .= '<table><thead><tr>' . implode('', array_map(fn($c) => '<th>' . $inline($c) . '</th>', $cells)) . '</tr></thead><tbody>'; $inTable = true; }
            else { $body .= '<tr>' . implode('', array_map(fn($c) => '<td>' . $inline($c) . '</td>', $cells)) . '</tr>'; }
        }
        elseif (trim($line) === '---') { $body .= '<hr>'; }
        elseif (trim($line) !== '') { $body .= '<p>' . $inline($line) . '</p>'; }
    }
    if ($inTable) { $body .= '</tbody></table>'; }
    while ($depth > 0) { $body .= '</ul>'; $depth--; }
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'none'"); // standalone report: inline styles only, no scripts
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>SecAIQ Watch report</title><style>body{font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem;color:#1e293b}h1{font-size:1.6rem}h2{margin-top:1.8rem;border-bottom:1px solid #e2e8f0;padding-bottom:.2rem}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e2e8f0;padding:.35rem .6rem;text-align:left}th{background:#f8fafc}code{background:#f1f5f9;padding:1px 4px;border-radius:4px;font-size:12px}ul{margin:.2rem 0}hr{margin-top:2rem}@media print{body{margin:0}}</style></head><body>' . $body . '</body></html>';
    exit;
}

header('Content-Type: text/markdown; charset=utf-8');
header("Content-Disposition: attachment; filename=\"secaiq-watch-report-$stamp.md\"");
echo $md;
