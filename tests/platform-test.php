<?php
/** php tests/platform-test.php — parser fixtures for every OS + a live macOS regression check. */
require dirname(__DIR__) . '/src/Platform.php';
$fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void { global $fail; echo ($cond ? "  ok   " : "  FAIL ") . $name . ($cond ? '' : " $extra") . "\n"; if (!$cond) $fail++; }

echo "Linux ss\n";
$ss = "0      0      10.0.0.5:43210   140.82.112.4:443   users:((\"node\",pid=1234,fd=23))\n"
    . "\t cubic wscale:7,7 rto:204 rtt:3.2/1.1 bytes_sent:1234 bytes_acked:1234 bytes_received:56789 segs_out:20\n"
    . "0      0      [::ffff:10.0.0.5]:5000  [::ffff:104.18.1.1]:443  users:((\"claude\",pid=77,fd=9),(\"claude\",pid=77,fd=10))\n"
    . "\t cubic bytes_sent:10 bytes_received:20\n"
    . "0      0      10.0.0.5:6000   1.2.3.4:80\n\t bytes_sent:5 bytes_received:6\n"          // no owner (other user) → skipped
    . "0      0      [fe80::1%eth0]:7000   [2606:4700::1]:443   users:((\"curl\",pid=9,fd=3))\n\t bytes_sent:1 bytes_received:2\n";
$r = Platform::parseSs($ss);
ok('3 owned sockets', count($r) === 3, (string) count($r));
ok('bytes parsed', $r[0]['out'] === 1234 && $r[0]['in'] === 56789 && $r[0]['pid'] === 1234 && $r[0]['rip'] === '140.82.112.4' && $r[0]['rport'] === 443);
ok('v4-mapped normalised', $r[1]['rip'] === '104.18.1.1' && $r[1]['pname'] === 'claude');
ok('ipv6 + scope', $r[2]['rip'] === '2606:4700::1' && $r[2]['lkey'] === 'fe80::1:7000');

echo "Linux ps\n";
$cmd = "    1 /sbin/init splash\n 4242 /home/u/.local/bin/claude --resume abc\n";
$tbl = "    1     0  0.0 11000 5-03:11:22 systemd\n 4242  1  3.5 204800    12:30 claude\n";
$p = Platform::parsePs($cmd, $tbl);
ok('2 procs', count($p) === 2);
ok('fields', $p[4242]['ppid'] === 1 && $p[4242]['cpu'] === 3.5 && $p[4242]['rss'] === 204800 * 1024 && $p[4242]['cmd'] === '/home/u/.local/bin/claude --resume abc' && $p[4242]['etime'] === '12:30');
ok('comma decimal', Platform::parsePs("1 x\n", "    1     0  0,3 100 00:10 x\n")[1]['cpu'] === 0.3);

echo "Windows\n";
$json = '[{"pid":4,"ppid":0,"name":"System","cmd":null,"rss":1000,"start":0},'
      . '{"pid":900,"ppid":4,"name":"claude.exe","cmd":"\"C:\\\\Users\\\\Ann\\\\.local\\\\bin\\\\claude.exe\" --resume","rss":52428800,"start":' . (time() - 3700) . '}]';
$w = Platform::parseWinProcs("\xEF\xBB\xBF" . $json, time());
ok('procs', count($w) === 2 && $w[900]['name'] === 'claude.exe');
ok('cmd normalised', $w[900]['cmd'] === 'C:/Users/Ann/.local/bin/claude.exe --resume', $w[900]['cmd'] ?? '');
ok('etime', preg_match('/^01:0[12]:\d\d$/', $w[900]['etime']) === 1, $w[900]['etime']);
ok('single object', count(Platform::parseWinProcs('{"pid":5,"ppid":1,"name":"a.exe","cmd":"a","rss":1,"start":0}', time())) === 1);
$csv = "\"OwningProcess\",\"LocalAddress\",\"LocalPort\",\"RemoteAddress\",\"RemotePort\"\r\n\"900\",\"192.168.1.9\",\"50000\",\"160.79.104.10\",\"443\"\r\n\"900\",\"::1\",\"50001\",\"::\",\"0\"\r\n";
$c = Platform::parseWinConns($csv);
ok('conns', count($c) === 1 && $c[0]['pid'] === 900 && $c[0]['rip'] === '160.79.104.10' && $c[0]['out'] === 0);

