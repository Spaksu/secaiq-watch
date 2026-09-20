<?php
/**
 * Permission collector: the sources for "which AI is authorized for which critical area?".
 *
 * Levels:  granted · inherited (from the terminal) · ask (asks every time)
 *             denied (explicitly forbidden) · possible (theoretical: runs as the same user)
 * The "observed" level is not here; it comes from the files table.
 *
 * Always: Claude Code / Codex settings files and MCP configuration.
 * Only if settings.php has scan_system=true: macOS TCC permissions + existence checks of critical directories.
 * File contents are never read.
 *
 * Each permission row can carry a machine-readable "meta" for removal actions (see Actions).
 */
require_once __DIR__ . '/Platform.php';

final class Permissions
{
    /** @var list<array{string,string,string,string,string,?array}> */
    private array $g = [];

    public function __construct(
        private PDO $db,
        private array $sig,
        private array $settings,
        private string $home,
    ) {
    }

    /**
     * @param list<array{string,string,string}> $mcp   [tool, name, command]
     * @param list<string>                       $projectPaths projects Claude Code works in
     * @param list<string>                       $seenTools
     */
    public function build(array $mcp, array $projectPaths, array $seenTools, int $now): void
    {
        $this->g = [];
        $this->fromMcp($mcp);
        $this->fromClaude($projectPaths);
        $this->fromCodex();
        if (!empty($this->settings['scan_system'])) {
            if (Platform::isMac()) {
                $this->fromTcc();
            } else {
                $this->setKv('tcc_status', 'na'); // no TCC outside macOS
            }
            $this->fromPossible($seenTools);
        } else {
            $this->setKv('tcc_status', 'off');
        }

        $prev = [];
        foreach ($this->db->query("SELECT tool, area, level FROM grants WHERE level IN ('granted','inherited','denied')")->fetchAll() as $r) {
            $prev[$r['tool'] . '|' . $r['area'] . '|' . $r['level']] = $r;
        }
        $this->db->beginTransaction();
        $this->db->exec('DELETE FROM grants');
        $st = $this->db->prepare('INSERT OR REPLACE INTO grants(tool,area,level,source,detail,seen,meta) VALUES(?,?,?,?,?,?,?)');
        foreach ($this->g as [$tool, $area, $level, $source, $detail, $meta]) {
            $st->execute([$tool, $area, $level, $source, $detail, $now, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
        }
        $this->db->commit();
        $this->reportChanges($prev, $now);
    }

    /** "Change:" events when a granted / inherited / denied permission appears or disappears (skipped on the first scan) */
    private function reportChanges(array $prev, int $now): void
    {
        if (!$prev) {
            return;
        }
        $cur = [];
        foreach ($this->g as [$tool, $area, $level]) {
            if (in_array($level, ['granted', 'inherited', 'denied'], true)) {
                $cur[$tool . '|' . $area . '|' . $level] = [$tool, $area, $level];
            }
        }
        $ev = $this->db->prepare('INSERT INTO events(ts,level,tool,msg) VALUES(?,?,?,?)');
        $name = fn(string $t) => $this->sig['tools'][$t][0] ?? $t;
        $lab = fn(string $a) => $this->sig['areas'][$a][0] ?? $a;
        foreach ($cur as $k => [$tool, $area, $level]) {
            if (!isset($prev[$k])) {
                $risky = in_array($this->sig['areas'][$area][1] ?? '', ['critical', 'high'], true) && $level !== 'denied';
                $ev->execute([$now, $risky ? 'warn' : 'info', $tool, sprintf('Change: %s permission %s: %s → %s', $level, 'added', $name($tool), $lab($area))]);
            }
        }
        foreach ($prev as $k => $r) {
            if (!isset($cur[$k])) {
                $ev->execute([$now, 'info', $r['tool'], sprintf('Change: %s permission removed: %s → %s', $r['level'], $name($r['tool']), $lab($r['area']))]);
            }
        }
    }

    private function add(string $tool, string $area, string $level, string $source, string $detail, ?array $meta = null): void
    {
        $this->g[] = [$tool, $area, $level, $source, mb_substr($detail, 0, 400), $meta];
    }

    /** Classifies a path/pattern text using the area catalog (text matching only) */
    public function areaOf(string $path): ?string
    {
        foreach ($this->sig['areas'] as $k => $a) {
            if ($a[3] !== null && preg_match($a[3], $path)) {
                return $k;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ MCP

    private function fromMcp(array $mcp): void
    {
        $by = [];
        foreach ($mcp as [$tool, $name]) {
            $by[$tool][$name] = $name;
        }
        foreach ($by as $tool => $names) {
            $names = array_values($names);
            $this->add($tool, 'mcp', 'granted', 'MCP configuration', count($names) . ' server(s): ' . implode(', ', array_slice($names, 0, 8)));
        }
    }

    // ---------------------------------------------------------- Claude Code

    private function fromClaude(array $projectPaths): void
    {
        $files = [$this->home . '/.claude/settings.json', $this->home . '/.claude/settings.local.json'];
        foreach ($projectPaths as $p) {
            if (str_starts_with($p, '/')) {
                $files[] = $p . '/.claude/settings.json';
                $files[] = $p . '/.claude/settings.local.json';
            }
        }
        $rules = ['allow' => [], 'deny' => []]; // kind => [rule => [dosyalar]]
        $mode = null;
        $bypassFiles = [];
        foreach (array_unique($files) as $f) {
            $j = is_readable($f) ? json_decode((string) file_get_contents($f), true) : null;
            if (!is_array($j)) {
                continue;
            }
            $perm = $j['permissions'] ?? [];
            foreach (['allow', 'deny'] as $kind) {
                foreach ((array) ($perm[$kind] ?? []) as $r) {
                    if (is_string($r)) {
                        $rules[$kind][$r][] = $f;
                    }
                }
            }
            $m = $perm['defaultMode'] ?? $j['defaultMode'] ?? null;
            if (is_string($m)) {
                $mode = $m;
            }
            if ($m === 'bypassPermissions' || ($j['skipDangerousModePermissionPrompt'] ?? $j['skipDangerousModePermissionsPrompt'] ?? false) === true) {
                $bypassFiles[] = $f;
            }
        }

        // area => level => list<[rule, file]>
        $agg = [];
        foreach ($rules as $kind => $list) {
            $level = $kind === 'allow' ? 'granted' : 'denied';
            foreach ($list as $rule => $fs) {
                if (!preg_match('/^([A-Za-z_][\w]*)(?:\((.*)\))?$/s', $rule, $m)) {
                    continue;
                }
                $tool = $m[1];
                $arg = $m[2] ?? '';
                if ($tool === 'Bash') {
                    $area = 'shell';
                } elseif (str_starts_with($tool, 'mcp__')) {
                    $area = 'mcp';
                } elseif (in_array($tool, ['Read', 'Edit', 'Write', 'MultiEdit', 'Glob', 'Grep', 'NotebookEdit'], true)) {
                    $area = $this->areaOf($arg) ?? ($arg === '' || $arg === '**' || $arg === '//**' ? 'fulldisk' : 'source');
                } else {
                    continue;
                }
                foreach ($fs as $f) {
                    $agg[$area][$level][] = [$rule, $f];
                }
            }
        }
        foreach ($agg as $area => $levels) {
            foreach ($levels as $level => $list) {
                $names = array_values(array_unique(array_column($list, 0)));
                $meta = $level === 'granted'
                    ? ['claude_rules' => array_map(fn($x) => ['rule' => $x[0], 'file' => $x[1]], $list)]
                    : ['claude_deny' => array_values(array_unique(array_column($list, 0)))]; // lets "Protect" know which presets are fully covered
                $this->add('claude-code', $area, $level, 'Claude Code settings', implode(' · ', array_slice($names, 0, 6)) . (count($names) > 6 ? ' …(+' . (count($names) - 6) . ')' : ''), $meta);
            }
        }
        if ($bypassFiles) {
            $meta = ['claude_bypass_files' => array_values(array_unique($bypassFiles))];
            $this->add('claude-code', 'shell', 'granted', 'Claude Code settings (bypass)', 'PERMISSIONS BYPASSED (bypassPermissions) — commands run without approval', $meta);
            $this->add('claude-code', 'fulldisk', 'granted', 'Claude Code settings (bypass)', 'bypassPermissions: file access without approval', $meta);
        }
        if (!$bypassFiles && !isset($agg['shell']['granted'])) {
            $this->add('claude-code', 'shell', 'ask', 'Claude Code default', 'asks for approval before running commands' . ($mode ? " (mode: $mode)" : ''));
        }
    }

    // ---------------------------------------------------------------- Codex

    private function fromCodex(): void
    {
        $f = $this->home . '/.codex/config.toml';
        if (!is_readable($f)) {
            return;
        }
        $t = (string) file_get_contents($f);
        $sandbox = preg_match('/^\s*sandbox_mode\s*=\s*"([^"]+)"/m', $t, $m) ? $m[1] : null;
        $approval = preg_match('/^\s*approval_policy\s*=\s*"([^"]+)"/m', $t, $m) ? $m[1] : null;
        preg_match_all('/^\[projects\."([^"]+)"\]\s*\n(?:[^\[]*?)trust_level\s*=\s*"trusted"/m', $t, $tp);
        $harden = $sandbox === 'danger-full-access' || $approval === 'never';

        foreach (['codex', 'chatgpt'] as $tool) {
            $src = 'Codex config.toml';
            $hm = $harden ? ['codex_harden' => true] : null;
            if ($sandbox === 'danger-full-access') {
                $this->add($tool, 'shell', 'granted', $src, 'sandbox_mode=danger-full-access' . ($approval ? ", approval_policy=$approval" : ''), $hm);
                $this->add($tool, 'fulldisk', 'granted', $src, 'sandbox off: entire file system', $hm);
            } elseif ($sandbox === 'workspace-write') {
                $this->add($tool, 'shell', 'granted', $src, 'sandbox: writes only inside the workspace' . ($approval ? ", approval_policy=$approval" : ''), $hm);
                $this->add($tool, 'source', 'granted', $src, 'workspace-write');
            } else {
                $lvl = $approval === 'never' ? 'granted' : 'ask';
                $this->add($tool, 'shell', $lvl, $src, ($sandbox ? "sandbox_mode=$sandbox" : 'default sandbox') . ($approval ? ", approval_policy=$approval" : ''), $hm);
            }
            if (!empty($tp[1])) {
                $this->add($tool, 'source', 'granted', $src . ' (trusted projects)', 'trusted projects: ' . implode(', ', array_map([$this, 'short'], array_slice($tp[1], 0, 5))), ['codex_trust' => array_values($tp[1])]);
            }
        }
    }

    // ------------------------------------------------ opt-in: sistem izinleri

    private function fromTcc(): void
    {
        $dbs = [
            $this->home . '/Library/Application Support/com.apple.TCC/TCC.db',
            '/Library/Application Support/com.apple.TCC/TCC.db',
        ];
        $ok = false;
        $denied = false;
        foreach ($dbs as $path) {
            if (!file_exists($path)) {
                continue;
            }
            $cmd = 'sqlite3 -readonly -separator "|" ' . escapeshellarg($path)
                . ' ' . escapeshellarg('SELECT service, client, auth_value FROM access WHERE auth_value = 2') . ' 2>&1';
            $out = (string) shell_exec($cmd);
            if (stripos($out, 'authorization denied') !== false || stripos($out, 'not permitted') !== false || stripos($out, 'unable to open') !== false) {
                $denied = true;
                continue;
            }
            $ok = true;
            foreach (explode("\n", trim($out)) as $line) {
                $p = explode('|', $line);
                if (count($p) < 3) {
                    continue;
                }
                [$service, $client] = $p;
                $area = $this->sig['tcc_services'][$service] ?? null;
                if ($area === null) {
                    continue;
                }
                $meta = ['tcc' => ['service' => $service, 'client' => $client]];
                $tool = $this->toolForClient($client);
                if ($tool !== null) {
                    $this->add($tool, $area, 'granted', 'macOS permissions (TCC): ' . $client, $client, $meta);
                } elseif (preg_match($this->sig['tcc_hosts'], $client)) {
                    foreach ($this->sig['cli_tools'] as $cli) {
                        $this->add($cli, $area, 'inherited', 'macOS permissions (TCC): ' . $client, "terminal/editor permission is inherited: $client", $meta);
                    }
                }
            }
        }
        $this->setKv('tcc_status', $ok ? 'ok' : ($denied ? 'denied' : 'missing'));
    }

    private function toolForClient(string $client): ?string
    {
        foreach ($this->sig['tcc_clients'] as $tool => $re) {
            if (preg_match($re, $client)) {
                return $tool;
            }
        }
        return null;
    }

    /** Does the critical path EXIST? (existence only; contents are never read) → theoretical access as the same user */
    private function fromPossible(array $seenTools): void
    {
        $exists = [];
        foreach ($this->sig['area_paths'] as $area => $paths) {
            foreach ($paths as $p) {
                $full = str_starts_with($p, '~') ? $this->home . substr($p, 1) : $p;
                if (file_exists($full)) {
                    $exists[$area] = true;
                    break;
                }
            }
        }
        foreach ($seenTools as $tool) {
            foreach (array_keys($exists) as $area) {
                $this->add($tool, $area, 'possible', 'User account', 'runs as the same user; the area exists on this machine');
            }
        }
    }

    // ------------------------------------------------ config audits (Findings inputs)

    /** Claude Code settings files: user, local and per-project */
    private function claudeSettingsFiles(array $projectPaths): array
    {
        $files = [$this->home . '/.claude/settings.json', $this->home . '/.claude/settings.local.json'];
        foreach ($projectPaths as $p) {
            if (str_starts_with($p, '/')) {
                $files[] = $p . '/.claude/settings.json';
                $files[] = $p . '/.claude/settings.local.json';
            }
        }
        return array_values(array_unique(array_filter($files, 'is_readable')));
    }

    /**
     * Claude Code hooks: commands the harness runs on events (PreToolUse, PostToolUse, SessionStart…).
     * @return list<array{string,string,string,string}> [file, event, matcher, command]
     */
    public function claudeHooks(array $projectPaths): array
    {
        $out = [];
        foreach ($this->claudeSettingsFiles($projectPaths) as $f) {
            $j = json_decode((string) file_get_contents($f), true);
            foreach ((array) ($j['hooks'] ?? []) as $event => $entries) {
                foreach ((array) $entries as $entry) {
                    foreach ((array) ($entry['hooks'] ?? []) as $h) {
                        if (is_array($h) && !empty($h['command']) && is_string($h['command'])) {
                            $out[] = [$f, (string) $event, (string) ($entry['matcher'] ?? '*'), $h['command']];
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Hard-coded secrets in the tools' own config files. Reports WHERE and WHAT KIND — the value itself is never kept.
     * @return list<array{string,string,string}> [file, kind, key name]
     */
    public function configSecrets(array $projectPaths): array
    {
        $h = $this->home;
        $files = $this->claudeSettingsFiles($projectPaths);
        foreach ([$h . '/.claude.json', Platform::appSupport($h) . '/Claude/claude_desktop_config.json', $h . '/.cursor/mcp.json',
                     $h . '/.codeium/windsurf/mcp_config.json', $h . '/.gemini/settings.json', $h . '/.codex/config.toml',
                     $h . '/.config/opencode/opencode.json', $h . '/.kiro/settings/mcp.json'] as $f) {
            if (is_readable($f)) {
                $files[] = $f;
            }
        }
        foreach ($projectPaths as $p) {
            if (str_starts_with($p, '/') && is_readable($p . '/.mcp.json')) {
                $files[] = $p . '/.mcp.json';
            }
        }
        $patterns = [
            'Anthropic API key' => '/sk-ant-[A-Za-z0-9_\-]{20,}/', 'OpenAI API key' => '/sk-(?!ant-)(?:proj-)?[A-Za-z0-9_\-]{32,}/',
            'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/', 'GitHub token' => '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}\b|github_pat_[A-Za-z0-9_]{40,}/',
            'Slack token' => '/\bxox[baprs]-[A-Za-z0-9\-]{10,}/', 'Google API key' => '/\bAIza[0-9A-Za-z_\-]{30,}\b/',
            'JWT' => '/\beyJ[A-Za-z0-9_\-]{15,}\.eyJ[A-Za-z0-9_\-]{15,}\./', 'Private key' => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
        ];
        $out = [];
        foreach (array_unique($files) as $f) {
            $t = (string) @file_get_contents($f);
            if ($t === '' || strlen($t) > 3000000) {
                continue;
            }
            foreach ($patterns as $kind => $re) {
                if (preg_match($re, $t)) {
                    $out[$f . '|' . $kind] = [$f, $kind, ''];
                }
            }
            // generic: a credential-looking key with a literal (non-variable) value
            // (the key NAME must end with the keyword: "maxTokens" / "claudeCodeFirstTokenDate" are not credentials)
            if (preg_match_all('/["\']?([A-Za-z0-9_]*(?:API_?KEY|_KEY|TOKEN|SECRET|PASSWORD|PASSWD))["\']?\s*[:=]\s*["\']([^"\'$\s{][^"\']{11,})["\']/i', $t, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    $v = $m[2];
                    $looksLikeSecret = preg_match('/[A-Za-z]/', $v) && preg_match('/\d/', $v) && !preg_match('/^\d{4}-\d{2}-\d{2}/', $v) && !preg_match('#^(https?://|/|~/|\./)#i', $v);
                    if ($looksLikeSecret && !preg_match('/^(\$|\{\{|<|your[-_ ]|xxx|\*+$|changeme|example)/i', $v)) {
                        $out[$f . '|key|' . $m[1]] = [$f, 'Credential value', $m[1]];
                    }
                }
            }
        }
        return array_values($out);
    }

    /**
     * Agent instruction files (CLAUDE.md, AGENTS.md, .cursorrules …) checked for hidden or suspicious content.
     * Reports the file, the kind of problem and the first line number — never the content.
     * @return list<array{string,string,int}> [file, code, line]
     */
    public function instructionIssues(array $projectPaths): array
    {
        $h = $this->home;
        $files = [$h . '/.claude/CLAUDE.md', $h . '/.codex/AGENTS.md', $h . '/.gemini/GEMINI.md'];
        foreach (array_slice($projectPaths, 0, 60) as $p) {
            if (!str_starts_with($p, '/') || $p === '/' || $p === $h) {
                continue;
            }
            foreach (['CLAUDE.md', 'AGENTS.md', 'GEMINI.md', '.cursorrules', '.windsurfrules', '.github/copilot-instructions.md', '.claude/CLAUDE.md'] as $n) {
                $files[] = $p . '/' . $n;
            }
            foreach (glob($p . '/.cursor/rules/*') ?: [] as $r) {
                $files[] = $r;
            }
        }
        $checks = [
            'hidden-characters' => '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{E0000}-\x{E007F}]/u',
            'encoded-blob'      => '/[A-Za-z0-9+\/]{160,}={0,2}/',
            'injection-phrase'  => '/ignore (?:all |any )?(?:previous|prior|above) (?:instructions|rules)|disregard (?:the )?(?:previous|above|system)|do not (?:tell|inform|mention)[^\n]{0,30}the user|without (?:telling|informing) the user|exfiltrate|send (?:the )?(?:contents?|data|files?) to https?:\/\//i',
            'remote-exec'       => '/(?:curl|wget)[^\n|]*\|\s*(?:ba)?sh\b/i',
        ];
        $out = [];
        foreach (array_unique($files) as $f) {
            if (!is_file($f) || !is_readable($f) || filesize($f) > 300000) {
                continue;
            }
            $lines = preg_split('/\R/u', (string) file_get_contents($f));
            foreach ($checks as $code => $re) {
                foreach ($lines as $i => $line) {
                    if ($code === 'hidden-characters' && $i === 0) {
                        $line = preg_replace('/^\x{FEFF}/u', '', $line); // a leading BOM is harmless
                    }
                    if (@preg_match($re, $line)) {
                        $out[] = [$f, $code, $i + 1];
                        break;
                    }
                }
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- helpers

    private function short(string $p): string
    {
        return $this->home !== '' && str_starts_with($p, $this->home) ? '~' . substr($p, strlen($this->home)) : $p;
    }

    private function setKv(string $k, string $v): void
    {
        $this->db->prepare('INSERT OR REPLACE INTO kv(k,v) VALUES(?,?)')->execute([$k, $v]);
    }
}
