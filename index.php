<?php
require __DIR__ . '/src/Guard.php';
Guard::localOnly(true);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SecAIQ Watch</title>
<link rel="icon" type="image/svg+xml" href="img/secaiq-watch.svg">
<script src="vendor/tailwind.js"></script>
<script>
/* SecAIQ brand: navy #002562 · cyber blue #00AAE3 → teal #00CBB8. The "sky" scale is remapped so existing accents follow the brand;
   "violet" (used for received data) becomes teal. */
tailwind.config = {theme: {extend: {colors: {
  sky: {50: '#e8f8fd', 100: '#cdeffa', 300: '#7fd5f1', 500: '#00AAE3', 600: '#002562', 700: '#00709a', 800: '#00506f', 900: '#002562'},
  violet: {600: '#00CBB8', 700: '#00796d'},
}}}};
</script>

<style>
  body { font-feature-settings: "tnum"; }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
  .pulse { animation: p 1.6s ease-in-out infinite; } @keyframes p { 50% { opacity: .35 } }
  td, th { padding: .5rem .7rem; vertical-align: middle; }
  thead th { font-weight: 600; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
  tbody tr:hover { background: #f8fafc; }
  html { -webkit-font-smoothing: antialiased; }
  :focus-visible { outline: 2px solid #00AAE3; outline-offset: 2px; border-radius: 4px; }
  @media (prefers-reduced-motion: reduce) { .pulse { animation: none; } #drawer { transition: none; } html { scroll-behavior: auto; } }
  .brand-grad { background: linear-gradient(90deg, #00AAE3, #00CBB8); -webkit-background-clip: text; background-clip: text; color: transparent; }
  /* Modular grid: the column count is computed from the screen width (JS: applyCols); a module = N column units */
  #grid { display: grid; grid-template-columns: repeat(var(--cols, 4), minmax(0, 1fr)); gap: 12px; grid-auto-flow: dense; align-items: stretch; }
  .w { grid-column: span var(--s, 1); min-width: 0; display: flex; flex-direction: column; }
  .w-body { overflow: auto; flex: 1 1 auto; min-height: 0; }
  .h1 { max-height: 15rem } .h2 { max-height: 26rem } .h3 { max-height: 40rem } .h4 { max-height: 60rem }
  .edit-only { display: none; }
  .editing .edit-only { display: inline-flex; }
  .editing .w { outline: 1px dashed #94a3b8; outline-offset: 2px; }
  .editing .w-head { cursor: grab; }
  .w.dragging { opacity: .35; }
  .w.over { outline: 2px solid #00AAE3 !important; }
  .cell { display: inline-flex; align-items: center; justify-content: center; min-width: 26px; height: 26px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }
  .vt { writing-mode: vertical-rl; transform: rotate(180deg); white-space: nowrap; }
  #drawer { transition: transform .2s; }
  #tip { position: fixed; z-index: 90; max-width: 320px; pointer-events: none; background: #0f172a; color: #f1f5f9; border-radius: 10px; padding: 10px 12px; font-size: 12.5px; line-height: 1.45; box-shadow: 0 10px 30px rgba(15,23,42,.35); opacity: 0; transition: opacity .12s; }
  #tip b { display: block; font-size: 13px; margin-bottom: 3px; color: #fff; }
  #tip p + p { margin-top: 4px; color: #cbd5e1; }
  [data-tip] { cursor: help; }
  th[data-tip], .cell[data-tip] { cursor: pointer; }
  .sticky-h thead th { position: sticky; top: 0; background: #f8fafc; z-index: 1; }
  #tabs { scrollbar-width: none; } #tabs::-webkit-scrollbar { display: none; } /* no scrollbar inside the tab bar (it changed its height) */
  ::-webkit-scrollbar { width: 8px; height: 8px } ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px }
</style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen text-[14px]">
<div class="md:sticky md:top-0 z-30 bg-white/95 backdrop-blur border-b border-slate-200 shadow-sm">
 <div class="max-w-[1850px] mx-auto px-4">
  <header class="flex flex-wrap items-center justify-between gap-2 py-3">
    <div class="flex items-center gap-3">
      <img src="img/secaiq-watch.svg" alt="" class="w-9 h-9">
      <div>
        <h1 class="text-lg font-bold leading-tight tracking-tight"><span class="text-[#002562]">Sec</span><span class="brand-grad">AI</span><span class="text-[#002562]">Q</span> <span class="font-semibold text-slate-500">Watch</span> <span id="verBadge" class="ml-1 align-middle text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded border border-[#00AAE3] text-[#00709a] bg-[#e8f8fd]" title="Beta release: please report problems">beta</span></h1>
        <p class="text-xs text-slate-500">AI activity · permission matrix · critical access monitoring</p>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-2 text-sm">
      <select id="range" class="bg-white border border-slate-300 rounded px-2 py-1">
        <option value="1h">Last 1 hour</option><option value="24h" selected>Last 24 hours</option><option value="7d">Last 7 days</option>
      </select>
      <select id="preset" class="bg-white border border-slate-300 rounded px-2 py-1" title="Layout presets">
        <option value="">Layout…</option><option value="default">Default</option><option value="security">Security focus</option><option value="compact">Compact</option>
      </select>
      <div class="relative">
        <button id="modBtn" class="bg-white border border-slate-300 rounded px-2 py-1 hover:border-sky-500">▦ Modules</button>
        <div id="modMenu" class="hidden absolute right-0 mt-1 w-60 bg-white border border-slate-300 rounded-lg shadow-xl p-2 z-40 text-sm"></div>
      </div>
      <button id="editBtn" class="bg-white border border-slate-300 rounded px-2 py-1 hover:border-sky-500">✎ Edit</button>
      <button id="setBtn" class="bg-white border border-slate-300 rounded px-2 py-1 hover:border-sky-500" title="Settings">⚙ Settings</button>
      <div class="relative"><button id="expBtn" class="bg-white border border-slate-300 rounded px-2 py-1 hover:border-sky-500" title="Export reports and data">⭳ Export</button>
        <div id="expMenu" class="hidden absolute right-0 mt-1 w-60 bg-white border border-slate-300 rounded-lg shadow-xl p-1 z-40 text-sm">
          <a class="block px-3 py-1.5 rounded hover:bg-slate-100" href="export.php?f=report" download>📝 Report (Markdown, 7 days)</a>
          <a class="block px-3 py-1.5 rounded hover:bg-slate-100" href="export.php?f=html" target="_blank">🖨 Report (HTML — print to PDF)</a>
          <a class="block px-3 py-1.5 rounded hover:bg-slate-100" href="export.php?f=aibom" download>📦 AI-BOM (CycloneDX JSON)</a>
          <a class="block px-3 py-1.5 rounded hover:bg-slate-100" href="export.php?f=json" download>{ } All data (JSON)</a>
          <a class="block px-3 py-1.5 rounded hover:bg-slate-100" href="export.php?f=events" download>⚠ Events (CSV)</a>
          <a class="block px-3 py-1.5 rounded hover:bg-slate-100" href="export.php?f=files" download>🗂 File access (CSV)</a>
          <a class="block px-3 py-1.5 rounded hover:bg-slate-100" href="export.php?f=usage" download>📈 Token usage (CSV)</a>
        </div></div>
      <button id="fsBtn" class="bg-white border border-slate-300 rounded px-2 py-1 hover:border-sky-500" title="Fullscreen">⛶</button>
      <span id="status" class="flex items-center gap-2 px-3 py-1 rounded-full bg-white border border-slate-300">
        <span class="w-2 h-2 rounded-full bg-slate-500"></span><span>connecting…</span>
      </span>
    </div>
  </header>
  <nav id="tabs" role="tablist" aria-label="Sections" class="flex gap-1 overflow-x-auto overflow-y-hidden"></nav>
 </div>
</div>
<div class="max-w-[1850px] mx-auto px-4 py-5 space-y-4">

  <div id="banner" class="space-y-2"></div>
  <div id="editHint" class="hidden rounded-lg border border-sky-300 bg-sky-50 text-sky-800 px-3 py-2 text-xs">
    Edit mode: drag modules by their header to reorder them, use ◀ ▶ to change the width, ↕ for the height and ✕ to hide. The layout is saved automatically in this browser.
  </div>

  <main id="grid"></main>

  <footer class="text-xs text-slate-500 pb-4 pt-2">
    Read-only observation: traffic is never blocked and content (prompts/responses, file contents) is never seen. Byte counts come from the operating system's per-connection socket counters (macOS <span class="mono">nettop</span>, Linux <span class="mono">ss</span>); Windows does not expose them.
    <b>certain</b> = a connection of a known AI process; <b>likely</b> = another process connecting to an AI provider's IP (shared IPs can be wrong).
    File access only captures files that are open at sampling time.
  </footer>
</div>

<!-- Tool / area detail panel -->
<div id="drawerBg" class="hidden fixed inset-0 bg-slate-900/30 z-40"></div>
<aside id="drawer" role="dialog" aria-label="Details" class="fixed top-0 right-0 h-full w-full max-w-2xl bg-slate-50 border-l border-slate-300 z-50 overflow-y-auto translate-x-full"></aside>

<!-- Confirmation dialog + notifications -->
<div id="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" class="hidden fixed inset-0 z-[60] items-center justify-center bg-slate-900/40 p-4">
  <div class="bg-white border border-slate-300 rounded-xl max-w-lg w-full p-5 shadow-2xl">
    <h3 id="modalTitle" class="font-semibold text-base mb-2"></h3>
    <pre id="modalBody" class="text-xs text-slate-600 whitespace-pre-wrap break-words max-h-72 overflow-auto bg-slate-50 border border-slate-200 rounded p-2 font-sans"></pre>
    <div class="flex justify-end gap-2 mt-4">
      <button id="modalNo" class="px-3 py-1.5 rounded bg-slate-100 hover:bg-slate-200 text-sm">Cancel</button>
      <button id="modalYes" class="px-3 py-1.5 rounded text-sm font-medium"></button>
    </div>
  </div>
</div>
<div id="settingsModal" role="dialog" aria-modal="true" aria-label="Settings" class="hidden fixed inset-0 z-[55] items-center justify-center bg-slate-900/40 p-4">
  <div class="bg-white border border-slate-300 rounded-xl max-w-xl w-full p-5 shadow-2xl">
    <div class="flex items-center justify-between mb-3"><h3 class="font-semibold text-base">⚙ Settings</h3><button id="setClose" aria-label="Close settings" class="text-slate-500 hover:text-slate-900 text-xl px-2">✕</button></div>
    <div id="settingsBody" class="space-y-4"></div>
    <p class="text-[11px] mt-4"><a class="text-sky-700 underline" href="?demo=1">View the demo (synthetic data)</a> — safe for screenshots; needs <span class="mono">php bin/seed-demo.php</span> once.</p>
    <p class="text-[11px] text-slate-500 mt-2">Changes are written to <span class="mono">config/settings.php</span> by the collector; a backup is taken first and you can undo them from «Permissions &amp; risk → Action history».</p>
  </div>
</div>
<div id="toasts" role="status" aria-live="polite" class="fixed top-3 right-3 z-[70] space-y-2 w-80"></div>
<div id="tip"></div>

<script>
/* ------------------------------------------------------------ helpers */
const $ = s => document.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const bytes = n => { n = +n || 0; const u = ['B','KB','MB','GB','TB']; let i = 0; while (n >= 1024 && i < 4) { n /= 1024; i++; } return (i ? n.toFixed(n < 10 ? 2 : 1) : n) + ' ' + u[i]; };
const ago = (ts, now) => { const d = now - ts; return d < 5 ? 'just now' : d < 60 ? d + 's ago' : d < 3600 ? Math.floor(d/60) + 'm ago' : d < 86400 ? Math.floor(d/3600) + 'h ago' : Math.floor(d/86400) + 'd ago'; };
const palette = ['#00AAE3','#f472b6','#a3e635','#fbbf24','#a78bfa','#fb7185','#2dd4bf','#f97316','#94a3b8','#22d3ee'];
const colorOf = (() => { const m = {}; let i = 0; return k => m[k] ??= palette[i++ % palette.length]; })();
const store = { get(k, d) { try { const v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } },
                set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} } };

const SEV = { 'critical': {c: '#dc2626', bg: 'bg-red-500/15 text-red-700 border-red-500/40', w: [10, 6, 1]},
              'high': {c: '#ea580c', bg: 'bg-orange-500/15 text-orange-700 border-orange-500/40', w: [5, 3, .5]},
              'medium':   {c: '#ca8a04', bg: 'bg-yellow-500/10 text-yellow-700 border-yellow-500/30', w: [1, 1, 0]},
              'low':      {c: '#64748b', bg: 'bg-slate-100 text-slate-600 border-slate-300', w: [0, 0, 0]} };
const LEVEL = { // priority order: most dangerous first
  observed:  {t: 'Observed',   d: 'This tool was seen touching this area',          cls: 'bg-red-500 text-white',                 sym: '●'},
  granted:   {t: 'Granted',    d: 'This permission has been granted to the tool',              cls: 'bg-amber-500 text-slate-900',           sym: '✓'},
  inherited: {t: 'Inherited',  d: 'A terminal/editor permission passes to the CLI tool',      cls: 'border border-amber-500 text-amber-700', sym: '↳'},
  ask:       {t: 'Asks first', d: 'Asks for your approval on every use',        cls: 'bg-sky-600/70 text-white',              sym: '?'},
  possible:  {t: 'Theoretical', d: 'Runs as the same user; the area exists on this machine', cls: 'bg-slate-200 text-slate-600',    sym: '·'},
  denied:    {t: 'Denied',      d: 'Explicitly denied in the settings',                  cls: 'bg-emerald-600/80 text-white',          sym: '⛨'},
};
/* Long descriptions (popup) */
const LEVEL_LONG = {
  observed:  'This tool was seen holding a file in this area open. So there is real evidence of access (only files open at sampling time are seen).',
  granted:   'This tool has been explicitly granted this area/capability (a settings file or a macOS permission). It may not have used it yet, but it can.',
  inherited: 'The permission was given to a terminal/editor; CLI tools running inside it (claude, codex…) inherit the same permission.',
  ask:       'The tool asks you every time before doing this; there is no automatic permission.',
  possible:  'Theoretical: because the tool runs as your user account it can technically reach this area. But no access was seen and no explicit permission was granted — this is only a "the door may be open" warning.',
  denied:    'This access is explicitly denied in the settings (a deny rule). Protected.',
};
const SEV_LONG = {
  'critical': 'If compromised, gives direct access to accounts/servers (keys, passwords, secrets, full system control).',
  'high': 'Serious impact on personal/work data or system behavior. Should be watched closely.',
  'medium':   'Limited impact (e.g. project source code). Normal for most AI coding tools.',
  'low':    'Informational: worth knowing, no action needed.',
};
const AREA_DESC = {
  ssh: 'Private keys used to log in to remote servers (~/.ssh). If stolen, those servers can be accessed.',
  cloud: 'Cloud/CLI session credentials such as AWS, Kubernetes, Azure and GitHub CLI.',
  secrets: '.env files, API keys, certificates, .npmrc and configurations that contain passwords.',
  keychain: 'macOS Keychain and password-manager data.',
  wallet: 'Crypto wallet files and keys.',
  gpg: 'GPG private keys for signing/encryption.',
  browser: 'Browser cookies, saved passwords and session data (Login Data, Cookies, Local Storage).',
  messages: 'Messages (iMessage, Slack, Signal, Telegram…) and email data.',
  docs: 'Personal files in the Documents, Desktop and Downloads folders.',
  db: 'Application databases (sqlite, db, sql, MySQL data).',
  system: 'Shell startup files (.zshrc…), LaunchAgents/Daemons and /etc: persistent changes can be made to the system.',
  source: 'The project/source folder the tool works in. Expected access for coding agents.',
  fulldisk: 'macOS "Full Disk Access": the app can read the whole disk, including protected folders.',
  accessibility: 'macOS Accessibility permission: the app can see other apps on screen and can click/type.',
  screen: 'Screen recording permission: can capture everything shown on screen.',
  input: 'Permission to monitor or generate keyboard/mouse events (key-logging risk).',
  shell: 'Ability to run terminal commands. One of the most powerful capabilities; the approval setting matters.',
  automation: 'Permission to control other apps by script (AppleScript/Apple Events).',
  mcp: 'MCP servers: external tools/services the tool can connect to (files, database, browser…).',
  camera: 'Permission to access the camera.',
  mic: 'Permission to access the microphone.',
  personal: 'Permission to access Contacts, Calendar and Photos data.',
};
const RISK_LONG = 'Risk score: the severity-weighted sum of observed access (heaviest), granted permissions and theoretical access. Low < 8 · Medium < 25 · High < 60 · Critical ≥ 60.';
const tipAttr = (title, ...lines) => `data-tip="${esc([title, ...lines.filter(Boolean)].join('\n'))}"`;

const LEVEL_ORDER = ['observed', 'granted', 'inherited', 'ask', 'possible', 'denied'];

const DEMO = new URLSearchParams(location.search).get('demo') === '1'; // synthetic data: nothing real is read, actions are disabled
let D = null, derived = null, drawerState = null;

/* Pagination and search state */
const PGS = {}, SEARCH = {};
function pageOf(key, rows, size = 12) {
  const total = rows.length, pages = Math.max(1, Math.ceil(total / size));
  const p = PGS[key] = Math.min(Math.max(1, PGS[key] || 1), pages);
  const a = (p - 1) * size, b = Math.min(total, p * size);
  const btn = (d, t, dis) => `<button data-pg="${key}:${d}" ${dis ? 'disabled' : ''} class="px-2.5 py-1 rounded border border-slate-300 bg-white ${dis ? 'opacity-40 cursor-default' : 'hover:bg-slate-100'}">${t}</button>`;
  const ctrl = total > size ? `<div class="flex items-center justify-between gap-2 pt-3 text-xs text-slate-500"><span>${a + 1}–${b} of ${total} records</span><div class="flex items-center gap-1.5">${btn('first', '«', p === 1)}${btn(-1, '‹ Previous', p === 1)}<span class="px-2 text-slate-700">${p} / ${pages}</span>${btn(1, 'Next ›', p === pages)}${btn('last', '»', p === pages)}</div></div>` : '';
  return {rows: rows.slice(a, b), ctrl, total};
}

const fmtTok = n => { n = +n || 0; return n >= 1e9 ? (n / 1e9).toFixed(2) + 'B' : n >= 1e6 ? (n / 1e6).toFixed(2) + 'M' : n >= 1e3 ? (n / 1e3).toFixed(1) + 'K' : String(n); };
const usdFmt = v => '$' + (v >= 100 ? v.toFixed(0) : v.toFixed(2));
const usageOffShort = () => '<div class="text-slate-500 text-center py-4 text-sm">Turn on token usage tracking (⚙ Settings) to see this.</div>';
const usageOff = () => `<div class="text-center py-10"><div class="text-slate-600 mb-1">Token usage tracking is <b>off</b>.</div>
  <p class="text-xs text-slate-500 max-w-xl mx-auto mb-3">When on, SecAIQ Watch reads only the numeric token counters, model id and folder name from your local Claude Code session logs (<span class="mono">~/.claude/projects</span>). Prompts and responses are never stored or shown.</p>
  <button data-openset class="px-3 py-1.5 rounded bg-sky-600 hover:bg-sky-500 text-white text-sm">Turn it on in ⚙ Settings</button></div>`;

/* ------------------------------------------------------------ logos */
const badge = (name, size, amber) => `<span class="inline-flex items-center justify-center rounded-md font-semibold shrink-0 ${amber ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-800'}" style="width:${size}px;height:${size}px;font-size:${Math.round(size * .45)}px">${esc((name || '?').trim()[0]?.toUpperCase() || '?')}</span>`;
function toolIcon(key, size = 22) {
  if (key && key.startsWith('other:')) return badge(key.slice(6), size, true);
  const src = D?.icons?.[key];
  return src ? `<img src="${esc(src)}" width="${size}" height="${size}" class="rounded-md shrink-0" style="width:${size}px;height:${size}px;object-fit:contain" alt="">` : badge(toolName(key), size);
}
function provIcon(name, size = 18) {
  const src = D?.icons?.['p:' + name];
  return src ? `<img src="${esc(src)}" width="${size}" height="${size}" class="shrink-0" style="width:${size}px;height:${size}px;object-fit:contain" alt="">`
             : `<span class="inline-flex items-center justify-center shrink-0 rounded-full bg-slate-200 text-slate-500" style="width:${size}px;height:${size}px;font-size:${Math.round(size * .55)}px">◌</span>`;
}
const toolName = k => { const t = D?.tools.find(x => x.tool === k); return t ? t.name : (String(k).startsWith('other:') ? String(k).slice(6) : k); };
const short = p => D && D.home && p.startsWith(D.home) ? '~' + p.slice(D.home.length) : p;
/* Permission-removal buttons: g.acts comes from the server (Actions::describe) */
const actBtns = g => (g.acts || []).map(a => `<button data-do='${esc(JSON.stringify(a))}' class="text-[11px] px-2 py-0.5 rounded border ${a.danger ? 'border-red-500/60 text-red-700 hover:bg-red-50' : 'border-amber-500/50 text-amber-800 hover:bg-amber-50'}">${esc(a.label)}</button>`).join(' ');
/* Manual-removal guide (config/howto.php): steps per OS for permissions that have no button. Open state survives the 3 s refresh. */
const HOWTO_OPEN = new Set(); let HOWTO_OS = null;
const OS_LABEL = {mac: 'macOS', linux: 'Linux', windows: 'Windows'};
const md = t => esc(t).replace(/`([^`]+)`/g, '<code class="mono text-[11px] bg-slate-100 border border-slate-200 rounded px-1 break-all">$1</code>').replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');
function howtoHtml(g) {
  const h = D.howto && D.howto[g.area]; if (!h) return '';
  const os = HOWTO_OS || D.platform?.os || 'mac', key = g.tool + '|' + g.area;
  const hasBtn = (g.acts || []).length > 0;
  const steps = [...(g.hint && os === 'mac' ? ['Exact command for this entry: `' + g.hint + '`, then quit and reopen the app.'] : []), ...(h[os] || []), ...(h.all || [])];
  const tabs = ['mac', 'linux', 'windows'].map(o => `<button data-howto-os="${o}" class="px-2 py-0.5 rounded text-[11px] border ${o === os ? 'bg-sky-600 text-white border-sky-600' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50'}">${OS_LABEL[o]}</button>`).join(' ');
  return `<details data-howto="${esc(key)}" class="mt-1.5 rounded border border-slate-200 bg-white/60" ${HOWTO_OPEN.has(key) ? 'open' : ''}>
    <summary class="cursor-pointer select-none px-2 py-1 text-[11px] text-sky-700 font-medium">${hasBtn ? 'Manual removal / more options' : 'How to remove this yourself'}</summary>
    <div class="px-2 pb-2 text-[11px] text-slate-700 leading-relaxed"><div class="mb-1 text-slate-500">${esc(h.why || '')}</div>
      <div class="flex flex-wrap gap-1 mb-1.5">${tabs}</div>
      ${steps.length ? '<ol class="list-decimal pl-4 space-y-1">' + steps.map(x => `<li>${md(x)}</li>`).join('') + '</ol>' : '<div class="text-slate-500">No manual steps for this OS.</div>'}
      <div class="mt-1 text-[11px] text-slate-500">SecAIQ Watch cannot change this itself. After removing it, the row disappears within a minute.</div></div></details>`;
}
document.addEventListener('toggle', e => { const d = e.target; if (d.dataset && d.dataset.howto) { d.open ? HOWTO_OPEN.add(d.dataset.howto) : HOWTO_OPEN.delete(d.dataset.howto); } }, true);
document.addEventListener('click', e => { const b = e.target.closest('[data-howto-os]'); if (!b) return; e.preventDefault(); HOWTO_OS = b.dataset.howtoOs; renderAll(); });
/* ---- User guide tab: GUIDE.md (served by guide.php) rendered with a small Markdown converter (headings, tables, lists, code, inline) */
let GUIDE = null, GUIDE_STATE = 'idle';
function inlineMd(t) {
  const codes = [];
  let s = esc(t).replace(/`([^`]+)`/g, (_, c) => { codes.push(c); return '\u0000' + (codes.length - 1) + '\u0000'; });
  s = s.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
    .replace(/(^|[\s(])\*([^*\s][^*]*)\*(?=$|[\s).,;:])/g, '$1<i>$2</i>')
    .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer" class="text-sky-700 underline">$1</a>')
    .replace(/&lt;(https?:\/\/[^\s&]+)&gt;/g, '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-sky-700 underline">$1</a>');
  return s.replace(/\u0000(\d+)\u0000/g, (_, i) => `<code class="mono text-[12px] bg-slate-100 border border-slate-200 rounded px-1 break-words">${codes[i]}</code>`);
}
function guideHtml(src) {
  const lines = src.replace(/\r/g, '').split('\n'), out = [], toc = [];
  const slug = s => 'g-' + s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  const splitRow = row => { row = row.trim(); if (row.startsWith('|')) row = row.slice(1); if (row.endsWith('|')) row = row.slice(0, -1); const cells = []; let cur = '', tick = false;
    for (const ch of row) { if (ch === '`') tick = !tick; if (ch === '|' && !tick) { cells.push(cur); cur = ''; } else cur += ch; } cells.push(cur); return cells.map(c => c.trim()); };
  const isSep = l => l && l.includes('-') && /^\s*\|?[\s:|-]+\|?\s*$/.test(l);
  const isBlock = l => /^(#{1,4}\s|```|---+\s*$|\s*([-*]|\d+\.)\s+)/.test(l);
  let i = 0;
  while (i < lines.length) {
    const l = lines[i];
    if (l.trim() === '') { i++; continue; }
    if (l.startsWith('```')) { const buf = []; i++; while (i < lines.length && !lines[i].startsWith('```')) buf.push(lines[i++]); i++;
      out.push(`<pre class="my-3 bg-slate-900 text-slate-100 rounded-lg p-3 overflow-auto text-xs mono leading-relaxed">${esc(buf.join('\n'))}</pre>`); continue; }
    let m = l.match(/^(#{1,4})\s+(.*)$/);
    if (m) { const n = m[1].length, id = slug(m[2]);
      if (n === 1) { i++; continue; }
      if (n === 2) { toc.push([id, m[2]]); out.push(`<h2 id="${id}" class="scroll-mt-40 mt-8 mb-2 pt-4 border-t border-slate-200 text-lg font-semibold text-slate-900">${inlineMd(m[2])}</h2>`); }
      else out.push(`<h${n} id="${id}" class="scroll-mt-40 mt-5 mb-1.5 font-semibold text-slate-800 ${n === 3 ? 'text-[15px]' : 'text-sm'}">${inlineMd(m[2])}</h${n}>`);
      i++; continue; }
    if (/^---+\s*$/.test(l)) { i++; continue; }
    if (l.includes('|') && isSep(lines[i + 1])) {
      const head = splitRow(l); i += 2; const rows = [];
      while (i < lines.length && lines[i].includes('|') && lines[i].trim() !== '') rows.push(splitRow(lines[i++]));
      out.push(`<div class="overflow-x-auto my-3 rounded-lg border border-slate-200"><table class="w-full text-[13px]"><thead><tr>${head.map(h => `<th class="text-left">${inlineMd(h)}</th>`).join('')}</tr></thead><tbody>${rows.map(r => `<tr class="border-t border-slate-200 align-top">${r.map(c => `<td>${inlineMd(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);
      continue; }
    m = l.match(/^(\s*)([-*]|\d+\.)\s+(.*)$/);
    if (m) { const ordered = /\d/.test(m[2]), items = [];
      while (i < lines.length) { const x = lines[i].match(/^(\s*)([-*]|\d+\.)\s+(.*)$/);
        if (x) { items.push(x[3]); i++; }
        else if (/^\s{2,}\S/.test(lines[i]) && items.length) { items[items.length - 1] += ' ' + lines[i].trim(); i++; }
        else break; }
      out.push(`<${ordered ? 'ol' : 'ul'} class="my-2 pl-5 space-y-1 ${ordered ? 'list-decimal' : 'list-disc'}">${items.map(t => `<li>${inlineMd(t)}</li>`).join('')}</${ordered ? 'ol' : 'ul'}>`);
      continue; }
    const buf = [];
    while (i < lines.length && lines[i].trim() !== '' && !isBlock(lines[i]) && !(lines[i].includes('|') && isSep(lines[i + 1]))) buf.push(lines[i++].trim());
    if (!buf.length) { i++; continue; }
    out.push(`<p class="my-2 leading-relaxed">${inlineMd(buf.join(' '))}</p>`);
  }
  const nav = `<div class="flex flex-wrap gap-1.5 mb-2">${toc.map(([id, t]) => `<button data-guide-jump="${id}" class="text-xs px-2.5 py-1 rounded-full border border-slate-300 bg-white text-slate-700 hover:border-sky-500 hover:text-sky-700">${esc(t.replace(/^\d+\.\s*/, ''))}</button>`).join('')}</div>`;
  return `<div class="max-w-4xl text-[14px] text-slate-800">${nav}${out.join('')}</div>`;
}
document.addEventListener('click', e => { const b = e.target.closest('[data-guide-jump]'); if (b) document.getElementById(b.dataset.guideJump)?.scrollIntoView({block: 'start'}); });
/* Coverage: the grade only covers the tools SecAIQ Watch recognises; say so, and point at what is not classified */
const coverageHtml = () => {
  const c = D.coverage; if (!c) return '';
  const n = c.unclassified_apps || 0;
  return `<div class="text-xs mt-1 ${n ? 'text-amber-700' : 'text-slate-500'}">Coverage: this grade reflects only the ${c.recognized_seen} AI tool(s) recognised out of ${c.signatures} signatures. ${n ? `<b>${n} other process(es)</b> are using the network and are <b>not classified</b>: <button data-tab="network" class="underline font-medium">review them</button>.` : 'No other process is using the network right now (browsers and system services are listed separately).'}</div>`;
};
const conf = c => c === 'certain' ? '<span class="px-1.5 rounded bg-emerald-100 text-emerald-700 text-xs">certain</span>' : '<span class="px-1.5 rounded bg-amber-100 text-amber-700 text-xs">likely</span>';
const head = cols => '<thead class="text-left text-xs text-slate-500 uppercase"><tr>' + cols.map(c => `<th>${c}</th>`).join('') + '</tr></thead>';
const empty = (n, msg) => `<tr><td colspan="${n}" class="text-slate-500 text-center py-6">${msg}</td></tr>`;
const sevChip = s => `<span ${tipAttr('Severity: ' + s, SEV_LONG[s])} class="px-1.5 py-0.5 rounded border text-[11px] uppercase tracking-wide ${SEV[s]?.bg || ''}">${s}</span>`;

/* ------------------------------------------------------------ derived data: permission matrix */
function derive(d) {
  const areaMap = Object.fromEntries(d.areas.map(a => [a.key, a]));
  const cells = {}; // cells[tool][area] = { level: [{detail, source, count, last}] }
  const put = (tool, area, level, rec) => { ((cells[tool] ??= {})[area] ??= {})[level] ??= []; cells[tool][area][level].push(rec); };
  d.files.forEach(f => { if (f.area) put(f.tool, f.area, 'observed', {detail: f.path, source: 'open file', last: f.last_seen, hits: f.hits, kind: f.kind}); });
  d.grants.forEach(g => put(g.tool, g.area, g.level, {detail: g.detail, source: g.source}));
  // Score: severity weights of observed + granted areas
  const score = {};
  d.tools.forEach(t => {
    let s = 0; const c = cells[t.tool] || {};
    for (const [area, lv] of Object.entries(c)) {
      const sev = areaMap[area]?.sev; if (!sev) continue; const w = SEV[sev].w;
      if (lv.observed) s += w[0]; if (lv.granted) s += w[1]; if (lv.inherited) s += w[1] * .7; if (lv.possible) s += w[2];
    }
    score[t.tool] = Math.round(s);
  });
  return {areaMap, cells, score};
}
const riskOf = s => s >= 60 ? ['Critical', '#dc2626'] : s >= 25 ? ['High', '#ea580c'] : s >= 8 ? ['Medium', '#ca8a04'] : ['Low', '#059669'];
const primaryLevel = lv => LEVEL_ORDER.find(l => lv?.[l]);

/* ------------------------------------------------------------ MODULES (widgets) */
const sparkOf = tool => {
  const per = Array(60).fill(0); const start = Math.floor(D.now / 60) * 60 - 59 * 60;
  D.chart.forEach(r => { if (r.tool === tool) { const i = Math.floor((r.minute - start) / 60); if (i >= 0 && i < 60) per[i] += (+r.bout) + (+r.bin); } });
  const max = Math.max(1, ...per);
  return `<svg viewBox="0 0 120 26" class="w-full h-6" preserveAspectRatio="none"><polyline fill="none" stroke="${colorOf(tool)}" stroke-width="1.5" points="${per.map((v, i) => `${(i * 120 / 59).toFixed(1)},${(24 - v / max * 22).toFixed(1)}`).join(' ')}"/></svg>`;
};

/* Matrix column headers: short names that fit on two lines (full name in the tooltip) */
const MSHORT = {ssh: 'SSH keys', cloud: 'Cloud keys', secrets: '.env / secrets', keychain: 'Keychain', wallet: 'Crypto wallet', gpg: 'GPG keys',
  browser: 'Browser data', messages: 'Mail / chat', docs: 'Docs', db: 'DB files', system: 'System config', source: 'Source code',
  fulldisk: 'Full disk access', accessibility: 'UI control', screen: 'Screen rec.', input: 'Key / mouse', shell: 'Shell',
  automation: 'Scripting', mcp: 'MCP / tools', camera: 'Camera', mic: 'Mic', personal: 'Contacts'};

const WIDGETS = {
  kpi: { title: 'Summary', tab: 'overview', desc: '', span: 99, h: 0, render() {
    const k = D.kpi, cells = derived.cells;
    let critObs = 0, critGr = 0;
    D.files.forEach(f => { if (f.area && derived.areaMap[f.area]?.sev === 'critical') critObs++; });
    D.grants.forEach(g => { if (g.level === 'granted' && derived.areaMap[g.area]?.sev === 'critical') critGr++; });
    const rng = $('#range').selectedOptions[0].text.toLowerCase();
    const card = (l, v, sub, cls = '') => `<div class="bg-white border border-slate-200 rounded-xl p-3"><div class="text-xs text-slate-500">${l}</div><div class="text-2xl font-semibold mt-0.5 ${cls}">${v}</div><div class="text-xs text-slate-500">${sub}</div></div>`;
    return `<div class="grid gap-3" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">` +
      card('Active AI tools', k.active_tools, `${k.known_tools} tools seen`) +
      card('Open connections', k.conns, 'AI-related') +
      card('Data sent', bytes(k.bout), rng, 'text-sky-700') +
      card('Data received', bytes(k.bin), rng, 'text-violet-700') +
      card('Critical-area access', critObs, 'observed files', critObs ? 'text-red-600' : '') +
      card('Critical permissions', critGr, 'granted', critGr ? 'text-amber-700' : '') +
      card('Security posture', `${(D.posture || {}).grade || '—'}`, `${(D.posture || {}).score ?? '—'} / 100 · ${(D.coverage || {}).recognized_seen ?? '?'} tool(s) recognised`, ({A: 'text-emerald-700', B: 'text-lime-700', C: 'text-yellow-700', D: 'text-orange-700', F: 'text-red-600'})[(D.posture || {}).grade] || '') + `</div>`;
  }},

  traffic: { title: 'Traffic — last 60 minutes', tab: 'overview', desc: 'How much data the tools sent to and received from the network in the last 60 minutes (minute by minute).', span: 2, h: 0, render() {
    const mode = store.get('aigw.tmode', 'bout');
    const W = 600, H = 130, N = 60, bw = W / N, start = Math.floor(D.now / 60) * 60 - (N - 1) * 60;
    const per = Array.from({length: N}, () => ({})); const tools = new Set();
    D.chart.forEach(r => { const i = Math.floor((r.minute - start) / 60); if (i >= 0 && i < N) { const v = mode === 'bin' ? +r.bin : mode === 'both' ? (+r.bin) + (+r.bout) : +r.bout; per[i][r.tool] = v; tools.add(r.tool); } });
    const max = Math.max(1, ...per.map(o => Object.values(o).reduce((a, b) => a + b, 0)));
    let svg = '';
    per.forEach((o, i) => { let y = H; for (const [tool, v] of Object.entries(o)) { const h = Math.max(1, v / max * (H - 6)); y -= h; svg += `<rect x="${i * bw + .5}" y="${y}" width="${bw - 1}" height="${h}" fill="${colorOf(tool)}"><title>${esc(toolName(tool))}: ${bytes(v)}</title></rect>`; } });
    const btn = (m, t) => `<button data-tm="${m}" class="px-2 py-0.5 rounded ${mode === m ? 'bg-sky-600 text-white' : 'bg-slate-100 text-slate-600'} text-xs">${t}</button>`;
    return `<div class="flex flex-wrap items-center justify-between gap-2 mb-2"><div class="flex gap-1">${btn('bout', 'Sent')}${btn('bin', 'Received')}${btn('both', 'Total')}</div>
      <div class="flex flex-wrap gap-3 text-xs text-slate-500">${[...tools].map(t => `<span class="flex items-center gap-1">${toolIcon(t, 14)}${esc(toolName(t))}</span>`).join('')}<span>peak ${bytes(max)}/min</span></div></div>
      <svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" class="w-full" style="height:150px"><line x1="0" y1="${H}" x2="${W}" y2="${H}" stroke="#cbd5e1"/>${svg}</svg>`;
  }},

  providers: { title: 'Provider breakdown', tab: 'overview', desc: 'Which AI provider the data went to.', span: 1, h: 0, render() {
    const list = D.providers.filter(p => (+p.bin) + (+p.bout) > 0); const total = list.reduce((a, p) => a + (+p.bin) + (+p.bout), 0);
    if (!total) return '<div class="text-slate-500 text-center py-8 text-sm">No traffic yet.</div>';
    const R = 42, C = 2 * Math.PI * R; let off = 0; const cols = ['#00AAE3','#f472b6','#a3e635','#fbbf24','#a78bfa','#fb7185','#2dd4bf','#94a3b8'];
    const segs = list.map((p, i) => { const v = (+p.bin) + (+p.bout), len = v / total * C; const s = `<circle r="${R}" cx="60" cy="60" fill="none" stroke="${cols[i % cols.length]}" stroke-width="16" stroke-dasharray="${len} ${C - len}" stroke-dashoffset="${-off}" transform="rotate(-90 60 60)"><title>${esc(p.provider)}: ${bytes(v)}</title></circle>`; off += len; return s; }).join('');
    return `<div class="flex items-center gap-4"><svg viewBox="0 0 120 120" class="w-32 h-32 shrink-0">${segs}<text x="60" y="58" text-anchor="middle" fill="#0f172a" font-size="13" font-weight="600">${bytes(total)}</text><text x="60" y="72" text-anchor="middle" fill="#64748b" font-size="8">total</text></svg>
      <div class="flex-1 space-y-1.5 text-sm min-w-0">${list.slice(0, 7).map((p, i) => { const v = (+p.bin) + (+p.bout); return `<div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full shrink-0" style="background:${cols[i % cols.length]}"></span>${provIcon(p.provider, 16)}<span class="truncate">${esc(p.provider)}</span><span class="ml-auto text-slate-500 text-xs">${bytes(v)} · ${(v / total * 100).toFixed(0)}%</span></div>`; }).join('')}</div></div>`;
  }},

  tools: { title: 'AI tools', tab: 'overview', desc: 'AI tools detected on this computer. Click a card for details.', span: 99, h: 0, render() {
    if (!D.tools.length) return '<div class="text-slate-500 text-center py-8">No AI tool seen yet.</div>';
    const cards = D.tools.map(t => {
      const [rl, rc] = riskOf(derived.score[t.tool] || 0); const c = derived.cells[t.tool] || {};
      const obs = Object.entries(c).filter(([a, lv]) => lv.observed && derived.areaMap[a]?.sev !== 'medium').length;
      const gr = Object.entries(c).filter(([a, lv]) => lv.granted && derived.areaMap[a]?.sev !== 'medium').length;
      return `<button data-tool="${esc(t.tool)}" class="text-left bg-white border border-slate-200 hover:border-sky-600 rounded-xl p-3 transition">
        <div class="flex items-center gap-3">${toolIcon(t.tool, 38)}
          <div class="min-w-0 flex-1"><div class="font-medium truncate">${esc(t.name)}</div><div class="text-xs text-slate-500">${esc(t.category)}</div></div>
          <div class="text-right"><div class="text-xs ${t.live ? 'text-emerald-600' : 'text-slate-500'} flex items-center gap-1 justify-end"><span class="w-1.5 h-1.5 rounded-full ${t.live ? 'bg-emerald-400 pulse' : 'bg-slate-600'}"></span>${t.live ? 'running' : 'stopped'}</div>
            <div class="text-[11px] mt-0.5" style="color:${rc}">risk: ${rl}</div></div></div>
        <div class="my-2">${sparkOf(t.tool)}</div>
        <div class="grid grid-cols-4 gap-1 text-center text-xs">
          <div><div class="text-slate-500">processes</div><div>${t.procs || '-'}</div></div><div><div class="text-slate-500">memory</div><div>${t.live ? bytes(t.rss) : '-'}</div></div>
          <div><div class="text-slate-500">↑ sent</div><div class="text-sky-700">${bytes(t.bout)}</div></div><div><div class="text-slate-500">↓ received</div><div class="text-violet-700">${bytes(t.bin)}</div></div></div>
        <div class="flex flex-wrap gap-1.5 mt-2 text-[11px]">
          <span class="px-1.5 rounded bg-slate-100 text-slate-600">${t.active_min} active min</span><span class="px-1.5 rounded bg-slate-100 text-slate-600">${t.conns} connections</span>
          ${obs ? `<span class="px-1.5 rounded bg-red-500/20 text-red-700">${obs} critical/high areas touched</span>` : ''}${gr ? `<span class="px-1.5 rounded bg-amber-500/20 text-amber-700">${gr} permission${gr === 1 ? '' : 's'}</span>` : ''}</div></button>`;
    }).join('');
    const others = D.other_traffic.map(o => `<div class="bg-white border border-dashed border-amber-300 rounded-xl p-3 text-sm"><div class="flex items-center gap-2">${toolIcon('other:' + o.name, 30)}<div><div class="font-medium">${esc(o.name)}</div><div class="text-xs text-amber-700">Process connecting to an AI provider (likely)</div></div></div><div class="text-xs mt-2 text-slate-500">↑ <span class="text-sky-700">${bytes(o.bout)}</span> · ↓ <span class="text-violet-700">${bytes(o.bin)}</span></div></div>`).join('');
    return `<div class="grid gap-3" style="grid-template-columns:repeat(auto-fill,minmax(280px,1fr))">${cards}${others}</div>`;
  }},

  matrix: { title: 'Permission matrix — which AI is authorized for which critical area?', tab: 'permissions', desc: 'Row: AI tool · Column: sensitive area or system capability. A colored cell = the tool accessed that area or has permission for it. Click a cell to see the evidence.', span: 99, h: 0, render() {
    const tools = D.tools; if (!tools.length) return '<div class="text-slate-500 text-center py-8">Waiting for tools…</div>';
    const groups = [['data', 'Data / file areas'], ['capability', 'System capabilities']];
    const areas = groups.flatMap(([g]) => D.areas.filter(a => a.group === g));
    const gh = groups.map(([g, l]) => `<th colspan="${D.areas.filter(a => a.group === g).length}" class="text-center text-[13px] font-semibold text-slate-700 border-l border-slate-200 py-2">${l}</th>`).join('');
    const ah = areas.map(a => `<th class="border-l border-slate-200 px-1 py-2 align-bottom" ${tipAttr(a.icon + ' ' + a.label, 'Severity: ' + a.sev.toUpperCase(), AREA_DESC[a.key])} style="border-bottom:3px solid ${SEV[a.sev].c}"><div class="flex flex-col items-center gap-1 leading-tight"><span class="text-xl leading-none">${a.icon}</span><span class="text-[12.5px] font-semibold text-slate-700 text-center normal-case" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere">${esc(MSHORT[a.key] || a.label)}</span></div></th>`).join('');
    const rows = tools.map(t => {
      const c = derived.cells[t.tool] || {}; const [rl, rc] = riskOf(derived.score[t.tool] || 0); const pct = Math.min(100, derived.score[t.tool] || 0);
      const tds = areas.map(a => {
        const lv = c[a.key]; const p = primaryLevel(lv);
        if (!p) return `<td class="border-l border-slate-200/60 text-center"><span class="text-slate-200">·</span></td>`;
        const n = lv[p].length; const L = LEVEL[p];
        const tip = Object.keys(lv).map(l => `${LEVEL[l].t}: ${lv[l].length}`).join(' · ');
        const sh = lv.denied && p !== 'denied' ? '<sup class="text-emerald-600">⛨</sup>' : '';
        return `<td class="border-l border-slate-200/60 text-center"><span class="cell ${L.cls}" style="min-width:32px;height:32px;font-size:14px" tabindex="0" role="button" data-cell="${esc(t.tool)}|${a.key}" ${tipAttr(t.name + ' → ' + a.label, tip, LEVEL_LONG[p], 'Click for details.')}>${L.sym}${p === 'observed' && n > 1 ? `<span class="text-[11px] ml-0.5">${n}</span>` : ''}${sh}</span></td>`;
      }).join('');
      return `<tr class="border-t border-slate-200"><td class="sticky left-0 bg-white pr-2"><button data-tool="${esc(t.tool)}" class="flex items-center gap-2.5 hover:text-sky-700 text-left">${toolIcon(t.tool, 26)}<span class="text-[14px] font-medium leading-tight">${esc(t.name)}</span></button></td>
        <td class="whitespace-nowrap"><div class="flex flex-col gap-1"><span class="text-sm font-semibold" ${tipAttr(t.name + ' — risk: ' + rl, 'Score: ' + (derived.score[t.tool] || 0), RISK_LONG)} style="color:${rc}">${rl}</span><div class="w-20 h-2 rounded bg-slate-100 overflow-hidden"><div class="h-full" style="width:${Math.max(4, pct)}%;background:${rc}"></div></div></div></td>${tds}</tr>`;
    }).join('');
    const legend = LEVEL_ORDER.map(l => `<span class="flex items-center gap-1.5 text-[13px]" ${tipAttr(LEVEL[l].t, LEVEL_LONG[l])}><span class="cell ${LEVEL[l].cls}" style="min-width:24px;height:24px">${LEVEL[l].sym}</span>${LEVEL[l].t} <span class="text-slate-500">ⓘ</span></span>`).join('');
    return `<div class="flex flex-wrap gap-5 text-xs text-slate-600 mb-2">${legend}</div>
      <table class="w-full text-sm border-collapse" style="table-layout:fixed;min-width:1560px"><colgroup><col style="width:220px"><col style="width:110px"></colgroup><thead><tr><th class="sticky left-0 bg-slate-50"></th><th></th>${gh}</tr><tr><th class="sticky left-0 bg-slate-50 text-left text-[13px] font-semibold text-slate-700 align-bottom">Tool</th><th class="text-left text-[13px] font-semibold text-slate-700 align-bottom" ${tipAttr('Risk level', RISK_LONG)}>Risk <span class="text-slate-500 font-normal">ⓘ</span></th>${ah}</tr></thead><tbody>${rows}</tbody></table>
      <p class="text-xs text-slate-500 mt-2">Click a cell to see the evidence. The risk score is the severity-weighted sum of observed (heaviest), granted and theoretical access.</p>`;
  }},

  critical: { title: 'Critical access feed', tab: 'permissions', desc: 'Files that touched critical/high-severity areas and the permissions granted to tools. You can remove a permission from here.', span: 99, h: 0, render() {
    const obs = D.files.filter(f => f.area && ['critical', 'high'].includes(derived.areaMap[f.area]?.sev)).sort((a, b) => b.last_seen - a.last_seen);
    const gr = D.grants.filter(g => ['granted', 'inherited'].includes(g.level) && ['critical', 'high'].includes(derived.areaMap[g.area]?.sev));
    const P1 = pageOf('cobs', obs, 6), P2 = pageOf('cgr', gr, 6);
    const ob = P1.rows.length ? P1.rows.map(f => { const a = derived.areaMap[f.area]; return `<div class="flex items-start gap-2 px-1 py-1.5 border-t border-slate-200"><span class="mt-1 w-2 h-2 rounded-full shrink-0" style="background:${SEV[a.sev].c}"></span>${toolIcon(f.tool, 18)}<div class="min-w-0 flex-1"><div class="text-xs"><span class="font-medium">${esc(toolName(f.tool))}</span> → ${a.icon} ${esc(a.label)} ${sevChip(a.sev)}</div><div class="mono text-[11px] text-slate-500 break-all">${esc(short(f.path))}</div></div><div class="text-[11px] text-slate-500 whitespace-nowrap">${ago(f.last_seen, D.now)}</div></div>`; }).join('') : '<div class="text-slate-500 text-sm py-3 text-center">No access to critical/high areas was seen.</div>';
    const g = P2.rows.length ? P2.rows.map(x => { const a = derived.areaMap[x.area]; return `<div class="flex items-start gap-2 px-1 py-1.5 border-t border-slate-200">${toolIcon(x.tool, 18)}<div class="min-w-0 flex-1"><div class="text-xs"><span class="font-medium">${esc(toolName(x.tool))}</span> → ${a.icon} ${esc(a.label)} ${sevChip(a.sev)} <span class="text-amber-700">${LEVEL[x.level].t}</span></div><div class="text-[11px] text-slate-500 break-words">${esc(x.detail)}</div><div class="text-[11px] text-slate-500">source: ${esc(x.source)}</div>${x.acts?.length ? `<div class="mt-1 flex flex-wrap gap-1">${actBtns(x)}</div>` : ''}${howtoHtml(x)}</div></div>`; }).join('') : '<div class="text-slate-500 text-sm py-3 text-center">No critical/high permissions defined.</div>';
    return `<div class="grid gap-6 lg:grid-cols-2"><div><div class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1">Observed access <span class="font-normal normal-case">(${obs.length})</span></div>${ob}${P1.ctrl}</div>
      <div><div class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1">Granted permissions <span class="font-normal normal-case">(${gr.length})</span></div>${g}${P2.ctrl}</div></div>`;
  }},

  events: { title: 'Alerts & events', tab: 'overview', desc: 'New tools, new destinations and critical-area alerts.', span: 99, h: 0, render() {
    const ic = {crit: ['⛔', 'text-red-600'], warn: ['⚠', 'text-amber-600'], info: ['ℹ', 'text-sky-600']};
    const P = pageOf('events', D.events, 10);
    return (P.rows.length ? P.rows.map(e => { const [i, c] = ic[e.level] || ic.info; return `<div class="py-1.5 flex gap-2 border-t border-slate-200 first:border-0 text-sm"><span class="${c}">${i}</span><div class="flex-1 break-words">${esc(e.msg)}</div><div class="text-slate-500 text-xs whitespace-nowrap">${ago(e.ts, D.now)}</div></div>`; }).join('') : '<div class="p-6 text-center text-slate-500">No events.</div>') + P.ctrl;
  }},

  live: { title: 'Active transfers', tab: 'network', desc: 'AI connections open right now and the data transferred since each connection started.', span: 99, h: 0, render() {
    const P = pageOf('live', D.live, 12);
    return `<table class="w-full text-sm">${head(['Tool','Process','Provider','Destination','Port','Proto','Sent','Received','Confidence'])}<tbody>` +
      (P.rows.length ? P.rows.map(c => `<tr class="border-t border-slate-200"><td><span class="flex items-center gap-2">${toolIcon(c.tool, 18)}${esc(toolName(c.tool))}</span></td><td class="text-slate-500">${esc(c.proc)} <span class="text-slate-500">#${c.pid}</span></td>
        <td><span class="flex items-center gap-2">${provIcon(c.provider, 16)}${esc(c.provider)}</span></td><td class="mono text-xs">${esc(c.host || c.rip)}${c.host ? `<div class="text-slate-500">${esc(c.rip)}</div>` : ''}</td>
        <td>${c.rport}</td><td class="text-slate-500">${c.proto}</td><td class="text-sky-700">${bytes(c.bytes_out)}</td><td class="text-violet-700">${bytes(c.bytes_in)}</td><td>${conf(c.conf)}</td></tr>`).join('') : empty(9, 'No open AI connections right now.')) + '</tbody></table>' + P.ctrl;
  }},

  heatmap: { title: 'Usage intensity — last 24 hours', tab: 'network', desc: 'How much data each tool transferred at each hour of the day (darker = busier).', span: 99, h: 0, render() {
    const H0 = Math.floor(D.now / 3600) * 3600 - 23 * 3600; const rows = {}; let max = 1;
    D.hourly.forEach(r => { const i = Math.floor((r.h - H0) / 3600); if (i >= 0 && i < 24) { (rows[r.tool] ??= Array(24).fill(0))[i] += +r.b; max = Math.max(max, rows[r.tool][i]); } });
    const tools = Object.keys(rows); if (!tools.length) return '<div class="text-slate-500 text-center py-8 text-sm">No hourly data yet.</div>';
    const hdr = Array.from({length: 24}, (_, i) => `<div class="text-[9px] text-slate-500 text-center">${i % 3 === 0 ? new Date((H0 + i * 3600) * 1000).getHours() : ''}</div>`).join('');
    return `<div class="space-y-1"><div class="grid gap-[2px]" style="grid-template-columns:130px repeat(24,1fr)"><div></div>${hdr}</div>${tools.map(t => `<div class="grid gap-[2px] items-center" style="grid-template-columns:130px repeat(24,1fr)"><div class="flex items-center gap-1.5 text-xs truncate">${toolIcon(t, 16)}<span class="truncate">${esc(toolName(t))}</span></div>${rows[t].map((v, i) => `<div class="h-5 rounded-sm" style="background:${colorOf(t)};opacity:${v ? (.15 + .85 * Math.log10(1 + v) / Math.log10(1 + max)).toFixed(2) : .06}" title="${esc(toolName(t))} · ${new Date((H0 + i * 3600) * 1000).getHours()}:00 · ${bytes(v)}"></div>`).join('')}</div>`).join('')}</div>`;
  }},

  dest: { title: 'Destinations', tab: 'network', desc: 'Addresses the tools connected to and the total transferred.', span: 99, h: 0, render() {
    const P = pageOf('dest', D.dest, 12);
    return `<table class="w-full text-sm">${head(['Tool','Provider','Destination','Sent','Received','Last'])}<tbody>` +
      (P.rows.length ? P.rows.map(x => `<tr class="border-t border-slate-200"><td><span class="flex items-center gap-2">${toolIcon(x.tool, 16)}${esc(toolName(x.tool))}</span></td><td><span class="flex items-center gap-2">${provIcon(x.provider, 15)}${esc(x.provider)}</span></td>
        <td class="mono text-xs">${esc(x.host || x.rip)}:${x.rport}</td><td class="text-sky-700">${bytes(x.bout)}</td><td class="text-violet-700">${bytes(x.bin)}</td><td class="text-slate-500">${ago(x.last_seen, D.now)}</td></tr>`).join('') : empty(6, 'No records.')) + '</tbody></table>' + P.ctrl;
  }},

  unclassified: { title: 'Not classified as AI', tab: 'network', defVisible: true, span: 99, h: 0,
    desc: 'Other processes with outbound connections. SecAIQ Watch recognises AI tools by signature; anything it does not recognise is listed here instead of being ignored, so an unfamiliar tool cannot stay invisible.', render() {
    const all = store.get('aigw.unc', 'apps') === 'all';
    const rowsAll = D.unclassified || [], rows = rowsAll.filter(r => all || r.kind === 'app');
    const P = pageOf('unc', rows, 10);
    const chip = k => `<span class="px-1.5 py-0.5 rounded text-[11px] ${k === 'app' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'}">${k === 'app' ? 'application' : esc(k)}</span>`;
    const bar = `<div class="flex flex-wrap items-center gap-2 mb-2 text-xs text-slate-500"><select data-filter="aigw.unc" class="bg-slate-100 border border-slate-300 rounded px-1.5 py-0.5 text-xs"><option value="apps" ${all ? '' : 'selected'}>Applications only</option><option value="all" ${all ? 'selected' : ''}>Include browsers &amp; system services</option></select>
      <span>${rows.length} process(es)${!all && rowsAll.some(r => r.kind !== 'app') ? ` · ${rowsAll.filter(r => r.kind !== 'app').length} browser/system process(es) hidden` : ''}</span></div>`;
    return bar + `<table class="w-full text-sm">${head(['Process','Type','Connections now','Destinations','Sent','Received','First seen','Last seen'])}<tbody>` +
      (P.rows.length ? P.rows.map(r => { const d = r.dests || [];
        return `<tr class="border-t border-slate-200 align-top"><td><span class="mono text-xs font-medium">${esc(r.proc)}</span><div class="mono text-[11px] text-slate-500 break-all">${esc(short(r.path || ''))}</div></td><td>${chip(r.kind)}</td>
        <td>${r.conns > 0 ? `<b>${r.conns}</b>` : '<span class="text-slate-500">idle</span>'}</td><td class="mono text-xs">${d.slice(-3).map(x => `<div class="break-all">${esc(x)}</div>`).join('')}${d.length > 3 ? `<div class="text-slate-500">+${d.length - 3} more</div>` : ''}</td>
        <td class="text-sky-700 whitespace-nowrap">${bytes(r.bout)}</td><td class="text-violet-700 whitespace-nowrap">${bytes(r.bin)}</td><td class="text-slate-500 whitespace-nowrap">${ago(r.first_seen, D.now)}</td><td class="text-slate-500 whitespace-nowrap">${ago(r.last_seen, D.now)}</td></tr>`; }).join('')
        : empty(8, all ? 'Nothing recorded yet.' : 'No unrecognised application has outbound connections right now.')) + `</tbody></table>${P.ctrl}
      <p class="text-xs text-slate-500 mt-2">Is one of these an AI tool? Add a pattern under <span class="mono">tools</span> in <span class="mono">config/signatures.php</span> (see the User guide) and it is tracked as a tool, with its own permissions and findings. Windows and Linux only show connections of your own user's processes.</p>`;
  }},

  files: { title: 'Files & folders touched', tab: 'files', desc: 'Files and folders the AI processes held open (at sampling time). Filter or search.', span: 99, h: 0, render() {
    const fa = store.get('aigw.fa', ''), fs = store.get('aigw.fs', false), ft = store.get('aigw.ft', '');
    const q = (SEARCH.files || '').trim().toLowerCase();
    let rows = D.files.filter(f => (!fs || f.sensitive == 1) && (!fa || f.area === fa) && (!ft || f.tool === ft)
      && (!q || (f.path + ' ' + toolName(f.tool) + ' ' + (derived.areaMap[f.area]?.label || '')).toLowerCase().includes(q)));
    const P = pageOf('files', rows, 20);
    const sel = (id, opts, cur) => `<select data-filter="${id}" class="bg-slate-100 border border-slate-300 rounded px-1.5 py-0.5 text-xs">${opts.map(([v, l]) => `<option value="${esc(v)}" ${v === cur ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
    const bar = `<div class="flex flex-wrap items-center gap-2 mb-2 text-xs text-slate-500">${sel('aigw.fa', [['', 'All areas'], ...D.areas.filter(a => a.group === 'data').map(a => [a.key, a.icon + ' ' + a.label])], fa)}${sel('aigw.ft', [['', 'All tools'], ...D.tools.map(t => [t.tool, t.name])], ft)}<input data-search="files" value="${esc(SEARCH.files || '')}" placeholder="Search: path, tool, area…" class="border border-slate-300 rounded px-2 py-1 text-xs w-56 bg-white text-slate-800"><label class="flex items-center gap-1"><input type="checkbox" data-filter="aigw.fs" ${fs ? 'checked' : ''}> critical/high only</label><span class="ml-auto">${rows.length} records</span></div><p class="text-[11px] text-slate-500 mb-1">Only files that are <em>held open</em> at sampling time are seen; accesses that read and immediately close a file can be missed.</p>`;
    return bar + `<table class="w-full text-sm">${head(['Severity','Area','Tool','Type','Path','Seen','Last'])}<tbody>` +
      (P.rows.length ? P.rows.map(f => { const a = derived.areaMap[f.area]; return `<tr class="border-t border-slate-200 ${f.sensitive == 1 ? 'bg-red-50' : ''}"><td>${a ? sevChip(a.sev) : ''}</td><td class="whitespace-nowrap">${a ? a.icon + ' ' + esc(a.label) : '<span class="text-slate-500">—</span>'}</td>
        <td><span class="flex items-center gap-2">${toolIcon(f.tool, 16)}${esc(toolName(f.tool))}</span></td><td class="text-slate-500">${esc(f.kind)}</td><td class="mono text-xs break-all">${esc(short(f.path))}</td><td class="text-slate-500">${f.hits}×</td><td class="text-slate-500 whitespace-nowrap">${ago(f.last_seen, D.now)}</td></tr>`; }).join('') : empty(7, 'No records.')) + '</tbody></table>' + P.ctrl;
  }},

  actions: { title: 'Action history & undo', tab: 'permissions', desc: 'A log of permission-removal actions. A backup is taken before each file change; use «Undo» to go back.', span: 99, h: 0, render() {
    const P = pageOf('actions', D.action_log, 8);
    const au = D.audit || {ok: true, count: 0, legacy: 0};
    const integrity = au.ok ? `<span class="text-emerald-700" data-tip="${esc('Tamper-evident log\nEach entry stores a SHA-256 hash of its content plus the previous entry\'s hash. Editing or deleting an entry breaks the chain and is detected here.')}">✔ Log integrity verified — ${au.count} chained entr${au.count === 1 ? 'y' : 'ies'}${au.legacy ? `, ${au.legacy} older entries predate the chain` : ''}</span>` : `<span class="text-red-600 font-medium">⚠ Log integrity check FAILED at entry ${esc(au.broken)} — the action log was modified.</span>`;
    const st = {done: ['applied', 'text-emerald-700 bg-emerald-100'], error: ['error', 'text-red-700 bg-red-100'], refused: ['refused', 'text-red-700 bg-red-100']};
    return `<p class="text-[11px] mb-1">${integrity}</p><p class="text-[11px] text-slate-500 mb-1">Permission-removal actions are applied by the collector; a backup is taken before file changes and «Undo» restores from it.</p>
      <table class="w-full text-sm">${head(['Time','Action','Status','Result',''])}<tbody>` +
      (P.rows.length ? P.rows.map(a => { const s = st[a.status] || [a.status, '']; return `<tr class="border-t border-slate-200"><td class="text-slate-500 whitespace-nowrap">${ago(a.ts, D.now)}</td><td>${esc(a.label)}</td>
        <td><span class="px-1.5 rounded text-xs ${s[1]}">${s[0]}${a.undone == 1 ? ' · undone' : ''}</span></td><td class="text-xs text-slate-500 break-words">${esc(a.result)}</td>
        <td class="text-right">${a.undoable ? `<button class="text-xs px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200" data-do='${esc(JSON.stringify({type: 'undo', params: {action: a.id}, label: 'Undo: ' + a.label, confirm: 'The file(s) will be restored from the backup taken before this action.', undoable: false, danger: false}))}'>↶ Undo</button>` : ''}</td></tr>`; }).join('') : empty(5, 'No actions yet.')) + '</tbody></table>' + P.ctrl;
  }},

  findings: { title: 'Security findings', tab: 'findings', defVisible: true, desc: 'Rule-based checks over your AI tools\' permissions, hooks, MCP servers, instruction files, browser extensions and observed file access. Fix buttons use the same backed-up, undoable actions as the rest of the panel.', span: 99, h: 0, render() {
    const all = D.findings || [], active = all.filter(f => !f.ack), accepted = all.filter(f => f.ack);
    const po = D.posture || {score: 100, grade: 'A'}, gc = {A: '#059669', B: '#65a30d', C: '#ca8a04', D: '#ea580c', F: '#dc2626'}[po.grade];
    const cnt = sv => active.filter(f => f.sev === sv).length;
    const chips = ['critical', 'high', 'medium', 'low'].map(sv => `<span class="px-2 py-1 rounded border text-xs ${SEV[sv].bg}"><b>${cnt(sv)}</b> ${sv}</span>`).join(' ');
    const showAcc = store.get('aigw.showAccepted', false);
    const list = showAcc ? [...active, ...accepted] : active;
    const head = `<div class="flex flex-wrap items-center gap-4 mb-3"><div class="w-16 h-16 rounded-full flex items-center justify-center text-3xl font-bold text-white shrink-0" style="background:${gc}" data-tip="${esc('Security posture: ' + po.grade + '\\n' + po.score + ' / 100\\nStarts at 100; every ACTIVE finding subtracts: critical 25, high 15, medium 5, low 2. Accepted risks are not counted. A ≥ 90 · B ≥ 80 · C ≥ 70 · D ≥ 60 · F below.')}">${po.grade}</div>
      <div><div class="text-lg font-semibold">Security posture: ${po.score} / 100</div><div class="text-xs text-slate-500">${active.length} active finding(s)${accepted.length ? ` · ${accepted.length} accepted risk(s) not counted` : ''}</div>${coverageHtml()}</div><div class="ml-auto flex flex-wrap gap-2 items-center">${chips}${accepted.length ? `<button data-toggle-acc class="text-xs px-2 py-1 rounded border border-slate-300 bg-white hover:bg-slate-100">${showAcc ? 'Hide' : 'Show'} accepted risks (${accepted.length})</button>` : ''}</div></div>`;
    if (!list.length) return head + `<div class="text-center py-8"><div class="text-2xl">✅</div><div class="font-medium mt-1">No active findings</div><p class="text-xs text-slate-500">Nothing suspicious in the current configuration.</p></div>`;
    const P = pageOf('findings', list, 8);
    return head + P.rows.map(f => `<div class="border border-slate-200 rounded-lg p-3 mb-2 ${f.ack ? 'bg-slate-50 opacity-70' : 'bg-white'}" style="border-left:4px solid ${f.ack ? '#94a3b8' : SEV[f.sev].c}">
        <div class="flex items-start gap-2"><div class="flex-1 min-w-0"><div class="font-semibold">${esc(f.title)} ${f.ack ? `<span class="ml-1 px-1.5 py-0.5 rounded text-[11px] bg-slate-200 text-slate-600 font-normal">accepted ${f.ack.until ? 'until ' + new Date(f.ack.until * 1000).toLocaleDateString() : 'forever'}</span>` : ''}</div><p class="text-xs text-slate-600 mt-0.5">${esc(f.why)}</p></div>${sevChip(f.sev)}</div>
        ${f.evidence.length ? `<div class="mt-1.5 space-y-0.5">${f.evidence.slice(0, 4).map(e => `<div class="mono text-[11px] text-slate-500 break-all">${esc(e)}</div>`).join('')}</div>` : ''}
        ${f.acts.length ? `<div class="mt-2 flex flex-wrap gap-1.5">${actBtns(f)}</div>` : ''}
        ${f.hint && !f.ack ? `<div class="mt-1.5 text-xs text-sky-800">💡 ${esc(f.hint)}</div>` : ''}</div>`).join('') + P.ctrl;
  }},

  protection: { title: 'Protect: block Claude Code from sensitive areas', tab: 'findings', defVisible: true, desc: 'Adds deny rules to ~/.claude/settings.json so Claude Code can never read or write these areas or run destructive commands, even if a prompt (or an injected instruction) asks it to. Existing rules are kept; every change is backed up and undoable. Codex is limited by its own sandbox instead.', span: 99, h: 0, render() {
    const rows = D.protection || [], pre = D.presets || [];
    const presetBar = `<div class="flex flex-wrap items-center gap-2 mb-3"><span class="text-xs text-slate-500">One-click level:</span>${pre.map(p => p.missing ? `<button data-do='${esc(JSON.stringify(p.act))}' class="text-xs px-2.5 py-1 rounded border border-emerald-600 text-emerald-700 hover:bg-emerald-50" data-tip="${esc(p.label + '\\n' + p.desc + '\\n' + p.missing + ' rule(s) still missing.')}">${esc(p.label)} <span class="text-slate-500">(+${p.missing})</span></button>` : `<span class="text-xs px-2.5 py-1 rounded bg-emerald-100 text-emerald-700" data-tip="${esc(p.label + '\\n' + p.desc)}">⛨ ${esc(p.label)} active</span>`).join('')}</div>`;
    const badge = {protected: '<span class="px-1.5 rounded text-xs bg-emerald-100 text-emerald-700">⛨ protected</span>', partial: '<span class="px-1.5 rounded text-xs bg-yellow-100 text-yellow-700">partly protected</span>', none: '<span class="px-1.5 rounded text-xs bg-amber-100 text-amber-700">not protected</span>'};
    return presetBar + `<table class="w-full text-sm">${head(['Area','Severity','Status','Deny rules',''])}<tbody>` + rows.map(p => `<tr class="border-t border-slate-200"><td class="whitespace-nowrap">${p.icon} ${esc(p.label)}</td><td>${sevChip(p.sev)}</td>
      <td>${badge[p.status]}</td>
      <td class="mono text-[11px] text-slate-500">${esc(p.rules.slice(0, 2).join(' · '))}${p.rules.length > 2 ? ' …(+' + (p.rules.length - 2) + ')' : ''}</td>
      <td class="text-right">${p.status === 'protected' ? '' : `<button data-do='${esc(JSON.stringify(p.act))}' class="text-xs px-2 py-1 rounded border border-emerald-600 text-emerald-700 hover:bg-emerald-50">Protect</button>`}</td></tr>`).join('') + '</tbody></table>';
  }},

  changes: { title: 'What changed — last 24 hours', tab: 'overview', defVisible: true, desc: 'New tools, destinations, permissions, MCP servers and extensions since yesterday.', span: 99, h: 0, render() {
    const cut = D.now - 86400, ev = D.events.filter(e => e.ts >= cut);
    const n = re => ev.filter(e => re.test(e.msg)).length;
    const perm = n(/^Change: .*permission/), inv = n(/^Change: /) - perm;
    const chips = [['New AI tools', n(/^New AI tool/)], ['New destinations', n(/^New destination/)], ['Permission changes', perm], ['Inventory changes', inv]]
      .map(([l, v]) => `<span class="px-2 py-1 rounded border text-xs ${v ? 'bg-sky-50 border-sky-300 text-sky-800' : 'bg-slate-50 border-slate-200 text-slate-500'}"><b>${v}</b> ${l}</span>`).join(' ');
    const list = ev.filter(e => e.msg.startsWith('Change:') || /^New (AI tool|destination)/.test(e.msg)).slice(0, 8);
    return `<div class="flex flex-wrap gap-2 mb-2">${chips}</div>` + (list.length ? list.map(e => `<div class="py-1 flex gap-2 border-t border-slate-200 text-sm"><span class="flex-1 break-words">${esc(e.msg.replace(/^Change: /, ''))}</span><span class="text-xs text-slate-500 whitespace-nowrap">${ago(e.ts, D.now)}</span></div>`).join('') : '<div class="text-sm text-slate-500 py-2">No changes in the last 24 hours.</div>');
  }},

  sessions: { title: 'Sessions', tab: 'network', defVisible: true, desc: 'Each running instance of an AI tool (its main process) with the folder it works in and the data it moved — so two Claude Code windows are told apart.', span: 99, h: 0, render() {
    const P = pageOf('sessions', D.sessions || [], 10);
    const dur = s => { const d = Math.max(0, s); return d < 3600 ? Math.floor(d / 60) + 'm' : d < 86400 ? Math.floor(d / 3600) + 'h ' + Math.floor(d % 3600 / 60) + 'm' : Math.floor(d / 86400) + 'd ' + Math.floor(d % 86400 / 3600) + 'h'; };
    return `<table class="w-full text-sm">${head(['Tool','Working folder','PID','Started','Duration','Sent','Received','Status'])}<tbody>` +
      (P.rows.length ? P.rows.map(x => `<tr class="border-t border-slate-200"><td><span class="flex items-center gap-2">${toolIcon(x.tool, 18)}${esc(toolName(x.tool))}</span></td><td class="mono text-xs break-all">${x.cwd ? esc(short(x.cwd)) : '<span class="text-slate-500">—</span>'}</td>
        <td class="text-slate-500">#${x.pid}</td><td class="text-slate-500 whitespace-nowrap">${ago(x.start_ts, D.now)}</td><td>${dur(x.last_seen - x.start_ts)}</td><td class="text-sky-700">${bytes(x.bout)}</td><td class="text-violet-700">${bytes(x.bin)}</td>
        <td>${+x.active ? '<span class="text-emerald-600">● running</span>' : '<span class="text-slate-500">ended</span>'}</td></tr>`).join('') : empty(8, 'No sessions recorded yet.')) + '</tbody></table>' + P.ctrl;
  }},

  usage_summary: { title: 'Token usage (Claude Code + Codex)', tab: 'usage', defVisible: true, desc: 'Tokens used by Claude Code and Codex, read from the local session logs (counters only — no prompt or response content). Cost is an estimate.', span: 99, h: 0, render() {
    const u = D.usage; if (!u) return usageOff();
    const t = u.totals, tok = x => (+x.tin) + (+x.tout);
    const hit = (+t.d7.cread) / Math.max(1, (+t.d7.tin) + (+t.d7.cread) + (+t.d7.cwrite));
    const card = (l, v, sub, cls = '') => `<div class="bg-white border border-slate-200 rounded-xl p-3"><div class="text-xs text-slate-500">${l}</div><div class="text-2xl font-semibold mt-0.5 ${cls}">${v}</div><div class="text-xs text-slate-500">${sub}</div></div>`;
    // last 14 days, missing days filled with zeros
    const byDay = Object.fromEntries(u.days.map(d => [d.day, d])); const days = [];
    for (let i = 13; i >= 0; i--) { const dt = new Date((D.now - i * 86400) * 1000); const k = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0'); days.push([k, byDay[k] || {tin: 0, tout: 0, cread: 0, cwrite: 0, msgs: 0}]); }
    const max = Math.max(1, ...days.map(([, d]) => (+d.tin) + (+d.cwrite) + (+d.tout)));
    const W = 700, H = 130, bw = W / 14;
    const bars = days.map(([k, d], i) => { const inp = (+d.tin) + (+d.cwrite), out = +d.tout, hi = inp / max * (H - 22), ho = out / max * (H - 22);
      return `<g><rect x="${i * bw + 6}" y="${H - 16 - hi - ho}" width="${bw - 12}" height="${ho}" fill="#00CBB8"/><rect x="${i * bw + 6}" y="${H - 16 - hi}" width="${bw - 12}" height="${hi}" fill="#00AAE3"/>
        <text x="${i * bw + bw / 2}" y="${H - 3}" text-anchor="middle" font-size="9" fill="#64748b">${k.slice(5)}</text>
        <rect x="${i * bw}" y="0" width="${bw}" height="${H}" fill="transparent"><title>${k}: ${fmtTok(inp)} input · ${fmtTok(out)} output · ${fmtTok(d.cread)} cache read · ${d.msgs} messages</title></rect></g>`; }).join('');
    return `<div class="grid gap-3 mb-3" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">` +
      card('Today', fmtTok(tok(t.today)), `${t.today.msgs} messages`) + card('Last 7 days', fmtTok(tok(t.d7)), `${t.d7.msgs} messages`) + card('Last 30 days', fmtTok(tok(t.d30)), `${t.d30.msgs} messages`) +
      card('Est. cost (7 days)', usdFmt(u.cost7), u.cost_partial ? 'partial — some models have no price' : 'estimate', 'text-emerald-700') +
      card('Cache hit ratio', (hit * 100).toFixed(0) + '%', 'cache reads ÷ all input, 7 days') + `</div>
      <div class="flex items-center gap-3 text-xs text-slate-500 mb-1"><span class="flex items-center gap-1"><span class="w-2 h-2 rounded-sm" style="background:#00AAE3"></span>input (incl. cache writes)</span><span class="flex items-center gap-1"><span class="w-2 h-2 rounded-sm" style="background:#00CBB8"></span>output</span><span>· cache reads not drawn · last scan ${u.last_scan ? ago(u.last_scan, D.now) : 'pending'}</span></div>
      <svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" class="w-full" style="height:150px"><line x1="0" y1="${H - 16}" x2="${W}" y2="${H - 16}" stroke="#cbd5e1"/>${bars}</svg>`;
  }},

  usage_models: { title: 'Usage by model — last 7 days', tab: 'usage', defVisible: true, desc: 'Tokens per model. Cost uses the price table in config/signatures.php; models without a price show a dash.', span: 99, h: 0, render() {
    const u = D.usage; if (!u) return usageOffShort();
    return `<table class="w-full text-sm">${head(['Tool','Model','Messages','Input','Output','Cache read','Cache write','Est. cost'])}<tbody>` + (u.models.length ? u.models.map(m => `<tr class="border-t border-slate-200"><td><span class="flex items-center gap-2">${toolIcon(m.tool, 16)}${esc(toolName(m.tool))}</span></td><td class="mono text-xs">${esc(m.model)}</td><td>${m.msgs}</td><td class="text-sky-700">${fmtTok(m.tin)}</td><td class="text-violet-700">${fmtTok(m.tout)}</td><td class="text-slate-500">${fmtTok(m.cread)}</td><td class="text-slate-500">${fmtTok(m.cwrite)}</td><td>${m.priced ? usdFmt(m.cost) : '<span class="text-slate-500" title="Model not in the price table">—</span>'}</td></tr>`).join('') : empty(8, 'No usage recorded yet — the first scan can take a minute.')) + '</tbody></table>';
  }},

  usage_tools: { title: 'Tool calls — last 30 days', tab: 'usage', defVisible: true, desc: 'Which tools Claude Code actually called, counted by NAME only (never arguments or results). MCP servers you configured but never used are listed too — each one is attack surface.', span: 99, h: 0, render() {
    const u = D.usage; if (!u) return usageOffShort();
    const raw = u.calls || {tools: [], servers: []};
    const sum = (arr, key) => { const m = {}; arr.forEach(x => { m[x[key]] = (m[x[key]] || 0) + (+x.n); }); return Object.entries(m).map(([k, n]) => ({[key]: k, n})).sort((a, b) => b.n - a.n); };
    const c = {tools: sum(raw.tools, 'name'), servers: sum(raw.servers, 'server')}, max = Math.max(1, ...c.tools.map(t => +t.n)); // one row per tool name across all agents
    const cfg = (D.inventory || []).filter(i => i.kind === 'mcp' && i.tool === 'claude-code').map(i => i.name);
    const usedSrv = new Set(c.servers.map(x => x.server));
    const unused = cfg.filter(n => !usedSrv.has(n.replace(/[^A-Za-z0-9_-]/g, '_')) && !usedSrv.has(n));
    if (!c.tools.length) return '<div class="text-sm text-slate-500 py-2">No tool calls recorded yet — the first scan can take a few minutes.</div>';
    return `<div class="grid gap-6 lg:grid-cols-2"><div><div class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1">Most used tools</div>
      ${c.tools.slice(0, 12).map(t => `<div class="flex items-center gap-2 py-0.5 text-sm"><span class="mono text-xs w-64 truncate" title="${esc(t.name)}">${esc(t.name)}</span><div class="flex-1 h-2 bg-slate-100 rounded"><div class="h-2 rounded" style="width:${Math.max(2, t.n / max * 100)}%;background:${t.name.startsWith('mcp__') ? '#00CBB8' : '#00AAE3'}"></div></div><span class="text-xs text-slate-600 w-12 text-right">${t.n}</span></div>`).join('')}</div>
      <div><div class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1">MCP servers</div>
      ${c.servers.map(x => `<div class="flex items-center gap-2 py-0.5 text-sm"><span class="mono text-xs flex-1 truncate">${esc(x.server)}</span><span class="text-xs text-slate-600">${x.n} calls</span></div>`).join('') || '<div class="text-sm text-slate-500">No MCP calls.</div>'}
      ${unused.map(n => `<div class="flex items-center gap-2 py-0.5 text-sm"><span class="mono text-xs flex-1 truncate">${esc(n)}</span><span class="text-xs px-1.5 rounded bg-amber-100 text-amber-700">configured, never used</span></div>`).join('')}</div></div>`;
  }},

  usage_projects: { title: 'Usage by project — last 7 days', tab: 'usage', defVisible: true, desc: 'Which working folders Claude Code spent tokens in.', span: 99, h: 0, render() {
    const u = D.usage; if (!u) return usageOffShort();
    const P = pageOf('usage_projects', u.projects, 10);
    return `<table class="w-full text-sm">${head(['Project','Messages','Input + output','Est. cost','Last used'])}<tbody>` + (P.rows.length ? P.rows.map(p => `<tr class="border-t border-slate-200"><td class="mono text-xs break-all">${esc(short(p.project || '(unknown)'))}</td><td>${p.msgs}</td><td>${fmtTok((+p.tin) + (+p.tout))}</td><td>${p.priced ? usdFmt(p.cost) : (p.cost > 0 ? usdFmt(p.cost) + '<span class="text-slate-500" title="Some models in this project have no price">*</span>' : '<span class="text-slate-500" title="Model not in the price table">—</span>')}</td><td class="text-slate-500">${ago(p.last, D.now)}</td></tr>`).join('') : empty(5, 'No usage recorded yet.')) + '</tbody></table>' + P.ctrl;
  }},

  guide: { title: 'User guide', tab: 'guide', defVisible: true, desc: 'How to install, use and troubleshoot SecAIQ Watch.', span: 99, h: 0, render() {
    if (GUIDE) return GUIDE;
    if (GUIDE_STATE === 'idle') {
      GUIDE_STATE = 'loading';
      fetch('guide.php').then(r => r.ok ? r.text() : Promise.reject(r.status)).then(t => { GUIDE = guideHtml(t); GUIDE_STATE = 'ready'; renderWidget('guide'); }).catch(() => { GUIDE_STATE = 'failed'; renderWidget('guide'); });
    }
    return GUIDE_STATE === 'failed' ? '<div class="text-red-600 text-sm py-4">Could not load the guide (guide.php / GUIDE.md).</div>' : '<div class="text-slate-500 text-sm py-8 text-center">Loading the guide…</div>';
  }},

  inventory: { title: 'Inventory', tab: 'inventory', desc: "Installed AI apps, CLI tools, MCP servers and the projects Claude Code works in.", span: 99, h: 0, render() {
    const kl = {app: 'Installed app', cli: 'CLI', config: 'Config folder', mcp: 'MCP server', project: 'Project', extension: 'AI browser extension', hook: 'Claude Code hook', secret: 'Hard-coded secret (config)', instr: 'Instruction-file issue'};
    const P = pageOf('inventory', D.inventory, 15);
    return `<table class="w-full text-sm">${head(['Type','Tool','Name','Details'])}<tbody>` + (P.rows.length ? P.rows.map(i => `<tr class="border-t border-slate-200"><td class="text-slate-500">${kl[i.kind] || i.kind}</td><td>${i.tool ? `<span class="flex items-center gap-2">${toolIcon(i.tool, 16)}${esc(toolName(i.tool))}</span>` : ''}</td><td class="mono text-xs">${esc(short(i.name))}</td><td class="mono text-xs text-slate-500 break-all">${esc(short(i.detail))}</td></tr>`).join('') : empty(4, 'No records.')) + '</tbody></table>' + P.ctrl;
  }},
};

/* ------------------------------------------------------------ layout presets */
// span = column UNIT (1..4, FULL = the whole row). The real column count is computed from the screen width.
const FULL = 99;
const L = (id, span, h = 0, hidden = false) => ({id, span, h, hidden});
const PRESETS = {
  default: [L('kpi',FULL), L('traffic',2), L('providers',1), L('tools',FULL), L('changes',FULL), L('events',FULL), L('findings',FULL), L('protection',FULL), L('matrix',FULL), L('critical',FULL), L('actions',FULL), L('sessions',FULL), L('live',FULL), L('heatmap',FULL), L('dest',FULL), L('files',FULL), L('usage_summary',FULL), L('usage_models',FULL), L('usage_projects',FULL), L('inventory',FULL)],
  security: [L('kpi',FULL), L('tools',FULL), L('changes',FULL), L('events',FULL), L('findings',FULL), L('protection',FULL), L('matrix',FULL), L('critical',FULL), L('actions',FULL), L('files',FULL), L('sessions',FULL), L('live',FULL), L('traffic',2,0,true), L('providers',1,0,true), L('heatmap',FULL,0,true), L('dest',FULL,0,true), L('usage_summary',FULL), L('usage_models',FULL), L('usage_projects',FULL), L('inventory',FULL,0,true)],
  compact: [L('kpi',FULL), L('traffic',2), L('providers',1), L('tools',FULL), L('findings',FULL), L('matrix',FULL,3), L('changes',FULL,0,true), L('events',FULL,1,true), L('protection',FULL,0,true), L('critical',FULL,0,true), L('actions',FULL,0,true), L('sessions',FULL,1), L('live',FULL,1), L('heatmap',FULL,0,true), L('dest',FULL,0,true), L('files',FULL,2,true), L('usage_summary',FULL), L('usage_models',FULL,0,true), L('usage_projects',FULL,0,true), L('inventory',FULL,0,true)],
};
let layout = normalize(store.get('aigw.layout.v6', null) || PRESETS.default);
let editing = false;
const TABS = [
  {id: 'overview', label: 'Overview', ico: '◧'}, {id: 'findings', label: 'Findings', ico: '🩺'}, {id: 'permissions', label: 'Permissions & risk', ico: '⛨'},
  {id: 'network', label: 'Network activity', ico: '⇅'}, {id: 'files', label: 'File access', ico: '🗂'}, {id: 'usage', label: 'Usage', ico: '📈'}, {id: 'inventory', label: 'Inventory', ico: '☰'}, {id: 'guide', label: 'User guide', ico: '📖'},
];
let TAB = (location.hash || '').slice(1);
if (!TABS.some(t => t.id === TAB)) TAB = store.get('aigw.tab', 'overview');
if (!TABS.some(t => t.id === TAB)) TAB = 'overview';
function setTab(id) { TAB = id; store.set('aigw.tab', id); try { history.replaceState(null, '', '#' + id); } catch (e) {} buildGrid(); renderTabs(); window.scrollTo({top: 0}); }
function renderTabs() {
  const badge = {};
  if (D) {
    const crit = D.files.filter(f => f.area && derived.areaMap[f.area]?.sev === 'critical').length + D.grants.filter(g => g.level === 'granted' && derived.areaMap[g.area]?.sev === 'critical').length;
    badge.overview = [D.kpi.active_tools, 'bg-slate-200 text-slate-700'];
    const act = (D.findings || []).filter(f => !f.ack), fc = act.filter(f => f.sev === 'critical' || f.sev === 'high').length; badge.findings = fc ? [fc, 'bg-red-100 text-red-700'] : [act.length, 'bg-slate-200 text-slate-700']; badge.permissions = crit ? [crit, 'bg-red-100 text-red-700'] : null;
    badge.network = [D.live.length, 'bg-slate-200 text-slate-700']; badge.files = [D.files.length, 'bg-slate-200 text-slate-700']; badge.inventory = [D.inventory.length, 'bg-slate-200 text-slate-700'];
  }
  $('#tabs').innerHTML = TABS.map(t => `<button role="tab" aria-selected="${t.id === TAB}" data-tab="${t.id}" class="px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap shrink-0 ${t.id === 'guide' ? 'ml-auto ' : ''}${t.id === TAB ? 'border-sky-600 text-sky-700' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300'}">${t.ico} ${t.label}${badge[t.id] ? ` <span class="ml-1 px-1.5 py-0.5 rounded-full text-[11px] ${badge[t.id][1]}">${badge[t.id][0]}</span>` : ''}</button>`).join('');
}
function normalize(l) { // add missing/new modules, drop unknown ones
  const seen = new Set(); const out = [];
  (l || []).forEach(x => { if (WIDGETS[x.id] && !seen.has(x.id)) { seen.add(x.id); out.push({id: x.id, span: x.span || WIDGETS[x.id].span, h: x.h ?? 0, hidden: !!x.hidden}); } });
  Object.keys(WIDGETS).forEach(id => { if (!seen.has(id)) out.push({id, span: WIDGETS[id].span, h: WIDGETS[id].h, hidden: !WIDGETS[id].defVisible}); });
  return out;
}
const saveLayout = () => store.set('aigw.layout.v6', layout);
const SPANS = [1, 2, 3, 4, FULL];
const spanLabel = s => s >= FULL ? 'full' : s;

/* Column count from the container width (max 1850px): ≥1000px → 3, ≥640px → 2, below → 1 */
let COLS = 3;
function calcCols() { const w = $('#grid').clientWidth || window.innerWidth; return w >= 1000 ? 3 : w >= 640 ? 2 : 1; }
function applyCols() {
  COLS = calcCols();
  const g = $('#grid'); g.style.setProperty('--cols', COLS);
  layout.forEach(x => { const el = g.querySelector(`[data-w="${x.id}"]`); if (el) el.style.setProperty('--s', Math.min(x.span, COLS)); });
}
let resizeT = null;
window.addEventListener('resize', () => { clearTimeout(resizeT); resizeT = setTimeout(applyCols, 120); });

/* ------------------------------------------------------------ grid setup */
function buildGrid() {
  const g = $('#grid'); g.className = editing ? 'editing' : '';
  const visible = layout.filter(x => !x.hidden && WIDGETS[x.id].tab === TAB);
  g.innerHTML = (visible.length ? '' : '<div class="col-span-full text-center text-slate-500 py-16">All modules in this tab are hidden. Turn them on from the <b>▦ Modules</b> menu.</div>') + visible.map(x => `
    <section class="w bg-white border border-slate-200 rounded-xl shadow-sm" data-w="${x.id}" ${editing ? 'draggable="true"' : ''}>
      <div class="w-head flex items-start justify-between gap-2 px-4 pt-3 pb-2">
        <div class="min-w-0"><h2 class="font-semibold text-[15px] text-slate-900">${WIDGETS[x.id].title}</h2>${WIDGETS[x.id].desc ? `<p class="text-xs text-slate-500 mt-0.5 leading-snug">${WIDGETS[x.id].desc}</p>` : ''}</div>
        <div class="edit-only items-center gap-1 text-xs"><span class="text-slate-500 mr-1" title="Column units">${spanLabel(x.span)} col</span>
          <button data-act="narrow" class="px-1.5 py-0.5 rounded bg-slate-100 hover:bg-slate-200" title="Narrower">◀</button>
          <button data-act="wide" class="px-1.5 py-0.5 rounded bg-slate-100 hover:bg-slate-200" title="Wider">▶</button>
          <button data-act="height" class="px-1.5 py-0.5 rounded bg-slate-100 hover:bg-slate-200" title="Height">↕</button>
          <button data-act="hide" aria-label="Hide module" class="px-1.5 py-0.5 rounded bg-slate-100 hover:bg-red-100" title="Hide">✕</button>
        </div></div>
      <div class="w-body px-4 pb-4 ${x.h ? 'h' + x.h : ''}"></div>
    </section>`).join('');
  applyCols();
  renderAll();
  buildModMenu();
  renderTabs();
}
/* The 3 s refresh rebuilds the modules; keep keyboard focus on the same control (found again by its data-* attribute) */
function saveFocus() {
  const a = document.activeElement; if (!a || a === document.body || !a.closest?.('#grid')) return null;
  const at = [...a.attributes].find(x => x.name.startsWith('data-') && x.name !== 'data-tip' && x.value);
  return at ? {sel: `${a.tagName.toLowerCase()}[${at.name}="${at.value.replace(/["\\]/g, '\\$&')}"]`} : null;
}
function restoreFocus(f) { if (!f) return; try { const el = document.querySelector('#grid ' + f.sel); if (el && el !== document.activeElement) el.focus({preventScroll: true}); } catch (e) {} }
function renderAll() {
  if (!D) return;
  const focusKeep = saveFocus();
  document.querySelectorAll('#grid [data-w]').forEach(sec => {
    const body = sec.querySelector('.w-body'), top = body.scrollTop, left = body.scrollLeft;
    if (sec.dataset.w === 'guide' && GUIDE && body.dataset.done) return; // static content: do not rebuild every 3 s (keeps text selection)
    try { body.innerHTML = WIDGETS[sec.dataset.w].render(); } catch (e) { body.innerHTML = `<div class="text-red-600 text-xs">Module error: ${esc(e.message)}</div>`; console.error(sec.dataset.w, e); }
    body.scrollTop = top; body.scrollLeft = left;
    if (sec.dataset.w === 'guide' && GUIDE) body.dataset.done = '1';
  });
  if (drawerState) renderDrawer();
  renderTabs();
  restoreFocus(focusKeep);
}
function renderWidget(id) { // refresh a single module (keep focus in the search box)
  const sec = document.querySelector(`#grid [data-w="${id}"]`); if (!sec || !D) return;
  const ae = document.activeElement, keep = ae?.dataset?.search, pos = ae?.selectionStart;
  sec.querySelector('.w-body').innerHTML = WIDGETS[id].render();
  if (keep) { const el = sec.querySelector(`[data-search="${keep}"]`); if (el) { el.focus(); try { el.setSelectionRange(pos, pos); } catch (e) {} } }
}
function buildModMenu() {
  $('#modMenu').innerHTML = TABS.map(t => `<div class="text-[11px] uppercase tracking-wide text-slate-500 px-1 pt-1">${t.label}</div>` + layout.filter(x => WIDGETS[x.id].tab === t.id).map(x => `<label class="flex items-center gap-2 px-1 py-1 rounded hover:bg-slate-100 cursor-pointer"><input type="checkbox" data-mod="${x.id}" ${x.hidden ? '' : 'checked'}>${WIDGETS[x.id].title}</label>`).join('')).join('') +
    '<div class="border-t border-slate-300 mt-2 pt-2 flex gap-2"><button data-reset class="text-xs px-2 py-1 rounded bg-slate-100 hover:bg-slate-200">Reset</button></div>';
}

/* ------------------------------------------------------------ detail panel */
function openDrawer(tool, area = null) { drawerState = {tool, area}; $('#drawerBg').classList.remove('hidden'); $('#drawer').classList.remove('translate-x-full'); renderDrawer(); }
function closeDrawer() { drawerState = null; $('#drawerBg').classList.add('hidden'); $('#drawer').classList.add('translate-x-full'); }
function renderDrawer() {
  const {tool, area} = drawerState; const t = D.tools.find(x => x.tool === tool); if (!t) return closeDrawer();
  const c = derived.cells[tool] || {}; const [rl, rc] = riskOf(derived.score[tool] || 0); const now = D.now;
  const sec = (title, inner) => `<section class="mt-5"><h3 class="text-xs uppercase tracking-wide text-slate-500 mb-1.5">${title}</h3>${inner}</section>`;
  const areaFocus = area ? (() => { const a = derived.areaMap[area], lv = c[area] || {};
    return sec(`${a.icon} ${esc(a.label)} ${sevChip(a.sev)}`, LEVEL_ORDER.filter(l => lv[l]).map(l => `<div class="mb-2"><div class="text-xs mb-1"><span class="cell ${LEVEL[l].cls}" style="min-width:20px;height:20px">${LEVEL[l].sym}</span> <b>${LEVEL[l].t}</b> <span class="text-slate-500">— ${LEVEL[l].d}</span></div>${lv[l].slice(0, 30).map(r => `<div class="mono text-[11px] text-slate-600 break-all pl-2 border-l border-slate-300 ml-2 mb-0.5">${esc(short(r.detail))}${r.source ? ` <span class="text-slate-500">· ${esc(r.source)}</span>` : ''}</div>`).join('')}</div>`).join('') || '<div class="text-sm text-slate-500">No records in this area.</div>'); })() : '';
  const permRows = Object.entries(c).sort((a, b) => ['critical','high','medium'].indexOf(derived.areaMap[a[0]]?.sev) - ['critical','high','medium'].indexOf(derived.areaMap[b[0]]?.sev)).map(([a, lv]) => { const A = derived.areaMap[a]; if (!A) return ''; const p = primaryLevel(lv);
    return `<button data-cell="${esc(tool)}|${a}" class="w-full flex items-center gap-2 py-1 border-t border-slate-200 text-left hover:bg-slate-50"><span class="cell ${LEVEL[p].cls}" style="min-width:22px;height:22px">${LEVEL[p].sym}</span><span class="text-sm flex-1">${A.icon} ${esc(A.label)}</span>${sevChip(A.sev)}<span class="text-xs text-slate-500 w-40 text-right">${Object.keys(lv).map(l => LEVEL[l].t).join(', ')}</span></button>`; }).join('');
  const procs = D.procs.filter(p => p.tool === tool);
  const tp = D.tool_providers.filter(p => p.tool === tool).sort((a, b) => ((+b.bin) + (+b.bout)) - ((+a.bin) + (+a.bout)));
  const dests = D.dest.filter(x => x.tool === tool).slice(0, 15);
  const cwd = D.files.filter(f => f.tool === tool && f.kind === 'working dir');
  const inv = D.inventory.filter(i => i.tool === tool && ['mcp', 'project'].includes(i.kind));
  $('#drawer').innerHTML = `<div class="p-5">
    <div class="flex items-start gap-3">${toolIcon(tool, 52)}<div class="flex-1"><div class="text-xl font-semibold">${esc(t.name)}</div><div class="text-sm text-slate-500">${esc(t.category)} · ${t.live ? '<span class="text-emerald-600">running</span>' : 'stopped'} · first seen ${ago(t.first_seen, now)}</div>
      <div class="mt-1 text-sm">Risk: <b style="color:${rc}">${rl}</b> <span class="text-slate-500">(score ${derived.score[tool] || 0})</span></div></div>
      <button id="drawerClose" aria-label="Close details" class="text-slate-500 hover:text-slate-900 text-xl px-2">✕</button></div>
    <div class="grid grid-cols-4 gap-2 mt-4 text-center text-xs">${[['Processes', t.procs || '-'], ['Memory', t.live ? bytes(t.rss) : '-'], ['↑ Sent', bytes(t.bout)], ['↓ Received', bytes(t.bin)], ['Active min', t.active_min], ['Connections', t.conns], ['CPU', t.live ? t.cpu + '%' : '-'], ['Last', ago(t.last_seen, now)]].map(([l, v]) => `<div class="bg-white border border-slate-200 rounded-lg py-2"><div class="text-slate-500">${l}</div><div class="text-sm">${v}</div></div>`).join('')}</div>
    ${areaFocus}
    ${sec('Granted permissions & removal', (() => { const gs = D.grants.filter(g => g.tool === tool && ['granted', 'inherited', 'ask'].includes(g.level) && (!area || g.area === area || true)).sort((a, b) => ['critical','high','medium'].indexOf(derived.areaMap[a.area]?.sev) - ['critical','high','medium'].indexOf(derived.areaMap[b.area]?.sev));
      return gs.length ? gs.map(g => { const A = derived.areaMap[g.area]; if (!A) return ''; return `<div class="py-2 border-t border-slate-200 ${area === g.area ? 'bg-white' : ''}"><div class="flex items-center gap-2 text-sm"><span class="cell ${LEVEL[g.level].cls}" style="min-width:20px;height:20px">${LEVEL[g.level].sym}</span><span class="flex-1">${A.icon} ${esc(A.label)}</span>${sevChip(A.sev)}<span class="text-xs text-slate-500">${LEVEL[g.level].t}</span></div><div class="text-[11px] text-slate-500 break-words mt-0.5">${esc(g.detail)}</div><div class="text-[11px] text-slate-500">source: ${esc(g.source)}</div>${g.acts?.length ? `<div class="mt-1.5 flex flex-wrap gap-1.5">${actBtns(g)}</div>` : '<div class="text-[11px] text-slate-500 mt-1">SecAIQ Watch has no button for this entry.</div>'}${howtoHtml(g)}</div>`; }).join('') : '<div class="text-sm text-slate-500">No granted permissions recorded.</div>'; })())}
    ${sec('Permission & access summary (by area)', permRows || '<div class="text-sm text-slate-500">No permissions/access recorded.</div>')}
    ${sec('Providers', tp.length ? tp.map(p => `<div class="flex items-center gap-2 py-0.5 text-sm">${provIcon(p.provider, 16)}<span class="flex-1">${esc(p.provider)}</span><span class="text-sky-700 text-xs">↑ ${bytes(p.bout)}</span><span class="text-violet-700 text-xs">↓ ${bytes(p.bin)}</span></div>`).join('') : '<div class="text-sm text-slate-500">No traffic.</div>')}
    ${sec('Destinations', dests.length ? dests.map(x => `<div class="flex items-center gap-2 py-0.5 text-sm"><span class="mono text-xs flex-1 truncate">${esc(x.host || x.rip)}:${x.rport}</span><span class="text-xs text-slate-500">${esc(x.provider)}</span><span class="text-sky-700 text-xs">${bytes(x.bout)}</span></div>`).join('') : '<div class="text-sm text-slate-500">No destinations.</div>')}
    ${sec('Working folders (where it is used)', cwd.length ? cwd.map(f => `<div class="mono text-[11px] break-all py-0.5">${esc(short(f.path))}</div>`).join('') : '<div class="text-sm text-slate-500">Not seen.</div>')}
    ${sec('MCP servers & projects', inv.length ? inv.map(i => `<div class="text-sm py-0.5"><span class="text-slate-500">${i.kind === 'mcp' ? '🔌' : '📁'}</span> <span class="mono text-xs">${esc(short(i.name))}</span> <span class="text-[11px] text-slate-500 break-all">${esc(short(i.detail))}</span></div>`).join('') : '<div class="text-sm text-slate-500">No records.</div>')}
    ${sec(`Processes (${procs.length})`, `<table class="w-full text-xs">${procs.slice(0, 25).map(p => `<tr class="border-t border-slate-200"><td class="mono truncate max-w-[16rem]">${esc(p.name)} <span class="text-slate-500">#${p.pid}</span></td><td class="text-right">${p.cpu}%</td><td class="text-right">${bytes(p.rss)}</td><td class="text-right text-slate-500">${esc(p.etime)}</td></tr>`).join('') || '<tr><td class="text-slate-500">No running processes.</td></tr>'}</table>`)}
  </div>`;
}

/* ------------------------------------------------------------ interaction */
/* ------------------------------------------------------------ description popup (data-tip) */
const tipEl = $('#tip'); let tipCur = null;
function tipShow(el, x, y) {
  tipCur = el; tipEl.innerHTML = '';
  el.dataset.tip.split('\n').forEach((line, i) => { const n = document.createElement(i === 0 ? 'b' : 'p'); n.textContent = line; tipEl.appendChild(n); });
  tipEl.style.opacity = 1; tipMove(x, y);
}
function tipMove(x, y) {
  const w = tipEl.offsetWidth, h = tipEl.offsetHeight;
  let l = x + 14, t = y + 16;
  if (l + w > innerWidth - 8) l = x - w - 14;
  if (t + h > innerHeight - 8) t = y - h - 12;
  tipEl.style.left = Math.max(8, l) + 'px'; tipEl.style.top = Math.max(8, t) + 'px';
}
function tipHide() { tipCur = null; tipEl.style.opacity = 0; }
document.addEventListener('mouseover', e => { const el = e.target.closest?.('[data-tip]'); if (el && el !== tipCur) tipShow(el, e.clientX, e.clientY); else if (!el && tipCur) tipHide(); });
document.addEventListener('mousemove', e => { if (!tipCur) return; if (!document.contains(tipCur)) return tipHide(); if (e.target.closest?.('[data-tip]') !== tipCur) return; tipMove(e.clientX, e.clientY); });
document.addEventListener('scroll', tipHide, true);

/* ------------------------------------------------------------ settings (config/settings.php from the web) */
const SVCF = () => D && D.launcher !== 'manual';
const INSTALL_CMD = () => (D && D.platform && D.platform.os === 'windows') ? 'powershell -ExecutionPolicy Bypass -File bin\\install-agent.ps1 install' : 'bin/install-agent.sh install';
function renderSettings() {
  if (!D) return;
  const SVC = SVCF();
  const tcc = {ok: ['readable', 'bg-emerald-100 text-emerald-700'], 'denied': ['unreadable — Full Disk Access required', 'bg-amber-100 text-amber-700'], 'missing': ['TCC.db not found', 'bg-slate-100 text-slate-600'], 'off': ['off', 'bg-slate-100 text-slate-600'], 'na': ['not available on this OS', 'bg-slate-100 text-slate-600']};
  $('#settingsBody').innerHTML = Object.entries(D.settings_schema).map(([k, m]) => {
    const val = D.settings[k];
    const st = k === 'scan_system' ? (tcc[D.tcc_status] || [D.tcc_status, 'bg-slate-100 text-slate-600']) : null;
    const control = m.type === 'int'
      ? `<input type="number" min="${m.min}" max="${m.max}" value="${+val}" data-setting-int="${k}" class="mt-0.5 shrink-0 w-24 border border-slate-300 rounded px-2 py-1 text-sm bg-white">`
      : `<button role="switch" aria-checked="${!!val}" data-setting="${k}" data-value="${!!val}" class="mt-0.5 shrink-0 w-11 h-6 rounded-full relative transition ${val ? 'bg-sky-600' : 'bg-slate-300'}"><span class="absolute top-0.5 ${val ? 'left-[22px]' : 'left-0.5'} w-5 h-5 rounded-full bg-white shadow transition-all"></span></button>`;
    const state = m.type === 'int' ? (val ? `${val} MB` : 'off') : (val ? 'on' : 'off');
    return `<div class="flex items-start gap-3">${control}
      <div class="min-w-0"><div class="font-medium">${esc(m.label)} <span class="text-xs font-normal ${val ? 'text-sky-700' : 'text-slate-500'}">${state}</span>${st ? ` <span class="ml-1 px-1.5 py-0.5 rounded text-[11px] ${st[1]}">${esc(D.platform.label)} system permissions: ${st[0]}</span>` : ''}</div>
      <p class="text-xs text-slate-600 mt-1 leading-relaxed">${esc(m.desc)}</p></div></div>`;
  }).join('') + `<div class="border-t border-slate-200 pt-4 text-xs text-slate-600">SecAIQ Watch <b>v${esc(D.version || '')}</b> <span class="px-1.5 rounded border border-[#00AAE3] text-[#00709a] bg-[#e8f8fd] uppercase text-[10px] font-bold">beta</span> — something not working? Run <span class="mono">php bin/diagnostics.php</span> and report it at <a class="text-sky-700 underline" target="_blank" rel="noopener noreferrer" href="${esc(D.issues_url || '#')}">GitHub issues</a>.</div><div class="border-t border-slate-200 pt-4"><div class="font-medium">Collector <span class="text-xs font-normal ${D.collector_alive ? 'text-emerald-700' : 'text-red-600'}">${D.collector_alive ? 'running' : 'stopped'}</span>
      <span class="ml-1 px-1.5 py-0.5 rounded text-[11px] ${SVC ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'}">started by ${SVC ? esc(D.supervisor) + ' (background service)' : 'hand (terminal)'}</span></div>
      <p class="text-xs text-slate-600 mt-1 leading-relaxed">${SVC ? 'The collector starts at login and restarts if it stops. Use the button to restart it (for example after changing a permission).' : 'Started manually, so it cannot restart itself from here. To run it as a background service (starts at login, restartable from this page), run <span class="mono">${INSTALL_CMD()}</span> in a terminal.'}</p>
      <button data-restart ${SVC ? '' : 'disabled'} class="mt-2 px-3 py-1.5 rounded text-sm ${SVC ? 'bg-slate-800 hover:bg-slate-700 text-white' : 'bg-slate-100 text-slate-500 cursor-not-allowed'}">↻ Restart collector</button></div>`;
}
function openSettings() { renderSettings(); const m = $('#settingsModal'); m.classList.remove('hidden'); m.classList.add('flex'); }
function closeSettings() { const m = $('#settingsModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
$('#settingsModal').addEventListener('click', e => {
  const b = e.target.closest('[data-restart]'); if (!b || b.disabled) return;
  runAct({type: 'collector.restart', params: {}, label: 'Restart collector', danger: false,
    confirm: 'The collector will exit and the service manager will start it again within a few seconds. The panel may show "collector stopped" briefly.'});
});
$('#setBtn').onclick = openSettings; $('#setClose').onclick = closeSettings;
$('#settingsModal').addEventListener('click', e => { if (e.target.id === 'settingsModal') closeSettings(); });
$('#settingsModal').addEventListener('change', e => {
  const k = e.target.dataset?.settingInt; if (!k) return;
  const m = D.settings_schema[k], v = String(parseInt(e.target.value, 10));
  runAct({type: 'settings.set', params: {key: k, value: v}, label: `${m.label}: ${v}`, danger: false,
    confirm: `${m.label} will be set to ${v}.\n\n${m.desc}\n\nThe settings file is backed up; you can undo this from the action history.`}).then(() => setTimeout(load, 4000));
});
$('#settingsModal').addEventListener('click', e => {
  const b = e.target.closest('[data-setting]'); if (!b) return;
  const key = b.dataset.setting, next = b.dataset.value !== 'true', m = D.settings_schema[key];
  runAct({type: 'settings.set', params: {key, value: String(next)}, label: `${m.label}: ${next ? 'turn on' : 'turn off'}`, danger: false,
    confirm: `${m.label} will be ${next ? 'TURNED ON' : 'TURNED OFF'}.\n\n${m.desc}\n\nThe settings file is backed up; you can undo this from the action history.`}).then(() => setTimeout(load, 4000));
});

/* ------------------------------------------------------------ permission removal: confirm → queue → result notification */
const pendingActs = {};
function toast(kind, msg) {
  const el = document.createElement('div');
  el.className = `rounded-lg border px-3 py-2 text-sm shadow-lg ${kind === 'ok' ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : kind === 'err' ? 'border-red-300 bg-red-50 text-red-900' : 'border-sky-300 bg-sky-50 text-sky-900'}`;
  el.textContent = msg; $('#toasts').appendChild(el); setTimeout(() => el.remove(), 7000);
}
function confirmAct(act) {
  return new Promise(res => {
    $('#modalTitle').textContent = act.label; $('#modalBody').textContent = act.confirm || 'This action will be applied.';
    const yes = $('#modalYes'); yes.textContent = act.danger ? 'Yes, reset' : 'Confirm';
    yes.className = 'px-3 py-1.5 rounded text-sm font-medium ' + (act.danger ? 'bg-red-600 hover:bg-red-500 text-white' : 'bg-amber-500 hover:bg-amber-400 text-slate-900');
    const m = $('#modal'), prev = document.activeElement; m.classList.remove('hidden'); m.classList.add('flex'); $('#modalNo').focus();
    const done = v => { m.classList.add('hidden'); m.classList.remove('flex'); yes.onclick = $('#modalNo').onclick = null; try { prev?.focus(); } catch (e) {} res(v); };
    yes.onclick = () => done(true); $('#modalNo').onclick = () => done(false);
  });
}
async function runAct(act) {
  if (DEMO) return toast('err', 'Demo mode: actions are disabled — nothing here is real.');
  if (!(await confirmAct(act))) return;
  try {
    const r = await fetch('action.php', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-AIGW-Token': D?.token || ''}, body: JSON.stringify({type: act.type, params: act.params, label: act.label})});
    const j = await r.json();
    if (!j.ok) return toast('err', j.error || 'Request rejected');
    pendingActs[j.id] = {label: act.label, at: Date.now()};
    toast('info', 'Request queued; the collector is applying it…');
  } catch (e) { toast('err', 'Request failed: ' + e.message); }
}
function checkPending() {
  for (const [id, p] of Object.entries(pendingActs)) {
    const a = D.action_log.find(x => x.id === id);
    if (a) { toast(a.status === 'done' ? 'ok' : 'err', a.result); delete pendingActs[id]; }
    else if (Date.now() - p.at > 30000) { toast('err', 'The collector did not respond: ' + p.label); delete pendingActs[id]; }
  }
}

document.addEventListener('click', e => {
  const t = e.target;
  const doBtn = t.closest('[data-do]'); if (doBtn) { try { runAct(JSON.parse(doBtn.dataset.do)); } catch (x) {} return; }
  const cell = t.closest('[data-cell]'); if (cell) { const [tool, area] = cell.dataset.cell.split('|'); return openDrawer(tool, area); }
  const tool = t.closest('[data-tool]'); if (tool && !editing) return openDrawer(tool.dataset.tool);
  if (t.closest('#drawerClose') || t.id === 'drawerBg') return closeDrawer();
  const tb = t.closest('[data-tab]'); if (tb) return setTab(tb.dataset.tab);
  const pg = t.closest('[data-pg]');
  if (pg && !pg.disabled) { const [k, d] = pg.dataset.pg.split(':'); PGS[k] = d === 'first' ? 1 : d === 'last' ? 1e9 : (PGS[k] || 1) + parseInt(d, 10); const wid = pg.closest('[data-w]')?.dataset.w; return wid ? renderWidget(wid) : renderAll(); }
  if (t.closest('[data-openset]')) return openSettings();
  if (t.closest('[data-toggle-acc]')) { store.set('aigw.showAccepted', !store.get('aigw.showAccepted', false)); PGS.findings = 1; return renderAll(); }
  const tm = t.closest('[data-tm]'); if (tm) { store.set('aigw.tmode', tm.dataset.tm); return renderAll(); }
  const act = t.closest('[data-act]');
  if (act) {
    const id = act.closest('[data-w]').dataset.w, it = layout.find(x => x.id === id), a = act.dataset.act;
    const si = Math.max(0, SPANS.findIndex(s => s >= it.span));
    if (a === 'narrow') it.span = SPANS[Math.max(0, si - 1)];
    if (a === 'wide') it.span = SPANS[Math.min(SPANS.length - 1, si + 1)];
    if (a === 'height') it.h = (it.h + 1) % 5;
    if (a === 'hide') it.hidden = true;
    saveLayout(); return buildGrid();
  }
  if (t.closest('[data-reset]')) { layout = normalize(PRESETS.default); saveLayout(); return buildGrid(); }
  if (t.id === 'expBtn') return $('#expMenu').classList.toggle('hidden');
  if (!t.closest('#expMenu')) $('#expMenu').classList.add('hidden');
  if (t.id === 'modBtn') return $('#modMenu').classList.toggle('hidden');
  if (!t.closest('#modMenu')) $('#modMenu').classList.add('hidden');
});
document.addEventListener('input', e => {
  const k = e.target.dataset?.search; if (!k) return;
  SEARCH[k] = e.target.value; PGS[k] = 1; renderWidget(e.target.closest('[data-w]').dataset.w);
});
document.addEventListener('change', e => {
  const t = e.target;
  if (t.dataset.mod) { layout.find(x => x.id === t.dataset.mod).hidden = !t.checked; saveLayout(); return buildGrid(); }
  if (t.dataset.filter) { store.set(t.dataset.filter, t.type === 'checkbox' ? t.checked : t.value); PGS.files = 1; return renderAll(); }
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { if (!$('#modal').classList.contains('hidden')) { $('#modalNo').click(); return; } closeDrawer(); closeSettings(); }
  // keyboard activation for non-button controls (matrix cells)
  if ((e.key === 'Enter' || e.key === ' ') && e.target?.matches?.('[data-cell][role="button"]')) { e.preventDefault(); e.target.click(); }
});
$('#editBtn').onclick = () => { editing = !editing; $('#editBtn').textContent = editing ? '✔ Done' : '✎ Edit'; $('#editHint').classList.toggle('hidden', !editing); buildGrid(); };
$('#fsBtn').onclick = () => { try { document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen(); } catch (e) {} };
$('#preset').onchange = e => { if (PRESETS[e.target.value]) { layout = normalize(PRESETS[e.target.value]); saveLayout(); buildGrid(); } e.target.value = ''; };
$('#range').onchange = load;

// Drag & drop reordering (edit mode only)
let dragId = null;
document.addEventListener('dragstart', e => { const w = e.target.closest?.('[data-w]'); if (!editing || !w) return; dragId = w.dataset.w; w.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', dragId); } catch (x) {} });
document.addEventListener('dragover', e => { const w = e.target.closest?.('[data-w]'); if (!editing || !dragId || !w) return; e.preventDefault(); document.querySelectorAll('.over').forEach(x => x.classList.remove('over')); if (w.dataset.w !== dragId) w.classList.add('over'); });
document.addEventListener('drop', e => {
  const w = e.target.closest?.('[data-w]'); if (!editing || !dragId || !w || w.dataset.w === dragId) return; e.preventDefault();
  const from = layout.findIndex(x => x.id === dragId), to = layout.findIndex(x => x.id === w.dataset.w);
  const [it] = layout.splice(from, 1); layout.splice(to, 0, it); saveLayout(); dragId = null; buildGrid();
});
document.addEventListener('dragend', () => { dragId = null; document.querySelectorAll('.dragging,.over').forEach(x => x.classList.remove('dragging', 'over')); });

/* ------------------------------------------------------------ data loop */
function banners(d) {
  const vb = $('#verBadge'); if (vb && d.version) vb.title = 'v' + d.version + ' — beta release: please report problems on GitHub';
  const b = [];
  if (DEMO) b.push(['amber', 'DEMO DATA — everything here is synthetic. Nothing real is read and actions are disabled. <a class="underline font-medium" href="./">Exit demo</a>']);
  const P = d.platform || {os: 'mac', label: 'macOS', bytes: true, files: true, tcc: true};
  if (!DEMO && !d.collector_alive) b.push(['red', `The collector is not running — data is stale. Install the background service once (<code class="mono">${INSTALL_CMD()}</code>) so it always runs and can be restarted from ⚙ Settings, or start it by hand: <code class="mono">php bin/collect.php</code>`]);
  if (!DEMO && P.os === 'windows') b.push(['sky', 'Windows mode: processes, destinations and configuration audits work, but Windows exposes <b>no per-connection byte counters</b> and no open-file listing, so <b>upload volume, the Files tab and observed-file findings stay empty</b>. Usage (tokens), Permissions and Findings work normally.']);
  if (!DEMO && P.os === 'linux') b.push(['sky', 'Linux mode: open files are read from <span class="mono">/proc</span> and byte counters from <span class="mono">ss</span>, so only processes owned by your own user are visible. UDP traffic is not counted.']);
  if (!DEMO && P.tcc && !d.scan_system) b.push(['sky', 'System permission scan is <b>off</b>: macOS permissions such as Full Disk Access, Screen Recording and Accessibility, and "theoretical access", are not shown. Turn it on from <b>⚙ Settings</b> at the top right.']);
  else if (!DEMO && P.tcc && d.tcc_status !== 'ok') b.push(['amber', `Could not read the macOS permission database (${esc(d.tcc_status)}). Grant <b>Full Disk Access</b> to the app that started the collector — or, when it runs as the LaunchAgent, to the <span class="mono">php</span> binary it uses (System Settings → Privacy & Security) — then restart the collector.`]);
  const col = {red: 'border-red-300 bg-red-50 text-red-800', sky: 'border-sky-300 bg-sky-50 text-sky-800', amber: 'border-amber-300 bg-amber-50 text-amber-800'};
  $('#banner').innerHTML = b.map(([c, m]) => `<div class="rounded-lg border ${col[c]} px-3 py-2 text-xs">${m}</div>`).join('');
}
async function load() {
  if (window.__TOURING && D) return; // the self-playing tour (tour.js) needs a stable page
  try {
    const r = await fetch('api.php?range=' + $('#range').value + (DEMO ? '&demo=1' : ''), {cache: 'no-store'}); const j = await r.json();
    if (!j.ready) { $('#banner').innerHTML = '<div class="rounded-lg border border-amber-300 bg-amber-50 text-amber-800 px-3 py-2 text-sm">No data yet. Start the collector: <code class="mono">bin/start.sh</code></div>'; return; }
    D = j; derived = derive(D);
    $('#status').innerHTML = `<span class="w-2 h-2 rounded-full ${D.collector_alive ? 'bg-emerald-400 pulse' : 'bg-red-500'}"></span><span>${D.collector_alive ? 'live' : 'collector stopped'}</span>`;
    banners(D); checkPending(); if (!$('#settingsModal').classList.contains('hidden') && !document.activeElement?.closest?.('#settingsModal input')) renderSettings();
    if (dragId) return; // do not refresh the DOM while dragging
    const ae = document.activeElement; // do not close an open select/checkbox by refreshing
    if (ae && ['SELECT', 'INPUT'].includes(ae.tagName) && ae.closest('#grid')) return;
    if (!document.querySelector('#grid [data-w]')) buildGrid(); else { renderAll(); }
  } catch (e) { console.error(e); $('#status').innerHTML = '<span class="w-2 h-2 rounded-full bg-red-500"></span><span>API error</span>'; }
}
/* Demo tour for screen recordings: ?demo=1&tour=1 (see tour.js) */
if (DEMO && new URLSearchParams(location.search).has('tour')) { window.__TOURING = true; const ts = document.createElement('script'); ts.src = 'tour.js'; document.body.appendChild(ts); }
buildGrid(); load(); setInterval(load, 3000);
</script>
</body>
</html>