echo "Windows netstat\n";
$ns = "Active Connections\r\n\r\n  Proto  Local Address          Foreign Address        State           PID\r\n"
    . "  TCP    0.0.0.0:135            0.0.0.0:0              LISTENING       1064\r\n"
    . "  TCP    192.168.1.9:50000      160.79.104.10:443      ESTABLISHED     900\r\n"
    . "  TCP    [::1]:49700            [::1]:11434            ESTABLISHED     4321\r\n"
    . "  TCP    192.168.1.9:50012      140.82.112.4:443       TIME_WAIT       0\r\n"
    . "  TCP    192.168.1.9:50013      140.82.112.4:443       HERGESTELLT     777\r\n"     // localised state text still parses
    . "  UDP    0.0.0.0:5353           *:*                                    1234\r\n";
$n = Platform::parseNetstat($ns);
ok('netstat rows (ESTABLISHED, IPv6, localised)', count($n) === 3, (string) count($n));
ok('netstat fields', $n[0]['pid'] === 900 && $n[0]['rip'] === '160.79.104.10' && $n[0]['rport'] === 443 && $n[0]['out'] === 0);
ok('netstat ipv6 loopback', $n[1]['rip'] === '::1' && $n[1]['rport'] === 11434);
ok('netstat skips listening / pid 0 / udp', !in_array(1064, array_column($n, 'pid'), true) && !in_array(0, array_column($n, 'pid'), true) && !in_array(1234, array_column($n, 'pid'), true));
ok('rDNS budget lower on Windows', (function () { Platform::force('windows'); $w = Platform::rdnsBudget(); Platform::force('linux'); $l = Platform::rdnsBudget(); Platform::force(null); return $w === 1 && $l === 3; })());

echo "Windows ACL (SDDL)\n";
ok('SDDL: owner/SYSTEM/Administrators only is closed', Platform::sddlOpenToOthers('D:PAI(A;OICI;FA;;;S-1-5-21-1-2-3-1001)(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)') === false);
ok('SDDL: Users entry is open', Platform::sddlOpenToOthers('D:PAI(A;OICI;FA;;;SY)(A;OICI;0x1200a9;;;BU)') === true);
ok('SDDL: Authenticated Users / Everyone are open', Platform::sddlOpenToOthers('D:(A;;FA;;;AU)') === true && Platform::sddlOpenToOthers('D:(A;;FA;;;WD)') === true);
ok('SDDL: a deny entry is not treated as open', Platform::sddlOpenToOthers('D:(D;;FA;;;BU)(A;;FA;;;SY)') === false);

echo "Not classified (unrecognised processes)\n";
$sig0 = require dirname(__DIR__) . '/config/signatures.php';
require_once dirname(__DIR__) . '/src/Unclassified.php';
ok('kind: browser', Unclassified::kind('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome Helper', 'Google Chrome Helper', $sig0) === 'browser');
ok('kind: system (path and name)', Unclassified::kind('/usr/sbin/mDNSResponder', 'mDNSResponder', $sig0) === 'system' && Unclassified::kind('C:/Windows/System32/svchost.exe -k netsvcs', 'svchost.exe', $sig0) === 'system');
ok('kind: everything else is an application', Unclassified::kind('/opt/homebrew/bin/python3.12 agent.py', 'python3.12', $sig0) === 'app');
$m = new PDO('sqlite::memory:'); $m->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $m->exec(Unclassified::SCHEMA);
$smp = fn(string $p, string $host, int $din, int $dout) => ['proc' => $p, 'cmd' => "/opt/x/$p --serve", 'kind' => 'app', 'host' => $host, 'rip' => '203.0.113.9', 'rport' => 443, 'din' => $din, 'dout' => $dout];
Unclassified::record($m, [$smp('mystery', 'api.new-ai.example', 10, 100), $smp('mystery', 'telemetry.new-ai.example', 5, 50)], 1000);
$r = $m->query("SELECT * FROM unclassified WHERE proc='mystery'")->fetch(PDO::FETCH_ASSOC);
ok('records an unknown process with 2 connections and 2 destinations', (int) $r['conns'] === 2 && count(json_decode($r['dests'], true)) === 2 && (int) $r['bout'] === 150 && $r['path'] === '/opt/x/mystery');
Unclassified::record($m, [$smp('mystery', 'api.new-ai.example', 1, 1)], 1003);
$r = $m->query("SELECT * FROM unclassified WHERE proc='mystery'")->fetch(PDO::FETCH_ASSOC);
ok('bytes accumulate, destinations merge without duplicates, first_seen is kept', (int) $r['bout'] === 151 && (int) $r['conns'] === 1 && count(json_decode($r['dests'], true)) === 2 && (int) $r['first_seen'] === 1000 && (int) $r['last_seen'] === 1003);
Unclassified::record($m, [], 1006);
ok('a process that stopped connecting shows 0 connections now (but is remembered)', (int) $m->query("SELECT conns FROM unclassified WHERE proc='mystery'")->fetchColumn() === 0);
Unclassified::prune($m, 1006 + 15 * 86400);
ok('old entries are pruned after 14 days', (int) $m->query('SELECT COUNT(*) FROM unclassified')->fetchColumn() === 0);

