<?php
/**
 * Security findings ("posture audit"): rule-based checks over what the collector already knows
 * (permissions, observed file access, MCP inventory, events). Nothing here reads new data;
 * every finding carries a severity, a plain explanation, evidence and — when possible — one-click fixes
 * (the same guarded actions used elsewhere in the UI).
 */
final class Findings
{
    private const RANK = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    /** areas where a permission grant is a real risk for a coding agent */
    private const RISKY_AREAS = ['ssh', 'cloud', 'secrets', 'keychain', 'gpg', 'wallet', 'browser', 'messages'];

    /** @return list<array{id:string,sev:string,title:string,why:string,evidence:list<string>,acts:list<array>,hint:string}> */
    public static function compute(PDO $db, array $sig, string $home, array $settings): array
    {
        $now = time();
        $acks = [];
        foreach ($db->query('SELECT id, ts, until FROM finding_acks')->fetchAll() as $a) {
            $acks[$a['id']] = $a;
        }
        $grants = [];
        foreach ($db->query('SELECT tool, area, level, source, detail, meta FROM grants')->fetchAll() as $g) {
            $g['meta'] = $g['meta'] ? (json_decode($g['meta'], true) ?: []) : [];
            $grants[] = $g;
        }
        $areas = $sig['areas'];
        $toolName = fn(string $k) => $sig['tools'][$k][0] ?? (str_starts_with($k, 'other:') ? substr($k, 6) : $k);
        $out = [];
        $add = function (string $id, string $sev, string $title, string $why, array $evidence = [], array $acts = [], string $hint = '', array $opts = []) use (&$out) {
            $out[$id] = compact('id', 'sev', 'title', 'why', 'evidence', 'acts', 'hint', 'opts');
        };
        $actsOf = fn(array $g, string $type) => array_values(array_filter(Actions::describe($g), fn($a) => $a['type'] === $type));

        // 1) Claude Code without permission prompts
        foreach ($grants as $g) {
            if (!empty($g['meta']['claude_bypass_files'])) {
                $add('claude-bypass', 'critical', 'Claude Code runs without permission prompts',
                    'bypassPermissions lets the agent run any command and edit any file without asking you first. A single bad prompt or injected instruction has full effect.',
                    array_map(fn($f) => str_replace($home, '~', $f), $g['meta']['claude_bypass_files']), $actsOf($g, 'claude.disable_bypass'));
                break;
            }
        }
        // 2) Codex sandbox / approvals
        foreach ($grants as $g) {
            if (!empty($g['meta']['codex_harden'])) {
                $full = str_contains($g['detail'], 'danger-full-access');
                $add('codex-sandbox', $full ? 'critical' : 'high', $full ? 'Codex sandbox is turned off' : 'Codex never asks for approval',
                    $full ? 'sandbox_mode = danger-full-access gives Codex unrestricted access to the whole file system.' : 'approval_policy = never means Codex acts without asking you.',
                    [$g['detail']], $actsOf($g, 'codex.harden'));
                break;
            }
        }
        // 3) overly broad Claude Code allow rules
        foreach ($grants as $g) {
            if ($g['tool'] === 'claude-code' && $g['level'] === 'granted' && !empty($g['meta']['claude_rules'])) {
                $broad = [];
                foreach ($g['meta']['claude_rules'] as $r) {
                    if (preg_match('/^(Bash|Write|Edit|MultiEdit|Read)$|^Bash\((\*|\*:\*|:\*)?\)$|^Bash\(\*/', $r['rule'])) {
                        $broad[$r['rule']] = true;
                    }
                }
                if ($broad) {
                    $add('claude-broad-' . $g['area'], 'high', 'Very broad Claude Code allow rule',
                        'A blanket allow rule approves every use of that tool in advance, so prompts no longer protect you.',
                        array_keys($broad), $actsOf($g, 'claude.revoke_area'));
                }
            }
        }
        // 4) Claude Code pre-approved for risky areas
        foreach ($grants as $g) {
            if ($g['tool'] === 'claude-code' && $g['level'] === 'granted' && in_array($g['area'], self::RISKY_AREAS, true) && !empty($g['meta']['claude_rules'])) {
                $add('claude-area-' . $g['area'], 'high', 'Claude Code is pre-approved for ' . ($areas[$g['area']][0] ?? $g['area']),
                    'Allow rules for this area let the agent read it without asking.', [$g['detail']], $actsOf($g, 'claude.revoke_area'));
            }
        }
        // 5) inherited terminal permissions
        foreach ($grants as $g) {
            if ($g['level'] === 'inherited' && in_array($areas[$g['area']][1] ?? '', ['critical', 'high'], true)) {
                $id = 'inherit-' . $g['area'];
                if (!isset($out[$id])) {
                    $add($id, ($areas[$g['area']][1] ?? '') === 'critical' ? 'high' : 'medium', 'CLI agents inherit the terminal\'s ' . ($areas[$g['area']][0] ?? $g['area']) . ' permission',
                        'macOS gives a terminal/editor permission to every program started inside it, including claude, codex and other agents.',
                        [$g['detail']], $actsOf($g, 'tcc.reset'));
                }
            }
        }
        // 6) MCP servers
        foreach ($db->query("SELECT tool, name, detail FROM inventory WHERE kind='mcp'")->fetchAll() as $m) {
            $who = $toolName($m['tool']) . ' → ' . $m['name'];
            $d = (string) $m['detail'];
            if (str_starts_with($d, 'http://')) {
                $add('mcp-http-' . $m['tool'] . $m['name'], 'high', 'MCP server over plain HTTP', 'Traffic to this server is not encrypted.', [$who . ' — ' . $d], [], 'Use an https:// endpoint or a local server.');
            } elseif (str_starts_with($d, 'https://')) {
                $add('mcp-remote-' . $m['tool'] . $m['name'], 'medium', 'Remote MCP server receives your data', 'Everything the agent sends through this server leaves your machine.', [$who . ' — ' . $d], [], 'Check that you trust the operator; remove it from the tool\'s MCP settings if unused.');
            }
            if (str_contains($d, '***')) {
                $add('mcp-secret-' . $m['tool'] . $m['name'], 'high', 'Secret in MCP server arguments', 'A token-like value is passed on the command line, where any local process can read it (ps).', [$who], [], 'Move the secret into the server\'s environment/config and rotate it.');
            }
            if (preg_match('/^(npx|uvx|bunx|pnpm dlx)\s+(-\S+\s+)*([^\s]+)/', $d, $mm) && !preg_match('/@\d/', $mm[3])) {
                $add('mcp-unpinned-' . $m['tool'] . $m['name'], 'medium', 'MCP server runs unpinned code', 'The package is downloaded and run fresh (latest version) every time; a compromised release would run with your permissions.', [$who . ' — ' . $d], [], 'Pin an exact version (package@1.2.3).');
            }
        }
        // 7) observed access: critical areas, plus high areas that are not the tool's own data
        $seen = [];
        foreach ($db->query("SELECT tool, area, path FROM files WHERE area != '' AND sensitive=1")->fetchAll() as $f) {
            $sev = $areas[$f['area']][1] ?? '';
            if (self::ownData($f['tool'], $f['path'], $sig)) {
                continue;
            }
            $k = $f['tool'] . '|' . $f['area'];
            $seen[$k] ??= ['tool' => $f['tool'], 'area' => $f['area'], 'sev' => $sev, 'n' => 0, 'sample' => $f['path']];
            $seen[$k]['n']++;
        }
        foreach ($seen as $k => $s) {
            $label = $areas[$s['area']][0] ?? $s['area'];
            $acts = $s['tool'] === 'claude-code' ? [Actions::protectAct($s['area'], $label, $sig)] : [];
            $add('access-' . md5($k), $s['sev'] === 'critical' ? 'critical' : 'medium',
                $toolName($s['tool']) . ' opened files in: ' . $label,
                $s['sev'] === 'critical' ? 'Files in this area can grant access to your accounts or servers.' : 'Personal or work data outside the tool\'s own folders.',
                [$s['n'] . ' file(s), e.g. ' . str_replace($home, '~', $s['sample'])], $acts,
                $s['tool'] === 'claude-code' ? '' : 'Restrict this tool in its own settings or macOS privacy settings.');
        }
        // 8) AI browser extensions with broad site access (see inventory scan)
        foreach ($db->query("SELECT name, detail FROM inventory WHERE kind='extension' AND detail LIKE '%all sites%'")->fetchAll() as $e) {
            $add('ext-' . md5($e['name']), 'medium', 'AI browser extension can read every site: ' . $e['name'], 'Extensions with all-sites access can see the pages you open, including logged-in pages.', [$e['detail']], [], 'Remove it in the browser, or limit its site access.');
        }
        // 9) recent large uploads
        $up = (int) $db->query("SELECT COUNT(*) FROM events WHERE msg LIKE 'High upload:%' AND ts > " . ($now - 86400))->fetchColumn();
        if ($up > 0) {
            $add('upload-spikes', 'medium', 'Large uploads in the last 24 hours', 'A tool sent more data than your alert threshold within 5 minutes.', [$up . ' alert(s) — see Overview → Alerts & events'], [], 'Check the Network activity tab for the destination.');
        }
        // 8b) Claude Code hooks: commands the harness runs automatically on events
        foreach ($db->query("SELECT name, detail FROM inventory WHERE kind='hook'")->fetchAll() as $hk) {
            [$cmd, $file] = array_pad(explode('  ·  ', (string) $hk['detail'], 2), 2, '');
            $why = [];
            if (preg_match('/(curl|wget)[^|;&\n]*\|\s*(sudo\s+)?(ba|z)?sh\b/i', $cmd)) {
                $why[] = ['critical', 'downloads and runs code'];
            } elseif (preg_match('/\b(curl|wget|nc|ncat|scp|rsync|ssh|ftp)\b/i', $cmd)) {
                $why[] = ['high', 'makes network connections'];
            }
            if (preg_match('#(\.ssh|\.aws|\.gnupg|\.kube|\.env\b|id_rsa|id_ed25519|credentials|Keychains|\.netrc)#i', $cmd)) {
                $why[] = ['high', 'reads credential files'];
            }
            if (preg_match('/\bsudo\b/i', $cmd)) {
                $why[] = ['high', 'uses sudo'];
            }
            if (preg_match('/base64\s+(-d|--decode)|\beval\b|source\s+<\(|\bpython[\d.]*\s+-c\b/i', $cmd)) {
                $why[] = ['medium', 'uses eval / decoding tricks'];
            }
            if ($file !== '' && !str_starts_with($file, '~/.claude/')) {
                $why[] = ['medium', 'comes from a project settings file (a cloned repository can ship hooks)'];
            }
            if (!$why) {
                continue;
            }
            usort($why, fn($a, $b) => self::RANK[$a[0]] <=> self::RANK[$b[0]]);
            $add('hook-' . md5($hk['name'] . $cmd), $why[0][0], 'Claude Code hook ' . $why[0][1],
                'Hooks run automatically with your permissions on every matching event: ' . implode('; ', array_column($why, 1)) . '.',
                [$hk['name'], $cmd, $file], [], 'Review the hook; remove it from the settings file if you do not recognize it.');
        }
        // 8c) hard-coded secrets in the tools' own config files (only location + kind are known)
        foreach ($db->query("SELECT name, detail FROM inventory WHERE kind='secret'")->fetchAll() as $sc) {
            $add('secret-' . md5($sc['name'] . $sc['detail']), 'high', 'Hard-coded secret in a tool config (' . preg_replace('/ in .*$/', '', $sc['name']) . ')',
                'A credential is stored in plain text in a file that other programs, backups and sync tools can read.', [$sc['detail']], [],
                'Move it to an environment variable or your keychain, then rotate the exposed credential.');
        }
        // 8d) agent instruction files with hidden or suspicious content
        $instr = ['hidden-characters' => ['high', 'Hidden characters in an agent instruction file', 'Invisible Unicode can hide instructions that you cannot see but the agent obeys.'],
                  'injection-phrase' => ['high', 'Prompt-injection wording in an agent instruction file', 'Phrases like "ignore previous instructions" or "do not tell the user" are how injected instructions read.'],
                  'remote-exec' => ['high', 'Instruction file tells the agent to download and run code', 'A pipe-to-shell command in an instruction file can make the agent run unreviewed code.'],
                  'encoded-blob' => ['medium', 'Large encoded blob in an agent instruction file', 'Encoded text is unreadable to you but not to the agent.']];
        foreach ($db->query("SELECT name, detail FROM inventory WHERE kind='instr'")->fetchAll() as $in) {
            $code = explode(' in ', (string) $in['name'])[0];
            if (isset($instr[$code])) {
                $add('instr-' . md5($in['name'] . $in['detail']), $instr[$code][0], $instr[$code][1], $instr[$code][2], [$in['detail']], [], 'Open the file and remove or rewrite the flagged lines. Be careful with instruction files that came from a repository you cloned.');
            }
        }
        // 8e) MCP servers configured for Claude Code but never called in 30 days (needs token/usage tracking for coverage)
        if (!empty($settings['scan_usage'])) {
            $window = $now - 30 * 86400;
            $total = (int) $db->query('SELECT COUNT(*) FROM tool_calls WHERE ts > ' . $window)->fetchColumn();
            if ($total >= 50) {
                $used = array_column($db->query("SELECT DISTINCT server FROM tool_calls WHERE tool='claude-code' AND server != '' AND ts > " . $window)->fetchAll(), 'server');
                foreach ($db->query("SELECT name, detail FROM inventory WHERE kind='mcp' AND tool='claude-code'")->fetchAll() as $m) {
                    if (!in_array(preg_replace('/[^A-Za-z0-9_-]/', '_', $m['name']), $used, true) && !in_array($m['name'], $used, true)) {
                        $add('mcp-unused-' . $m['name'], 'medium', 'MCP server not used in 30 days: ' . $m['name'],
                            'Every configured MCP server is attack surface (its tools are available to the agent, and to any injected instruction) even when you never use it.',
                            [$m['name'] . ' — ' . $m['detail'], $total . ' tool calls in 30 days, none through this server'], [], 'Remove it from Claude Code\'s MCP settings (claude mcp remove ' . $m['name'] . ').');
                    }
                }
            }
        }
        // 8f) no deny rules for destructive shell commands
        $hasClaude = (int) $db->query("SELECT COUNT(*) FROM tools_seen WHERE tool='claude-code'")->fetchColumn() > 0;
        if ($hasClaude) {
            foreach (self::protection($db, $sig) as $pr) {
                if ($pr['area'] === 'destructive' && $pr['status'] !== 'protected') {
                    $add('no-destructive-deny', 'medium', 'Claude Code has no deny rules for destructive commands',
                        'Nothing blocks rm -rf, sudo, force-push or reset --hard if the agent (or an injected instruction) tries them and a broad allow rule exists.',
                        [$pr['status'] === 'partial' ? 'Only some of the recommended rules are present.' : 'No deny rules found.'], [$pr['act']]);
                }
            }
        }
        // 8g) AI processes sending real volume to destinations that are not a known AI provider
        $seenDest = [];
        foreach ($db->query("SELECT tool, host, rip, SUM(bin) bin, SUM(bout) bout FROM dest WHERE provider='Other' AND conf='certain' GROUP BY tool, COALESCE(NULLIF(host,''), rip) HAVING SUM(bin)+SUM(bout) >= 524288 ORDER BY SUM(bout) DESC LIMIT 8")->fetchAll() as $d) {
            $h = $d['host'] ?: $d['rip'];
            $id = 'dest-' . md5($d['tool'] . '|' . $h);
            $add($id, 'medium', $toolName($d['tool']) . ' exchanges data with an unrecognized host',
                'The host is not one of the known AI providers. It may be normal infrastructure (cloud hosting, telemetry, updates) — confirm once, then trust it.',
                [$h . ' — sent ' . self::fmt((int) $d['bout']) . ', received ' . self::fmt((int) $d['bin'])], [], '', ['trust' => true]);
        }
        // 10) the permission scan itself
        if (empty($settings['scan_system'])) {
            $add('scan-off', 'low', 'System permission scan is off', 'macOS permissions (Full Disk Access, Screen Recording, Accessibility…) are not being checked.', [], [], 'Turn on "System permission scan" in ⚙ Settings.');
        }

        // finalize: stable safe ids, accepted-risk state, accept / restore actions
        $list = [];
        foreach ($out as $f) {
            $f['id'] = strlen($f['id']) > 100 ? substr(preg_replace('/[^a-z0-9_.-]+/i', '-', $f['id']), 0, 60) . md5($f['id']) : preg_replace('/[^a-z0-9_.-]+/i', '-', $f['id']);
            $ack = $acks[$f['id']] ?? null;
            $f['ack'] = $ack && (!(int) $ack['until'] || (int) $ack['until'] > $now) ? ['ts' => (int) $ack['ts'], 'until' => (int) $ack['until']] : null;
            if ($f['ack']) {
                $f['acts'] = [self::ackAct($f['id'], 'unack', '', 'Restore (stop accepting)')];
            } elseif (!empty($f['opts']['trust'])) {
                $f['acts'][] = self::ackAct($f['id'], 'ack', '0', 'Trust this host', "Trust this host? The finding is hidden for good (you can restore it later under «Accepted risks»).");
            } else {
                $f['acts'][] = self::ackAct($f['id'], 'ack', '30', 'Accept risk · 30 days');
                $f['acts'][] = self::ackAct($f['id'], 'ack', '0', 'Accept risk · forever');
            }
            unset($f['opts']);
            $list[] = $f;
        }
        usort($list, fn($a, $b) => (self::RANK[$a['sev']] <=> self::RANK[$b['sev']]) ?: strcmp($a['title'], $b['title']));
        return $list;
    }

