/*
 * SecAIQ Watch — self-playing demo tour, for screen recordings.
 * Only active in demo mode:  ?demo=1&tour=1   (the static demo adds demo=1 itself)
 * Options:  speed=1.5   faster/slower (0.4–3)      loop=1   repeat      banner=0   hide the yellow "DEMO DATA" bar
 * Press Esc to stop. It drives the page's own UI (tabs, tooltips, side panel) with an animated cursor; nothing is sent anywhere.
 */
(() => {
  const q = new URLSearchParams(location.search);
  const SPEED = Math.max(0.4, Math.min(3, parseFloat(q.get('speed')) || 1));
  const LOOP = q.get('loop') === '1';
  let stopped = false;
  const sleep = ms => new Promise(r => setTimeout(r, ms / SPEED));
  const stopCheck = () => { if (stopped) throw new Error('tour stopped'); };

  document.head.insertAdjacentHTML('beforeend', `<style>
    ${q.get('banner') === '0' ? '#banner{display:none!important}' : ''}
    #tourCursor{position:fixed;left:0;top:0;width:28px;height:28px;z-index:100000;pointer-events:none;transform:translate(60vw,50vh);
      transition:transform .9s cubic-bezier(.45,.05,.25,1);filter:drop-shadow(0 2px 3px rgba(0,0,0,.35))}
    #tourRipple{position:fixed;left:0;top:0;width:44px;height:44px;margin:-22px 0 0 -22px;border-radius:50%;z-index:99999;pointer-events:none;
      border:3px solid #00AAE3;opacity:0;transform:scale(.3)}
    #tourRipple.go{animation:tourRip .55s ease-out}
    @keyframes tourRip{0%{opacity:.9;transform:scale(.3)}100%{opacity:0;transform:scale(1.25)}}
    #tourEnd{position:fixed;inset:0;z-index:100001;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;
      background:#fff;opacity:0;pointer-events:none;transition:opacity .9s}
    #tourEnd.show{opacity:1}
    #tourEnd img{width:150px;height:150px}
    #tourEnd h2{font:700 54px/1.15 system-ui,sans-serif;margin:18px 0 8px;color:#002562;letter-spacing:-.5px}
    #tourEnd h2 b{background:linear-gradient(90deg,#00AAE3,#00CBB8);-webkit-background-clip:text;background-clip:text;color:transparent}
    #tourEnd p{font:500 22px/1.5 system-ui,sans-serif;color:#475569;margin:4px 0}
    #tourEnd code{font:600 21px ui-monospace,Menlo,monospace;color:#00709a;background:#e8f8fd;padding:6px 14px;border-radius:10px}
  </style>`);
  const cur = document.createElement('div');
  cur.id = 'tourCursor';
  cur.innerHTML = '<svg viewBox="0 0 24 24" width="28" height="28"><path d="M3 2l17 8.5-7 2-3 7.5z" fill="#111" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/></svg>';
  const rip = document.createElement('div'); rip.id = 'tourRipple';
  const end = document.createElement('div'); end.id = 'tourEnd';
  end.innerHTML = '<img src="img/secaiq-watch.svg" alt=""><h2><span style="color:#002562">Sec</span><b>AI</b><span style="color:#002562">Q</span> Watch</h2>' +
    '<p>Free · open source · local and read-only</p><p style="margin-top:16px"><code>github.com/Spaksu/secaiq-watch</code></p>';
  document.body.append(cur, rip, end);
  let cx = innerWidth * 0.6, cy = innerHeight * 0.5;

  document.addEventListener('keydown', e => { if (e.key === 'Escape') { stopped = true; cur.remove(); rip.remove(); end.remove(); } });

  const $$ = (s, root = document) => [...root.querySelectorAll(s)];
  const $1 = (s, root = document) => root.querySelector(s);
  async function waitFor(fn, ms = 4000) { const t0 = Date.now(); for (;;) { const v = fn(); if (v) return v; if (Date.now() - t0 > ms) return null; await new Promise(r => setTimeout(r, 80)); stopCheck(); } }
  const textEl = (re, root = document) => { const w = document.createTreeWalker(root.body || root, NodeFilter.SHOW_TEXT); for (let n; (n = w.nextNode());) if (re.test(n.nodeValue) && n.parentElement && n.parentElement.offsetParent) return n.parentElement; return null; };

  async function scrollToY(y, ms = 1300) {
    stopCheck();
    const y0 = scrollY, max = Math.max(0, document.documentElement.scrollHeight - innerHeight), y1 = Math.max(0, Math.min(max, y)), dur = ms / SPEED, t0 = performance.now();
    if (Math.abs(y1 - y0) < 4) return;
    await new Promise(res => { const step = t => { const p = Math.min(1, (t - t0) / dur), e = p < .5 ? 2 * p * p : 1 - Math.pow(-2 * p + 2, 2) / 2; scrollTo(0, y0 + (y1 - y0) * e); p < 1 ? requestAnimationFrame(step) : res(); }; requestAnimationFrame(step); });
  }
  async function ensureVisible(el) {
    const r = el.getBoundingClientRect();
    if (r.top < 150 || r.bottom > innerHeight - 40) await scrollToY(scrollY + r.top - Math.min(260, innerHeight * 0.3), 900);
  }
  async function moveTo(x, y, ms = 900) {
    stopCheck(); cur.style.transitionDuration = (ms / SPEED) + 'ms'; cur.style.transform = `translate(${x}px,${y}px)`; cx = x; cy = y; await sleep(ms + 60);
  }
  function fire(el, type, x, y) { el.dispatchEvent(new MouseEvent(type, {bubbles: true, cancelable: true, clientX: x, clientY: y, view: window})); }
  async function hover(target, dwell = 1100, inScrollable = false) {
    const el = typeof target === 'string' ? await waitFor(() => $1(target)) : target; if (!el) return null;
    if (!inScrollable) await ensureVisible(el);
    const r = el.getBoundingClientRect(), x = r.left + Math.min(r.width / 2, 60), y = r.top + Math.min(r.height / 2, 30);
    await moveTo(x, y); fire(el, 'mouseover', x, y); fire(el, 'mousemove', x, y); await sleep(dwell);
    return el;
  }
  async function click(target, after = 700, inScrollable = false) {
    const el = await hover(target, 250, inScrollable); if (!el) return null;
    rip.style.left = cx + 'px'; rip.style.top = cy + 'px'; rip.classList.remove('go'); void rip.offsetWidth; rip.classList.add('go');
    await sleep(160); el.click(); await sleep(after); return el;
  }
  const clearTip = () => { try { window.tipHide && window.tipHide(); } catch (e) {} };
  async function tab(id) { clearTip(); await click(`[data-tab="${id}"]`, 900); await scrollToY(0, 600); }

  async function tourOnce() {
    const dr = () => $1('#drawer');
    // ---- 1 Overview
    await tab('overview'); await sleep(600);
    const k = textEl(/^Data sent$/); if (k) await hover(k.closest('div') || k, 900);
    const kr = textEl(/^Critical-area access$/); if (kr) await hover(kr.closest('div') || kr, 800);
    const bars = $$('#grid svg rect').filter(r => r.getBoundingClientRect().height > 20).sort((a, b) => b.getBoundingClientRect().height - a.getBoundingClientRect().height);
    if (bars[0]) await hover(bars[0], 900);
    const donut = $1('#grid svg circle'); if (donut) await hover(donut, 800);
    const ai = textEl(/^AI tools$/); if (ai) { await scrollToY(scrollY + ai.getBoundingClientRect().top - 120, 1200); }
    const card = await waitFor(() => $1('[data-tool]')); if (card) { await hover(card, 700); await click(card, 1400); }
    if (dr() && !dr().classList.contains('translate-x-full')) { await sleep(600); await click('#drawerClose', 700); }

    // ---- 2 Permissions matrix -> evidence side panel -> how to remove
    await tab('permissions'); await sleep(500);
    const seen = new Set(), cells = [];
    for (const c of $$('[data-cell]')) { const key = c.className; if (!seen.has(key)) { seen.add(key); cells.push(c); } if (cells.length >= 4) break; }
    for (const c of cells) await hover(c, 1600, true);
    clearTip();
    const granted = $$('[data-cell]').find(c => /bg-amber|orange|granted/i.test(c.className)) || cells[1] || cells[0];
    if (granted) { await click(granted, 1600, true); }
    const sum = await waitFor(() => $1('#drawer details[data-howto] summary'), 3000);
    if (sum) {
      await click(sum, 900, true);
      for (const os of ['linux', 'windows', 'mac']) { const b = $1(`#drawer [data-howto-os="${os}"]`); if (b) await click(b, 1100, true); }
      await sleep(900);
    }
    if (dr() && !dr().classList.contains('translate-x-full')) await click('#drawerClose', 700);

    // ---- 3 Findings
    await tab('findings'); await sleep(700);
    await scrollToY(300, 1500); await sleep(600);
    const fix = textEl(/^Protect: block/) || textEl(/^Turn off bypass mode$/); if (fix) await hover(fix, 1200);
    await scrollToY(760, 1800); await sleep(600);
    const acc = textEl(/^Accept risk · 30 days$/); if (acc) await hover(acc, 900);
    await scrollToY(0, 1000);

    // ---- 4 Network, files, usage
    await tab('network'); await sleep(600);
    const row = $1('#grid tbody tr'); if (row) await hover(row, 900);
    await scrollToY(420, 1400); await sleep(500);
    await tab('files'); await sleep(500);
    const frow = $1('#grid tbody tr'); if (frow) await hover(frow, 800);
    await tab('usage'); await sleep(700);
    const ub = $$('#grid svg rect').filter(r => r.getBoundingClientRect().height > 20)[4]; if (ub) await hover(ub, 900);
    await scrollToY(430, 1500); await sleep(900);

    // ---- 5 Inventory, guide
    await tab('inventory'); await sleep(700);
    await tab('guide'); await sleep(600);
    const jump = textEl(/^Install$/) ; if (jump && jump.closest('button')) { await click(jump.closest('button'), 1300); await sleep(900); }
    await scrollToY(0, 900);

    // ---- end card
    await tab('overview'); await sleep(800);
    end.classList.add('show'); await sleep(4200);
  }

  async function run() {
    await waitFor(() => window.D && document.querySelector('#grid [data-w]'), 15000);
    await sleep(1500);
    try {
      do { await tourOnce(); if (LOOP) { end.classList.remove('show'); await sleep(900); } } while (LOOP && !stopped);
    } catch (e) { if (!/tour stopped/.test(String(e))) console.error('tour:', e); }
    window.__tourDone = true;
  }
  run();
})();
