# SecAIQ Watch — User Guide

SecAIQ Watch is a **local, read-only** monitor for the AI tools on your computer. It detects them, shows where they
connect and how much data they send, what they can access, what they have touched, and how many tokens they use.

It never blocks traffic, never sees prompts/responses or file *contents*, and sends nothing anywhere: everything stays
in a local SQLite file. The panel only answers on `127.0.0.1`.

---

## 1. What it shows

| Tab | What you get |
|---|---|
| **Overview** | KPIs, traffic over time, providers, active tools, recent changes, events, posture score (A–F), top findings |
| **Findings** | Risky configuration, ranked `critical / high / medium / low`, with *why* and a fix or **Accept risk** |
| **Permissions & risk** | Matrix of *which tool may reach which sensitive area* (SSH keys, `.env`, cloud credentials, browser data…), plus **Protect** presets |
| **Network activity** | Live connections, destinations (host / provider), bytes in/out, upload heatmap |
| **File access** | Files, folders and working dirs the tools have open, classified by sensitivity |
| **Usage** | Token usage per day, model, project and tool (Claude Code, Codex) with a cost *estimate* |
| **Inventory** | Installed apps and CLIs, config folders, MCP servers, hooks, hard-coded secrets, instruction files, browser extensions, projects |
| **User guide** | This guide, inside the app (rightmost tab) |

Detected out of the box: ~37 tools (Claude, Codex, Cursor, Windsurf, Copilot, Gemini, Ollama, LM Studio, Aider,
OpenCode, Zed, Kiro, Warp, Goose, MCP servers, agent frameworks…) and ~33 provider domains. Add more in
`config/signatures.php`.

Hover any label for a short explanation (tooltip).

---

## 2. Requirements

- **PHP 8.1+** with `pdo_sqlite` (XAMPP works). No Composer, no database server, no build step.
- A browser.
- Per OS:

| | macOS | Linux | Windows |
|---|---|---|---|
| Processes | ✅ | ✅ | ✅ |
| Connections + bytes sent/received | ✅ (`nettop`) | ✅ TCP (`ss`) | ⚠️ connections only, **no byte counters** |
| Open files | ✅ (`lsof`) | ✅ (`/proc`, your own processes) | ❌ |
| System permissions (TCC) | ✅ optional | – | – |
| Desktop notifications | ✅ | needs `notify-send` | ✅ |

Linux extras: `ss` (package `iproute2`), optional `libnotify-bin`.
Linux/Windows support is newer and has been tested with sample outputs only — please report anything odd.

---

## 3. Install

Put the folder anywhere (for example `~/secaiq-watch`). Nothing needs a web server: the panel runs on
PHP's built-in server.

### Quick try (any OS, no install)

```bash
php bin/collect.php                 # terminal 1: the collector (keep it running)
php -S 127.0.0.1:8099 -t . router.php   # terminal 2: the panel (router.php hides the database, token and logs)
```
Open <http://127.0.0.1:8099/>. Ctrl+C stops each one.

### As a background service (recommended)

Starts at login, restarts if it stops, and can be restarted from **⚙ Settings → Restart collector**.

**macOS** (LaunchAgents `com.secaiq.watch.collector` and `com.secaiq.watch.panel`)
```bash
bin/install-agent.sh install        # also: status | uninstall
```
Optional, for the system-permission scan: System Settings → Privacy & Security → **Full Disk Access** → add the
`php` binary the script prints (⌘⇧G in the file dialog to paste the path), then **⚙ Settings → Restart collector**.
Grant it to `php`, not to Terminal: a collector started from a terminal loses the permission.

**Linux** (systemd user units, no root)
```bash
bin/install-agent.sh install        # runs bin/install-systemd.sh; also: status | uninstall
loginctl enable-linger $USER        # optional: keep running when logged out
```

