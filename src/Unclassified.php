<?php
/**
 * "Not classified": processes that are NOT a recognised AI tool but have outbound connections to a destination that is not a
 * known AI provider. They used to be dropped silently, which made the dashboard look complete when it was not; now they are
 * listed (grouped by process name) so an unfamiliar tool cannot stay invisible. Loopback traffic is not recorded.
 */
final class Unclassified
{
    public const SCHEMA = 'CREATE TABLE IF NOT EXISTS unclassified (
        proc TEXT PRIMARY KEY, kind TEXT, path TEXT, conns INTEGER, dests TEXT,
        bout INTEGER, bin INTEGER, first_seen INTEGER, last_seen INTEGER
    )';
    private const MAX_DESTS = 12;

    /** app | browser | system — browsers and OS services are listed too, but separately, because they talk to the network all day */
    public static function kind(string $cmd, string $name, array $sig): string
    {
        if (!empty($sig['browsers']) && preg_match($sig['browsers'], $cmd)) {
            return 'browser';
        }
        if (preg_match('#^(/System/|/usr/(libexec|sbin|lib)/|/sbin/|/lib/|/lib64/)|^[A-Za-z]:/windows/#i', $cmd)
            || preg_match('/^(svchost|system|mdnsresponder|trustd|apsd|nsurlsessiond|networkd|systemd[-\w]*|networkmanager|avahi-daemon)(\.exe)?$/i', $name)) {
            return 'system';
        }
        return 'app';
    }

    /**
     * @param list<array{proc:string,cmd:string,kind:string,host:?string,rip:string,rport:int,din:int,dout:int}> $samples one per connection this tick
     */
    public static function record(PDO $db, array $samples, int $now): void
    {
        $db->prepare('UPDATE unclassified SET conns = 0 WHERE last_seen < ?')->execute([$now]); // "now" = still connected in this tick
        $by = [];
        foreach ($samples as $s) {
            $g = &$by[$s['proc']];
            $g ??= ['kind' => $s['kind'], 'cmd' => $s['cmd'], 'conns' => 0, 'dests' => [], 'in' => 0, 'out' => 0];
            $g['conns']++;
            $g['dests'][] = ($s['host'] ?: $s['rip']) . ':' . $s['rport'];
            $g['in'] += $s['din'];
            $g['out'] += $s['dout'];
            unset($g);
        }
        if (!$by) {
            return;
        }
        $sel = $db->prepare('SELECT dests FROM unclassified WHERE proc = ?');
        $up = $db->prepare(
            'INSERT INTO unclassified(proc,kind,path,conns,dests,bout,bin,first_seen,last_seen) VALUES(?,?,?,?,?,?,?,?,?)
             ON CONFLICT(proc) DO UPDATE SET kind=excluded.kind, path=excluded.path, conns=excluded.conns, dests=excluded.dests,
               bout=bout+excluded.bout, bin=bin+excluded.bin, last_seen=excluded.last_seen'
        );
        foreach ($by as $proc => $g) {
            $sel->execute([$proc]);
            $old = json_decode((string) $sel->fetchColumn(), true);
            $dests = array_slice(array_values(array_unique(array_merge(is_array($old) ? $old : [], $g['dests']))), -self::MAX_DESTS);
            $path = mb_substr(preg_split('/\s+-/', trim($g['cmd']), 2)[0], 0, 200); // the executable, without its arguments
            $up->execute([(string) $proc, $g['kind'], $path, $g['conns'], json_encode($dests, JSON_UNESCAPED_UNICODE), $g['out'], $g['in'], $now, $now]);
        }
    }

    public static function prune(PDO $db, int $now): void
    {
        $db->prepare('DELETE FROM unclassified WHERE last_seen < ?')->execute([$now - 14 * 86400]);
    }
}
