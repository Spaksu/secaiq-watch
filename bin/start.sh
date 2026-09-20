#!/bin/bash
# Starts / stops the collector in the background.  Usage: bin/start.sh [start|stop|status]
DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP=/Applications/XAMPP/xamppfiles/bin/php
[ -x "$PHP" ] || PHP="$(command -v php)"
PIDF="$DIR/var/collector.pid"
case "${1:-start}" in
  start)
    if [ -f "$PIDF" ] && kill -0 "$(cat "$PIDF")" 2>/dev/null; then echo "Already running (pid $(cat "$PIDF"))"; exit 0; fi
    nohup "$PHP" "$DIR/bin/collect.php" >> "$DIR/var/collector.log" 2>&1 &
    echo $! > "$PIDF"; echo "Started (pid $!). Panel: run  php -S 127.0.0.1:8099 -t \"$DIR\" \"$DIR/router.php\"  (or install the background service: bin/install-agent.sh install)" ;;
  stop)
    [ -f "$PIDF" ] && kill "$(cat "$PIDF")" 2>/dev/null && rm -f "$PIDF" && echo "Stopped" || echo "Not running" ;;
  status)
    [ -f "$PIDF" ] && kill -0 "$(cat "$PIDF")" 2>/dev/null && echo "Running (pid $(cat "$PIDF"))" || echo "Not running" ;;
esac
