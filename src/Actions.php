<?php
/**
 * Permission-removal actions.
 *
 * Flow: the web side (action.php) drops the request as JSON under var/queue/ → the collector (runs as the user)
 * reads the queue on every tick, RE-validates the request and applies it. The web process needs no file-write permission.
 *
 * Security principles:
 *  - Only the fixed action types below (TYPES); free-form commands/paths are never accepted.
 *  - Targets are validated against the collector's own permission records (grants.meta), not the request.
 *  - Every file-changing action takes a backup first (var/backups); "undo" restores it.
 *  - Every request is written to the actions table (audit log).
 */
require_once __DIR__ . '/Platform.php';

final class Actions
{
    public const TYPES = ['claude.revoke_area', 'claude.disable_bypass', 'codex.untrust', 'codex.harden', 'tcc.reset', 'settings.set', 'collector.restart', 'claude.deny_area', 'claude.deny_preset', 'finding.ack', 'finding.unack', 'undo'];

    /** Set by collector.restart: the collector exits after this tick and the service manager starts it again */
    public bool $restartRequested = false;

    /** Settings that can be changed from the web (config/settings.php). Only these keys and bool values are accepted. */
    public const SETTINGS = [
        'scan_system' => [
            'type' => 'bool', 'default' => false,
            'label' => 'System permission scan',
            'desc' => 'Reads the macOS permission database (TCC) read-only to show which AI has been granted Full Disk Access, Screen Recording, Accessibility, Camera/Microphone; checks only whether critical folders such as ~/.ssh, ~/.aws and Keychains EXIST ("Theoretical" access). File contents are never read. Reading TCC requires Full Disk Access for the app that starts the collector.',
        ],
        'notify_critical' => [
            'type' => 'bool', 'default' => false,
            'label' => 'Desktop notifications',
            'desc' => 'Shows a macOS notification when a tool touches a critical-severity area for the first time (SSH keys, .env files, keychain…). Best effort: macOS may suppress notifications depending on how the collector was started.',
        ],
        'scan_usage' => [
            'type' => 'bool', 'default' => false,
            'label' => 'Claude Code token usage',
            'desc' => 'Reads ONLY numeric token counters (input, output, cache), the model id and the folder name from your local Claude Code session logs (~/.claude/projects) to show usage per day, model and project. Prompts, responses and tool output are never stored or shown.',
        ],
        'daily_token_alert_k' => [
            'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 10000000,
            'label' => 'Daily token alert (thousand tokens)',
            'desc' => 'Raises a warning event once per day when Claude Code / Codex use more than this many thousand fresh tokens (input + output + cache writes) in a day. Needs "Claude Code token usage" turned on. Set 0 to turn it off.',
        ],
        'anomaly_alerts' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Unusual upload alerts (baseline)',
            'desc' => 'Learns how much each tool normally sends in a 10-minute window (from the last 14 days) and warns when a window is far above its usual peak. Needs a few days of history; complements the fixed MB threshold below.',
        ],
        'notify_idle' => [
            'type' => 'bool', 'default' => false,
            'label' => 'Notify when a coding agent is waiting for you',
            'desc' => 'Shows a macOS notification when a coding agent (Claude Code, Codex…) finishes a burst of work and goes quiet, so you know it is your turn. Best effort: based on network activity.',
        ],
        'upload_alert_mb' => [
            'type' => 'int', 'default' => 100, 'min' => 0, 'max' => 100000,
            'label' => 'Upload spike alert (MB per 5 min)',
            'desc' => 'Raises a warning event when a single tool sends more than this many megabytes within 5 minutes. Set 0 to turn it off.',
        ],
    ];
    private const SETTINGS_HEADER = <<<'TXT'
