#!/bin/bash
# Probes a RUNNING panel for the protections SecAIQ Watch relies on. Usage: tests/security-check.sh [base-url]
# Exit code 0 = every probe behaved as expected.
BASE="${1:-http://127.0.0.1:8099}"
DIR="$(cd "$(dirname "$0")/.." && pwd)"
TOKEN="$(cat "$DIR/var/csrf.key" 2>/dev/null)"
fail=0
expect() {  # DESCRIPTION EXPECTED_CODE curl-args...
  local d="$1" want="$2"; shift 2
  local got; got=$(curl -s -o /dev/null -w '%{http_code}' "$@")
  if [ "$got" = "$want" ]; then printf "  ok    %-42s %s\n" "$d" "$got"; else printf "  FAIL  %-42s got %s, expected %s\n" "$d" "$got" "$want"; fail=1; fi
}
echo "Static files must not be served"
for p in var/csrf.key db/gateway.sqlite config/settings.php src/Actions.php bin/collect.php var/collector.log GUIDE.md .htaccess; do
  expect "/$p" 404 "$BASE/$p"
done
expect "path traversal" 404 --path-as-is "$BASE/img/../var/csrf.key"
expect "encoded traversal" 404 --path-as-is "$BASE/%2e%2e/var/csrf.key"
echo "Requests must come from a local host name"
expect "DNS rebinding (GET api.php)" 403 -H "Host: evil.example" "$BASE/api.php"
echo "Actions must be authenticated"
B='{"type":"finding.unack","params":{"id":"x"}}'
expect "no token" 403 -X POST -d "$B" "$BASE/action.php"
expect "wrong token" 403 -X POST -H "X-AIGW-Token: nope" -d "$B" "$BASE/action.php"
if [ -n "$TOKEN" ]; then
  expect "cross-site Origin" 403 -X POST -H "X-AIGW-Token: $TOKEN" -H "Origin: https://evil.example" -d "$B" "$BASE/action.php"
  expect "Sec-Fetch-Site cross-site" 403 -X POST -H "X-AIGW-Token: $TOKEN" -H "Sec-Fetch-Site: cross-site" -d "$B" "$BASE/action.php"
  expect "unknown action type" 400 -X POST -H "X-AIGW-Token: $TOKEN" -d '{"type":"rm.everything"}' "$BASE/action.php"
fi
expect "GET on action.php" 405 "$BASE/action.php"
echo "Hardening headers"
h=$(curl -sI "$BASE/" )
for hd in "X-Frame-Options: DENY" "X-Content-Type-Options: nosniff" "Content-Security-Policy:" "Referrer-Policy: no-referrer"; do
  if echo "$h" | grep -qi "^$hd"; then printf "  ok    %s\n" "$hd"; else printf "  FAIL  missing header %s\n" "$hd"; fail=1; fi
done
echo "File permissions (owner only)"
for f in var/csrf.key db/gateway.sqlite; do
  m=$(stat -f '%Lp' "$DIR/$f" 2>/dev/null || stat -c '%a' "$DIR/$f" 2>/dev/null)
  if [ "$m" = "600" ]; then printf "  ok    %-20s %s\n" "$f" "$m"; else printf "  FAIL  %-20s %s (expected 600)\n" "$f" "$m"; fail=1; fi
done
[ $fail = 0 ] && echo "All checks passed" || echo "Some checks FAILED"
exit $fail
