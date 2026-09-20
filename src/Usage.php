<?php
/**
 * Token usage tracker for Claude Code and Codex (opt-in: settings scan_usage).
 *
 * Reads ONLY numeric usage counters (input / output / cache tokens), the model id, the timestamp, the
 * message id (for de-duplication) and the working directory from the local Claude Code session logs
 * (~/.claude/projects/<project>/*.jsonl, ~/.codex/sessions, dated folders). Prompts, responses and tool output are parsed in memory and
 * discarded — they are never stored, logged or shown.
 *
 * Scanning is incremental (byte offsets per file) and capped per pass so a large history never stalls the collector.
 */
final class Usage
{
    private const MAX_BYTES_PER_PASS = 40 * 1048576; // read at most 40 MB of new log data per scan
    private const MAX_LINE_BYTES = 8 * 1048576;      // ignore absurdly long lines
    private const LOOKBACK_DAYS = 30;

    /** @var list<array{string,int,string,string}> tool_use blocks of the message being parsed: [id, ts, day, name] (names only) */
    private array $calls = [];

    public function __construct(private PDO $db, private string $home)
    {
    }

    /** Log sources: [tool key, glob] */
    private function sources(): array
    {
        return [
            ['claude-code', $this->home . '/.claude/projects/*/*.jsonl'],
            ['codex', $this->home . '/.codex/sessions/*/*/*/*.jsonl'],
        ];
    }

    /** @return array{int,int} [files touched, messages upserted] */
    public function scan(int $now): array
    {
        clearstatcache(true); // the collector is long-lived: never trust cached filesize()/filemtime()
        $cut = $now - self::LOOKBACK_DAYS * 86400;
        $budget = self::MAX_BYTES_PER_PASS;
        $selF = $this->db->prepare('SELECT offset, model, cwd FROM usage_files WHERE path=?');
        $putF = $this->db->prepare('INSERT INTO usage_files(path,offset,model,cwd) VALUES(?,?,?,?) ON CONFLICT(path) DO UPDATE SET offset=excluded.offset, model=excluded.model, cwd=excluded.cwd');
        $putM = $this->db->prepare(
            'INSERT INTO usage_msgs(id,ts,day,model,project,tin,tout,cread,cwrite,tool) VALUES(?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT(id) DO UPDATE SET tin=MAX(tin,excluded.tin), tout=MAX(tout,excluded.tout),
                 cread=MAX(cread,excluded.cread), cwrite=MAX(cwrite,excluded.cwrite)'
        );
        $putC = $this->db->prepare('INSERT OR IGNORE INTO tool_calls(id,ts,day,tool,name,server) VALUES(?,?,?,?,?,?)');
        $touched = 0;
        $msgs = 0;
        $this->db->beginTransaction();
        foreach ($this->sources() as [$tool, $pattern]) {
            foreach (glob($pattern) ?: [] as $f) {
                if ($budget <= 0) {
                    break 2; // continue on the next pass
                }
                $mtime = (int) @filemtime($f);
                $size = (int) @filesize($f);
                if ($mtime < $cut || $size <= 0) {
                    continue;
                }
                $selF->execute([$f]);
                $st = $selF->fetch() ?: ['offset' => 0, 'model' => '', 'cwd' => ''];
                $offset = (int) $st['offset'];
                if ($size < $offset) {
                    $offset = 0; // file was rewritten
                    $st['model'] = $st['cwd'] = '';
                }
                if ($size === $offset) {
                    continue;
                }
                $fh = @fopen($f, 'r');
                if (!$fh) {
                    continue;
                }
                fseek($fh, $offset);
                $data = (string) fread($fh, min($budget, $size - $offset));
                fclose($fh);
                $last = strrpos($data, "\n");
                if ($last === false) {
                    continue; // no complete line yet
                }
                $data = substr($data, 0, $last + 1);
                $budget -= strlen($data);
                $model = (string) $st['model'];
                $cwd = (string) $st['cwd'];
                foreach (explode("\n", $data) as $line) {
                    if (strlen($line) > self::MAX_LINE_BYTES) {
                        continue;
                    }
                    if ($tool === 'codex') {
                        // turn_context / session_meta lines carry the model and the working folder
                        if (str_contains($line, '"turn_context"') || str_contains($line, '"session_meta"')) {
                            if (preg_match('/"model":"([^"]+)"/', $line, $mm)) {
                                $model = $mm[1];
                            }
                            if (preg_match('/"cwd":"((?:[^"\\\\]|\\\\.)*)"/', $line, $mm)) {
                                $cwd = stripcslashes($mm[1]);
                            }
                        }
                        $row = str_contains($line, '"token_count"') ? $this->extractCodex($line, basename($f), $model, $cwd, $now) : null;
                    } else {
                        $this->calls = [];
                        $row = str_contains($line, '"usage"') ? $this->extract($line, $now) : null;
                        foreach ($this->calls as [$cid, $cts, $cday, $cname]) {
                            $putC->execute([$cid, $cts, $cday, $tool, $cname, preg_match('/^mcp__(.+?)__/', $cname, $mm) ? $mm[1] : '']);
                        }
                    }
                    if ($row !== null) {
                        $row[] = $tool;
                        $putM->execute($row);
                        $msgs++;
                    }
                }
                $putF->execute([$f, $offset + strlen($data), $model, $cwd]);
                $touched++;
            }
        }
        $this->db->commit();
        return [$touched, $msgs];
    }