<?php
/**
 * SecAIQ Watch settings. ⚙ Settings in the web UI writes this file (a backup is taken before each change).
 *
 * notify_critical: show a macOS notification for critical-severity access events.
 * scan_usage:      read Claude Code token counters from the local session logs (numbers only).
 * upload_alert_mb:  warn when one tool sends more than this many MB in 5 minutes (0 = off).
 *
 * scan_system: when true the collector also does the following:
 *   - reads the macOS permission databases (TCC.db) READ-ONLY (needs Full Disk Access for the launching app),
 *   - checks ONLY whether critical directories such as ~/.ssh, ~/.aws and Keychains exist.
 *     File CONTENTS are never read.
 */
return [
TXT;

    public function __construct(private PDO $db, private array $sig, private string $home)
    {
    }

    // ------------------------------------------------------------ dizin / token

    public static function queueDir(): string   { return dirname(__DIR__) . '/var/queue'; }
    public static function backupDir(): string  { return dirname(__DIR__) . '/var/backups'; }
    public static function keyFile(): string    { return dirname(__DIR__) . '/var/csrf.key'; }

    /**
     * Called by the collector at startup: queue/backup directories and the token file.
     * Default: owner-only (0700/0600) — the panel (php -S) and the collector run as the same user, so no other local user
     * can read the token or drop requests in the queue. Set AIWATCH_SHARED_WEB_USER=1 only if a web server running as a
     * DIFFERENT user (e.g. Apache "daemon") must write the queue; that widens access to every local user.
     */
    public static function ensureSetup(): void
    {
        $shared = getenv('AIWATCH_SHARED_WEB_USER') === '1';
        $old = umask($shared ? 0 : 0077);
        foreach ([[self::queueDir(), $shared ? 0777 : 0700], [self::backupDir(), 0700]] as [$d, $mode]) {
            if (!is_dir($d)) {
                mkdir($d, $mode, true);
            }
            chmod($d, $mode);
        }
        if (!is_file(self::keyFile())) {
            file_put_contents(self::keyFile(), bin2hex(random_bytes(24)));
        }
        chmod(self::keyFile(), $shared ? 0644 : 0600);
        umask($old);
    }

    public static function token(): ?string
    {
        $t = is_readable(self::keyFile()) ? trim((string) file_get_contents(self::keyFile())) : '';
        return $t !== '' ? $t : null;
    }

    // ------------------------------------------------- action definitions for the UI

    /**
     * Available removal actions for a permission row.
     * @return list<array{type:string,params:array,label:string,confirm:string,undoable:bool,danger:bool}>
     */
    public static function describe(array $g): array
    {
        $meta = is_array($g['meta'] ?? null) ? $g['meta'] : [];
        $out = [];
        if (!empty($meta['claude_rules']) && ($g['level'] ?? '') === 'granted') {
            $rules = array_values(array_unique(array_column($meta['claude_rules'], 'rule')));
            $files = array_values(array_unique(array_column($meta['claude_rules'], 'file')));
            $out[] = ['type' => 'claude.revoke_area', 'params' => ['area' => $g['area']], 'undoable' => true, 'danger' => false,
                'label' => 'Remove permission (' . count($rules) . (count($rules) === 1 ? ' rule)' : ' rules)'),
                'confirm' => "These permission rules will be deleted from the Claude Code settings files:\n• " . implode("\n• ", array_slice($rules, 0, 10))
                    . (count($rules) > 10 ? "\n… (+" . (count($rules) - 10) . ')' : '')
                    . "\n\nFiles: " . count($files) . ". A backup is taken and you can restore it with «Undo». Claude Code will ask for approval again for these operations next time."];
        }
        if (!empty($meta['claude_bypass_files'])) {
            $out[] = ['type' => 'claude.disable_bypass', 'params' => [], 'undoable' => true, 'danger' => false,
                'label' => 'Turn off bypass mode',
                'confirm' => "bypassPermissions mode will be turned off (defaultMode → default, skipDangerousModePermissionPrompt is removed).\nFiles:\n• " . implode("\n• ", $meta['claude_bypass_files'])
                    . "\n\nA backup is taken; this can be undone."];
        }
        foreach ((array) ($meta['codex_trust'] ?? []) as $p) {
            $out[] = ['type' => 'codex.untrust', 'params' => ['path' => $p], 'undoable' => true, 'danger' => false,
                'label' => 'Remove trust: ' . basename($p),
                'confirm' => "This project will be removed from the trusted list in Codex config.toml:\n$p\n\nA backup is taken; this can be undone."];
        }
        if (!empty($meta['codex_harden'])) {
            $out[] = ['type' => 'codex.harden', 'params' => [], 'undoable' => true, 'danger' => false,
                'label' => 'Tighten the sandbox',
                'confirm' => "In Codex config.toml: sandbox_mode «danger-full-access» becomes «workspace-write» and approval_policy «never» becomes «on-request».\n\nA backup is taken; this can be undone."];
        }
        if (!empty($meta['tcc']['service']) && !empty($meta['tcc']['client'])) {
            $client = $meta['tcc']['client'];
            $host = ($g['level'] ?? '') === 'inherited';
            $out[] = ['type' => 'tcc.reset', 'params' => ['service' => $meta['tcc']['service'], 'client' => $client], 'undoable' => false, 'danger' => true,
                'label' => 'Reset macOS permission',
                'confirm' => "The macOS permission will be RESET:\nService: {$meta['tcc']['service']}\nApp: $client\n\n"
                    . ($host ? "⚠ This is a terminal/editor permission; ALL tools that use it (including CLI agents) will lose it.\n\n" : '')
                    . "This cannot be undone; the permission only returns when the app asks again and you approve it."];
        }
        return $out;
    }

    // ------------------------------------------------------------- queue processing

    /** Processes pending requests; returns true if at least one was applied successfully */
    public function processQueue(): bool
    {
        $changed = false;
        foreach (glob(self::queueDir() . '/*.json') ?: [] as $file) {
            // Only regular files owned by this user (never a symlink or another user's file)
            $mine = !is_link($file) && is_file($file) && (!function_exists('posix_geteuid') || getenv('AIWATCH_SHARED_WEB_USER') === '1' || @fileowner($file) === posix_geteuid());
            $raw = $mine ? @file_get_contents($file) : '';
            @unlink($file); // delete first so the same request never runs twice
            $req = json_decode((string) $raw, true);
            if (!is_array($req) || !preg_match('/^[a-f0-9]{12}$/', (string) ($req['id'] ?? ''))) {
                continue;
            }
            $id = $req['id'];
            $type = (string) ($req['type'] ?? '');
            $params = is_array($req['params'] ?? null) ? $req['params'] : [];
            $label = mb_substr((string) ($req['label'] ?? $type), 0, 120);
            if (!in_array($type, self::TYPES, true)) {
                $this->log($id, $type, $params, $label, 'refused', 'Unknown action type', []);
                continue;
            }
            try {
                [$msg, $backups] = $this->run($id, $type, $params);
                $this->log($id, $type, $params, $label, 'done', $msg, $backups);
                $changed = true;
            } catch (RuntimeException $e) {
                $this->log($id, $type, $params, $label, 'error', $e->getMessage(), []);
            } catch (Throwable $e) {
                $this->log($id, $type, $params, $label, 'error', 'Unexpected error: ' . $e->getMessage(), []);
            }
        }
        return $changed;
    }

    /** @return array{string,list<array{string,string}>} [mesaj, yedekler] */
    private function run(string $id, string $type, array $p): array
    {
        return match ($type) {
            'claude.revoke_area'    => $this->claudeRevokeArea($id, (string) ($p['area'] ?? '')),
            'claude.disable_bypass' => $this->claudeDisableBypass($id),
            'codex.untrust'         => $this->codexUntrust($id, (string) ($p['path'] ?? '')),
            'codex.harden'          => $this->codexHarden($id),
            'tcc.reset'             => $this->tccReset((string) ($p['service'] ?? ''), (string) ($p['client'] ?? '')),
            'collector.restart'     => $this->collectorRestart(),
            'claude.deny_area'      => $this->claudeDenyArea($id, (string) ($p['area'] ?? '')),
            'claude.deny_preset'    => $this->claudeDenyPreset($id, (string) ($p['preset'] ?? '')),
            'finding.ack'           => $this->findingAck((string) ($p['id'] ?? ''), (string) ($p['days'] ?? '')),
            'finding.unack'         => $this->findingUnack((string) ($p['id'] ?? '')),
            'settings.set'          => $this->settingsSet($id, (string) ($p['key'] ?? ''), (string) ($p['value'] ?? '')),
            'undo'                  => $this->undo($id, (string) ($p['action'] ?? '')),
        };
    }

    // ---------------------------------------------------------------- eylemler

    private function claudeRevokeArea(string $id, string $area): array
    {
        if (!isset($this->sig['areas'][$area])) {
            throw new RuntimeException('Invalid area');
        }
        $byFile = [];
        foreach ($this->grantsMeta('claude-code') as $g) {
            if ($g['area'] === $area && $g['level'] === 'granted') {
                foreach ((array) ($g['meta']['claude_rules'] ?? []) as $r) {
                    $byFile[$r['file']][$r['rule']] = true;
                }
            }
        }
        if (!$byFile) {
            throw new RuntimeException('No permission rule to remove was found (it may already be removed)');
        }
        $backups = [];
        $removed = 0;
        foreach ($byFile as $file => $rules) {
            $this->assertClaudeSettings($file);
            $j = $this->readJson($file);
            $allow = $j->permissions->allow ?? null;
            if (!is_array($allow)) {
                continue;
            }
            $keep = array_values(array_filter($allow, fn($r) => !isset($rules[$r])));
            if (count($keep) === count($allow)) {
                continue;
            }
            $backups[] = $this->backup($id, $file);
            $removed += count($allow) - count($keep);
            $j->permissions->allow = $keep;
            $this->writeJson($file, $j);
        }
        if (!$removed) {
            throw new RuntimeException('No rule left to remove in the files');
        }
        return ["$removed permission rule(s) removed (" . count($backups) . ' file(s), backed up)', $backups];
    }

    private function claudeDisableBypass(string $id): array
    {
        $files = [];
        foreach ($this->grantsMeta('claude-code') as $g) {
            foreach ((array) ($g['meta']['claude_bypass_files'] ?? []) as $f) {
                $files[$f] = true;
            }
        }
        $backups = [];
        foreach (array_keys($files) as $file) {
            $this->assertClaudeSettings($file);
            $j = $this->readJson($file);
            $changed = false;
            if (($j->permissions->defaultMode ?? null) === 'bypassPermissions') {
                $j->permissions->defaultMode = 'default';
                $changed = true;
            }
            if (($j->defaultMode ?? null) === 'bypassPermissions') {
                $j->defaultMode = 'default';
                $changed = true;
            }
            foreach (['skipDangerousModePermissionPrompt', 'skipDangerousModePermissionsPrompt'] as $k) {
                if (property_exists($j, $k)) {
                    unset($j->$k);
                    $changed = true;
                }
            }
            if ($changed) {
                $backups[] = $this->backup($id, $file);
                $this->writeJson($file, $j);
            }
        }
        if (!$backups) {
            throw new RuntimeException('No bypass setting to turn off was found');
        }
        return ['Bypass mode turned off (' . count($backups) . ' file(s), backed up)', $backups];
    }

    private function codexUntrust(string $id, string $path): array
    {
        $allowed = [];
        foreach (array_merge($this->grantsMeta('codex'), $this->grantsMeta('chatgpt')) as $g) {
            foreach ((array) ($g['meta']['codex_trust'] ?? []) as $x) {
                $allowed[$x] = true;
            }
        }
        if ($path === '' || !isset($allowed[$path])) {
            throw new RuntimeException('This project is not in the trusted list');
        }
        $file = $this->home . '/.codex/config.toml';
        $lines = preg_split('/\R/', (string) file_get_contents($file));
        $out = [];
        $skip = false;
        $found = false;
        foreach ($lines as $l) {
            if (preg_match('/^\s*\[/', $l)) {
                $skip = trim($l) === '[projects."' . $path . '"]';
                $found = $found || $skip;
            }
            if (!$skip) {
                $out[] = $l;
            }
        }
        if (!$found) {
            throw new RuntimeException('Section not found in config.toml');
        }
        $b = $this->backup($id, $file);
        $this->writeAtomic($file, implode("\n", $out));
        return ['Trusted project removed: ' . basename($path), [$b]];
    }

    private function codexHarden(string $id): array
    {
        $file = $this->home . '/.codex/config.toml';
        $t = (string) file_get_contents($file);
        $n = 0;
        $new = preg_replace('/^(\s*sandbox_mode\s*=\s*)"danger-full-access"/m', '$1"workspace-write"', $t, -1, $c1);
        $new = preg_replace('/^(\s*approval_policy\s*=\s*)"never"/m', '$1"on-request"', (string) $new, -1, $c2);
        $n = $c1 + $c2;
        if (!$n) {
            throw new RuntimeException('No setting to tighten was found');
        }
        $b = $this->backup($id, $file);
        $this->writeAtomic($file, (string) $new);
        return ["Codex sandbox/approval setting tightened ($n change(s))", [$b]];
    }

    private function tccReset(string $service, string $client): array
    {
        $ok = false;
        foreach ($this->grantsAll() as $g) {
            $t = $g['meta']['tcc'] ?? null;
            if ($t && $t['service'] === $service && $t['client'] === $client) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new RuntimeException('This permission entry is no longer listed');
        }
        if (!preg_match('/^kTCCService[A-Za-z]+$/', $service) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{2,120}$/', $client)) {
            throw new RuntimeException('Path-based permissions cannot be reset here; remove them in System Settings → Privacy & Security');
        }
        $short = substr($service, strlen('kTCCService'));
        $out = trim((string) shell_exec('tccutil reset ' . escapeshellarg($short) . ' ' . escapeshellarg($client) . ' 2>&1'));
        if (stripos($out, 'Successfully reset') === false) {
            throw new RuntimeException('tccutil failed: ' . mb_substr($out, 0, 200) . ' (system-level permissions may require administrator rights)');
        }
        return ["macOS permission reset: $short → $client", []];
    }

    /** Action descriptor for the "Protect" button (Findings tab) */
    public static function protectAct(string $area, string $label, array $sig): array
    {
        $rules = $sig['deny_rules'][$area] ?? [];
        return ['type' => 'claude.deny_area', 'params' => ['area' => $area], 'undoable' => true, 'danger' => false,
            'label' => 'Protect: block Claude Code',
            'confirm' => "These deny rules will be added to ~/.claude/settings.json (permissions.deny) so Claude Code can never read or write this area — even if a prompt asks it to:\n• " . implode("\n• ", $rules)
                . "\n\nArea: $label. A backup is taken and you can undo it."];
    }

    /** Descriptor for a one-click protection level (Developer / Balanced / Strict) */
    public static function presetAct(string $key, string $label, string $desc, array $keys, array $sig): array
    {
        $rules = [];
        foreach ($keys as $k) {
            $rules = array_merge($rules, $sig['deny_rules'][$k] ?? []);
        }
        return ['type' => 'claude.deny_preset', 'params' => ['preset' => $key], 'undoable' => true, 'danger' => false,
            'label' => 'Apply ' . $label,
            'confirm' => "$label protection: $desc\n\n" . count(array_unique($rules)) . " deny rules will be added to ~/.claude/settings.json (existing rules are kept). Claude Code will never be able to read or run these, even if asked.\nA backup is taken and you can undo it."];
    }

    private function claudeDenyArea(string $id, string $area): array
    {
        $rules = $this->sig['deny_rules'][$area] ?? null;
        if ($rules === null) {
            throw new RuntimeException('Invalid area');
        }
        $label = $this->sig['areas'][$area][0] ?? ($this->sig['deny_meta'][$area]['label'] ?? $area);
        return $this->applyDeny($id, $rules, $label);
    }

    private function claudeDenyPreset(string $id, string $preset): array
    {
        $p = $this->sig['protection_presets'][$preset] ?? null;
        if ($p === null) {
            throw new RuntimeException('Invalid preset');
        }
        $rules = [];
        foreach ($p['keys'] as $k) {
            $rules = array_merge($rules, $this->sig['deny_rules'][$k] ?? []);
        }
        return $this->applyDeny($id, array_values(array_unique($rules)), $p['label'] . ' protection');
    }

    /** Adds missing deny rules to ~/.claude/settings.json (backup first); existing rules are kept */
    private function applyDeny(string $id, array $rules, string $what): array
    {
        $file = $this->home . '/.claude/settings.json';
        $backups = [];
        if (is_file($file)) {
            $j = $this->readJson($file);
            $backups[] = $this->backup($id, $file);
        } else {
            if (!is_dir(dirname($file))) {
                throw new RuntimeException('~/.claude does not exist');
            }
            $j = new stdClass();
            $j->permissions = new stdClass();
        }
        $deny = is_array($j->permissions->deny ?? null) ? $j->permissions->deny : [];
        $add = array_values(array_diff($rules, $deny));
        if (!$add) {
            throw new RuntimeException('Already protected: nothing to add');
        }
        $j->permissions->deny = array_merge($deny, $add);
        $this->writeJson($file, $j);
        return [count($add) . ' deny rule(s) added: ' . $what, $backups];
    }

    /** Accept a finding's risk (days: 0 = forever). Stored in the database only; no files are touched. */
    private function findingAck(string $id, string $days): array
    {
        if (!preg_match('/^[a-z0-9_.-]{1,140}$/i', $id) || !in_array($days, ['0', '7', '30', '90'], true)) {
            throw new RuntimeException('Invalid finding or duration');
        }
        $until = $days === '0' ? 0 : time() + (int) $days * 86400;
        $this->db->prepare('INSERT OR REPLACE INTO finding_acks(id,ts,until,note) VALUES(?,?,?,?)')->execute([$id, time(), $until, '']);
        return ['Risk accepted' . ($days === '0' ? ' permanently' : " for $days days") . ': ' . $id, []];
    }

    private function findingUnack(string $id): array
    {
        if (!preg_match('/^[a-z0-9_.-]{1,140}$/i', $id)) {
            throw new RuntimeException('Invalid finding');
        }
        $st = $this->db->prepare('DELETE FROM finding_acks WHERE id=?');
        $st->execute([$id]);
        if (!$st->rowCount()) {
            throw new RuntimeException('That finding was not accepted');
        }
        return ['Finding restored: ' . $id, []];
    }

    /** Only meaningful under the LaunchAgent (KeepAlive): otherwise exiting would just stop the collector */
    private function collectorRestart(): array
    {
        if (!Platform::supervised()) {
            throw new RuntimeException('The collector was started manually, so it cannot restart itself. Install the background service (bin/install-agent.sh on macOS/Linux, bin/install-agent.ps1 on Windows) or restart it in the terminal.');
        }
        $this->restartRequested = true;
        return ['Collector is restarting…', []];
    }

    private function settingsSet(string $id, string $key, string $val): array
    {
        $def = self::SETTINGS[$key] ?? null;
        if ($def === null) {
            throw new RuntimeException('Unknown setting');
        }
        if ($def['type'] === 'bool') {
            if (!in_array($val, ['true', 'false'], true)) {
                throw new RuntimeException('Invalid value');
            }
            $new = $val === 'true';
        } else {
            if (!preg_match('/^\d{1,9}$/', $val) || (int) $val < $def['min'] || (int) $val > $def['max']) {
                throw new RuntimeException('Invalid value (expected a whole number from ' . $def['min'] . ' to ' . $def['max'] . ')');
            }
            $new = (int) $val;
        }
        $file = dirname(__DIR__) . '/config/settings.php';
        $cur = is_file($file) ? (require $file) : [];
        $cur = is_array($cur) ? $cur : [];
        if (($cur[$key] ?? $def['default']) === $new) {
            throw new RuntimeException('The setting already has this value');
        }
        $cur[$key] = $new;
        $backups = is_file($file) ? [$this->backup($id, $file)] : [];
        $body = self::SETTINGS_HEADER . "\n";
        foreach (self::SETTINGS as $k => $d) {
            $v = $cur[$k] ?? $d['default'];
            $body .= "    '$k' => " . ($d['type'] === 'bool' ? ($v ? 'true' : 'false') : (int) $v) . ",\n";
        }
        $this->writeAtomic($file, $body . "];\n");
        $shown = $def['type'] === 'bool' ? ($new ? 'turned on' : 'turned off') : 'set to ' . $new;
        return [$def['label'] . ' ' . $shown . ' (backed up)', $backups];
    }

    private function undo(string $id, string $actionId): array
    {
        if (!preg_match('/^[a-f0-9]{12}$/', $actionId)) {
            throw new RuntimeException('Invalid action id');
        }
        $st = $this->db->prepare('SELECT * FROM actions WHERE id=? AND status="done" AND undone=0');
        $st->execute([$actionId]);
        $a = $st->fetch();
        $backups = $a ? (json_decode((string) $a['backups'], true) ?: []) : [];
        if (!$a || !$backups) {
            throw new RuntimeException('This action cannot be undone (no backup, or it was already undone)');
        }
        $newBackups = [];
        $dir = str_replace('\\', '/', (string) realpath(self::backupDir())); // Windows realpath uses backslashes
        foreach ($backups as [$file, $bak]) {
            $f = str_replace('\\', '/', (string) $file);
            $allowed = preg_match('#/\.claude/settings(\.local)?\.json$#', $f) || preg_match('#/\.codex/config\.toml$#', $f)
                || $f === str_replace('\\', '/', dirname(__DIR__) . '/config/settings.php');
            if (!$allowed) {
                throw new RuntimeException('Refusing to restore a file outside the managed settings files');
            }
            if (!is_file($bak) || !str_starts_with(str_replace('\\', '/', (string) realpath($bak)), $dir . '/')) {
                throw new RuntimeException('Backup file not found');
            }
            $newBackups[] = $this->backup($id, $file); // also keep the state from before the undo
            $this->writeAtomic($file, (string) file_get_contents($bak));
        }
        $this->db->prepare('UPDATE actions SET undone=1 WHERE id=?')->execute([$actionId]);
        return ['Undone: ' . $a['label'], $newBackups];
    }

    // ----------------------------------------------------------------- helpers

    /** Only Claude Code settings files may be edited */
    private function assertClaudeSettings(string $file): void
    {
        if (!preg_match('#/\.claude/settings(\.local)?\.json$#', $file) || !is_file($file)) {
            throw new RuntimeException('File not allowed: ' . $file);
        }
    }

    private function readJson(string $file): object
    {
        $j = json_decode((string) file_get_contents($file));
        if (!is_object($j)) {
            throw new RuntimeException('Could not read JSON: ' . basename($file));
        }
        $j->permissions ??= new stdClass();
        return $j;
    }

    private function writeJson(string $file, object $j): void
    {
        // if permissions did not exist we may have added an empty object; remove it if empty
        if (isset($j->permissions) && $j->permissions instanceof stdClass && !get_object_vars($j->permissions)) {
            unset($j->permissions);
        }
        $this->writeAtomic($file, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    private function writeAtomic(string $file, string $content): void
    {
        if (is_link($file)) { // dotfiles are often symlinks: write through to the target instead of replacing the link
            $real = realpath($file);
            if ($real === false) {
                throw new RuntimeException('Broken symlink: ' . basename($file));
            }
            $file = $real;
        }
        $mode = @fileperms($file) ?: 0644;
        $tmp = $file . '.aigw-' . bin2hex(random_bytes(3));
        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException('Could not write: ' . basename($file));
        }
        chmod($tmp, $mode & 0777);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Could not replace: ' . basename($file));
        }
    }

    /** @return array{string,string} [dosya, yedek] */
    private function backup(string $id, string $file): array
    {
        $dest = self::backupDir() . '/' . $id . '-' . substr(sha1($file), 0, 8) . '-' . basename($file) . '.bak';
        if (!copy($file, $dest)) {
            throw new RuntimeException('Could not back up: ' . basename($file));
        }
        chmod($dest, 0600);
        return [$file, $dest];
    }

    private function grantsAll(): array
    {
        $out = [];
        foreach ($this->db->query('SELECT tool, area, level, meta FROM grants WHERE meta IS NOT NULL') as $r) {
            $r['meta'] = json_decode((string) $r['meta'], true) ?: [];
            $out[] = $r;
        }
        return $out;
    }

    private function grantsMeta(string $tool): array
    {
        return array_values(array_filter($this->grantsAll(), fn($g) => $g['tool'] === $tool));
    }

    private function log(string $id, string $type, array $params, string $label, string $status, string $msg, array $backups): void
    {
        $ts = time();
        $pj = json_encode($params, JSON_UNESCAPED_UNICODE);
        $bj = json_encode($backups, JSON_UNESCAPED_UNICODE);
        // Tamper-evident log: every entry hashes the previous entry's hash together with its own content
        $prev = (string) $this->db->query('SELECT hash FROM actions WHERE hash IS NOT NULL ORDER BY rowid DESC LIMIT 1')->fetchColumn();
        $hash = hash('sha256', implode('|', [$prev, $id, $ts, $type, $pj, $status, $msg, $bj]));
        $this->db->prepare('INSERT OR REPLACE INTO actions(id,ts,type,params,label,status,result,backups,undone,prev_hash,hash) VALUES(?,?,?,?,?,?,?,?,0,?,?)')
            ->execute([$id, $ts, $type, $pj, $label, $status, $msg, $bj, $prev, $hash]);
    }

    /** Verifies the hash chain of the action log. @return array{ok:bool,count:int,legacy:int,broken:?string} */
    public static function verifyChain(PDO $db): array
    {
        $prev = '';
        $count = 0;
        $legacy = 0;
        try {
            $rows = $db->query('SELECT id, ts, type, params, status, result, backups, prev_hash, hash FROM actions ORDER BY rowid')->fetchAll();
        } catch (PDOException $e) {
            return ['ok' => true, 'count' => 0, 'legacy' => 0, 'broken' => null]; // columns appear after the collector migrates the database
        }
        foreach ($rows as $r) {
            if ($r['hash'] === null || $r['hash'] === '') {
                $legacy++; // written before the chain existed
                continue;
            }
            $expect = hash('sha256', implode('|', [$prev, $r['id'], $r['ts'], $r['type'], $r['params'], $r['status'], $r['result'], $r['backups']]));
            if ($r['prev_hash'] !== $prev || $r['hash'] !== $expect) {
                return ['ok' => false, 'count' => $count, 'legacy' => $legacy, 'broken' => $r['id']];
            }
            $prev = $r['hash'];
            $count++;
        }
        return ['ok' => true, 'count' => $count, 'legacy' => $legacy, 'broken' => null];
    }
}
