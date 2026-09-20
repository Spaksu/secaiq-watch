# Changelog

## 0.9.0-beta (2026-09-20)
First public beta.

- Detection of ~37 AI tools and ~33 providers; network activity with per-tool bytes, upload-spike and baseline alerts.
- Permission matrix, Findings (posture score A–F), Protect presets, hash-chained action log with undo.
- Per-permission "how to remove this yourself" guide for macOS, Linux and Windows.
- File access, token usage (Claude Code, Codex), reports (HTML/Markdown), AI-BOM (CycloneDX 1.5), CSV/JSON exports.
- Built-in User guide tab, demo mode, macOS / Linux / Windows support.
- Hardened panel: loopback + local Host only, no data files served, owner-only data, strict CSP, bundled Tailwind.

Known limitations (why this is a beta)
- Linux and Windows adapters are tested with sample command output only, not yet on real machines.
- Windows has no per-connection byte counters and no open-file view.
- Linux: only your own user's processes are visible; UDP is not counted.
- Usage/token tracking covers Claude Code and Codex only.
- No automatic updater; update by replacing the files and restarting the collector.