**Windows** (Task Scheduler, no admin)
```powershell
powershell -ExecutionPolicy Bypass -File bin\install-agent.ps1 install
# optional: -Php C:\xampp\php\php.exe   |   status   |   uninstall
```

Panel: **http://127.0.0.1:8099/** · logs: `var/collector.log`, `var/panel.log`.

Do **not** run `bin/start.sh` once the service is installed (it would start a second collector).

### Updating
Replace the files and restart the collector (**⚙ Settings → Restart collector**). New database tables/columns are
created when the collector starts, so a restart is required after updates.

---

## 4. First 5 minutes

1. Open the panel. Data appears within a few seconds; wait a minute for traffic to build up.
2. **Overview → posture score** gives a quick grade. Click a finding to see the reason.
3. **Findings**: work top-down. For each one either fix it (button/instructions) or **Accept risk** if it is intended
   (accepted findings move to a separate list and can be restored).
4. **Permissions & risk → Protect**: pick a preset and confirm (see below).
5. **⚙ Settings**: turn on the scans you want (see below).
6. Not sure what it looks like with real activity? Add `?demo=1` to the URL for synthetic data (see *Demo mode*).

---

## 5. Settings (⚙ top right)

Settings are saved to `config/settings.php` (a backup is taken before every change) and can also be edited by hand.

| Setting | Default | Meaning |
|---|---|---|
| System permission scan | off | macOS only. Reads the TCC permission database **read-only** (Full Disk Access, Screen Recording, Accessibility, Camera/Mic…) and checks only whether critical folders exist |
| Desktop notifications | off | Notify when a tool touches a *critical* area for the first time |
| "Your turn" notification | off | Notify when a coding agent finishes a burst of work and waits for you |
| Anomaly alerts | on | Warn when a tool's activity is far above its own 14-day baseline |
| Claude Code / Codex token usage | off | Reads **only numeric counters**, model id and folder name from local session logs (`~/.claude/projects`, `~/.codex/sessions`). Never message text |
| Daily token alert (thousands) | 0 (off) | Warn when daily tokens exceed the limit |
| Upload alert (MB / 5 min) | 100 | Warn when one tool sends more than this in 5 minutes (`0` = off) |

The Collector row shows whether it runs as a service and offers **↻ Restart collector**.

---

## 6. Protecting yourself (Permissions & risk)

SecAIQ Watch is an observer, but it can tighten *Claude Code's own permission rules* and revoke grants for you.
Every change:

- asks for confirmation and states exactly which file it edits,
- takes a **backup** first,
- is recorded in the **action log** (hash-chained, tamper-evident) and can be **undone** from there.

| Action | What it does |
|---|---|
| **Protect presets** — *Developer* / *Balanced* / *Strict* | Adds `permissions.deny` rules to `~/.claude/settings.json`. Developer: SSH, keychain, GPG. Balanced: + cloud credentials, `.env`, destructive commands (`rm -rf`, `sudo`, force-push…). Strict: + browser data, messages/mail |
| Deny one area | Same, for a single area |
| Revoke a Claude Code allow rule | Removes that rule from the settings file it came from |
| Disable bypass mode | Turns off "bypass permissions" |
| Codex: untrust / harden | Removes a trusted project, or tightens the Codex sandbox |
| Reset a macOS permission | `tccutil reset` for one app (may require admin) |

Changes are made by the collector (not the web page), and only for whitelisted actions, from the local machine.

---

## 7. Permissions SecAIQ Watch cannot remove — do it yourself

**In the app:** every permission row (Critical access feed → *Granted permissions*, and the tool drawer → *Granted
permissions & removal*) has a **"How to remove this yourself"** panel with the steps for that specific area. It opens
on your OS; switch between macOS / Linux / Windows with the buttons inside. For macOS permission-database grants it
also shows the exact `tccutil reset …` command for that entry. Rows that have a removal button get a
"Manual removal / more options" panel instead. The texts live in `config/howto.php` and can be edited.