    /** Codex token_count event → counters only. input_tokens includes cached tokens (OpenAI style), so split them. */
    private function extractCodex(string $line, string $file, string $model, string $cwd, int $now): ?array
    {
        $j = json_decode($line, true);
        $u = is_array($j) ? ($j['payload']['info']['last_token_usage'] ?? null) : null;
        if (!is_array($u) || ($j['payload']['type'] ?? '') !== 'token_count') {
            return null;
        }
        $ts = strtotime((string) ($j['timestamp'] ?? '')) ?: $now;
        $cached = (int) ($u['cached_input_tokens'] ?? 0);
        return [
            'cx:' . $file . ':' . ($j['ordinal'] ?? $ts), $ts, date('Y-m-d', $ts), $model !== '' ? $model : 'codex', $cwd,
            max(0, (int) ($u['input_tokens'] ?? 0) - $cached), (int) ($u['output_tokens'] ?? 0), $cached, (int) ($u['cache_write_input_tokens'] ?? 0),
        ];
    }

    /** @return ?list<mixed> [id, ts, day, model, project, tin, tout, cread, cwrite] — counters only */
    private function extract(string $line, int $now): ?array
    {
        $j = json_decode($line, true);
        $m = is_array($j) ? ($j['message'] ?? null) : null;
        $u = is_array($m) ? ($m['usage'] ?? null) : null;
        if (!is_array($u)) {
            return null;
        }
        $id = (string) ($m['id'] ?? $j['uuid'] ?? '');
        $model = (string) ($m['model'] ?? '');
        if ($id === '' || $model === '' || $model[0] === '<') { // skip synthetic entries
            return null;
        }
        $ts = strtotime((string) ($j['timestamp'] ?? '')) ?: $now;
        // tool_use block NAMES only (Bash, Edit, mcp__server__tool …): no inputs, no results
        foreach ((array) ($m['content'] ?? []) as $blk) {
            if (is_array($blk) && ($blk['type'] ?? '') === 'tool_use' && !empty($blk['id']) && !empty($blk['name'])) {
                $this->calls[] = [(string) $blk['id'], $ts, date('Y-m-d', $ts), mb_substr((string) $blk['name'], 0, 120)];
            }
        }
        return [
            $id, $ts, date('Y-m-d', $ts), $model, (string) ($j['cwd'] ?? ''),
            (int) ($u['input_tokens'] ?? 0), (int) ($u['output_tokens'] ?? 0),
            (int) ($u['cache_read_input_tokens'] ?? 0), (int) ($u['cache_creation_input_tokens'] ?? 0),
        ];
    }

    public function prune(int $now): void
    {
        $this->db->prepare('DELETE FROM usage_msgs WHERE ts < ?')->execute([$now - 90 * 86400]);
        $this->db->prepare('DELETE FROM tool_calls WHERE ts < ?')->execute([$now - 90 * 86400]);
    }

    // ---------------------------------------------------------------- reporting

    /** Estimated cost in USD for one model's counters, or null when the model is not in the pricing table */
    public static function cost(array $pricing, string $model, int $tin, int $tout, int $cread, int $cwrite): ?float
    {
        foreach ($pricing as $re => [$pin, $pout, $rm, $wm]) {
            if (preg_match($re, $model)) {
                return ($tin * $pin + $tout * $pout + $cread * $pin * $rm + $cwrite * $pin * $wm) / 1e6;
            }
        }
        return null;
    }