echo "Signatures per OS\n";
foreach (['mac', 'linux', 'windows'] as $os) {
    Platform::force($os);
    $sig = Platform::adapt(require dirname(__DIR__) . '/config/signatures.php');
    $m = function (string $cmd) use ($sig): ?string { foreach ($sig['tools'] as $k => [, , $re]) if (preg_match($re, $cmd)) return $k; return null; };
    if ($os === 'windows') {
        ok("$os claude.exe", $m('C:/Users/Ann/.local/bin/claude.exe --resume') === 'claude-code');
        ok("$os Cursor.exe", $m('C:/Users/Ann/AppData/Local/Programs/cursor/Cursor.exe --type=renderer') === 'cursor');
        ok("$os ollama.exe", $m('C:/Users/Ann/AppData/Local/Programs/Ollama/ollama.exe serve') === 'ollama');
        ok("$os npm claude", $m('node C:/Users/Ann/AppData/Roaming/npm/node_modules/@anthropic-ai/claude-code/cli.js') === 'claude-code');
    } elseif ($os === 'linux') {
        ok("$os claude", $m('/home/u/.local/bin/claude --resume') === 'claude-code');
        ok("$os AppImage", $m('/home/u/Applications/Cursor-0.4.AppImage --no-sandbox') === 'cursor');
        ok("$os ollama", $m('/usr/local/bin/ollama serve') === 'ollama');
    } else {
        ok("$os Claude.app", $m('/Applications/Claude.app/Contents/MacOS/Claude') === 'claude-desktop');
    }
    ok("$os unrelated", $m('/usr/bin/vim notes.txt') === null);
}

echo "Etime\n";
ok('fmt', Platform::fmtEtime(65) === '01:05' && Platform::fmtEtime(3725) === '01:02:05' && Platform::fmtEtime(90061) === '1-01:01:01');
Platform::force(null);

echo "macOS live regression\n";
if (Platform::isMac()) {
    [$procs] = Platform::procs();
    $old = [];
    foreach (explode("\n", (string) shell_exec('LC_ALL=C /bin/ps -axww -o pid=,ppid=,pcpu=,rss=,etime=,comm= 2>/dev/null')) as $l) {
        if (preg_match('/^\s*(\d+)\s+(\d+)\s+/', $l, $m)) $old[(int) $m[1]] = (int) $m[2];
    }
    $same = 0; foreach ($old as $pid => $pp) if (isset($procs[$pid]) && $procs[$pid]['ppid'] === $pp) $same++;
    ok('process table matches ps (' . count($procs) . ' procs)', count($procs) > 50 && $same >= count($old) * 0.95);
    $conns = Platform::connections();
    ok('nettop connections parse (' . count($conns) . ')', count($conns) > 0 && isset($conns[0]['rip'], $conns[0]['in']));
    ok('sig unchanged on mac', Platform::adapt(['tools' => [['x', 'y', '#a#']]])['tools'][0][2] === '#a#');
    ok('home', Platform::home() !== '' && is_dir(Platform::home()));
}
echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
