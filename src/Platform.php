<?php
/**
 * Platform adapter: everything that differs between macOS, Linux and Windows lives here.
 * The parse*() functions take raw command output, so they can be tested with fixtures on any OS.
 *
 * Capabilities per OS (see caps()):
 *   macOS   processes, per-connection byte counters (nettop), open files (lsof), TCC permission database
 *   Linux   processes (ps), per-connection byte counters (ss -i), open files (/proc/<pid>/fd), no TCC
 *   Windows processes + connections (PowerShell/CIM) but NO byte counters and NO open-file view, no TCC
 */
final class Platform
{
    private static ?string $os = null;
    private static array $has = [];
    private const WIN_PROC_TTL = 12;
    private static ?string $winProcRaw = null;
    private static int $winProcAt = 0;

    public static function os(): string
    {
        if (self::$os === null) {
            self::$os = match (PHP_OS_FAMILY) {
                'Darwin' => 'mac',
                'Windows' => 'windows',
                default => 'linux',
            };
        }
        return self::$os;
    }

    /** Test hook: force an OS for parser/adapter tests */
    public static function force(?string $os): void
    {
        self::$os = $os;
        self::$has = [];
    }

    public static function isMac(): bool { return self::os() === 'mac'; }
    public static function isWindows(): bool { return self::os() === 'windows'; }
    public static function isLinux(): bool { return self::os() === 'linux'; }

    public static function label(): string
    {
        return ['mac' => 'macOS', 'linux' => 'Linux', 'windows' => 'Windows'][self::os()];
    }

    /** What this OS can observe; the UI uses it to explain gaps instead of showing misleading zeros */
    public static function caps(): array
    {
        return [
            'os' => self::os(),
            'label' => self::label(),
            'bytes' => !self::isWindows(),
            'files' => true, // Windows: known credential files (Restart Manager) + audited folders (Security log), not a full open-file list
            'tcc' => self::isMac(),
            'udp' => self::isMac(),
        ];
    }

    /**
     * Use the machine's timezone. XAMPP's php.ini often forces another one (e.g. Europe/Berlin), which shifted report/CSV
     * timestamps and daily usage buckets. TZ env → /etc/localtime → /etc/timezone; Windows keeps PHP's configured zone.
     */
    public static function applyTimezone(): void
    {
        $tz = (string) getenv('TZ');
        if ($tz === '' && !self::isWindows()) {
            $real = @realpath('/etc/localtime');
            if ($real !== false && preg_match('#zoneinfo/(.+)$#', $real, $m)) {
                $tz = $m[1];
            } elseif (is_readable('/etc/timezone')) {
                $tz = trim((string) file_get_contents('/etc/timezone'));
            }
        }
        if ($tz !== '') {
            @date_default_timezone_set(ltrim($tz, ':'));
        }
    }

    // ------------------------------------------------------------------ paths

    /** Backslashes → slashes so every regex and path comparison in the app can assume "/" */
    public static function norm(string $p): string
    {
        return self::isWindows() ? str_replace('\\', '/', $p) : $p;
    }

    public static function home(): string
    {
        $h = (string) getenv('HOME');
        if ($h === '' && self::isWindows()) {
            $h = (string) getenv('USERPROFILE');
        }
        if ($h === '' && function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $h = (string) (posix_getpwuid(posix_getuid())['dir'] ?? '');
        }
        return rtrim(self::norm($h), '/');
    }

    /** Per-user application data root: where desktop apps keep their config */
    public static function appSupport(string $home): string
    {
        if (self::isMac()) {
            return $home . '/Library/Application Support';
        }
        if (self::isWindows()) {
            $a = (string) getenv('APPDATA');
            return $a !== '' ? rtrim(self::norm($a), '/') : $home . '/AppData/Roaming';
        }
        $x = (string) getenv('XDG_CONFIG_HOME');
        return $x !== '' ? rtrim($x, '/') : $home . '/.config';
    }

    /** Browser profile roots relative to $HOME (Chromium family) */
    public static function browserProfiles(): array
    {
        if (self::isWindows()) {
            return [
                'Chrome' => 'AppData/Local/Google/Chrome/User Data',
                'Brave' => 'AppData/Local/BraveSoftware/Brave-Browser/User Data',
                'Edge' => 'AppData/Local/Microsoft/Edge/User Data',
                'Chromium' => 'AppData/Local/Chromium/User Data',
            ];
        }
        if (self::isLinux()) {
            return [
                'Chrome' => '.config/google-chrome',
                'Brave' => '.config/BraveSoftware/Brave-Browser',
                'Edge' => '.config/microsoft-edge',
                'Chromium' => '.config/chromium',
            ];
        }
        return [
            'Chrome' => 'Library/Application Support/Google/Chrome',
            'Brave' => 'Library/Application Support/BraveSoftware/Brave-Browser',
            'Edge' => 'Library/Application Support/Microsoft Edge',
            'Arc' => 'Library/Application Support/Arc/User Data',
            'Chromium' => 'Library/Application Support/Chromium',
        ];
    }

