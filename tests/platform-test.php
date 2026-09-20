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
