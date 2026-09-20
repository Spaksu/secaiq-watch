#!/bin/bash
# Runs SecAIQ Watch as two per-user macOS LaunchAgents:
#   com.secaiq.watch.collector  the collector (starts at login, restarts if it stops, restartable from ⚙ Settings)
#   com.secaiq.watch.panel      the web panel on http://127.0.0.1:8099/ (PHP built-in server, no Apache needed)
# Full Disk Access is granted ONCE to the php binary (not to Terminal / VS Code).
#
# Usage: bin/install-agent.sh [install|uninstall|status]
set -e
DIR="$(cd "$(dirname "$0")/.." && pwd)"
# Linux uses systemd user units instead of LaunchAgents (Windows: bin/install-agent.ps1)
if [ "$(uname -s)" = "Linux" ]; then exec "$DIR/bin/install-systemd.sh" "$@"; fi
PHP=/Applications/XAMPP/xamppfiles/bin/php
[ -x "$PHP" ] || PHP="$(command -v php || true)"
[ -n "$PHP" ] || { echo "php not found: install PHP 8.1+ with the pdo_sqlite extension first."; exit 1; }
PORT=8099
DOMAIN="gui/$(id -u)"
AGENTS="$HOME/Library/LaunchAgents"
ENVBLOCK="<key>HOME</key><string>$HOME</string>
    <key>PATH</key><string>/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:/opt/homebrew/bin</string>
    <key>LC_ALL</key><string>C</string>"

# write_plist LABEL LOGFILE EXTRA_ENV_XML PROGRAM_ARG...
write_plist() {
  local label="$1" log="$2" extra="$3"; shift 3
  local args=""; for a in "$@"; do args="$args<string>$a</string>"; done
  cat > "$AGENTS/$label.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$label</string>
  <key>ProgramArguments</key><array>$args</array>
  <key>WorkingDirectory</key><string>$DIR</string>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>ThrottleInterval</key><integer>5</integer>
  <key>StandardOutPath</key><string>$log</string>
  <key>StandardErrorPath</key><string>$log</string>
  <key>EnvironmentVariables</key>
  <dict>
    $ENVBLOCK
    $extra
  </dict>
</dict>
</plist>
EOF
  plutil -lint "$AGENTS/$label.plist" >/dev/null
}

# Services installed before the SecAIQ rename (com.aiwatch.*) are removed so two collectors never run side by side
remove_legacy() {
  for l in com.aiwatch.collector com.aiwatch.panel; do
    if [ -f "$AGENTS/$l.plist" ] || launchctl print "$DOMAIN/$l" >/dev/null 2>&1; then
      launchctl bootout "$DOMAIN/$l" 2>/dev/null || true
      rm -f "$AGENTS/$l.plist"
      echo "Removed legacy agent $l"
    fi
  done
}

# bootout is asynchronous: wait for the old job to disappear, then bootstrap (retry a few times)
load() {
  launchctl bootout "$DOMAIN/$1" 2>/dev/null || true
  for i in 1 2 3 4 5 6 7 8 9 10; do launchctl print "$DOMAIN/$1" >/dev/null 2>&1 || break; sleep 0.5; done
  for i in 1 2 3 4 5; do
    launchctl bootstrap "$DOMAIN" "$AGENTS/$1.plist" 2>/dev/null && break
    [ "$i" = 5 ] && { launchctl bootstrap "$DOMAIN" "$AGENTS/$1.plist"; exit 1; }
    sleep 1
  done
  launchctl kickstart -k "$DOMAIN/$1"
}

case "${1:-status}" in
  install)
    remove_legacy
    sleep 1
    if lsof -nP -iTCP:$PORT -sTCP:LISTEN >/dev/null 2>&1 && ! launchctl print "$DOMAIN/com.secaiq.watch.panel" >/dev/null 2>&1; then
      echo "Port $PORT is already in use by another process; stop it first (or edit PORT in this script)."; exit 1
    fi
    "$DIR/bin/start.sh" stop >/dev/null 2>&1 || true   # stop a manually started collector
    mkdir -p "$AGENTS" "$DIR/var"
    write_plist com.secaiq.watch.collector "$DIR/var/collector.log" "<key>AIWATCH_LAUNCHD</key><string>1</string><key>AIWATCH_SERVICE</key><string>1</string>" "$PHP" "$DIR/bin/collect.php"
    write_plist com.secaiq.watch.panel "$DIR/var/panel.log" "<key>PHP_CLI_SERVER_WORKERS</key><string>4</string>" "$PHP" -S "127.0.0.1:$PORT" -t "$DIR" "$DIR/router.php"
    load com.secaiq.watch.collector
    load com.secaiq.watch.panel
    echo "Installed and started: com.secaiq.watch.collector, com.secaiq.watch.panel"
    echo "Panel: http://127.0.0.1:$PORT/"
    echo
    echo "One-time step for macOS permission scanning (optional):"
    echo "  System Settings → Privacy & Security → Full Disk Access → + → add:  $PHP"
    echo "  (press Cmd+Shift+G in the file dialog and paste that path), then use ⚙ Settings → Restart collector."
    ;;
  uninstall)
    remove_legacy
    for l in com.secaiq.watch.collector com.secaiq.watch.panel; do
      launchctl bootout "$DOMAIN/$l" 2>/dev/null || true
      rm -f "$AGENTS/$l.plist"
    done
    echo "Removed both agents (you can still start the collector manually with bin/start.sh start)"
    ;;
  status)
    for l in com.secaiq.watch.collector com.secaiq.watch.panel; do
      echo "$l:"
      if launchctl print "$DOMAIN/$l" >/dev/null 2>&1; then
        launchctl print "$DOMAIN/$l" | grep -E "^\s*(state|pid|last exit code) =" | sed 's/^[[:space:]]*/  /'
      else
        echo "  not installed (bin/install-agent.sh install)"
      fi
    done
    ;;
  *) echo "Usage: $0 [install|uninstall|status]"; exit 1 ;;
esac
