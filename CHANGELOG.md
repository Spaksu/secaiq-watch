# Changelog

## 0.11.0-beta (2026-09-23)
- **Windows: the Files tab is no longer empty.** Ported from SecAIQ Watch Enterprise. Windows has no `lsof`, so the collector now asks the
  **Restart Manager** which AI tool holds a known credential file open (SSH keys, cloud CLI credentials, GPG, Credential Manager files, browser
  password and cookie databases), at most once a minute and without admin rights. Optionally, `bin\windows-file-audit.ps1 enable` (elevated,
  once) turns on file auditing for the credential folders; the collector then reads new Security-log records and shows every read by an AI
  tool, attributed by program path even when the process has already exited. The Windows banner shows the auditing status.
- Windows: an empty process list right after sign-in (WMI not ready yet) is no longer kept for 12 s; the next tick asks again.

## 0.10.3-beta (2026-09-23)
- **Windows: no console window at sign-in.** The Collector and Panel tasks started `powershell.exe` directly, which shows a window for a
  moment before `-WindowStyle Hidden` takes effect. The tasks now start a small `wscript` launcher that opens PowerShell hidden from the first
  moment, and the tasks are marked hidden. Run `bin\install-agent.ps1 install` again to update the tasks. Found by a Windows tester.

## 0.10.2-beta (2026-09-21)
- **Windows: the charts are no longer empty.** Windows has no per-connection byte counters, so every byte-based chart (traffic, provider
  breakdown, usage heatmap, sparklines, "active minutes") stayed blank. The collector now also records the number of open connections per
  minute, and on such systems the charts show **open connections** instead of data volume (clearly labelled); byte figures show "–".
  Preview the Windows view on any machine: `?demo=1&os=windows`.

## 0.10.1-beta (2026-09-21)
**Windows fix, please update.** The ACL lock-down added in 0.9.x/0.10.0 could crash-loop the collector on Windows: `icacls /inheritance:r ... /T`
left the files in `db/` and `var/` with an empty ACL that nobody could open, so the collector could not read its own database, restarted every
5 s and locked it again. Found by a Windows tester.
- Each file and folder is now restricted individually (never with `/T`), and afterwards everything must still be openable; if not, the change is undone
  with `icacls /reset` and never attempted again on that install (`.no-acl-lock`). It runs once (`.acl-locked`). Opt out: `AIWATCH_NO_ACL=1`.
- `bin/diagnostics.php` reports the lock-down state and any data file that cannot be opened.
- **If you were affected**: `git pull`, then run `icacls db /reset /T` and `icacls var /reset /T` once, and restart the two tasks.

## 0.10.0-beta (2026-09-21)

**Unrecognised tools are no longer silent.** Until now a process that was not in the signature list showed up only if it connected to a
known AI provider address; anything else was dropped, and the posture grade said nothing about it, so a dashboard that looked
authoritative could read well while an unfamiliar tool was running. Thanks to a reader who pointed this out.

- New **Not classified as AI** view (Network activity tab): every other process with outbound connections, with destinations and bytes.
  Browsers and OS services are listed separately (a filter switches them on).
- The **posture grade now states its coverage**: "reflects only the N AI tools recognised out of M signatures. K other processes are
  using the network and are not classified", with a link to the list. The KPI card shows how many tools the grade is based on.
- `unclassified` and `coverage` added to the API; new demo data; tests.
- Windows: connections now come from `netstat -ano` (fast) and the PowerShell process list is cached ~12 s, so the collector keeps its 3 s rhythm instead of taking ~12 s per cycle. Found by a Windows tester.
- Windows: `db/` and `var/` are locked to the current user with ACLs (`chmod` has no effect there); `bin/diagnostics.php` reports it.
- Fixed a garbled log line printed when a restart was requested from the UI.
- Windows installer: the `pdo_sqlite` check failed on Windows PowerShell 5.1 (it strips double quotes inside arguments to native programs). Found by a Windows tester.

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
