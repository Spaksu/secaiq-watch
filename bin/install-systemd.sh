#!/bin/bash
# Runs SecAIQ Watch on Linux as two systemd USER units (no root needed):
#   secaiq-watch-collector.service  the collector (starts at login, restarts if it stops, restartable from ⚙ Settings)
#   secaiq-watch-panel.service      the web panel on http://127.0.0.1:8099/ (PHP built-in server)
# Called by bin/install-agent.sh on Linux; can also be run directly.
#
# Usage: bin/install-systemd.sh [install|uninstall|status]
set -e
DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP="$(command -v php || true)"
PORT=8099
UNITS="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"

need() { command -v "$1" >/dev/null 2>&1 || { echo "Missing: $1 — $2"; exit 1; }; }

write_unit() {  # NAME DESCRIPTION EXEC [EXTRA_ENV]
  cat > "$UNITS/$1.service" <<UNIT
[Unit]
Description=$2
After=network-online.target

[Service]
Type=simple
WorkingDirectory=$DIR
ExecStart=$3
Restart=always
RestartSec=5
Environment=LC_ALL=C
Environment=AIWATCH_SERVICE=1
${4:-}
StandardOutput=append:$DIR/var/$5.log
StandardError=append:$DIR/var/$5.log

[Install]
WantedBy=default.target
UNIT
}

# Units installed before the SecAIQ rename (aiwatch-*) are removed so two collectors never run side by side
remove_legacy() {
  for u in aiwatch-collector aiwatch-panel; do
    if [ -f "$UNITS/$u.service" ]; then
      systemctl --user disable --now "$u.service" 2>/dev/null || true
      rm -f "$UNITS/$u.service"
      echo "Removed legacy unit $u"
    fi
  done
}

case "${1:-status}" in
  install)
    remove_legacy
    need systemctl "systemd is required (use 'php bin/collect.php' and 'php -S 127.0.0.1:$PORT' by hand otherwise)"
    [ -n "$PHP" ] || { echo "php not found: install PHP 8.1+ with pdo_sqlite (e.g. apt install php-cli php-sqlite3)."; exit 1; }
    "$PHP" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' || { echo "PHP is missing the pdo_sqlite extension (apt install php-sqlite3)."; exit 1; }
    if command -v ss >/dev/null 2>&1 && ss -ltn "sport = :$PORT" 2>/dev/null | grep -q LISTEN && ! systemctl --user is-active --quiet secaiq-watch-panel; then
      echo "Port $PORT is already in use by another process; stop it first (or edit PORT in this script)."; exit 1
    fi
    mkdir -p "$UNITS" "$DIR/var"
    write_unit secaiq-watch-collector "SecAIQ Watch collector" "\"$PHP\" \"$DIR/bin/collect.php\"" "" collector
    write_unit secaiq-watch-panel "SecAIQ Watch panel" "\"$PHP\" -S 127.0.0.1:$PORT -t \"$DIR\" \"$DIR/router.php\"" "Environment=PHP_CLI_SERVER_WORKERS=4" panel
    systemctl --user daemon-reload
    systemctl --user enable --now secaiq-watch-collector.service secaiq-watch-panel.service
    echo "Installed and started: secaiq-watch-collector, secaiq-watch-panel"
    echo "Panel: http://127.0.0.1:$PORT/"
    echo
    echo "To keep them running when you are logged out (optional):  loginctl enable-linger $USER"
    command -v ss >/dev/null 2>&1 || echo "Note: 'ss' (iproute2) is not installed, so connections cannot be listed."
    command -v notify-send >/dev/null 2>&1 || echo "Note: 'notify-send' (libnotify-bin) is not installed, so desktop notifications are unavailable."
    ;;
  uninstall)
    remove_legacy
    systemctl --user disable --now secaiq-watch-collector.service secaiq-watch-panel.service 2>/dev/null || true
    rm -f "$UNITS/secaiq-watch-collector.service" "$UNITS/secaiq-watch-panel.service"
    systemctl --user daemon-reload 2>/dev/null || true
    echo "Removed both units (you can still run the collector by hand: php bin/collect.php)"
    ;;
  status)
    for u in secaiq-watch-collector secaiq-watch-panel; do
      echo "$u:"
      if [ -f "$UNITS/$u.service" ]; then
        systemctl --user show "$u.service" -p ActiveState -p SubState -p MainPID | sed 's/^/  /'
      else
        echo "  not installed (bin/install-agent.sh install)"
      fi
    done
    ;;
  *) echo "Usage: $0 [install|uninstall|status]"; exit 1 ;;
esac
