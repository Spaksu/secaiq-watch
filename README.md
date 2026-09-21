# SecAIQ Watch

**See what the AI tools on your computer are doing.** SecAIQ Watch detects AI assistants, coding agents, local models and
MCP servers on your machine and shows where they connect, how much data they send, what they are allowed to reach, what
they have touched, and how many tokens they use — in a local dashboard. It is **read-only**: it never blocks traffic and
never sees prompts, responses or file contents.

> Local · read-only · no cloud · no account · PHP + SQLite · macOS, Linux, Windows

> **Beta (v0.9.0-beta).** It works well on macOS; Linux and Windows support has only been tested against sample command output.
> If something misbehaves, run `php bin/diagnostics.php` and [open an issue](https://github.com/Spaksu/secaiq-watch/issues) with the output.
> See [`CHANGELOG.md`](CHANGELOG.md) for known limitations.

![SecAIQ Watch overview (demo data)](docs/overview.jpg)
![Permission matrix (demo data)](docs/permissions.jpg)

<sub>Screenshots use the built-in demo mode (synthetic data).</sub>

## What you get

| | |
|---|---|
| **Detection** | ~37 tools (Claude, Codex, Cursor, Windsurf, Copilot, Gemini, Ollama, LM Studio, Aider, OpenCode, Zed, Kiro, Warp, Goose, MCP servers, agent frameworks…) and ~33 provider domains |
| **Network** | Live connections, destinations, bytes sent/received per tool, upload spike and baseline-anomaly alerts |
| **Permissions & risk** | Which tool can reach which sensitive area (SSH keys, `.env`, cloud credentials, browser data, keychain…), with one-click *Protect* presets for Claude Code and per-permission "how to remove it" guides for macOS, Linux and Windows |
| **Findings** | Risky settings ranked critical → low: bypass modes, broad allow rules, MCP servers, hooks, hard-coded secrets, prompt-injection-style instruction files, AI browser extensions, posture score A–F |
| **File access** | Files and folders tools have open, classified by sensitivity |
| **Usage** | Token usage per day, model, project and tool (Claude Code, Codex) with cost estimates |
| **Reports** | HTML / Markdown report, **AI-BOM** (CycloneDX 1.5), CSV and JSON exports, hash-chained action log with undo |
| **Guide** | Full user guide built into the app (**User guide** tab), also in [`GUIDE.md`](GUIDE.md) |

## Platform support

| | macOS | Linux | Windows |
|---|---|---|---|
| Processes | ✅ | ✅ | ✅ |
| Connections + bytes | ✅ | ✅ TCP | ⚠️ connections only |
| Open files | ✅ | ✅ (your processes) | ❌ |
| System permission scan (TCC) | ✅ optional | – | – |

Linux and Windows support is newer and has been tested with sample command output only — issues and fixes are welcome.

## Quick start

Requires **PHP 8.1+** with `pdo_sqlite`. No Composer, no build step, no database server, no account.
The commands differ per system, mostly because of **where PHP lives**: pick yours.

### macOS

```bash
brew install php git                                   # or use XAMPP's PHP: /Applications/XAMPP/xamppfiles/bin/php
git clone https://github.com/Spaksu/secaiq-watch.git && cd secaiq-watch
php bin/collect.php &                                  # collector (in the background)
php -S 127.0.0.1:8099 -t . router.php                  # panel
```
Background service that starts at login: `bin/install-agent.sh install`

### Linux

```bash
sudo apt install php-cli php-sqlite3 iproute2 git      # Debian/Ubuntu. Fedora: sudo dnf install php-cli php-pdo iproute git
git clone https://github.com/Spaksu/secaiq-watch.git && cd secaiq-watch
php bin/collect.php &                                  # collector (in the background)
php -S 127.0.0.1:8099 -t . router.php                  # panel
```
Background service (systemd user units): `bin/install-agent.sh install`

### Windows (PowerShell)

On Windows `php` is usually **not on your PATH**, so the plain `php` command fails with *"php is not recognized"*.
Install PHP first (XAMPP puts it in `C:\xampp\php`; or download the zip from php.net), then use the **full path** to `php.exe`.
`&` does not run things in the background on Windows, so use two windows:

```powershell
git clone https://github.com/Spaksu/secaiq-watch.git
cd secaiq-watch
$php = "C:\xampp\php\php.exe"          # change to where your php.exe is (write just: $php = "php" if it is on your PATH)
& $php bin\collect.php                  # window 1: the collector, keep it open
```
```powershell
# window 2, same folder:
$php = "C:\xampp\php\php.exe"
& $php -S 127.0.0.1:8099 -t . router.php # the panel
```
Background service that starts at logon (no open windows):
```powershell
powershell -ExecutionPolicy Bypass -File bin\install-agent.ps1 install -Php C:\xampp\php\php.exe
```
If PHP complains about `pdo_sqlite`, enable `extension=pdo_sqlite` and `extension=sqlite3` in `php.ini` (XAMPP has them on).
Windows shows processes and connections but has no per-connection byte counters and no open-file view.

### Then

Open **http://127.0.0.1:8099/** (only `127.0.0.1` / `localhost` are accepted). A service can be restarted from **⚙ Settings**.

Optional provider/app icons: `bin/icons.sh` (macOS) extracts app icons from your installed apps and downloads brand logos.
The UI works without them.

**Live demo (synthetic data, no install): https://spaksu.github.io/secaiq-watch/demo/**

Or locally with synthetic data: `php bin/seed-demo.php`, then open `http://127.0.0.1:8099/?demo=1`. (`php bin/build-static-demo.php` rebuilds the public demo.)

## Security in one paragraph

The panel accepts loopback connections with a local `Host` only (no DNS-rebinding), serves no data files, requires a secret
token + same-origin for every action, stores its database/token/queue owner-only, ships its own scripts (no CDN) under a strict
Content-Security-Policy, and escapes all observed text. Every change it makes to Claude Code / Codex settings is backed up,
logged and undoable. Details and honest limits: [`GUIDE.md`](GUIDE.md) → *Privacy & safety model*, [`SECURITY.md`](SECURITY.md).
Check a running install with `tests/security-check.sh`.

## Configuration

Everything is editable in **⚙ Settings**; the file is `config/settings.php` (see `config/settings.example.php`).
Tool and provider signatures live in `config/signatures.php`; the manual-removal texts in `config/howto.php`.

## Tests

```bash
php tests/platform-test.php     # parsers and signatures for macOS / Linux / Windows output
tests/security-check.sh         # probes the running panel
```

## License

[MIT](LICENSE) © SecAIQ. Third-party notices: [`NOTICE.md`](NOTICE.md). The SecAIQ name and logo are not covered by the code license.