    /** Descriptor for the accept / restore buttons */
    private static function ackAct(string $id, string $kind, string $days, string $label, string $confirm = ''): array
    {
        return ['type' => $kind === 'ack' ? 'finding.ack' : 'finding.unack', 'params' => $kind === 'ack' ? ['id' => $id, 'days' => $days] : ['id' => $id],
            'label' => $label, 'undoable' => false, 'danger' => false,
            'confirm' => $confirm !== '' ? $confirm : ($kind === 'ack'
                ? 'You accept this risk' . ($days === '0' ? ' permanently' : " for $days days") . '. The finding moves to «Accepted risks» and no longer counts toward your score. You can restore it at any time.'
                : 'The finding becomes active again and counts toward your score.')];
    }

    /** Posture score: 100 minus a penalty per ACTIVE (not accepted) finding, graded A–F (AgentShield-style deductions) */
    public static function posture(array $findings): array
    {
        $pen = ['critical' => 25, 'high' => 15, 'medium' => 5, 'low' => 2];
        $score = 100;
        foreach ($findings as $f) {
            if (empty($f['ack'])) {
                $score -= $pen[$f['sev']] ?? 0;
            }
        }
        $score = max(0, $score);
        return ['score' => $score, 'grade' => $score >= 90 ? 'A' : ($score >= 80 ? 'B' : ($score >= 70 ? 'C' : ($score >= 60 ? 'D' : 'F')))];
    }