The tables below are the same knowledge in one place.

SecAIQ Watch only edits Claude Code / Codex settings and (on macOS) resets one app's TCC entry. Everything else is
controlled by the operating system or by the tool itself. Use the matrix in **Permissions & risk** to find *what* to
remove, then follow the steps for your OS. Paths in `<angle brackets>` are placeholders.

### macOS
| Permission | How to remove |
|---|---|
| Full Disk Access, Accessibility, Screen Recording, Input Monitoring, Camera, Microphone, Files & Folders, Automation, Contacts/Photos | **System Settings → Privacy & Security →** the category → switch the app off (or select it and press **−**). Terminal alternative: `tccutil reset <Service> <bundle-id>` (e.g. `tccutil reset ScreenCapture com.example.App`); `tccutil reset All <bundle-id>` clears everything for that app. Then restart the app |
| Apps that start at login | **System Settings → General → Login Items & Extensions** |
| Background agents (LaunchAgents) | `launchctl bootout gui/$(id -u)/<label>` then delete `~/Library/LaunchAgents/<label>.plist` (system-wide ones are in `/Library/LaunchAgents`, need admin) |
| Keychain items an app may read | **Keychain Access →** item → **Access Control** → remove the app; or delete the item |
| Browser extensions | `chrome://extensions` (or the browser's extension page) → Remove |
| Outbound network | Not built in — use an app firewall such as LuLu or Little Snitch |

Notes: Mac profiles managed by an organisation (MDM) may lock some switches. `tccutil` cannot change permissions
granted by an MDM profile.

### Linux
There is no central permission database; access comes from file permissions, groups, sandboxes and services.
| Permission | How to remove |
|---|---|
| Access to your files | Tighten the files themselves: `chmod 700 ~/.ssh ~/.gnupg`, move secrets out of `.env` files, or run the tool as a separate user |
| Flatpak apps | `flatpak override --user --nofilesystem=home <app-id>` (or use **Flatseal**); `flatpak permission-reset <app-id>` clears portal grants (camera, screen, files) |
| Snap apps | `snap connections <snap>` then `snap disconnect <snap>:<plug>` (e.g. `home`, `camera`, `removable-media`) |
| Group memberships (docker, sudo…) | `sudo gpasswd -d $USER docker` (log out and in afterwards) |
| sudo rights | Edit with `sudo visudo` / `/etc/sudoers.d/`; avoid `NOPASSWD` for accounts that run AI tools |
| SSH agent keys | `ssh-add -D` (unload all); remove or re-protect the key with a passphrase |
| Saved passwords / keyrings | **Seahorse** ("Passwords and Keys") or `secret-tool clear …`; `pass rm <entry>` |
| Background services | `systemctl --user disable --now <unit>`; autostart entries in `~/.config/autostart/` |
| Outbound network | Per-user rule, e.g. `sudo iptables -A OUTPUT -m owner --uid-owner <user> -d <ip> -j REJECT`, or `ufw`/OpenSnitch for per-app rules |
| Notifications, camera, microphone | Desktop settings → **Privacy** / **Applications**; PipeWire/portal grants are cleared with `flatpak permission-reset` |

### Windows
| Permission | How to remove |
|---|---|
| Camera, Microphone, Location, Documents, Pictures, File system, Screen capture | **Settings → Privacy & security →** the category → switch the app off (desktop apps: the "Let desktop apps access…" toggle) |
| Packaged (Store) app permissions | **Settings → Apps → Installed apps →** ⋯ **→ Advanced options → App permissions** |
| Apps that start at login | **Settings → Apps → Startup**, or **Task Manager → Startup apps** |
| Scheduled tasks | `schtasks /query /fo LIST` then `schtasks /delete /tn "<name>" /f` (or Task Scheduler) |
| Saved credentials | **Credential Manager**, or `cmdkey /list` then `cmdkey /delete:<target>` |
| Administrator rights | **Settings → Accounts → Other users** (make the account Standard); check `net localgroup Administrators` |
| Protected folders | **Windows Security → Virus & threat protection → Ransomware protection → Controlled folder access** |
| Outbound network | Admin PowerShell: `New-NetFirewallRule -DisplayName "Block <tool>" -Direction Outbound -Program "<path to exe>" -Action Block` (remove with `Remove-NetFirewallRule -DisplayName …`) |

### Any OS
- **Accounts and API keys**: revoke them at the provider (GitHub → Settings → Applications, Google Account →
  Security → Third-party access, OpenAI / Anthropic console → API keys). Rotating a key is the only real fix for a
  secret that was exposed.
- **MCP servers**: delete the entry in the tool's config file (Inventory shows which file), or remove the package.
- **Editor / assistant settings** (Cursor, Windsurf, Copilot, Zed…): change them inside the tool; SecAIQ Watch cannot edit them.
- **Browser extensions**: remove from the browser's extension page.
- After any change, wait a minute and check the **Permissions & risk** matrix and **Findings**: a grant that is really
  gone disappears from both.

## 8. What the Findings look at

Bypass/“allow everything” modes and broad allow rules · risky areas allowed · MCP servers (remote/HTTP, secrets in
config, unpinned packages, **unused** servers) · Claude Code **hooks** that run commands · **hard-coded secrets** in
config files (key names only; values are masked) · **instruction files** (`CLAUDE.md`, `AGENTS.md`, rules) with hidden
characters, encoded blobs, injection phrases or remote-execution snippets · observed access to critical files ·
AI browser extensions · upload spikes · unknown destinations (with **Trust host**) · missing destructive-command
protection · scans turned off.

Severity: `critical` > `high` > `medium` > `low`. Confidence: `certain` (seen or configured) or `likely`
(inferred, e.g. a shared IP).

---

## 9. Reports and exports

From the export menu, or directly:

| URL | Output |
|---|---|
| `export.php?f=html` | Standalone HTML report |
| `export.php?f=report` | Markdown report |
| `export.php?f=aibom` | **AI-BOM** (CycloneDX 1.5 JSON): tools, MCP servers, providers |
| `export.php?f=json` | Full snapshot (no tokens) |
| `export.php?f=events` / `files` / `usage` | CSV |

---

## 10. Demo mode

```bash
php bin/seed-demo.php               # once: creates db/demo.sqlite with synthetic data
```
Then open `http://127.0.0.1:8099/?demo=1`. Nothing real is read and all actions are disabled.
Useful for screenshots and for showing the tool to someone without exposing your data.

---

## 11. Privacy & safety model

**What it collects.** Process names and command lines, IP addresses/host names, byte counts, file *paths*, config *keys*.
Never prompts, responses, file contents or secret values. It observes only: no proxy, no traffic interception.

**What protects the panel** (all of it is checked by `tests/security-check.sh`, run it against your install):

- **Loopback only, local host names only.** Connections must come from `127.0.0.1`/`::1` *and* carry a `Host` of
  `localhost`, `127.0.0.1` or `[::1]`. A web page you visit cannot read the API by re-pointing its own domain at your
  machine (DNS rebinding).
- **Nothing sensitive is served as a file.** `router.php` (used by the services) answers 404 for `db/`, `var/`, `config/`,
  `src/`, `bin/`, `tests/`, dotfiles, `*.md`, `*.log`, database and key files. Under Apache the same is done with `.htaccess`.
- **Actions need proof.** POST only, secret token (`var/csrf.key`, mode 600), same-origin `Origin`/`Sec-Fetch-Site`, a fixed
  list of action types. The collector re-validates every request against its own data, never trusts the panel's parameters,
  and only edits known files (Claude Code / Codex settings, this app's settings). Every change is backed up, logged in a
  hash-chained audit log and can be undone.
- **Owner-only files.** The database, logs, backups, token and the action queue are created `600`/`700`.
  Set `AIWATCH_SHARED_WEB_USER=1` only if an Apache running as *another* user must write the queue (this weakens the above).
- **Hardened responses.** No framing (clickjacking), no MIME sniffing, no referrer, a Content-Security-Policy that allows
  only this app's own scripts and no remote resources — Tailwind is bundled in `vendor/` (pinned, hash in `vendor/README.txt`).
- **Untrusted text is escaped.** Process names, paths and config values can contain anything; every value is HTML-escaped,
  and exports guard against spreadsheet formula injection.

**Limits, honestly.** Anything running as *your* user can read `var/csrf.key` and the database — the panel protects
against other users, other machines and web pages, not against malware already running as you. SecAIQ Watch is a
visibility tool, not an endpoint-protection product: it cannot block traffic or stop a tool.

Data lives in `db/gateway.sqlite` and `var/`; delete them to reset. Cost figures are estimates from a price table in
`config/signatures.php`. History is pruned automatically (30–90 days) and logs are rotated at 5 MB.

---

## 12. Troubleshooting

| Symptom | Fix |
|---|---|
| Banner “collector is not running” | Install the service (§3) or run `php bin/collect.php` |
| Panel shows `denied` for macOS permissions | Grant Full Disk Access to the `php` binary (not Terminal), then **Restart collector** |
| Everything empty on Windows in *Files* / upload volume | Expected: Windows exposes no byte counters or open-file listing |
| Linux: no connections | Install `iproute2` (`ss`); only your own user's processes are visible |
| Tool not detected | Add a regex to `config/signatures.php` → `tools` |
| Numbers look odd after upgrading | Restart the collector so the database migrates |
| `http://localhost/<folder>/` (Apache/XAMPP) shows "SecAIQ Watch has its own address" | Intended: the app runs as its own service on `http://127.0.0.1:8099/`. Apache runs PHP as a user shared with every other app in htdocs, so it must not read the owner-only database. Set `AIWATCH_SHARED_WEB_USER=1` only if you knowingly accept that |
| Page says "Blocked: open this panel via http://127.0.0.1…" | You used another host name (LAN IP, machine name). Open `http://127.0.0.1:8099/` or `http://localhost:8099/` — this is the DNS-rebinding protection |
| Report/CSV times are off by an hour | Fixed: the app now uses the machine's timezone instead of php.ini's `date.timezone` |
| Port 8099 busy | Change `PORT` in `bin/install-agent.sh` / `install-systemd.sh`, or `-Port` on Windows |
| “Restart collector” is greyed out | The collector was started by hand; install the service |
| Tokens/usage empty | Turn on the usage setting; it currently supports Claude Code and Codex only |
| Terminal `head` prints a Perl usage error | XAMPP's `bin` is first on your PATH; call `php` by its full path instead |

---

## 13. Files at a glance

```
index.php            the UI (single page)          bin/collect.php     collector entry point
api.php              read-only JSON API            bin/install-*.sh/.ps1  service installers
action.php           queued, validated actions     bin/seed-demo.php   demo data
export.php           reports & exports             config/signatures.php  tools, providers, areas, presets
config/howto.php     manual removal steps per area and OS
router.php           php -S router: hides data files   guide.php           serves this guide to the UI
vendor/tailwind.js   bundled UI runtime (no CDN)       tests/security-check.sh  probes the running panel
src/Platform.php     macOS / Linux / Windows       config/settings.php    your settings (edit in the UI)
src/Collector.php    processes, traffic, files     tests/platform-test.php  parser tests
```

Run the tests: `php tests/platform-test.php` (parsers, per OS) and `tests/security-check.sh` (probes the running panel).

### Uninstall
`bin/install-agent.sh uninstall` (macOS/Linux) or `bin\install-agent.ps1 uninstall` (Windows), then delete the folder.