    /** Aggregates for the UI: daily totals (14 d), models and projects (7 d), summary (today / 7 d / 30 d) */
    public static function report(PDO $db, array $pricing, int $now): array
    {
        $day = fn(int $back) => date('Y-m-d', $now - $back * 86400);
        $q = function (string $sql, array $p = []) use ($db): array {
            $st = $db->prepare($sql);
            $st->execute($p);
            return $st->fetchAll();
        };
        $days = $q('SELECT day, SUM(tin) tin, SUM(tout) tout, SUM(cread) cread, SUM(cwrite) cwrite, COUNT(*) msgs
                    FROM usage_msgs WHERE day >= ? GROUP BY day ORDER BY day', [$day(13)]);

        $byModel = fn(string $from) => $q('SELECT tool, model, project, SUM(tin) tin, SUM(tout) tout, SUM(cread) cread, SUM(cwrite) cwrite, COUNT(*) msgs, MAX(ts) last
                    FROM usage_msgs WHERE day >= ? GROUP BY tool, model, project', [$from]);
        $rows7 = $byModel($day(6));

        $models = [];
        $projects = [];
        $partial = false;
        $cost7 = 0.0;
        foreach ($rows7 as $r) {
            $c = self::cost($pricing, $r['model'], (int) $r['tin'], (int) $r['tout'], (int) $r['cread'], (int) $r['cwrite']);
            $partial = $partial || $c === null;
            $cost7 += $c ?? 0;
            $m = &$models[$r['tool'] . '|' . $r['model']];
            $m ??= ['tool' => $r['tool'], 'model' => $r['model'], 'msgs' => 0, 'tin' => 0, 'tout' => 0, 'cread' => 0, 'cwrite' => 0, 'cost' => 0.0, 'priced' => true];
            foreach (['msgs', 'tin', 'tout', 'cread', 'cwrite'] as $k) {
                $m[$k] += (int) $r[$k];
            }
            $m['cost'] += $c ?? 0;
            $m['priced'] = $m['priced'] && $c !== null;
            unset($m);
            $p = &$projects[$r['project']];
            $p ??= ['project' => $r['project'], 'msgs' => 0, 'tin' => 0, 'tout' => 0, 'cread' => 0, 'cwrite' => 0, 'cost' => 0.0, 'priced' => true, 'last' => 0];
            foreach (['msgs', 'tin', 'tout', 'cread', 'cwrite'] as $k) {
                $p[$k] += (int) $r[$k];
            }
            $p['cost'] += $c ?? 0;
            $p['priced'] = $p['priced'] && $c !== null;
            $p['last'] = max($p['last'], (int) $r['last']);
            unset($p);
        }
        usort($models, fn($a, $b) => ($b['tin'] + $b['tout']) <=> ($a['tin'] + $a['tout']));
        usort($projects, fn($a, $b) => ($b['tin'] + $b['tout']) <=> ($a['tin'] + $a['tout']));

        $sum = fn(string $from) => $q('SELECT COALESCE(SUM(tin),0) tin, COALESCE(SUM(tout),0) tout, COALESCE(SUM(cread),0) cread, COALESCE(SUM(cwrite),0) cwrite, COUNT(*) msgs
                    FROM usage_msgs WHERE day >= ?', [$from])[0];
        return [
            'days' => $days,
            'models' => $models,
            'projects' => array_slice($projects, 0, 15),
            'totals' => ['today' => $sum($day(0)), 'd7' => $sum($day(6)), 'd30' => $sum($day(29))],
            'cost7' => round($cost7, 2),
            'cost_partial' => $partial,
            'calls' => [
                'tools' => $q('SELECT tool, name, COUNT(*) n FROM tool_calls WHERE ts >= ? GROUP BY tool, name ORDER BY n DESC LIMIT 25', [$now - 30 * 86400]),
                'servers' => $q("SELECT tool, server, COUNT(*) n FROM tool_calls WHERE ts >= ? AND server != '' GROUP BY tool, server ORDER BY n DESC", [$now - 30 * 86400]),
            ],
            'last_scan' => (int) ($q('SELECT v FROM kv WHERE k="usage_scan"')[0]['v'] ?? 0),
        ];
    }
}