    /** Places to look for an installed desktop app named $base (e.g. "Claude") */
    public static function appCandidates(string $base, string $home): array
    {
        $lc = strtolower(str_replace(' ', '-', $base));
        if (self::isMac()) {
            return ["/Applications/$base.app", "$home/Applications/$base.app"];
        }
        if (self::isWindows()) {
            $out = [];
            foreach (['LOCALAPPDATA' => 'Programs/', 'ProgramFiles' => '', 'ProgramFiles(x86)' => '', 'APPDATA' => ''] as $env => $sub) {
                $d = (string) getenv($env);
                if ($d !== '') {
                    $out[] = rtrim(self::norm($d), '/') . '/' . $sub . $base;
                }
            }
            return $out;
        }
        $out = ["/opt/$base", "/opt/$lc", "/usr/share/$lc", "/usr/lib/$lc", "$home/.local/share/$lc", "/snap/$lc", "/var/lib/flatpak/app/$lc"];
        foreach (glob("$home/Applications/$base*.AppImage") ?: [] as $g) {
            $out[] = $g;
        }
        return $out;
    }

    /** Is an executable on PATH (plus the usual per-user bin dirs)? Returns its path or '' */
    public static function which(string $bin, string $home): string
    {
        if (self::isMac()) {
            // Unchanged macOS behaviour: ask the shell with a widened PATH (launchd starts us with a minimal one)
            $dirs = array_map(fn($d) => $home . '/' . $d, ['.local/bin', '.opencode/bin', '.bun/bin', '.cargo/bin', '.npm-global/bin', '.volta/bin', '.antigravity-ide/antigravity-ide/bin']);
            return trim((string) shell_exec('PATH="$PATH:' . implode(':', $dirs) . ':/opt/homebrew/bin:/usr/local/bin" command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
        }
        $dirs = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')));
        $extra = ['.local/bin', '.opencode/bin', '.bun/bin', '.cargo/bin', '.npm-global/bin', '.volta/bin', 'go/bin'];
        foreach ($extra as $d) {
            $dirs[] = $home . '/' . $d;
        }
        if (self::isWindows()) {
            $appdata = (string) getenv('APPDATA');
            if ($appdata !== '') {
                $dirs[] = $appdata . '\\npm';
            }
            $dirs[] = $home . '/AppData/Local/Programs/Ollama';
        } else {
            array_push($dirs, '/usr/local/bin', '/usr/bin', '/snap/bin', $home . '/.nix-profile/bin', '/home/linuxbrew/.linuxbrew/bin');
        }
        $exts = self::isWindows() ? ['.exe', '.cmd', '.bat', '.ps1', ''] : [''];
        foreach ($dirs as $d) {
            foreach ($exts as $e) {
                $p = rtrim(self::norm($d), '/') . '/' . $bin . $e;
                if (is_file($p) && (self::isWindows() || is_executable($p))) {
                    return $p;
                }
            }
        }
        return '';
    }

    /** Is a helper command available (cached)? */
    public static function has(string $bin): bool
    {
        return self::$has[$bin] ??= self::which($bin, self::home()) !== '';
    }

    // -------------------------------------------------------------- PowerShell

    /** Run a PowerShell script (Windows). -EncodedCommand avoids every cmd.exe quoting problem. */
    private static function powershell(string $script): string
    {
        $script = "[Console]::OutputEncoding=[Text.Encoding]::UTF8; \$ProgressPreference='SilentlyContinue'; " . $script;
        $enc = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        return (string) shell_exec('powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $enc . ' 2>NUL');
    }

    // --------------------------------------------------------------- processes

    /**
     * @return array{0:array<int,array{ppid:int,cpu:float,rss:int,etime:string,name:string,cmd:string}>,1:string} [processes, raw sample]
     */
    public static function procs(): array
    {
        if (self::isWindows()) {
            // Starting powershell.exe can take seconds on some machines, so the process list is refreshed every WIN_PROC_TTL
            // seconds and reused in between (connections come from netstat, which is fast, see connections()).
            if (self::$winProcRaw === null || time() - self::$winProcAt >= self::WIN_PROC_TTL) {
                self::$winProcRaw = self::powershell(
                    'Get-CimInstance Win32_Process | ForEach-Object { [pscustomobject]@{ pid=[int]$_.ProcessId; ppid=[int]$_.ParentProcessId; name=$_.Name; cmd=$_.CommandLine; '
                    . 'rss=[int64]$_.WorkingSetSize; start= $(if ($_.CreationDate) { [int64]([DateTimeOffset]$_.CreationDate).ToUnixTimeSeconds() } else { 0 }) } } | ConvertTo-Json -Compress'
                );
                // an empty answer (WMI not ready right after sign-in) is not cached: the next tick asks again
                self::$winProcAt = trim(self::$winProcRaw) === '' ? 0 : time();
            }
            return [self::parseWinProcs(self::$winProcRaw, time()), self::$winProcRaw];
        }
        if (self::isMac()) {
            $raw = (string) shell_exec('LC_ALL=C /bin/ps -axww -o pid=,command= 2>&1');
            $tbl = (string) shell_exec('LC_ALL=C /bin/ps -axww -o pid=,ppid=,pcpu=,rss=,etime=,comm= 2>/dev/null');
        } else {
            $raw = (string) shell_exec('LC_ALL=C ps -eww -o pid=,args= 2>&1');
            $tbl = (string) shell_exec('LC_ALL=C ps -eww -o pid=,ppid=,pcpu=,rss=,etime=,comm= 2>/dev/null');
        }
        return [self::parsePs($raw, $tbl), $raw];
    }

    /** ps output (BSD/procps share the same column format) → process table */
    public static function parsePs(string $cmdRaw, string $tableRaw): array
    {
        $cmds = [];
        foreach (explode("\n", $cmdRaw) as $l) {
            if (preg_match('/^\s*(\d+)\s+(.*)$/', $l, $m)) {
                $cmds[(int) $m[1]] = $m[2];
            }
        }
        $out = [];
        foreach (explode("\n", $tableRaw) as $l) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+([\d.,]+)\s+(\d+)\s+(\S+)\s+(.*)$/', $l, $m)) {
                $pid = (int) $m[1];
                $out[$pid] = [
                    'ppid' => (int) $m[2], 'cpu' => (float) str_replace(',', '.', $m[3]), 'rss' => (int) $m[4] * 1024,
                    'etime' => $m[5], 'name' => basename($m[6]), 'cmd' => $cmds[$pid] ?? $m[6],
                ];
            }
        }
        return $out;
    }

    /** PowerShell JSON (Win32_Process) → process table; command lines get "/" separators and no quotes */
    public static function parseWinProcs(string $json, int $now): array
    {
        $j = json_decode(ltrim($json, "\xEF\xBB\xBF"), true);
        if (!is_array($j)) {
            return [];
        }
        if (isset($j['pid'])) {
            $j = [$j]; // a single object is not wrapped in an array
        }
        $out = [];
        foreach ($j as $r) {
            $pid = (int) ($r['pid'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $cmd = trim(str_replace(['"', '\\'], ['', '/'], (string) ($r['cmd'] ?? '')));
            $name = (string) ($r['name'] ?? '');
            $start = (int) ($r['start'] ?? 0);
            $out[$pid] = [
                'ppid' => (int) ($r['ppid'] ?? 0), 'cpu' => 0.0, 'rss' => (int) ($r['rss'] ?? 0),
                'etime' => self::fmtEtime($start > 0 ? max(0, $now - $start) : 0),
                'name' => $name, 'cmd' => $cmd !== '' ? $cmd : $name,
            ];
        }
        return $out;
    }

    /** seconds → ps etime "[[dd-]hh:]mm:ss" */
    public static function fmtEtime(int $s): string
    {
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;
        if ($d > 0) {
            return sprintf('%d-%02d:%02d:%02d', $d, $h, $m, $sec);
        }
        return $h > 0 ? sprintf('%02d:%02d:%02d', $h, $m, $sec) : sprintf('%02d:%02d', $m, $sec);
    }

    // ------------------------------------------------------------- connections

    /**
     * @return list<array{pid:int,pname:string,proto:string,lkey:string,rip:string,rport:int,in:int,out:int}>
     */
    public static function connections(): array
    {
        if (self::isMac()) {
            $out = '';
            // If nettop gets several -m flags only the last one applies; TCP and UDP are called separately.
            foreach (['tcp', 'udp'] as $m) {
                $out .= (string) shell_exec("nettop -L 1 -x -n -m $m -J bytes_in,bytes_out 2>/dev/null") . "\n";
            }
            return self::parseNettop($out);
        }
        if (self::isWindows()) {
            $rows = self::parseNetstat((string) shell_exec('netstat -ano 2>NUL'));
            if ($rows) {
                return $rows;
            }
            $raw = self::powershell(
                'Get-NetTCPConnection -State Established -ErrorAction SilentlyContinue | Select-Object OwningProcess,LocalAddress,LocalPort,RemoteAddress,RemotePort | ConvertTo-Csv -NoTypeInformation'
            );
            return self::parseWinConns($raw);
        }
        if (!self::has('ss')) {
            return [];
        }
        return self::parseSs((string) shell_exec('LC_ALL=C ss -tnpHi state established 2>/dev/null'));
    }

    /** "1.2.3.4:443" or "fe80::1.443" → [ip, port] */
    public static function splitAddr(string $a): array
    {
        if (preg_match('/^(.*)[:.](\d+|\*)$/', $a, $m)) {
            return [$m[1], $m[2] === '*' ? 0 : (int) $m[2]];
        }
        return [$a, 0];
    }

    /** nettop: a process line + connection lines below it, with byte counters */
    public static function parseNettop(string $out): array
    {
        $rows = [];
        $pid = 0;
        $pname = '';
        foreach (explode("\n", $out) as $l) {
            if ($l === '' || $l[0] === ',') {
                continue;
            }
            // Connection lines (tcp4/tcp6/udp4/udp6 ...) can look like process lines (IPv6 "::1.1,"): handle them first
            if (!preg_match('/^(tcp|udp)[46]? /', $l) && preg_match('/^(.+)\.(\d+),(\d*),(\d*),$/', $l, $m)) {
                $pname = $m[1];
                $pid = (int) $m[2];
                continue;
            }
            if (!preg_match('/^(tcp|udp)[46] (\S+)<->(\S+),(\d*),(\d*),$/', $l, $m)) {
                continue;
            }
            [$rip, $rport] = self::splitAddr($m[3]);
            if ($rip === '*' || $rport === 0) {
                continue; // listening / unconnected socket
            }
            $rows[] = [
                'pid' => $pid, 'pname' => $pname, 'proto' => strtoupper($m[1]),
                'lkey' => $m[2], 'rip' => $rip, 'rport' => $rport,
                'in' => (int) $m[4], 'out' => (int) $m[5],
            ];
        }
        return $rows;
    }

    /**
     * Linux `ss -tnpHi state established`: one line per socket, the TCP info (with bytes_sent / bytes_received)
     * on the indented line below it.
     *   0 0 10.0.0.5:43210 140.82.112.4:443 users:(("node",pid=1234,fd=23))
     *        cubic wscale:7,7 ... bytes_sent:1234 bytes_acked:1234 bytes_received:5678 ...
     */
    public static function parseSs(string $out): array
    {
        $rows = [];
        $cur = null;
        $flush = function () use (&$rows, &$cur) {
            if ($cur !== null && $cur['pid'] > 0) {
                $rows[] = $cur;
            }
            $cur = null;
        };
        foreach (explode("\n", $out) as $l) {
            if (trim($l) === '') {
                continue;
            }
            if ($l[0] !== ' ' && $l[0] !== "\t") {
                $flush();
                if (!preg_match('/^(?:ESTAB\s+)?\d+\s+\d+\s+(\S+):(\d+)\s+(\S+):(\d+)\s*(.*)$/', $l, $m)) {
                    continue;
                }
                $rip = self::cleanIp($m[3]);
                $pid = 0;
                $pname = '';
                if (preg_match('/users:\(\("([^"]*)",pid=(\d+)/', $m[5], $u)) {
                    $pname = $u[1];
                    $pid = (int) $u[2];
                }
                $cur = [
                    'pid' => $pid, 'pname' => $pname, 'proto' => 'TCP',
                    'lkey' => self::cleanIp($m[1]) . ':' . $m[2], 'rip' => $rip, 'rport' => (int) $m[4], 'in' => 0, 'out' => 0,
                ];
            } elseif ($cur !== null) {
                if (preg_match('/\bbytes_sent:(\d+)/', $l, $b)) {
                    $cur['out'] = (int) $b[1];
                }
                if (preg_match('/\bbytes_received:(\d+)/', $l, $b)) {
                    $cur['in'] = (int) $b[1];
                }
            }
        }
        $flush();
        return $rows;
    }

    /** "[::1]" / "fe80::1%eth0" / "::ffff:1.2.3.4" → plain address */
    private static function cleanIp(string $a): string
    {
        $a = trim($a, '[]');
        $a = preg_replace('/%.*$/', '', $a) ?? $a;
        return preg_replace('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', '$1', $a) ?? $a;
    }

    /**
     * Windows `netstat -ano`: "  TCP  192.168.1.5:50000  140.82.112.4:443  ESTABLISHED  1234". Only the column layout is used, not the
     * state text (netstat translates it on non-English Windows). Listening sockets, PID 0 and closing states are skipped.
     * No byte counters are available, so in/out stay 0.
     */
    public static function parseNetstat(string $out): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', $out) ?: [] as $l) {
            if (!preg_match('/^\s*TCP\s+(\S+):(\d+)\s+(\S+):(\d+)\s+(\S+)\s+(\d+)\s*$/i', $l, $m)) {
                continue;
            }
            $pid = (int) $m[6];
            $rip = self::cleanIp($m[3]);
            if ($pid <= 0 || (int) $m[4] === 0 || $rip === '0.0.0.0' || $rip === '::' || $rip === '*'
                || preg_match('/^(TIME_WAIT|CLOSE_WAIT|FIN_WAIT_\d|LAST_ACK|CLOSING|SYN_SENT|SYN_RECEIVED|LISTENING)$/i', $m[5])) {
                continue;
            }
            $rows[] = [
                'pid' => $pid, 'pname' => '', 'proto' => 'TCP', 'lkey' => self::cleanIp($m[1]) . ':' . $m[2],
                'rip' => $rip, 'rport' => (int) $m[4], 'in' => 0, 'out' => 0,
            ];
        }
        return $rows;
    }

    /** Reverse-DNS lookups allowed per collector tick (nslookup is slow on Windows) */
    public static function rdnsBudget(): int
    {
        return self::isWindows() ? 1 : 3;
    }

    /**
     * Windows: chmod() does nothing, so restrict the data folders with ACLs instead: only this user, SYSTEM and Administrators
     * (well-known SIDs, so it works on every Windows language). Inheritance from the parent (which often gives all local users
     * read access under C:\xampp\htdocs) is removed.
     *
     * Two lessons are built in (a first version crash-looped the collector on a Windows machine):
     *  - `icacls /inheritance:r ... /T` can leave the FILES with an empty ACL that nobody, not even the owner, can open. So every
     *    file and folder is restricted individually, deepest first, never with /T.
     *  - after locking, everything must still be openable by us. If not, the change is undone (icacls /reset) and never tried again
     *    on this install (.no-acl-lock). On success it runs once (.acl-locked); new files inherit from the locked folders.
     * Opt out with AIWATCH_NO_ACL=1.
     */
    public static function lockDown(array $dirs): void
    {
        $root = dirname(__DIR__);
        if (!self::isWindows() || getenv('AIWATCH_NO_ACL') === '1' || is_file("$root/.acl-locked") || is_file("$root/.no-acl-lock")) {
            return;
        }
        $user = (string) getenv('USERNAME');
        if ($user === '') {
            return;
        }
        $dom = (string) getenv('USERDOMAIN');
        $who = ($dom !== '' ? $dom . '\\' : '') . $user;
        $grant = fn(string $flags): string => escapeshellarg($who . ':' . $flags) . ' ' . escapeshellarg('*S-1-5-18:' . $flags) . ' ' . escapeshellarg('*S-1-5-32-544:' . $flags);
        $winPath = fn(string $p): string => escapeshellarg(str_replace('/', '\\', $p));
        $dirs = array_values(array_filter($dirs, 'is_dir'));
        foreach ($dirs as $d) {
            foreach (self::listTree($d) as $p) {
                shell_exec('icacls ' . $winPath($p) . ' /inheritance:r /grant:r ' . $grant(is_dir($p) ? '(OI)(CI)F' : 'F') . ' /C /Q 2>NUL');
            }
        }
        $bad = self::unreadable($dirs);
        if ($bad) {
            foreach ($dirs as $d) {
                shell_exec('icacls ' . $winPath($d) . ' /reset /T /C /Q 2>NUL');
            }
            $msg = 'ACL lock-down undone, it made these unreadable: ' . implode(', ', array_map('basename', $bad));
            @file_put_contents("$root/.no-acl-lock", date('c') . ' ' . $msg . "\n");
            fwrite(STDERR, "[SecAIQ Watch] $msg. The data folders keep their default Windows permissions.\n");
            return;
        }
        @file_put_contents("$root/.acl-locked", date('c') . "\n");
    }

    /** Files and folders under $dir, deepest first, $dir itself last (capped so a huge backups folder cannot make a start-up crawl) */
    public static function listTree(string $dir, int $max = 3000): array
    {
        $out = [];
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                if (count($out) >= $max) {
                    break;
                }
                $out[] = str_replace('\\', '/', $f->getPathname());
            }
        } catch (Throwable $e) {
            // unreadable subfolder: whatever was collected so far is enough, the caller re-checks readability
        }
        $out[] = rtrim(str_replace('\\', '/', $dir), '/');
        return $out;
    }

    /** Paths under $dirs that this process cannot open (the symptom of a broken ACL); at most $limit are returned */
    public static function unreadable(array $dirs, int $limit = 5): array
    {
        $bad = [];
        foreach ($dirs as $d) {
            foreach (self::listTree($d, 500) as $p) {
                if (is_dir($p) ? @scandir($p) === false : (($h = @fopen($p, 'rb')) === false ? true : !fclose($h))) {
                    $bad[] = $p;
                    if (count($bad) >= $limit) {
                        return $bad;
                    }
                }
            }
        }
        return $bad;
    }

    /**
     * True when other local users could read this Windows folder. Uses `icacls /save` (SDDL), whose group aliases are the same in
     * every language: BU = Users, AU = Authenticated Users, WD = Everyone. (Group *names* are translated, e.g. on Turkish Windows.)
     */
    public static function windowsFolderOpen(string $dir): ?bool
    {
        if (!self::isWindows() || !is_dir($dir)) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'acl');
        if ($tmp === false) {
            return null;
        }
        shell_exec('icacls ' . escapeshellarg(str_replace('/', '\\', $dir)) . ' /save ' . escapeshellarg($tmp) . ' /C /Q 2>NUL');
        $raw = (string) @file_get_contents($tmp);
        @unlink($tmp);
        if ($raw === '') {
            return null;
        }
        return self::sddlOpenToOthers(str_starts_with($raw, "\xFF\xFE") ? (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE') : $raw);
    }

    /** SDDL text (e.g. D:PAI(A;OICI;FA;;;SY)(A;OICI;0x1200a9;;;BU)) → does an allow entry exist for Users / Authenticated Users / Everyone? */
    public static function sddlOpenToOthers(string $sddl): bool
    {
        return (bool) preg_match('/\(A;[^;)]*;[^;)]*;[^;)]*;[^;)]*;(BU|AU|WD)\)/', $sddl);
    }

    /** PowerShell CSV of Get-NetTCPConnection. Windows exposes no per-connection byte counters → in/out are 0. */
    public static function parseWinConns(string $csv): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', ltrim($csv, "\xEF\xBB\xBF")) ?: [] as $i => $l) {
            if ($i === 0 || trim($l) === '') {
                continue; // header
            }
            $f = str_getcsv($l, ',', '"', '');
            if (count($f) < 5) {
                continue;
            }
            [$pid, $lip, $lport, $rip, $rport] = $f;
            $rip = self::cleanIp((string) $rip);
            if ($rip === '' || $rip === '0.0.0.0' || $rip === '::' || (int) $rport === 0) {
                continue;
            }
            $rows[] = [
                'pid' => (int) $pid, 'pname' => '', 'proto' => 'TCP', 'lkey' => self::cleanIp((string) $lip) . ':' . $lport,
                'rip' => $rip, 'rport' => (int) $rport, 'in' => 0, 'out' => 0,
            ];
        }
        return $rows;
    }

    // -------------------------------------------------------------- open files

    /**
     * Paths the given processes have open, plus their working directories.
     * @return list<array{pid:int,fd:string,type:string,path:string}> type is REG or DIR; fd is "cwd" for the working dir
     */
    public static function openPaths(array $pids): array
    {
        if (!$pids) {
            return [];
        }
        if (self::isWindows()) {
            return self::rmOpenPaths($pids);
        }
        if (self::isMac()) {
            return self::parseLsof((string) shell_exec('lsof -nP -w -p ' . implode(',', $pids) . ' -F pfnt 2>/dev/null'));
        }
        $rows = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            $cwd = @readlink("/proc/$pid/cwd");
            if (is_string($cwd) && $cwd !== '') {
                $rows[] = ['pid' => $pid, 'fd' => 'cwd', 'type' => 'DIR', 'path' => $cwd];
            }
            $n = 0;
            foreach (@scandir("/proc/$pid/fd") ?: [] as $fd) {
                if ($fd === '.' || $fd === '..' || ++$n > 300) {
                    continue;
                }
                $t = @readlink("/proc/$pid/fd/$fd");
                if (!is_string($t) || $t === '' || $t[0] !== '/') {
                    continue; // socket:[..], pipe:[..], anon_inode:..
                }
                $t = preg_replace('/ \(deleted\)$/', '', $t) ?? $t;
                $rows[] = ['pid' => $pid, 'fd' => $fd, 'type' => is_dir($t) ? 'DIR' : 'REG', 'path' => $t];
            }
        }
        return $rows;
    }

    // ------------------------------------------------- Windows: open credential files

    private const RM_TTL = 60;
    /** @var list<array{pid:int,path:string}>|null */
    private static ?array $rmRows = null;
    private static int $rmAt = 0;

    /**
     * Windows has no lsof. The Restart Manager (the documented API installers use to find programs that keep a file in use)
     * answers, per file, which processes hold it open. It is asked about a fixed list of credential files that exist on this
     * machine; no administrator rights are needed. Compiling the helper costs about a second, so the answer is refreshed at
     * most once a minute and filtered to the AI processes of the current tick.
     * @return list<array{pid:int,fd:string,type:string,path:string}>
     */
    public static function rmOpenPaths(array $pids): array
    {
        if (self::$rmRows === null || time() - self::$rmAt >= self::RM_TTL) {
            self::$rmRows = self::rmQuery(self::sensitiveFiles(self::home()));
            self::$rmAt = time();
        }
        $want = array_flip(array_map('intval', $pids));
        $out = [];
        foreach (self::$rmRows as $r) {
            if (isset($want[$r['pid']])) {
                $out[] = ['pid' => $r['pid'], 'fd' => 'rm', 'type' => 'REG', 'path' => self::norm($r['path'])];
            }
        }
        return $out;
    }

    /** @return list<array{pid:int,path:string}> */
    private static function rmQuery(array $files): array
    {
        if (!$files) {
            return [];
        }
        $list = tempnam(sys_get_temp_dir(), 'saqrm');
        if ($list === false) {
            return [];
        }
        file_put_contents($list, implode("\n", $files));
        $cs = <<<'CS'
using System; using System.Text; using System.Runtime.InteropServices;
public static class SecaiqRm {
  [StructLayout(LayoutKind.Sequential)] struct RM_UNIQUE_PROCESS { public int dwProcessId; public System.Runtime.InteropServices.ComTypes.FILETIME ProcessStartTime; }
  [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)] struct RM_PROCESS_INFO {
    public RM_UNIQUE_PROCESS Process;
    [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 256)] public string strAppName;
    [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 64)] public string strServiceShortName;
    public int ApplicationType; public uint AppStatus; public uint TSSessionId; [MarshalAs(UnmanagedType.Bool)] public bool bRestartable; }
  [DllImport("rstrtmgr.dll", CharSet = CharSet.Unicode)] static extern int RmStartSession(out uint h, int flags, StringBuilder key);
  [DllImport("rstrtmgr.dll")] static extern int RmEndSession(uint h);
  [DllImport("rstrtmgr.dll", CharSet = CharSet.Unicode)] static extern int RmRegisterResources(uint h, uint nFiles, string[] files, uint nApps, IntPtr apps, uint nSvc, string[] svc);
  [DllImport("rstrtmgr.dll")] static extern int RmGetList(uint h, out uint needed, ref uint n, [In, Out] RM_PROCESS_INFO[] info, ref uint reasons);
  public static int[] Holders(string path) {
    uint h; var key = new StringBuilder(64);
    if (RmStartSession(out h, 0, key) != 0) return new int[0];
    try {
      if (RmRegisterResources(h, 1, new string[] { path }, 0, IntPtr.Zero, 0, null) != 0) return new int[0];
      uint needed = 0, n = 0, reasons = 0;
      int r = RmGetList(h, out needed, ref n, null, ref reasons);
      if (r != 234 || needed == 0) return new int[0];
      var info = new RM_PROCESS_INFO[needed]; n = needed;
      if (RmGetList(h, out needed, ref n, info, ref reasons) != 0) return new int[0];
      var o = new int[n]; for (int i = 0; i < n; i++) o[i] = info[i].Process.dwProcessId; return o;
    } finally { RmEndSession(h); }
  }
}
CS;
        $ps = "Add-Type -TypeDefinition @'\n" . $cs . "\n'@\n"
            . '$out = foreach ($f in Get-Content -LiteralPath ' . "'" . str_replace("'", "''", $list) . "'" . ' -Encoding UTF8) { if ($f) { foreach ($p in [SecaiqRm]::Holders($f)) { [pscustomobject]@{ pid = $p; path = $f } } } }' . "\n"
            . 'ConvertTo-Json -InputObject @($out) -Compress';
        $raw = self::powershell($ps);
        @unlink($list);
        return self::parseRm($raw);
    }

    /** @return list<array{pid:int,path:string}> */
    public static function parseRm(string $json): array
    {
        $j = json_decode(trim($json), true);
        if (!is_array($j)) {
            return [];
        }
        if (isset($j['pid'])) {
            $j = [$j];
        }
        $out = [];
        foreach ($j as $r) {
            if (is_array($r) && isset($r['pid'], $r['path']) && is_string($r['path'])) {
                $out[] = ['pid' => (int) $r['pid'], 'path' => $r['path']];
            }
        }
        return $out;
    }

    /** Credential and browser-secret files under the home folder (Windows layout) that exist; existence only, never read. */
    public static function sensitiveFiles(string $home): array
    {
        if ($home === '') {
            return [];
        }
        $pats = ['.ssh/*', '.aws/credentials', '.aws/config', '.kube/config', '.azure/*.json', '.azure/msal_token_cache.*',
            '.config/gcloud/credentials.db', '.config/gcloud/access_tokens.db', '.config/gcloud/application_default_credentials.json',
            '.git-credentials', '.netrc', '.npmrc', '.docker/config.json', '.gnupg/pubring.kbx', '.gnupg/private-keys-v1.d/*',
            'AppData/Roaming/Microsoft/Credentials/*', 'AppData/Local/Microsoft/Credentials/*',
            'AppData/Local/Google/Chrome/User Data/*/Login Data', 'AppData/Local/Google/Chrome/User Data/*/Network/Cookies',
            'AppData/Local/Microsoft/Edge/User Data/*/Login Data', 'AppData/Local/Microsoft/Edge/User Data/*/Network/Cookies',
            'AppData/Local/BraveSoftware/Brave-Browser/User Data/*/Login Data',
            'AppData/Roaming/Mozilla/Firefox/Profiles/*/logins.json', 'AppData/Roaming/Mozilla/Firefox/Profiles/*/key4.db',
            'AppData/Roaming/Mozilla/Firefox/Profiles/*/cookies.sqlite'];
        $out = [];
        foreach ($pats as $p) {
            foreach (glob(rtrim($home, '/\\') . '/' . $p) ?: [] as $f) {
                if (is_file($f)) {
                    $out[$f] = true;
                }
            }
        }
        $out = array_keys($out);
        sort($out);
        return array_slice($out, 0, 150);
    }

    // ------------------------------------------------- Windows: Security-log file auditing

    /**
     * File accesses (event 4663) recorded since $cursor. Only exists when an administrator turned auditing on for chosen folders
     * (bin/windows-file-audit.ps1) and this user may read the Security log (Event Log Readers). A null cursor only sets the
     * cursor, so old records are not replayed.
     * @return array{rows:list<array{exe:string,path:string}>,cursor:?int,status:string}
     */
    public static function auditRecords(?int $cursor): array
    {
        if (!self::isWindows()) {
            return ['rows' => [], 'cursor' => $cursor, 'status' => 'na'];
        }
        $q = $cursor === null ? '*[System[(EventID=4663)]]' : '*[System[(EventID=4663) and (EventRecordID>' . (int) $cursor . ')]]';
        $raw = (string) shell_exec('wevtutil qe Security "/q:' . $q . '" /f:xml ' . ($cursor === null ? '/c:1 /rd:true' : '/c:3000') . ' 2>&1');
        if ($raw !== '' && !str_contains($raw, '<Event')) {
            return ['rows' => [], 'cursor' => $cursor, 'status' => 'denied'];
        }
        $recs = self::parseAudit($raw);
        if ($cursor === null) {
            return ['rows' => [], 'cursor' => $recs ? $recs[0]['id'] : null, 'status' => $recs ? 'ok' : 'empty'];
        }
        $rows = [];
        foreach ($recs as $r) {
            $cursor = max($cursor, $r['id']);
            $rows[] = ['exe' => self::norm($r['exe']), 'path' => self::norm($r['path'])];
        }
        return ['rows' => $rows, 'cursor' => $cursor, 'status' => 'ok'];
    }

    /** wevtutil /f:xml output (events without a root element) → file-access records. @return list<array{id:int,exe:string,path:string}> */
    public static function parseAudit(string $xml): array
    {
        $out = [];
        if (!preg_match_all('#<Event[ >].*?</Event>#s', $xml, $m)) {
            return [];
        }
        foreach ($m[0] as $ev) {
            if (!preg_match('#<EventID[^>]*>\s*4663\s*</EventID>#', $ev) || !preg_match('#<EventRecordID>(\d+)</EventRecordID>#', $ev, $id)) {
                continue;
            }
            $d = [];
            if (preg_match_all("#<Data Name=['\"]([A-Za-z]+)['\"]>(.*?)</Data>#s", $ev, $dm, PREG_SET_ORDER)) {
                foreach ($dm as $x) {
                    $d[$x[1]] = trim(html_entity_decode($x[2], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                }
            }
            if (($d['ObjectName'] ?? '') === '' || ($d['ProcessName'] ?? '') === '' || (($d['ObjectType'] ?? 'File') !== 'File')) {
                continue;
            }
            $out[] = ['id' => (int) $id[1], 'exe' => $d['ProcessName'], 'path' => $d['ObjectName']];
        }
        return $out;
    }

    /** lsof -F pfnt → rows (only REG / DIR entries with an absolute path) */
    public static function parseLsof(string $out): array
    {
        $rows = [];
        $pid = 0;
        $fd = '';
        $type = '';
        foreach (explode("\n", $out) as $l) {
            if ($l === '') {
                continue;
            }
            $tag = $l[0];
            $val = substr($l, 1);
            if ($tag === 'p') {
                $pid = (int) $val;
            } elseif ($tag === 'f') {
                $fd = $val;
                $type = '';
            } elseif ($tag === 't') {
                $type = $val;
            } elseif ($tag === 'n' && $val !== '' && $val[0] === '/' && in_array($type, ['REG', 'DIR'], true)) {
                $rows[] = ['pid' => $pid, 'fd' => $fd, 'type' => $type, 'path' => $val];
            }
        }
        return $rows;
    }

    // --------------------------------------------------------------- misc I/O

    /** Reverse DNS with a short timeout; null when unknown */
    public static function rdns(string $ip): ?string
    {
        if (self::isWindows()) {
            $o = (string) shell_exec('nslookup -timeout=1 -retry=1 ' . escapeshellarg($ip) . ' 2>NUL');
            return preg_match('/^Name:\s+(\S+)/mi', $o, $m) ? rtrim($m[1], '.') : null;
        }
        if (self::has('host')) {
            $o = (string) shell_exec('host -W 1 ' . escapeshellarg($ip) . ' 2>/dev/null');
            return preg_match('/pointer\s+(\S+?)\.?\s*$/m', $o, $m) ? $m[1] : null;
        }
        if (self::has('getent')) {
            $o = (string) shell_exec('getent hosts ' . escapeshellarg($ip) . ' 2>/dev/null');
            return preg_match('/^\S+\s+(\S+)/', trim($o), $m) ? $m[1] : null;
        }
        return null;
    }

    /** Best-effort desktop notification, fire and forget */
    public static function notify(string $title, string $msg): void
    {
        if (self::isMac()) {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            $script = 'display notification ' . json_encode($msg, $flags) . ' with title ' . json_encode($title, $flags);
            shell_exec('/usr/bin/osascript -e ' . escapeshellarg($script) . ' >/dev/null 2>&1 &');
        } elseif (self::isWindows()) {
            $q = fn(string $s) => "'" . str_replace("'", "''", $s) . "'";
            $ps = 'Add-Type -AssemblyName System.Windows.Forms,System.Drawing; $n=New-Object System.Windows.Forms.NotifyIcon; '
                . '$n.Icon=[System.Drawing.SystemIcons]::Warning; $n.Visible=$true; '
                . '$n.ShowBalloonTip(6000,' . $q($title) . ',' . $q($msg) . ',[System.Windows.Forms.ToolTipIcon]::Warning); Start-Sleep 7; $n.Dispose()';
            $enc = base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));
            @pclose(@popen('start /B powershell.exe -NoProfile -WindowStyle Hidden -EncodedCommand ' . $enc, 'r'));
        } elseif (self::has('notify-send')) {
            shell_exec('notify-send -a "SecAIQ Watch" ' . escapeshellarg($title) . ' ' . escapeshellarg($msg) . ' >/dev/null 2>&1 &');
        }
    }

    /** True when a supervisor (launchd / systemd / Task Scheduler) started this process and will restart it */
    public static function supervised(): bool
    {
        return getenv('AIWATCH_LAUNCHD') === '1' || getenv('AIWATCH_SERVICE') === '1';
    }

    public static function supervisorName(): string
    {
        return ['mac' => 'launchd', 'linux' => 'systemd', 'windows' => 'Task Scheduler'][self::os()];
    }

    /** Adapt the signature catalog to the current OS (macOS: unchanged) */
    public static function adapt(array $sig): array
    {
        if (self::isMac()) {
            return $sig;
        }
        foreach ($sig['tools'] as $k => $t) {
            $re = $t[2];
            // "/Name.app/" → also match Name.exe (Windows) and Name.AppImage (Linux)
            $re = str_replace('\.app/', '(?:\.app/|\.exe\b|[-_.\d]*\.AppImage\b)', $re);
            // "claude " / "claude<end>" → also "claude.exe"
            $re = str_replace('(\s|$)', '(?:\.exe|\.cmd)?(\s|$)', $re);
            $sig['tools'][$k][2] = $re;
        }
        // The Claude Desktop app and the Claude Code CLI are both "claude(.exe)": tell them apart by install location
        $sig['tools']['claude-desktop'][2] = self::isWindows()
            ? '#/(AnthropicClaude|Programs/Claude|WindowsApps/[^/]*Claude[^/]*)/#i'
            : '#/opt/Claude/|/claude-desktop|Claude-[\d.]+\.AppImage#i';
        $sig['browser_profiles'] = self::browserProfiles();
        $sig['browsers'] = '#/(Google Chrome|Safari|Firefox|Arc|Brave Browser|Microsoft Edge|Opera|Vivaldi)\b|Chrome Helper|com\.apple\.WebKit|/(chrome|chromium|firefox|msedge|brave|opera|vivaldi)(\.exe)?(\s|$)|google-chrome#i';
        if (self::isLinux()) {
            $sig['deny_rules']['keychain'] = ['Read(~/.local/share/keyrings/**)', 'Read(~/.password-store/**)'];
            $sig['deny_rules']['browser'] = ['Read(~/.config/google-chrome/**)', 'Read(~/.config/chromium/**)', 'Read(~/.mozilla/**)'];
            $sig['deny_rules']['messages'] = ['Read(~/.config/Signal/**)', 'Read(~/.thunderbird/**)'];
        } else {
            $sig['deny_rules']['keychain'] = ['Read(~/AppData/Roaming/Microsoft/Credentials/**)', 'Read(~/AppData/Roaming/Microsoft/Protect/**)'];
            $sig['deny_rules']['browser'] = ['Read(~/AppData/Local/Google/Chrome/**)', 'Read(~/AppData/Local/Microsoft/Edge/**)', 'Read(~/AppData/Roaming/Mozilla/**)'];
            $sig['deny_rules']['messages'] = ['Read(~/AppData/Roaming/Signal/**)', 'Read(~/AppData/Roaming/Slack/**)'];
        }
        return $sig;
    }
}
