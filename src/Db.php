<?php
/** SQLite connection + schema. The collector writes, the web UI reads. */
require_once __DIR__ . '/Unclassified.php';

final class Db
{
    private static ?string $override = null;

    /** Switch this request to the synthetic demo database (never the real one) */
    public static function useDemo(): void
    {
        self::$override = dirname(__DIR__) . '/db/demo.sqlite';
    }

    public static function path(): string
    {
        return self::$override ?? dirname(__DIR__) . '/db/gateway.sqlite';
    }

    public static function connect(bool $readOnly = false): PDO
    {
        $pdo = new PDO('sqlite:' . self::path());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 4000');
        if ($readOnly) {
            $pdo->exec('PRAGMA query_only = 1');
        } else {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            self::migrate($pdo);
        }
        return $pdo;
    }

    private static function migrate(PDO $db): void
    {
        $db->exec(Unclassified::SCHEMA);
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS tools_seen (
    tool TEXT PRIMARY KEY, name TEXT, category TEXT, first_seen INTEGER, last_seen INTEGER
);
CREATE TABLE IF NOT EXISTS tool_live (
    tool TEXT PRIMARY KEY, procs INTEGER, cpu REAL, rss INTEGER, ts INTEGER
);
CREATE TABLE IF NOT EXISTS procs_live (
    tool TEXT, pid INTEGER, name TEXT, cpu REAL, rss INTEGER, etime TEXT
);
CREATE TABLE IF NOT EXISTS live_conns (
    tool TEXT, pid INTEGER, proc TEXT, proto TEXT, rip TEXT, rport INTEGER,
    host TEXT, provider TEXT, conf TEXT, bytes_in INTEGER, bytes_out INTEGER
);
CREATE TABLE IF NOT EXISTS traffic (
    minute INTEGER, tool TEXT, provider TEXT, bin INTEGER DEFAULT 0, bout INTEGER DEFAULT 0,
    PRIMARY KEY (minute, tool, provider)
);
CREATE TABLE IF NOT EXISTS dest (
    tool TEXT, provider TEXT, host TEXT, rip TEXT, rport INTEGER, conf TEXT,
    first_seen INTEGER, last_seen INTEGER, bin INTEGER DEFAULT 0, bout INTEGER DEFAULT 0,
    PRIMARY KEY (tool, rip, rport)
);
CREATE TABLE IF NOT EXISTS files (
    tool TEXT, path TEXT, kind TEXT, sensitive INTEGER DEFAULT 0,
    first_seen INTEGER, last_seen INTEGER, hits INTEGER DEFAULT 1,
    PRIMARY KEY (tool, path)
);
CREATE TABLE IF NOT EXISTS inventory (
    kind TEXT, tool TEXT, name TEXT, detail TEXT, seen INTEGER,
    PRIMARY KEY (kind, tool, name)
);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER, level TEXT, tool TEXT, msg TEXT
);
CREATE TABLE IF NOT EXISTS grants (
    tool TEXT, area TEXT, level TEXT, source TEXT, detail TEXT, seen INTEGER,
    PRIMARY KEY (tool, area, level, source)
);
CREATE TABLE IF NOT EXISTS actions (
    id TEXT PRIMARY KEY, ts INTEGER, type TEXT, params TEXT, label TEXT,
    status TEXT, result TEXT, backups TEXT, undone INTEGER DEFAULT 0
);
CREATE TABLE IF NOT EXISTS usage_files (path TEXT PRIMARY KEY, offset INTEGER, model TEXT, cwd TEXT);
CREATE TABLE IF NOT EXISTS usage_msgs (
    id TEXT PRIMARY KEY, ts INTEGER, day TEXT, model TEXT, project TEXT,
    tin INTEGER DEFAULT 0, tout INTEGER DEFAULT 0, cread INTEGER DEFAULT 0, cwrite INTEGER DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_usage_day ON usage_msgs(day);
CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tool TEXT, pid INTEGER, name TEXT, cwd TEXT DEFAULT '',
    start_ts INTEGER, last_seen INTEGER, bout INTEGER DEFAULT 0, bin INTEGER DEFAULT 0, active INTEGER DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_sessions_seen ON sessions(last_seen);
CREATE TABLE IF NOT EXISTS finding_acks (id TEXT PRIMARY KEY, ts INTEGER, until INTEGER, note TEXT);
CREATE TABLE IF NOT EXISTS tool_calls (id TEXT PRIMARY KEY, ts INTEGER, day TEXT, tool TEXT, name TEXT, server TEXT);
CREATE INDEX IF NOT EXISTS idx_tool_calls_day ON tool_calls(day);
CREATE TABLE IF NOT EXISTS dns_cache (ip TEXT PRIMARY KEY, host TEXT, ts INTEGER);
CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT);
CREATE INDEX IF NOT EXISTS idx_traffic_minute ON traffic(minute);
CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts);
SQL);
        // Add the files.area column to older databases
        $cols = array_column($db->query('PRAGMA table_info(files)')->fetchAll(), 'name');
        if (!in_array('area', $cols, true)) {
            $db->exec('ALTER TABLE files ADD COLUMN area TEXT');
        }
        foreach ([['actions', 'prev_hash', 'TEXT'], ['actions', 'hash', 'TEXT'], ['usage_msgs', 'tool', "TEXT DEFAULT 'claude-code'"], ['usage_files', 'model', 'TEXT'], ['usage_files', 'cwd', 'TEXT']] as [$t, $c, $def]) {
            if (!in_array($c, array_column($db->query("PRAGMA table_info($t)")->fetchAll(), 'name'), true)) {
                $db->exec("ALTER TABLE $t ADD COLUMN $c $def");
            }
        }
        $gcols = array_column($db->query('PRAGMA table_info(grants)')->fetchAll(), 'name');
        if (!in_array('meta', $gcols, true)) {
            $db->exec('ALTER TABLE grants ADD COLUMN meta TEXT');
        }
    }
}