    private static function fmt(int $n): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $u) {
            if ($n < 1024 || $u === 'GB') {
                return ($i ? number_format($n, 1) : $n) . ' ' . $u;
            }
            $n /= 1024;
        }
        return (string) $n;
    }

    /** Deny-rule presets for Claude Code: which are fully / partly / not yet in ~/.claude settings */
    public static function protection(PDO $db, array $sig): array
    {
        $have = [];
        foreach ($db->query("SELECT meta FROM grants WHERE tool='claude-code' AND level='denied' AND meta IS NOT NULL")->fetchAll() as $r) {
            foreach ((json_decode($r['meta'], true)['claude_deny'] ?? []) as $rule) {
                $have[$rule] = true;
            }
        }
        $rows = [];
        foreach ($sig['deny_rules'] as $key => $rules) {
            [$label, $sev, $icon] = isset($sig['areas'][$key]) ? [$sig['areas'][$key][0], $sig['areas'][$key][1], $sig['areas'][$key][2]]
                : [$sig['deny_meta'][$key]['label'], $sig['deny_meta'][$key]['sev'], $sig['deny_meta'][$key]['icon']];
            $missing = array_values(array_filter($rules, fn($r) => !isset($have[$r])));
            $status = !$missing ? 'protected' : (count($missing) < count($rules) ? 'partial' : 'none');
            $rows[$key] = ['area' => $key, 'label' => $label, 'sev' => $sev, 'icon' => $icon, 'rules' => $rules, 'missing' => count($missing),
                'status' => $status, 'protected' => $status === 'protected', 'act' => Actions::protectAct($key, $label, $sig)];
        }
        return array_values($rows);
    }

    /** One-click levels (Developer / Balanced / Strict) with how many rules each still needs */
    public static function presets(PDO $db, array $sig): array
    {
        $byKey = array_column(self::protection($db, $sig), null, 'area');
        $out = [];
        foreach ($sig['protection_presets'] as $key => $p) {
            $need = 0;
            foreach ($p['keys'] as $k) {
                $need += $byKey[$k]['missing'] ?? 0;
            }
            $out[] = ['key' => $key, 'label' => $p['label'], 'desc' => $p['desc'], 'missing' => $need, 'act' => Actions::presetAct($key, $p['label'], $p['desc'], $p['keys'], $sig)];
        }
        return $out;
    }

    /** A tool's own app data (e.g. Claude Desktop's Local Storage) is not a finding */
    private static function ownData(string $tool, string $path, array $sig): bool
    {
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($tool . ' ' . ($sig['tools'][$tool][0] ?? '')));
        $tokens = array_filter($tokens, fn($t) => strlen($t) >= 4 && !in_array($t, ['code', 'desktop', 'cli', 'app', 'other', 'computer'], true));
        // vendor synonyms: a tool's own bundle ids / folders often use the company name
        foreach (['claude' => 'anthropic', 'chatgpt' => 'openai', 'codex' => 'openai', 'gemini' => 'google', 'cursor' => 'todesktop'] as $k => $syn) {
            if (in_array($k, $tokens, true)) {
                $tokens[] = $syn;
            }
        }
        $p = strtolower($path);
        foreach ($tokens as $t) {
            if (str_contains($p, $t)) {
                return true;
            }
        }
        return false;
    }
}
