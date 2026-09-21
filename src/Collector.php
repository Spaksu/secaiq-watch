<?php
/**
 * Collector: reads process / connection / open-file data through Platform (macOS, Linux, Windows) and writes the AI tools'
 * processes, network connections, byte counters and open files to SQLite.
 * Read-only observation: it never modifies or blocks traffic and never sees its content.
 */
require_once __DIR__ . '/Platform.php';

final class Collector
{
    private PDO $db;
    private array $sig;
    private string $home;
    /** @var array<string,array{in:int,out:int}> previous connection counters */
    private array $prev = [];
    private bool $baseline = true;
    /** @var array<string,list<string>> ip => [domain,...] */
    private array $ipMap = [];
    private int $dnsAt = 0;
    private int $invAt = 0;
    private int $tick = 0;
    private array $seenTools = [];
    private array $settings;
    private Permissions $perm;
    private Actions $actions;
    private Usage $usage;
    private string $psRaw = '';
    /** @var array<int,int> pid => root pid of its process tree within the same tool (one root = one "session") */
    private array $rootOf = [];
    private array $sessOut = [];
    private array $sessIn = [];
    /** @var array<int,array{last:int,burst:int,notified:bool}> per-session activity, for the "your turn" notification */
    private array $sessAct = [];
    private int $anomalyAt = 0;
    /** @var array<string,list<array{int,int}>> rolling window of bytes sent per tool: [ts, bytes] */
    private array $upWin = [];
    private array $upAlerted = [];
    private int $lastNotify = 0;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->sig = require dirname(__DIR__) . '/config/signatures.php';
        $this->home = Platform::home();
        $cfg = dirname(__DIR__) . '/config/settings.php';
        $this->settings = is_file($cfg) ? (require $cfg) : [];
        $this->perm = new Permissions($db, $this->sig, $this->settings, $this->home);
        $this->actions = new Actions($db, $this->sig, $this->home);
        $this->usage = new Usage($db, $this->home);
        Actions::ensureSetup();
        $this->setKv('launcher', Platform::supervised() ? 'service' : 'manual');
        foreach ($db->query('SELECT tool FROM tools_seen') as $r) {
            $this->seenTools[$r['tool']] = true;
        }
        // Re-classify files saved before the area catalog existed
        $upd = $db->prepare('UPDATE files SET area=?, sensitive=? WHERE tool=? AND path=?');
        foreach ($db->query('SELECT tool, path, kind FROM files WHERE area IS NULL')->fetchAll() as $f) {
            [$area, $sens] = $this->classifyPath($f['path'], $f['kind']);
            $upd->execute([$area ?? '', $sens, $f['tool'], $f['path']]);
        }
    }

    /** @return array{?string,int} [area, sensitive? (critical/high)] */
    private function classifyPath(string $path, string $kind): array
    {
        $area = $this->perm->areaOf($path) ?? ($kind === 'working dir' ? 'source' : null);
        $sev = $area !== null ? $this->sig['areas'][$area][1] : null;
        return [$area, in_array($sev, ['critical', 'high'], true) ? 1 : 0];
    }

    public function run(int $interval, bool $once): void
    {
        do {
            $t0 = microtime(true);
            try {
                $this->tick();
            } catch (Throwable $e) {
                fwrite(STDERR, '[' . date('H:i:s') . '] error: ' . $e->getMessage() . "\n");
            }
            if ($once) {
                return;
            }
            $sleep = $interval - (microtime(true) - $t0);
            if ($sleep > 0) {
                usleep((int) ($sleep * 1e6));
            }
        } while (true);
    }

    private function tick(): void
    {
        $now = time();
        $this->tick++;
        // "Remove permission" requests from the UI; if applied, re-scan the permissions right away
        if ($this->actions->processQueue()) {
            $cfg = dirname(__DIR__) . '/config/settings.php'; // settings may have changed: reload
            $this->settings = is_file($cfg) ? (require $cfg) : [];
            $this->perm = new Permissions($this->db, $this->sig, $this->settings, $this->home);
            $this->invAt = 0;
            if ($this->actions->restartRequested) {
                fwrite(STDERR, '[' . date('H:i:s') . '] restart requested from the UI — exiting for the service manager (' . Platform::supervisorName() . ") to relaunch\n");
                exit(0);
            }
        }
        if ($now - $this->dnsAt > 900) {
            $this->refreshDns();
            $this->dnsAt = $now;
        }

        $procs = $this->ps();
        $toolOf = $this->classify($procs);
        // Diagnostics: how many processes ps returned / how many were attributed to an AI tool
        $this->setKv('diag', json_encode([
            'ts' => $now, 'procs' => count($procs), 'ai_procs' => count(array_filter($toolOf)),
            'ps_sample' => count($procs) < 50 ? mb_substr($this->psRaw, 0, 300) : '',
        ]));

        $this->db->beginTransaction();
        $this->saveTools($procs, $toolOf, $now);
        $this->saveConnections($procs, $toolOf, $now);
        $this->updateSessions($procs, $toolOf, $now);
        $this->db->commit();

        // The file scan is expensive: about every 3rd tick
        if ($this->tick % 3 === 1) {
            $this->scanFiles($toolOf, $now);
        }
        if ($now - $this->anomalyAt >= 300) { // every 5 minutes
            $this->anomalyAt = $now;
            $this->checkAnomalies($now);
        }
        // Token usage (opt-in): about once a minute, incremental
        if (!empty($this->settings['scan_usage']) && $this->tick % 20 === 2) {
            [$files, $msgs] = $this->usage->scan($now);
            $this->setKv('usage_scan', (string) $now);
            $this->checkDailyBudget($now);
        }
        if ($now - $this->invAt > 300) {
            $this->scanInventory($now);
            $this->invAt = $now;
        }
        $this->setKv('heartbeat', (string) $now);
        $this->baseline = false;
        if ($this->tick % 200 === 0) {
            $this->prune($now);
        }
    }

    // ---------------------------------------------------------------- processes

    /** @return array<int,array{ppid:int,cpu:float,rss:int,etime:string,name:string,cmd:string}> */
    private function ps(): array
    {
        [$procs, $raw] = Platform::procs();
        $this->psRaw = $raw;
        return $procs;
    }

    /** @return array<int,string|null> pid => tool key */
    private function classify(array $procs): array
    {
        // Exclude our own process and its children (ps/nettop/lsof)
        $self = getmypid();
        $skip = [$self => true];
        foreach ($procs as $pid => $p) {
            for ($x = $pid, $i = 0; $x > 1 && $i < 8; $i++, $x = $procs[$x]['ppid'] ?? 0) {
                if ($x === $self) {
                    $skip[$pid] = true;
                    break;
                }
            }
        }

        // (processes whose command line contains our own folder are the collector/panel themselves, wherever it is installed)
        $direct = [];
        foreach ($procs as $pid => $p) {
            if (isset($skip[$pid]) || str_contains($p['cmd'], dirname(__DIR__))) {
                continue;
            }
            foreach ($this->sig['tools'] as $key => [, , $re]) {
                if (preg_match($re, $p['cmd'])) {
                    $direct[$pid] = $key;
                    break;
                }
            }
        }
        // Inherit from ancestors: child processes started by an AI tool are attributed to that tool
        $toolOf = [];
        foreach ($procs as $pid => $p) {
            if (isset($skip[$pid])) {
                $toolOf[$pid] = null;
                continue;
            }
            $x = $pid;
            $found = null;
            for ($i = 0; $i < 8 && $x > 1; $i++) {
                if (isset($direct[$x])) {
                    $found = $direct[$x];
                    break;
                }
                $x = $procs[$x]['ppid'] ?? 0;
            }
            $toolOf[$pid] = $found;
        }
        // Session roots: walk up while the parent belongs to the same tool
        $this->rootOf = [];
        foreach ($toolOf as $pid => $t) {
            if ($t === null) {
                continue;
            }
            $x = $pid;
            for ($i = 0; $i < 12; $i++) {
                $pp = $procs[$x]['ppid'] ?? 0;
                if ($pp > 1 && ($toolOf[$pp] ?? null) === $t) {
                    $x = $pp;
                } else {
                    break;
                }
            }
            $this->rootOf[$pid] = $x;
        }
        return $toolOf;
    }

    private function saveTools(array $procs, array $toolOf, int $now): void
    {
        $agg = [];
        foreach ($toolOf as $pid => $tool) {
            if ($tool === null) {
                continue;
            }
            $a = &$agg[$tool];
            $a['procs'] = ($a['procs'] ?? 0) + 1;
            $a['cpu'] = ($a['cpu'] ?? 0) + $procs[$pid]['cpu'];
            $a['rss'] = ($a['rss'] ?? 0) + $procs[$pid]['rss'];
            unset($a);
        }
        $this->db->exec('DELETE FROM tool_live');
        $this->db->exec('DELETE FROM procs_live');
        $insP = $this->db->prepare('INSERT INTO procs_live VALUES(?,?,?,?,?,?)');
        $perTool = [];
        foreach ($toolOf as $pid => $tool) {
            if ($tool !== null && ($perTool[$tool] = ($perTool[$tool] ?? 0) + 1) <= 40) {
                $p = $procs[$pid];
                $insP->execute([$tool, $pid, $p['name'], $p['cpu'], $p['rss'], $p['etime']]);
            }
        }
        $ins = $this->db->prepare('INSERT INTO tool_live(tool,procs,cpu,rss,ts) VALUES(?,?,?,?,?)');
        $up = $this->db->prepare(
            'INSERT INTO tools_seen(tool,name,category,first_seen,last_seen) VALUES(?,?,?,?,?)
             ON CONFLICT(tool) DO UPDATE SET name=excluded.name, category=excluded.category, last_seen=excluded.last_seen'
        );
        foreach ($agg as $tool => $a) {
            $ins->execute([$tool, $a['procs'], round($a['cpu'], 1), $a['rss'], $now]);
            [$name, $cat] = $this->sig['tools'][$tool];
            $up->execute([$tool, $name, $cat, $now, $now]);
            if (!isset($this->seenTools[$tool])) {
                $this->seenTools[$tool] = true;
                $this->event('info', $tool, "New AI tool detected: $name");
            }
        }
    }

    // ------------------------------------------------------------- connections

    private function saveConnections(array $procs, array $toolOf, int $now): void
    {
        $rows = Platform::connections();
        $minute = intdiv($now, 60) * 60;
        $this->db->exec('DELETE FROM live_conns');

        $insLive = $this->db->prepare('INSERT INTO live_conns VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $upTraffic = $this->db->prepare(
            'INSERT INTO traffic(minute,tool,provider,bin,bout) VALUES(?,?,?,?,?)
             ON CONFLICT(minute,tool,provider) DO UPDATE SET bin=bin+excluded.bin, bout=bout+excluded.bout'
        );
        $selDest = $this->db->prepare('SELECT 1 FROM dest WHERE tool=? AND rip=? AND rport=?');
        $insDest = $this->db->prepare('INSERT INTO dest VALUES(?,?,?,?,?,?,?,?,?,?)');
        $updDest = $this->db->prepare(
            'UPDATE dest SET last_seen=?, bin=bin+?, bout=bout+?, host=COALESCE(?,host), provider=? WHERE tool=? AND rip=? AND rport=?'
        );

        $newKeys = [];
        $sentNow = [];
        $this->sessOut = [];
        $this->sessIn = [];
        $rdnsBudget = Platform::rdnsBudget();
        foreach ($rows as $c) {
            $pid = $c['pid'];
            $tool = $toolOf[$pid] ?? null;
            $proc = $procs[$pid]['name'] ?? $c['pname'];
            $rip = $c['rip'];
            if ($rip === '' || $rip === '*') {
                continue;
            }
            $local = $this->isLoopback($rip);
            $provider = $this->providerFor($rip, $c['rport'], $local);

            if ($tool === null) {
                // Non-AI process: only count it as "likely" if it connects to a known AI provider IP
                if ($provider === null) {
                    continue;
                }
                $shared = $this->sig['providers'][$provider]['shared'] ?? false;
                $isBrowser = (bool) preg_match($this->sig['browsers'], $procs[$pid]['cmd'] ?? $proc);
                $isSystem = (bool) preg_match('#^/(System|usr/(libexec|sbin))/#', $procs[$pid]['cmd'] ?? '');
                if ($shared && ($isBrowser || $isSystem)) {
                    continue;
                }
                $toolKey = 'other:' . $proc;
                $conf = 'likely';
            } else {
                $toolKey = $tool;
                $conf = 'certain';
            }

            $host = $this->hostFor($rip, $rdnsBudget);
            if ($provider === null) {
                $provider = $local ? 'Local' : 'Other';
            }

            $key = $pid . '|' . $c['proto'] . '|' . $c['lkey'] . '|' . $rip . ':' . $c['rport'];
            $p = $this->prev[$key] ?? null;
            if ($p === null) {
                $dIn = $this->baseline ? 0 : $c['in'];
                $dOut = $this->baseline ? 0 : $c['out'];
            } else {
                $dIn = $c['in'] >= $p['in'] ? $c['in'] - $p['in'] : $c['in'];
                $dOut = $c['out'] >= $p['out'] ? $c['out'] - $p['out'] : $c['out'];
            }
            $newKeys[$key] = ['in' => $c['in'], 'out' => $c['out']];
            $sentNow[$toolKey] = ($sentNow[$toolKey] ?? 0) + $dOut;
            if ($tool !== null) {
                $root = $this->rootOf[$pid] ?? $pid;
                $this->sessOut[$root] = ($this->sessOut[$root] ?? 0) + $dOut;
                $this->sessIn[$root] = ($this->sessIn[$root] ?? 0) + $dIn;
            }

            $insLive->execute([$toolKey, $pid, $proc, $c['proto'], $rip, $c['rport'], $host, $provider, $conf, $c['in'], $c['out']]);
            if ($dIn || $dOut) {
                $upTraffic->execute([$minute, $toolKey, $provider, $dIn, $dOut]);
            }
            $selDest->execute([$toolKey, $rip, $c['rport']]);
            if ($selDest->fetchColumn()) {
                $updDest->execute([$now, $dIn, $dOut, $host, $provider, $toolKey, $rip, $c['rport']]);
            } else {
                // A new IP of an already-known host (or provider+port when there is no host name) is not a new destination
                $seenBefore = $host
                    ? $this->db->prepare('SELECT 1 FROM dest WHERE tool=? AND host=? LIMIT 1')
                    : $this->db->prepare('SELECT 1 FROM dest WHERE tool=? AND provider=? AND rport=? LIMIT 1');
                $seenBefore->execute($host ? [$toolKey, $host] : [$toolKey, $provider, $c['rport']]);
                $isNew = !$seenBefore->fetchColumn();
                $insDest->execute([$toolKey, $provider, $host, $rip, $c['rport'], $conf, $now, $now, $dIn, $dOut]);
                if (!$this->baseline && $provider !== 'Local' && $isNew) {
                    $this->event('info', $toolKey, sprintf('New destination: %s → %s (%s:%d)', $this->toolName($toolKey), $provider, $host ?: $rip, $c['rport']));
                }
            }
        }
        $this->prev = $newKeys;
        $this->checkUploadSpikes($sentNow, $now);
    }

    private function isLoopback(string $ip): bool
    {
        return $ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '127.');
    }

    private function providerFor(string $ip, int $port, bool $local): ?string
    {
        if ($local) {
            return $this->sig['local_ports'][$port] ?? null;
        }
        return isset($this->ipMap[$ip]) ? $this->ipMap[$ip]['provider'] : null;
    }

    private function hostFor(string $ip, int &$budget): ?string
    {
        if (isset($this->ipMap[$ip])) {
            return $this->ipMap[$ip]['domain'];
        }
        if ($this->isLoopback($ip)) {
            return 'localhost';
        }
        $st = $this->db->prepare('SELECT host, ts FROM dns_cache WHERE ip=?');
        $st->execute([$ip]);
        $r = $st->fetch();
        if ($r && time() - (int) $r['ts'] < 86400) {
            return $r['host'] ?: null;
        }
        if ($budget <= 0) {
            return null;
        }
        $budget--;
        $host = Platform::rdns($ip);
        $this->db->prepare('INSERT OR REPLACE INTO dns_cache(ip,host,ts) VALUES(?,?,?)')->execute([$ip, $host ?? '', time()]);
        return $host;
    }

    private function refreshDns(): void
    {
        $map = [];
        foreach ($this->sig['providers'] as $prov => $def) {
            foreach ($def['domains'] as $d) {
                $recs = @dns_get_record($d, DNS_A | DNS_AAAA) ?: [];
                foreach ($recs as $r) {
                    $ip = $r['ip'] ?? $r['ipv6'] ?? null;
                    if ($ip) {
                        $map[$ip] ??= ['provider' => $prov, 'domain' => $d];
                    }
                }
            }
        }
        if ($map) {
            $this->ipMap = $map;
        }
    }

    // ------------------------------------------------------------------ dosyalar

    private function scanFiles(array $toolOf, int $now): void
    {
        $pids = array_keys(array_filter($toolOf));
        if (!$pids) {
            return;
        }
        $pids = array_slice($pids, 0, 60);
        $found = [];
        $cwds = [];
        foreach (Platform::openPaths($pids) as ['pid' => $pid, 'fd' => $fd, 'type' => $type, 'path' => $val]) {
            if ($fd === 'cwd' && $type === 'DIR') {
                $cwds[$pid] = $val;
            }
            if (preg_match($this->sig['file_ignore'], $val)) {
                continue;
            }
            $kind = $fd === 'cwd' ? 'working dir' : ($type === 'DIR' ? 'folder' : 'file');
            $found[($toolOf[$pid] ?? '') . "\0" . $val] = $kind;
        }
        $setCwd = $this->db->prepare("UPDATE sessions SET cwd=? WHERE pid=? AND active=1 AND cwd=''");
        foreach ($cwds as $cpid => $dir) {
            if (($this->rootOf[$cpid] ?? null) === $cpid && $dir !== '/') {
                $setCwd->execute([$dir, $cpid]);
            }
        }
        $sel = $this->db->prepare('SELECT 1 FROM files WHERE tool=? AND path=?');
        $selArea = $this->db->prepare('SELECT 1 FROM files WHERE tool=? AND area=? LIMIT 1');
        $ins = $this->db->prepare('INSERT INTO files(tool,path,kind,sensitive,first_seen,last_seen,hits,area) VALUES(?,?,?,?,?,?,1,?)');
        $upd = $this->db->prepare('UPDATE files SET last_seen=?, hits=hits+1 WHERE tool=? AND path=?');
        $this->db->beginTransaction();
        foreach ($found as $k => $kind) {
            [$tool, $path] = explode("\0", $k, 2);
            if ($tool === '') {
                continue;
            }
            [$area, $sens] = $this->classifyPath($path, $kind);
            $sel->execute([$tool, $path]);
            if ($sel->fetchColumn()) {
                $upd->execute([$now, $tool, $path]);
                continue;
            }
            // If this tool touches this area for the first time, raise an event (not one per file)
            $firstInArea = false;
            if ($area !== null && $sens) {
                $selArea->execute([$tool, $area]);
                $firstInArea = !$selArea->fetchColumn();
            }
            $ins->execute([$tool, $path, $kind, $sens, $now, $now, $area ?? '']);
            if ($firstInArea) {
                [$label, $sev] = $this->sig['areas'][$area];
                $this->event($sev === 'critical' ? 'crit' : 'warn', $tool, sprintf(
                    '%s [%s] was seen accessing: %s — %s',
                    $this->toolName($tool), $sev, $label, $this->short($path)
                ));
            }
        }
        $this->db->commit();
    }

    // ------------------------------------------------------------------ envanter

    private function scanInventory(int $now): void
    {
        $prev = [];
        foreach ($this->db->query('SELECT kind, tool, name FROM inventory')->fetchAll() as $r) {
            $prev[$r['kind'] . '|' . $r['tool'] . '|' . $r['name']] = true;
        }
        $this->db->beginTransaction();
        $this->db->exec('DELETE FROM inventory');
        $st = $this->db->prepare('INSERT OR REPLACE INTO inventory(kind,tool,name,detail,seen) VALUES(?,?,?,?,?)');

        // Installed apps
        $apps = ['Claude.app' => 'Claude Desktop', 'ChatGPT.app' => 'ChatGPT', 'Cursor.app' => 'Cursor', 'Windsurf.app' => 'Windsurf',
            'Ollama.app' => 'Ollama', 'LM Studio.app' => 'LM Studio', 'Gemini.app' => 'Gemini', 'Perplexity.app' => 'Perplexity',
            'Comet.app' => 'Comet', 'Jan.app' => 'Jan', 'Msty.app' => 'Msty',
            'Antigravity.app' => 'Google Antigravity', 'Zed.app' => 'Zed', 'Kiro.app' => 'Kiro', 'Warp.app' => 'Warp', 'GPT4All.app' => 'GPT4All',
            'AnythingLLM.app' => 'AnythingLLM', 'Dia.app' => 'Dia', 'ChatGPT Atlas.app' => 'ChatGPT Atlas', 'Goose.app' => 'Goose',
            'Draw Things.app' => 'Draw Things', 'DiffusionBee.app' => 'DiffusionBee'];
        foreach ($apps as $app => $name) {
            foreach (Platform::appCandidates(preg_replace('/\.app$/', '', $app), $this->home) as $path) {
                if (file_exists($path)) {
                    $st->execute(['app', '', $name, $path, $now]);
                }
            }
        }
        // CLI tools
        foreach (['claude', 'codex', 'gemini', 'aider', 'ollama', 'llm', 'copilot', 'opencode', 'goose', 'crush', 'kiro-cli', 'qwen', 'amp', 'llama-server', 'llama-cli', 'lms'] as $bin) {
            $p = Platform::which($bin, $this->home);
            if ($p !== '') {
                $st->execute(['cli', '', $bin, $p, $now]);
            }
        }
        // Config folders (traces the tool leaves locally)
        foreach (['.claude' => 'Claude Code', '.codex' => 'Codex', '.cursor' => 'Cursor', '.gemini' => 'Gemini CLI', '.ollama' => 'Ollama',
            '.continue' => 'Continue', '.aider' => 'Aider', '.opencode' => 'OpenCode', '.config/opencode' => 'OpenCode', '.kiro' => 'Kiro',
            '.codeium' => 'Codeium / Windsurf', '.windsurf' => 'Windsurf', '.config/goose' => 'Goose', '.lmstudio' => 'LM Studio'] as $d => $name) {
            if (is_dir($this->home . '/' . $d)) {
                $st->execute(['config', '', $name, $this->home . '/' . $d, $now]);
            }
        }
        // MCP servers: which tools/services an AI can reach
        $mcp = $this->mcpServers();
        foreach ($mcp as [$tool, $name, $detail]) {
            $st->execute(['mcp', $tool, $name, $detail, $now]);
        }
        // AI browser extensions (manifest only)
        foreach ($this->browserExtensions() as [$name, $detail]) {
            $st->execute(['extension', '', $name, $detail, $now]);
        }
        // Project folders Claude Code works in
        $projects = $this->claudeProjects();
        foreach ($projects as [$path, $mtime]) {
            $st->execute(['project', 'claude-code', $path, date('c', $mtime), $now]);
        }
        // Config audits that feed the Findings tab (kept in the inventory; contents are never stored)
        $pp = array_column($projects, 0);
        foreach ($this->perm->claudeHooks($pp) as $i => [$file, $event, $matcher, $cmd]) {
            $st->execute(['hook', 'claude-code', sprintf('%s [%s] #%d', $event, $matcher, $i + 1), $this->mask(mb_substr(trim($cmd), 0, 300)) . '  ·  ' . $this->short($file), $now]);
        }
        foreach ($this->perm->configSecrets($pp) as $i => [$file, $kind, $key]) {
            $st->execute(['secret', '', sprintf('%s in %s #%d', $kind, basename($file), $i + 1), $this->short($file) . ($key !== '' ? '  ·  key: ' . $key : ''), $now]);
        }
        foreach ($this->perm->instructionIssues($pp) as $i => [$file, $code, $line]) {
            $st->execute(['instr', '', sprintf('%s in %s #%d', $code, basename($file), $i + 1), $this->short($file) . '  ·  line ' . $line, $now]);
        }
        $this->db->commit();
        $this->reportInventoryChanges($prev);

        // Permissions: settings files always; system permissions only if settings.php has scan_system=true
        $this->perm->build($mcp, array_column($projects, 0), array_keys($this->seenTools), $now);
    }

    /** One row per running "session" = the root process of an AI tool's process tree, with the bytes it moved */
    private function updateSessions(array $procs, array $toolOf, int $now): void
    {
        $roots = [];
        foreach ($this->rootOf as $pid => $root) {
            $roots[$root] = $toolOf[$root] ?? $toolOf[$pid] ?? null;
        }
        $sel = $this->db->prepare('SELECT id FROM sessions WHERE pid=? AND tool=? AND active=1');
        $ins = $this->db->prepare('INSERT INTO sessions(tool,pid,name,start_ts,last_seen) VALUES(?,?,?,?,?)');
        $upd = $this->db->prepare('UPDATE sessions SET last_seen=?, bout=bout+?, bin=bin+? WHERE id=?');
        foreach ($roots as $root => $tool) {
            if ($tool === null) {
                continue;
            }
            $sel->execute([$root, $tool]);
            $id = $sel->fetchColumn();
            if (!$id) {
                $ins->execute([$tool, $root, $procs[$root]['name'] ?? '', $now - $this->etimeToSec($procs[$root]['etime'] ?? '0'), $now]);
                $id = $this->db->lastInsertId();
            }
            $upd->execute([$now, (int) ($this->sessOut[$root] ?? 0), (int) ($this->sessIn[$root] ?? 0), $id]);
            $this->watchIdle($root, $tool, (int) ($this->sessOut[$root] ?? 0) + (int) ($this->sessIn[$root] ?? 0), $now);
        }
        // sessions whose root process is gone have ended
        $alive = array_keys(array_filter($roots));
        $end = $this->db->query('SELECT id, pid FROM sessions WHERE active=1')->fetchAll();
        $stop = $this->db->prepare('UPDATE sessions SET active=0 WHERE id=?');
        foreach ($end as $r) {
            if (!in_array((int) $r['pid'], $alive, true)) {
                $stop->execute([$r['id']]);
            }
        }
    }

    /** ps etime "[[dd-]hh:]mm:ss" → seconds */
    private function etimeToSec(string $e): int
    {
        $days = 0;
        if (str_contains($e, '-')) {
            [$d, $e] = explode('-', $e, 2);
            $days = (int) $d;
        }
        $parts = array_map('intval', explode(':', $e));
        $sec = 0;
        foreach ($parts as $p) {
            $sec = $sec * 60 + $p;
        }
        return $days * 86400 + $sec;
    }

    /** Emits "Change:" events for apps / CLIs / MCP servers / extensions that appeared or disappeared since the last scan */
    private function reportInventoryChanges(array $prev): void
    {
        if (!$prev) {
            return; // first scan: nothing to compare with
        }
        $now = [];
        foreach ($this->db->query("SELECT kind, tool, name FROM inventory WHERE kind IN ('app','cli','mcp','extension')")->fetchAll() as $r) {
            $now[$r['kind'] . '|' . $r['tool'] . '|' . $r['name']] = $r;
        }
        $label = ['app' => 'app', 'cli' => 'CLI tool', 'mcp' => 'MCP server', 'extension' => 'AI browser extension'];
        foreach ($now as $k => $r) {
            if (!isset($prev[$k])) {
                $this->event(in_array($r['kind'], ['mcp', 'extension'], true) ? 'warn' : 'info', $r['tool'],
                    sprintf('Change: new %s: %s%s', $label[$r['kind']], $r['name'], $r['tool'] ? ' (' . $this->toolName($r['tool']) . ')' : ''));
            }
        }
        foreach ($prev as $k => $_) {
            [$kind, $tool, $name] = explode('|', $k, 3);
            if (isset($label[$kind]) && !isset($now[$k])) {
                $this->event('info', $tool, sprintf('Change: %s removed: %s', $label[$kind], $name));
            }
        }
    }

    /** Once a day: warn when fresh tokens (input + output + cache writes) exceed the daily limit (settings daily_token_alert_k) */
    private function checkDailyBudget(int $now): void
    {
        $limit = (int) ($this->settings['daily_token_alert_k'] ?? 0) * 1000;
        $day = date('Y-m-d', $now);
        if ($limit <= 0 || $this->kv('budget_day') === $day) {
            return;
        }
        $st = $this->db->prepare('SELECT COALESCE(SUM(tin + tout + cwrite), 0) FROM usage_msgs WHERE day = ?');
        $st->execute([$day]);
        $used = (int) $st->fetchColumn();
        if ($used > $limit) {
            $this->setKv('budget_day', $day);
            $this->event('warn', '', sprintf('Daily token budget exceeded: %s fresh tokens today (limit %s)', number_format($used), number_format($limit)));
        }
    }

    private function kv(string $k): ?string
    {
        $st = $this->db->prepare('SELECT v FROM kv WHERE k=?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /** AI-related browser extensions (Chromium family). Reads each extension's manifest only. @return list<array{string,string}> [name, detail] */
    private function browserExtensions(): array
    {
        $re = $this->sig['ai_extension_regex'];
        $out = [];
        foreach ($this->sig['browser_profiles'] as $browser => $rel) {
            foreach (glob($this->home . '/' . $rel . '/*/Extensions/*', GLOB_ONLYDIR) ?: [] as $extDir) {
                $versions = glob($extDir . '/*', GLOB_ONLYDIR) ?: [];
                $vdir = $versions ? end($versions) : null;
                $mf = $vdir ? $vdir . '/manifest.json' : null;
                $m = $mf && is_readable($mf) ? json_decode((string) file_get_contents($mf), true) : null;
                if (!is_array($m)) {
                    continue;
                }
                $name = (string) ($m['name'] ?? basename($extDir));
                if (preg_match('/^__MSG_(.+)__$/', $name, $mm)) {
                    foreach (['en', 'en_US', 'en_GB'] as $loc) {
                        $msgs = @json_decode((string) @file_get_contents("$vdir/_locales/$loc/messages.json"), true);
                        if (is_array($msgs)) {
                            foreach ($msgs as $key => $v) {
                                if (strcasecmp($key, $mm[1]) === 0 && isset($v['message'])) {
                                    $name = (string) $v['message'];
                                    break 2;
                                }
                            }
                        }
                    }
                }
                $desc = (string) ($m['description'] ?? '');
                if (!preg_match($re, $name . ' ' . $desc)) {
                    continue;
                }
                $hosts = array_merge((array) ($m['host_permissions'] ?? []), array_filter((array) ($m['permissions'] ?? []), fn($p) => is_string($p) && str_contains($p, '://') || $p === '<all_urls>'));
                foreach ((array) ($m['content_scripts'] ?? []) as $cs) {
                    $hosts = array_merge($hosts, (array) ($cs['matches'] ?? []));
                }
                $broad = (bool) array_filter($hosts, fn($h) => is_string($h) && in_array($h, ['<all_urls>', '*://*/*', 'http://*/*', 'https://*/*'], true));
                $perms = array_values(array_filter((array) ($m['permissions'] ?? []), fn($p) => is_string($p) && !str_contains($p, '://')));
                $out[] = [$name . ' (' . $browser . ')', ($broad ? 'can read all sites' : 'limited site access') . ' · permissions: ' . ($perms ? implode(', ', array_slice($perms, 0, 8)) : 'none')];
            }
        }
        return $out;
    }

    /** @return list<array{string,string,string}> [tool, name, "command arguments"] — env VALUES are never read */
    private function mcpServers(): array
    {
        $h = $this->home;
        $as = Platform::appSupport($h);
        $files = [
            'claude-code'    => [$h . '/.claude.json', $h . '/.claude/settings.json'],
            'claude-desktop' => [$as . '/Claude/claude_desktop_config.json'],
            'cursor'         => [$h . '/.cursor/mcp.json'],
            'gemini-cli'     => [$h . '/.gemini/settings.json'],
            'windsurf'       => [$h . '/.codeium/windsurf/mcp_config.json'],
            'copilot'        => [$as . '/Code/User/mcp.json'],
            'opencode'       => [$h . '/.config/opencode/opencode.json'],
            'kiro'           => [$h . '/.kiro/settings/mcp.json'],
        ];
        $res = [];
        foreach ($files as $tool => $list) {
            foreach ($list as $f) {
                $j = is_readable($f) ? json_decode((string) file_get_contents($f), true) : null;
                if (!is_array($j)) {
                    continue;
                }
                // shapes: mcpServers (Claude/Cursor/Gemini/Windsurf/Kiro), servers (VS Code), mcp (OpenCode)
                $sets = [$j['mcpServers'] ?? [], $j['servers'] ?? [], is_array($j['mcp'] ?? null) ? $j['mcp'] : []];
                foreach (($j['projects'] ?? []) as $proj) {
                    $sets[] = $proj['mcpServers'] ?? [];
                }
                foreach ($sets as $set) {
                    foreach ((array) $set as $name => $def) {
                        $c = is_array($def) ? ($def['command'] ?? $def['url'] ?? '') : '';
                        $c = is_array($c) ? implode(' ', $c) : (string) $c; // OpenCode uses a command array
                        $cmd = is_array($def) ? ($c . ' ' . implode(' ', (array) ($def['args'] ?? []))) : '';
                        $res[] = [$tool, (string) $name, $this->mask(trim($cmd))];
                    }
                }
            }
        }
        $toml = $h . '/.codex/config.toml';
        if (is_readable($toml) && preg_match_all('/^\[mcp_servers\.([^\].]+)\]/m', (string) file_get_contents($toml), $m)) {
            foreach ($m[1] as $name) {
                $res[] = ['codex', trim($name, '"'), 'config.toml'];
            }
        }
        return $res;
    }

    /** @return list<array{string,int}> */
    private function claudeProjects(): array
    {
        $dir = $this->home . '/.claude/projects';
        $out = [];
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $newest = 0;
            $newestFile = null;
            foreach (glob($d . '/*.jsonl') ?: [] as $f) {
                $mt = (int) filemtime($f);
                if ($mt > $newest) {
                    $newest = $mt;
                    $newestFile = $f;
                }
            }
            if (!$newestFile) {
                continue;
            }
            $path = null;
            $fh = fopen($newestFile, 'r');
            if ($fh) {
                $head = (string) fread($fh, 16384);
                fclose($fh);
                if (preg_match('/"cwd":"((?:[^"\\\\]|\\\\.)*)"/', $head, $m)) {
                    $path = stripcslashes($m[1]);
                }
            }
            $out[] = [$path ?? basename($d), $newest];
        }
        usort($out, fn($a, $b) => $b[1] <=> $a[1]);
        return array_slice($out, 0, 40);
    }

    /** Mask long key/token-like values */
    private function mask(string $s): string
    {
        return (string) preg_replace('/\b[A-Za-z0-9_\-]{28,}\b/', '***', $s);
    }

    // -------------------------------------------------------------------- helpers

    private function toolName(string $key): string
    {
        return $this->sig['tools'][$key][0] ?? (str_starts_with($key, 'other:') ? substr($key, 6) : $key);
    }

    private function short(string $p): string
    {
        return $this->home !== '' && str_starts_with($p, $this->home) ? '~' . substr($p, strlen($this->home)) : $p;
    }

    private function event(string $level, string $tool, string $msg): void
    {
        $this->db->prepare('INSERT INTO events(ts,level,tool,msg) VALUES(?,?,?,?)')->execute([time(), $level, $tool, $msg]);
        if ($level === 'crit') {
            $this->notify('SecAIQ Watch — critical access', $msg);
        }
    }

    /** Best-effort desktop notification (Platform::notify) (settings: notify_critical), rate-limited to one per 10 s */
    private function notify(string $title, string $msg, string $setting = 'notify_critical'): void
    {
        if (empty($this->settings[$setting]) || time() - $this->lastNotify < 10) {
            return;
        }
        $this->lastNotify = time();
        Platform::notify($title, mb_substr($msg, 0, 200));
    }

    /** "Your turn": a coding agent moved a real burst of data, then went quiet for 30 s */
    private function watchIdle(int $root, string $tool, int $bytes, int $now): void
    {
        if (($this->sig['tools'][$tool][1] ?? '') !== 'Coding agent' || $this->baseline) {
            return;
        }
        $a = &$this->sessAct[$root];
        $a ??= ['last' => $now, 'burst' => 0, 'notified' => true];
        if ($bytes > 2048) {
            $a['last'] = $now;
            $a['burst'] += $bytes;
            $a['notified'] = false;
        } elseif (!$a['notified'] && $a['burst'] >= 50000 && $now - $a['last'] >= 30) {
            $a['notified'] = true;
            $a['burst'] = 0;
            $cwd = (string) $this->db->query('SELECT cwd FROM sessions WHERE pid=' . $root . ' AND active=1 ORDER BY id DESC LIMIT 1')->fetchColumn();
            $msg = sprintf('Your turn: %s%s looks idle — waiting for you', $this->toolName($tool), $cwd !== '' ? ' (' . basename($cwd) . ')' : '');
            $this->event('info', $tool, $msg);
            $this->notify('SecAIQ Watch', $msg, 'notify_idle');
        }
    }

    /** Baseline anomaly: the last complete 10-minute window vs the tool's own 14-day history (90th percentile of active windows) */
    private function checkAnomalies(int $now): void
    {
        if (($this->settings['anomaly_alerts'] ?? true) === false) {
            return;
        }
        $win = 600;
        $cur = intdiv($now, $win) * $win - $win;
        $sumCur = $this->db->prepare('SELECT COALESCE(SUM(bout),0) FROM traffic WHERE tool=? AND minute >= ? AND minute < ?');
        $hist = $this->db->prepare('SELECT SUM(bout) s FROM traffic WHERE tool=? AND minute >= ? AND minute < ? GROUP BY minute / ' . $win . ' HAVING s > 0');
        $tools = $this->db->prepare("SELECT DISTINCT tool FROM traffic WHERE minute >= ? AND minute < ? AND tool NOT LIKE 'other:%'");
        $tools->execute([$cur, $cur + $win]);
        foreach ($tools->fetchAll(PDO::FETCH_COLUMN) as $tool) {
            $sumCur->execute([$tool, $cur, $cur + $win]);
            $now10 = (int) $sumCur->fetchColumn();
            if ($now10 < 5 * 1048576) {
                continue; // too small to matter
            }
            $hist->execute([$tool, $now - 14 * 86400, $cur]);
            $vals = array_map('intval', $hist->fetchAll(PDO::FETCH_COLUMN));
            if (count($vals) < 30) {
                continue; // not enough history to call anything "unusual"
            }
            sort($vals);
            $p90 = $vals[(int) floor(0.9 * (count($vals) - 1))];
            $last = (int) ($this->kv('anom_' . $tool) ?? 0);
            if ($now10 > max(3 * $p90, 5 * 1048576) && $now - $last > 3600) {
                $this->setKv('anom_' . $tool, (string) $now);
                $this->event('warn', $tool, sprintf('Unusual upload: %s sent %s in 10 minutes (its usual peak is %s)', $this->toolName($tool), $this->fmtBytes($now10), $this->fmtBytes($p90)));
            }
        }
    }

    /** Upload spike alert: bytes sent per tool in a rolling 5-minute window (settings: upload_alert_mb, 0 = off) */
    private function checkUploadSpikes(array $sentNow, int $now): void
    {
        $limit = (int) ($this->settings['upload_alert_mb'] ?? 100) * 1048576;
        if (!$this->baseline) {
            foreach ($sentNow as $tool => $b) {
                if ($b > 0) {
                    $this->upWin[$tool][] = [$now, $b];
                }
            }
        }
        foreach ($this->upWin as $tool => $w) {
            $w = array_values(array_filter($w, fn($x) => $x[0] > $now - 300));
            if (!$w) {
                unset($this->upWin[$tool]);
                continue;
            }
            $this->upWin[$tool] = $w;
            $sum = array_sum(array_column($w, 1));
            if ($limit > 0 && $sum > $limit && $now - ($this->upAlerted[$tool] ?? 0) > 1800) {
                $this->upAlerted[$tool] = $now;
                $this->event('warn', $tool, sprintf('High upload: %s sent %s in the last 5 minutes (threshold %d MB)',
                    $this->toolName($tool), $this->fmtBytes($sum), intdiv($limit, 1048576)));
            }
        }
    }

    private function fmtBytes(int $n): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $u) {
            if ($n < 1024 || $u === 'GB') {
                return ($i ? number_format($n, 1) : $n) . ' ' . $u;
            }
            $n /= 1024;
        }
        return (string) $n;
    }

    private function setKv(string $k, string $v): void
    {
        $this->db->prepare('INSERT OR REPLACE INTO kv(k,v) VALUES(?,?)')->execute([$k, $v]);
    }

    private function prune(int $now): void
    {
        $this->usage->prune($now);
        $this->db->prepare('DELETE FROM sessions WHERE last_seen < ?')->execute([$now - 30 * 86400]);
        $this->db->prepare('DELETE FROM traffic WHERE minute < ?')->execute([$now - 30 * 86400]);
        $this->db->prepare('DELETE FROM events WHERE ts < ?')->execute([$now - 30 * 86400]);
        $this->db->prepare('DELETE FROM files WHERE last_seen < ?')->execute([$now - 30 * 86400]);
        $this->db->prepare('DELETE FROM dns_cache WHERE ts < ?')->execute([$now - 7 * 86400]);
        $this->db->prepare('DELETE FROM dest WHERE last_seen < ?')->execute([$now - 90 * 86400]);
        $this->db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $this->rotateLogs();
    }

    /** var/*.log are appended by the service manager forever: keep the last ~2 MB when one passes 5 MB */
    private function rotateLogs(): void
    {
        foreach (['collector', 'panel'] as $n) {
            $f = dirname(__DIR__) . "/var/$n.log";
            if (is_file($f) && filesize($f) > 5 * 1024 * 1024) {
                $tail = (string) file_get_contents($f, false, null, filesize($f) - 2 * 1024 * 1024);
                @file_put_contents($f, "[log rotated]\n" . substr($tail, (int) strpos($tail, "\n") + 1));
            }
        }
    }
}
