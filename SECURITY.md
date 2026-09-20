# Security policy

SecAIQ Watch is a local, read-only monitor. Its security model is described in `GUIDE.md` (section "Privacy & safety model").

## Reporting a vulnerability
Please do **not** open a public issue for security problems. Use GitHub's private vulnerability reporting
("Security" tab → "Report a vulnerability") on this repository.

Useful details: OS, PHP version, how the panel was started, and a minimal reproduction.

## Self-check
`tests/security-check.sh` probes a running panel (static-file exposure, DNS rebinding, token/origin checks, headers, file modes).
Run it after installing or changing anything about how the panel is served.

## Scope notes
- The panel is meant for `127.0.0.1` only. Do not expose it to a network.
- Malware already running as your user can read the database and token; SecAIQ Watch does not defend against that.
