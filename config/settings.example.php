<?php
/**
 * SecAIQ Watch settings (example). Copy to config/settings.php, or just use ⚙ Settings in the web UI,
 * which writes config/settings.php for you (a backup is taken before each change).
 *
 * scan_system:        macOS only. Read the permission database (TCC.db) READ-ONLY and check whether critical folders exist.
 *                     Needs Full Disk Access for the php binary that runs the collector. File CONTENTS are never read.
 * notify_critical:    desktop notification when a tool touches a critical-severity area for the first time.
 * notify_idle:        desktop notification when a coding agent finishes a burst of work and waits for you.
 * anomaly_alerts:     warn when a tool is far above its own 14-day baseline.
 * scan_usage:         read ONLY numeric token counters, model id and folder name from local Claude Code / Codex session logs.
 * daily_token_alert_k: warn when daily tokens exceed this many thousand (0 = off).
 * upload_alert_mb:    warn when one tool sends more than this many MB in 5 minutes (0 = off).
 */
return [
    'scan_system' => false,
    'notify_critical' => false,
    'notify_idle' => false,
    'anomaly_alerts' => true,
    'scan_usage' => false,
    'daily_token_alert_k' => 0,
    'upload_alert_mb' => 100,
];
