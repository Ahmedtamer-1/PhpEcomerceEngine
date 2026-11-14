// LUMEEGY Part 5: automated live-demo runner.
//
// Opens two visible Edge windows side by side and plays the 8 steps of demo-script.md:
//   LEFT  = attacker / guest / customer      RIGHT = admin
// Every action follows the same pattern, in the window where it happens:
//   1. a yellow "NEXT" card says what the attacker will do and what we expect,
//   2. the window gets an orange outline and an orange Continue button; nothing moves until you click it,
//   3. the action runs in the page (typing into the real forms), then a green/red RESULT card says
//      what happened and which control blocked it.
// Talks to Edge over the DevTools protocol, so it needs no npm package.
// LOCAL TEST COPY ONLY: it refuses any site other than localhost:8090 (and its own attacker page on :9000).
//
// Run from the repo root (PowerShell or Git Bash):
//   node docs/security/demo-runner.mjs --speed 0.7   the presentation pace we use
//   --auto      no clicks needed (rehearsal)          --burp      left window goes through Burp (127.0.0.1:8082)
//   --step 3    start at step 3 (no seed reset)       --keep      leave the windows open at the end
//   --speed N   scale the short holds inside an action (typing speed never changes)
//   --shots D   save a screenshot after every action in folder D (fresh fallback evidence)
//
// It resets the database from database/seed-security-test.sql at the start and the end.

import { spawn, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdtempSync, mkdirSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { createInterface } from 'node:readline';

const args = process.argv.slice(2);
const AUTO = args.includes('--auto');
const BURP = args.includes('--burp');
const KEEP = args.includes('--keep');
const START = args.includes('--step') ? Number(args[args.indexOf('--step') + 1]) || 1 : 1;
const SPEED = args.includes('--speed') ? Number(args[args.indexOf('--speed') + 1]) || 1 : 1;
const SHOTS = args.includes('--shots') ? resolve(args[args.indexOf('--shots') + 1]) : '';
const SITE = 'http://localhost:8090';
const ATTACKER = 'http://localhost:9000';
const ROOT = resolve('.');
const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const MYSQL = 'C:/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe';
const ERRLOG = 'C:/Apache24/logs/lumeegy-error.log';
const REPORT = join(ROOT, 'docs/security/Vulnerability-Assessment-Report.pdf');
const ZAP = join(ROOT, 'docs/security/evidence/zap/zap-before-summary.html');
const fileUrl = p => 'file:///' + p.split('\\').join('/');
const sleep = ms => new Promise(r => setTimeout(r, ms));
const beat = ms => sleep(AUTO ? Math.min(ms, 300) : ms * SPEED);

if (!existsSync(join(ROOT, 'database/seed-security-test.sql'))) {
  console.error('Run this from the repository root.'); process.exit(1);
}

// ── Test accounts and DB access (local test values from the seed and .env) ──
const seed = readFileSync(join(ROOT, 'database/seed-security-test.sql'), 'utf8');
const cred = label => (seed.match(new RegExp('^--\\s+' + label + '\\s+(\\S+)\\s+(\\S+)', 'm')) || []).slice(1);
const [ADMIN_EMAIL, ADMIN_PW] = cred('admin');
const [A_EMAIL, A_PW] = cred('customer A');
const [B_EMAIL, B_PW] = cred('customer B');
const env = Object.fromEntries(readFileSync(join(ROOT, '.env'), 'utf8').split(/\r?\n/)
  .filter(l => /^[A-Z_]+=/.test(l)).map(l => { const i = l.indexOf('='); return [l.slice(0, i), l.slice(i + 1).replace(/^["']|["']$/g, '')]; }));
const sql = q => {
  const r = spawnSync(MYSQL, ['-u', env.DB_USER, env.DB_NAME, '-N', '-e', q], { cwd: ROOT, env: { ...process.env, MYSQL_PWD: env.DB_PASS }, encoding: 'utf8' });
  if (r.status !== 0) throw new Error('mysql: ' + r.stderr);
  return r.stdout.trim();
};
const events = t => Number(sql(`SELECT COUNT(*) FROM security_events WHERE event_type='${t}'`));
const reseed = () => { sql('source database/seed-security-test.sql'); };

// ── Demo-only files in a temp folder: the attacker's page and the fake image ──
const TMP = mkdtempSync(join(tmpdir(), 'lume-demo-files-'));
// Same forged request as evidence/burp/csrf-poc.html, but with a button the presenter clicks,
// so the audience sees the attack happen instead of an instant auto-submit.
writeFileSync(join(TMP, 'gift.html'), `<!doctype html><html><head><meta charset="utf-8"><title>Free gift!</title></head>
<body style="font-family:Segoe UI,Arial;background:#fff7e6;text-align:center;padding-top:150px">
<h1 style="font-size:40px">🎁 Congratulations!</h1><p style="font-size:20px">You have won a free gift. Claim it now.</p>
<form id="f" method="POST" action="${SITE}/api/cart.php">
  <input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="3"><input type="hidden" name="quantity" value="5">
  <button id="claim" style="font-size:22px;padding:14px 28px;background:#2e7d32;color:#fff;border:0;border-radius:8px;cursor:pointer">Claim your free gift</button>
</form>
<p style="color:#888;margin-top:40px">(attacker's site, localhost:9000 – the hidden form posts to the shop with no CSRF token)</p></body></html>`);
const SHELL = join(TMP, 'shell.php.jpg');
writeFileSync(SHELL, '<?php echo 1; ?>\n');

// ── Minimal DevTools-protocol client for one Edge window ──
class Win {
  static async open(name, label, port, x, extra = []) {
    const prof = mkdtempSync(join(tmpdir(), `lume-demo-${name}-`));
    const proc = spawn(EDGE, [`--remote-debugging-port=${port}`, `--user-data-dir=${prof}`, '--no-first-run',
      '--no-default-browser-check', '--disable-sync', '--disable-features=msEdgeSignin,EdgeCollections',
      `--window-position=${x},0`, '--window-size=960,1040', ...extra, 'about:blank'], { stdio: 'ignore' });
    let ver;
    for (let i = 0; i < 80 && !ver; i++) {
      try { ver = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); } catch { await sleep(250); }
    }
    if (!ver) throw new Error(`Edge did not start on port ${port}`);
    await sleep(1500); // let Edge finish opening its first tab
    // Use a tab we create ourselves, and close every other one (welcome pages etc.).
    const tab = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
    for (const t of await (await fetch(`http://127.0.0.1:${port}/json/list`)).json())
      if (t.type === 'page' && t.id !== tab.id) await fetch(`http://127.0.0.1:${port}/json/close/${t.id}`);
    const w = new Win(name, label, proc);
    w.browserWs = ver.webSocketDebuggerUrl;
    await w.connect(tab.webSocketDebuggerUrl);
    return w;
  }
  constructor(name, label, proc) { this.name = name; this.label = label; this.proc = proc; this.id = 0; this.pend = new Map(); this.loads = 0; this.top = ['', '']; }
  connect(url) {
    this.ws = new WebSocket(url);
    this.ws.onmessage = m => {
      const d = JSON.parse(m.data);
      if (d.id && this.pend.has(d.id)) { this.pend.get(d.id)(d); this.pend.delete(d.id); }
      else if (d.method === 'Page.loadEventFired') this.loads++;
      else if (d.method === 'Page.javascriptDialogOpening') this.send('Page.handleJavaScriptDialog', { accept: true });
    };
    return new Promise(r => this.ws.onopen = async () => { await this.send('Page.enable'); await this.send('Runtime.enable'); await this.send('DOM.enable'); r(); });
  }
  send(method, params = {}) {
    return new Promise(r => { const i = ++this.id; this.pend.set(i, r); this.ws.send(JSON.stringify({ id: i, method, params })); });
  }
  async js(expr) {
    const r = await this.send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
    if (r.result?.exceptionDetails) throw new Error(`[${this.name}] ${r.result.exceptionDetails.exception?.description || expr}`);
    return r.result?.result?.value;
  }
  // Overlay helpers never fail the demo (e.g. the PDF viewer page cannot take them).
  ui(expr) { return this.js(expr).catch(() => {}); }
  async waitLoad(before, ms = 20000) {
    for (let t = 0; t < ms && this.loads <= before; t += 100) await sleep(100);
    await sleep(400);
  }
  async go(url) {
    if (![SITE, ATTACKER, 'file:'].some(p => url.startsWith(p))) throw new Error('refusing non-local URL ' + url);
    const n = this.loads; await this.send('Page.navigate', { url }); await this.waitLoad(n);
    await this.banner(...this.top);
  }
  async type(sel, text) {
    await this.js(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});e.scrollIntoView({block:'center'});e.focus();e.value='';})()`);
    for (const ch of text) { await this.send('Input.insertText', { text: ch }); await sleep(AUTO ? 5 : 35); }
  }
  async click(sel, nav = true) {
    const n = this.loads;
    await this.js(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});e.scrollIntoView({block:'center'});e.click();})()`);
    if (nav) { await this.waitLoad(n); await this.banner(...this.top); } else await sleep(1200);
  }
  // Top bar: which window this is and which step is running.
  banner(title, sub = '') {
    this.top = [title, sub];
    const role = `${this.label}`;
    return this.ui(`(()=>{let b=document.getElementById('__demo');if(!b){b=document.createElement('div');b.id='__demo';
      b.style.cssText='position:fixed;left:0;right:0;top:0;z-index:2147483646;background:#1f3a5f;color:#fff;font:600 16px Segoe UI,Arial;padding:8px 14px;box-shadow:0 2px 8px #0006';
      document.documentElement.appendChild(b);}
      b.innerHTML='<span style="background:#fff;color:#1f3a5f;border-radius:4px;padding:1px 8px;margin-right:10px;font-size:13px">'+${JSON.stringify(role)}+'</span>'+${JSON.stringify(title)}
        +'<div style="font-weight:400;font-size:13px;opacity:.85">'+${JSON.stringify(sub)}+'</div>';})()`);
  }
  // Yellow card under the banner: what happens next (before the click) / now (after it).
  card(kind, text) {
    const bg = kind === 'NEXT' ? '#fff3c4' : '#e3ecf7', fg = kind === 'NEXT' ? '#5a4300' : '#1f3a5f';
    return this.ui(`(()=>{let c=document.getElementById('__card');if(!c){c=document.createElement('div');c.id='__card';document.documentElement.appendChild(c);}
      c.style.cssText='position:fixed;left:12px;right:12px;top:64px;z-index:2147483646;background:${bg};color:${fg};font:500 15px Segoe UI,Arial;padding:10px 14px;border-radius:6px;white-space:pre-wrap;box-shadow:0 2px 10px #0005;border-left:6px solid ${fg}';
      c.innerHTML='<b>${kind} ▸ </b>'+${JSON.stringify(text)}.replace(/&/g,'&amp;').replace(/</g,'&lt;');})()`);
  }
  // Result card at the bottom: what happened and which control did it.
  result(text, ok = true) {
    return this.ui(`(()=>{let b=document.getElementById('__note');if(!b){b=document.createElement('div');b.id='__note';document.documentElement.appendChild(b);}
      b.style.cssText='position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483646;color:#fff;font:600 15px Segoe UI,Arial;padding:10px 14px;border-radius:6px;white-space:pre-wrap;box-shadow:0 2px 10px #0007;background:${ok ? '#1b5e20' : '#9b1c1c'}';
      b.textContent=(${ok} ? '✔ RESULT: ' : '✘ RESULT: ')+${JSON.stringify(text)};})()`);
  }
  clearCards() { return this.ui(`['__card','__note'].forEach(i=>document.getElementById(i)?.remove())`); }
  // Orange outline + Continue button: only in the window where the next action will happen.
  waitMarks(on, label = '') {
    return this.ui(on ? `(()=>{['__cont','__frame'].forEach(i=>document.getElementById(i)?.remove());window.__demoGo=false;
      const f=document.createElement('div');f.id='__frame';f.style.cssText='position:fixed;inset:0;border:6px solid #e65100;z-index:2147483645;pointer-events:none';document.documentElement.appendChild(f);
      const b=document.createElement('button');b.id='__cont';b.textContent='Continue ▶  '+${JSON.stringify(label)};b.onclick=()=>{window.__demoGo=true;b.textContent='…';};
      b.style.cssText='position:fixed;right:16px;bottom:110px;z-index:2147483647;background:#e65100;color:#fff;border:0;border-radius:8px;font:700 16px Segoe UI,Arial;padding:12px 18px;cursor:pointer;box-shadow:0 3px 12px #0008;max-width:70%;text-align:left';
      document.documentElement.appendChild(b);})()` : `(()=>{window.__demoGo=false;['__cont','__frame'].forEach(i=>document.getElementById(i)?.remove());})()`);
  }
  async highlight(textToFind) {
    await this.ui(`(()=>{const w=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);let n;while(n=w.nextNode()){if(n.nodeValue.includes(${JSON.stringify(textToFind)})){const el=n.parentElement;el.scrollIntoView({block:'center'});el.style.outline='3px solid #f9a825';el.style.background='#fff3c4';el.style.color='#000';return true;}}return false;})()`);
  }
  // Panel in the middle of the page (used for requests, responses and log lines).
  panel(title, body) {
    return this.ui(`(()=>{let p=document.getElementById('__panel');if(!p){p=document.createElement('div');p.id='__panel';document.documentElement.appendChild(p);}
      p.style.cssText='position:fixed;left:40px;right:40px;top:200px;z-index:2147483646;background:#111827;color:#e5e7eb;font:14px Consolas,monospace;padding:14px 16px;border-radius:8px;white-space:pre-wrap;word-break:break-all;box-shadow:0 4px 16px #000a;border:2px solid #f9a825';
      p.textContent=${JSON.stringify(title)}+'\\n\\n'+${JSON.stringify(body)};})()`);
  }
  clearPanel() { return this.ui(`document.getElementById('__panel')?.remove()`); }
  async setFile(sel, path) {
    const doc = await this.send('DOM.getDocument', { depth: -1 });
    const q = await this.send('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: sel });
    await this.send('DOM.setFileInputFiles', { nodeId: q.result.nodeId, files: [path] });
  }
  // Killing the launcher does not close Edge, so ask the browser itself to quit.
  async close() {
    try {
      const b = new WebSocket(this.browserWs);
      await new Promise((r, j) => { b.onopen = r; b.onerror = j; });
      b.send(JSON.stringify({ id: 1, method: 'Browser.close' })); await sleep(500); b.close();
    } catch {}
    try { this.ws.close(); } catch {} try { this.proc.kill(); } catch {}
  }
}

// ── Pacing: nothing moves until the presenter clicks (or presses Enter in the terminal) ──
const rl = createInterface({ input: process.stdin });
const lines = []; rl.on('line', l => lines.push(l));
async function waitClick(win, label) {
  console.log(`  [${win.label}] click Continue: ${label}`);
  if (AUTO) { await sleep(400); return; }
  await win.waitMarks(true, label);
  lines.length = 0;
  for (let go = false; !go;) {
    if (lines.length) { lines.shift(); break; }
    try { go = await win.js('window.__demoGo === true'); } catch {}
    if (!go) await sleep(250);
  }
  await win.waitMarks(false);
}
// One action = NEXT card → click → run → (the action shows its own RESULT card).
async function act(win, what, fn) {
  await win.clearCards(); await win.clearPanel();
  await win.card('NEXT', what);
  await waitClick(win, what.split('\n')[0]);
  await win.card('NOW', what);
  await fn();
  if (SHOTS) {
    const s = await win.send('Page.captureScreenshot', { format: 'png' });
    if (s.result) writeFileSync(join(SHOTS, `${String(++shotN).padStart(2, '0')}-${win.name}.png`), Buffer.from(s.result.data, 'base64'));
  }
}
let shotN = 0;
const results = [];
const check = (step, label, pass, detail) => {
  results.push({ step, label, pass, detail });
  console.log(`  ${pass ? '\x1b[32mPASS\x1b[0m' : '\x1b[31mFAIL\x1b[0m'}  ${label}  ${detail}`);
};
const header = (n, title) => console.log(`\n\x1b[1;36m▶ Step ${n} · ${title}\x1b[0m`);

// Type into the live search box and wait until its results (or "No results found") arrive.
async function searchFor(win, text) {
  await win.js(`document.getElementById('lume-search-results').innerHTML=''`);
  await win.type('#lume-search-input', text);
  for (let i = 0; i < 50; i++) {
    const done = await win.js(`!!document.querySelector('#lume-search-results .lume-search-result, #lume-search-results .lume-search-empty')`);
    if (done) break;
    await sleep(100);
  }
  return win.js(`document.querySelectorAll('#lume-search-results .lume-search-result').length`);
}

// ── Steps ──
async function step1(L) {
  header(1, 'SQLI-01: SQL injection in the shop search');
  await L.banner('Step 1 · SQL injection (SQLI-01)', 'Attack the shop search box · defence: prepared statements');
  await L.go(`${SITE}/shop.php`);
  let c = 0, a = -1;
  await act(L, 'Control: a normal search.\nType "ring" in the shop search box. Expected: the 2 rings.', async () => {
    await L.click('#btn-search', false); c = await searchFor(L, 'ring'); await beat(1500);
    await L.result(`Normal search "ring" → ${c} products.`, c === 2);
  });
  const payload = "ring' OR '1'='1' -- -";
  await act(L, `Attack: type an SQL injection payload into the same box:\n${payload}\nIf the input became SQL, the OR '1'='1' would return EVERY product.`, async () => {
    a = await searchFor(L, payload); await beat(1500);
    await L.result(`Payload → ${a} products ("No results found"). No extra rows, no SQL error.\nThe search text is sent to MySQL as a bound parameter (prepared statement), so it is only ever treated as text.`, a === 0);
  });
  let err = true, a2 = -1;
  await act(L, 'Same payload on the full shop results page (shop.php?q=…).\nExpected: 0 products and no database error on the page.', async () => {
    await L.go(`${SITE}/shop.php?q=${encodeURIComponent(payload)}`);
    a2 = await L.js(`document.querySelectorAll('.lume-product-card').length`);
    err = await L.js(`/SQLSTATE|syntax error|mysql/i.test(document.body.innerText)`);
    await L.highlight('Showing');
    await L.result(`Results page: ${a2} products, SQL error shown: ${err ? 'YES' : 'no'}. Blocked by prepared statements.`, a2 === 0 && !err);
  });
  check(1, 'SQLI-01', c === 2 && a === 0 && a2 === 0 && !err, `control=${c} payloadBox=${a} payloadPage=${a2} sqlError=${err}`);
}

async function step2(L, R) {
  header(2, 'XSS-02: stored XSS through the contact form, viewed by the admin');
  const P = '<img src=x onerror=alert(1)>';
  await L.banner('Step 2 · Stored XSS (XSS-02)', 'A guest plants script in a message the admin will read · defence: h() escaping');
  await L.go(`${SITE}/contact.php`);
  await act(L, `A guest sends a contact message whose name, subject and text are:\n${P}\nIf the admin page printed it raw, the browser would run alert(1).`, async () => {
    await L.type('#contact-form [name=name]', P); await L.type('#contact-form [name=email]', 'guest@lumeegy.test');
    await L.type('#contact-form [name=subject]', P); await L.type('#contact-form [name=message]', P);
    await L.click('#contact-form button[type=submit]', false);
    await L.result(`${await L.js(`(document.getElementById('contact-msg')||{}).innerText||'Message sent'`)}\nThe payload is now stored in the database.`);
  });
  let raw = -1;
  await R.banner('Step 2 · Stored XSS (XSS-02)', 'The admin reads the message');
  await act(R, 'The admin opens Messages. Expected: the payload is shown as plain text and NO alert pops up.', async () => {
    await R.go(`${SITE}/admin/messages.php`); await R.highlight(P);
    raw = await R.js(`document.documentElement.innerHTML.split('<img src=x onerror').length-1`);
    await R.result(`Shown as text (highlighted). Executable <img onerror> tags on the page: ${raw}. No alert ran.\nEvery output goes through h() (htmlspecialchars).`, raw === 0);
  });
  check(2, 'XSS-02', raw === 0, `raw tags=${raw}`);
}

async function step3(L, R) {
  header(3, 'AUTH-01: brute force → lockout → Security Events → admin Unlock');
  await L.banner('Step 3 · Brute force (AUTH-01)', 'Password guessing against customer B · defence: C4 lockout + C1 logging');
  await R.banner('Step 3 · Brute force (AUTH-01)', 'The admin will see the attack');
  await L.go(`${SITE}/account.php`);
  let last = '';
  await act(L, `The attacker guesses customer B's password 6 times (wrong1 … wrong6).\nExpected: the server slows down after 2 failures, then locks the account.`, async () => {
    for (let i = 1; i <= 6; i++) {
      await L.type('form:has(input[name=action][value=login]) [name=email]', B_EMAIL);
      await L.type('form:has(input[name=action][value=login]) [name=password]', `wrong${i}`);
      const t0 = Date.now(); await L.click('form:has(input[name=action][value=login]) button[type=submit]'); const ms = Date.now() - t0;
      last = await L.js(`(document.body.innerText.match(/Too many attempts[^\\n]*|Invalid email or password\\.?/)||[''])[0]`);
      await L.card('NOW', `Attempt ${i}/6 with "wrong${i}": ${last}  (${ms} ms)`);
      await beat(1400);
    }
    await L.result(`${last}\nAccount locked (C4). Note the growing response time before the lock (progressive delay).`, /Too many attempts/.test(last));
  });
  const locked = /Too many attempts/.test(last);
  let f = 0, k = 0;
  await act(R, 'The admin opens Security Events. Expected: 5 × login_failed and 1 × login_locked (critical) for customer B.', async () => {
    await R.go(`${SITE}/admin/security.php`); await R.highlight('login_locked');
    f = events('login_failed'); k = events('login_locked');
    await R.result(`Logged by C1: ${f} × login_failed, ${k} × login_locked. The attack is visible to the admin.`, f === 5 && k === 1);
  });
  await act(R, 'The admin opens customer B and clicks "Unlock account". Expected: "Sign-in lock cleared."', async () => {
    await R.go(`${SITE}/admin/users.php?id=3`); await R.highlight('Unlock account'); await beat(2000);
    await R.click('form:has(input[name=unlock_user_id]) button[type=submit]');
    await R.highlight('Sign-in lock cleared');
    await R.result('Sign-in lock cleared (login_unlock()); account_unlocked is logged.');
  });
  let signedIn = false;
  await act(L, 'The real customer B now signs in with the correct password. Expected: success.', async () => {
    await L.go(`${SITE}/account.php`);
    await L.type('form:has(input[name=action][value=login]) [name=email]', B_EMAIL);
    await L.type('form:has(input[name=action][value=login]) [name=password]', B_PW);
    await L.click('form:has(input[name=action][value=login]) button[type=submit]');
    signedIn = await L.js(`/Welcome back|Logout/i.test(document.body.innerText)`);
    await L.result(signedIn ? 'Customer B is signed in again after the unlock.' : 'Sign-in failed', signedIn);
  });
  check(3, 'AUTH-01', locked && f === 5 && k === 1 && signedIn, `locked=${locked} failed=${f} lockedEvt=${k} signedInAfterUnlock=${signedIn}`);
}

async function step4(L, R) {
  header(4, "RBAC-01/02: a customer tries the admin area and another customer's order");
  await L.banner('Step 4 · Access control (RBAC-01/02)', 'A normal customer tries to reach what is not hers · defence: role and ownership checks');
  let who = '', url1 = '', api = '', own = false, url2 = '', leak = true;
  await act(L, 'Sign in as customer A (Alice): a normal customer, NOT an admin.', async () => {
    await L.go(`${SITE}/account.php?action=logout`);
    await L.type('form:has(input[name=action][value=login]) [name=email]', A_EMAIL);
    await L.type('form:has(input[name=action][value=login]) [name=password]', A_PW);
    await L.click('form:has(input[name=action][value=login]) button[type=submit]');
    who = await L.js(`(document.body.innerText.match(/Welcome back,?\\s*[^|\\n]*/i)||[''])[0].trim()`);
    await L.highlight('Welcome back');
    await L.result(`${who || 'Signed in'} → customer A is logged in, with role "customer".`, !!who);
  });
  await act(L, 'RBAC-01: Alice types the admin address /admin/orders.php.\nExpected: DENIED, no orders shown.', async () => {
    await L.go(`${SITE}/admin/orders.php`); url1 = await L.js('location.pathname');
    await L.result(`DENIED. The server did not show the orders; it sent her to the admin sign-in page (${url1}).\nadmin/includes/auth.php checks role === 'admin' on every admin page, and logs access_denied.`, url1 === '/admin/login.php');
  });
  await act(L, 'RBAC-01: Alice calls an admin API directly: /api/admin-products.php?action=list.\nExpected: DENIED.', async () => {
    await L.go(`${SITE}/api/admin-products.php?action=list`); api = (await L.js('document.body.innerText')).trim();
    await L.result(`DENIED: the API answered ${api} (HTTP 403). It checks the role too.`, /Unauthorized/.test(api));
  });
  await act(L, 'Control: Alice opens HER OWN order (order_id=1).\nExpected: allowed, her order LME-AAA111 is shown.', async () => {
    await L.go(`${SITE}/instapay-upload.php?order_id=1`); own = await L.js(`document.body.innerText.includes('AAA111')`);
    await L.highlight('AAA111');
    await L.result(own ? 'Allowed: her own order LME-AAA111 is shown. The page works for the owner.' : 'Own order not shown', own);
  });
  await act(L, "RBAC-02 (IDOR): Alice changes the number in the URL to order_id=2, which is customer B's order.\nExpected: DENIED, none of B's data.", async () => {
    await L.go(`${SITE}/instapay-upload.php?order_id=2`);
    url2 = await L.js('location.pathname'); leak = await L.js(`document.body.innerText.includes('BBB222')`);
    await L.result(`DENIED: sent away to ${url2}; B's order (LME-BBB222) shown: ${leak ? 'YES' : 'no'}.\ncan_view_order() checks that the order belongs to the signed-in customer.`, !leak);
  });
  let d = 0;
  await R.banner('Step 4 · Access control (RBAC-01/02)', 'What the admin sees');
  await act(R, 'The admin opens Security Events. Expected: access_denied rows for customer A.', async () => {
    await R.go(`${SITE}/admin/security.php`); await R.highlight('access_denied'); d = events('access_denied');
    await R.result(`${d} × access_denied logged for customer A's attempts (C1).`, d >= 1);
  });
  check(4, 'RBAC-01/02', url1 === '/admin/login.php' && /Unauthorized/.test(api) && own && !leak, `adminRedirect=${url1} api403=${/Unauthorized/.test(api)} own=${own} leakB=${leak} denied=${d}`);
}

async function step5(L, R) {
  header(5, 'CSRF-01/02: forged requests without the CSRF token');
  await L.banner('Step 5 · CSRF (CSRF-01/02)', 'Customer A is signed in · defence: secret CSRF token in every form');
  await L.go(`${SITE}/shop.php`);
  const before = events('csrf_rejected');
  const send = tok => L.js(`fetch('/api/cart.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams(Object.assign({action:'add',product_id:'3',quantity:'1'},${tok ? "{csrf:document.getElementById('lume-csrf').value}" : '{}'}))}).then(async r=>r.status+' '+await r.text())`);
  let noTok = '', withTok = '', cartEmpty = false;
  await act(L, 'CSRF-01: send an add-to-cart request with Alice\'s cookie but WITHOUT the CSRF token.\nExpected: refused.', async () => {
    await L.panel('Request sent', 'POST /api/cart.php\nCookie: (Alice\'s session)\nBody: action=add&product_id=3&quantity=1\n      ← no csrf token');
    await beat(2000); noTok = await send(false);
    await L.panel('Request sent → server answer', `POST /api/cart.php   (no csrf token)\n\nHTTP ${noTok}`);
    await L.result('Refused (HTTP 400 "Invalid CSRF token"). csrf_verify() logs csrf_rejected.', noTok.startsWith('400'));
  });
  await act(L, "CSRF-02: Alice visits an attacker's website (localhost:9000) offering a free gift.", async () => {
    await L.go(`${ATTACKER}/gift.html`);
    await L.result("This page belongs to the attacker. Its button submits a hidden form to the shop's cart, using Alice's cookie but with no token.");
  });
  await act(L, "Alice clicks \"Claim your free gift\".\nExpected: the shop refuses the forged request; nothing is added to her cart.", async () => {
    await L.click('#claim');
    cartEmpty = await L.js(`/empty/i.test(document.body.innerText)`);
    await L.result(`The shop refused it and sent her to the cart page: ${cartEmpty ? '"Your bag is empty"' : 'cart NOT empty'}.\nThe attacker's site cannot read or send the secret token, so the forgery fails.`, cartEmpty);
  });
  await act(L, 'Control: the real shop page sends the same request WITH its token.\nExpected: accepted.', async () => {
    await L.go(`${SITE}/shop.php`); withTok = await send(true);
    await L.panel('Request sent → server answer', `POST /api/cart.php   (with the page's csrf token)\n\nHTTP ${withTok}`);
    await L.result('Accepted (HTTP 200): the same request works when it carries the token. Only forged requests are blocked.', withTok.startsWith('200'));
  });
  let after = 0;
  await R.banner('Step 5 · CSRF (CSRF-01/02)', 'What the admin sees');
  await act(R, 'The admin opens Security Events. Expected: new csrf_rejected rows.', async () => {
    await R.go(`${SITE}/admin/security.php`); await R.highlight('csrf_rejected'); after = events('csrf_rejected');
    await R.result(`csrf_rejected: ${before} → ${after} (the API request and the attacker's page).`, after >= before + 2);
  });
  check(5, 'CSRF-01/02', noTok.startsWith('400') && cartEmpty && withTok.startsWith('200') && after >= before + 2, `noToken=${noTok.slice(0, 3)} giftCartEmpty=${cartEmpty} withToken=${withTok.slice(0, 3)} rejected ${before}->${after}`);
}

async function step6(L, R) {
  header(6, 'UPL-01: upload shell.php.jpg as the admin');
  await R.banner('Step 6 · Malicious upload (UPL-01)', 'A PHP web shell disguised as an image · defence: real file-type check + assets/.htaccess');
  await R.go(`${SITE}/admin/media.php`);
  let toast = '', direct = '', rej = 0, code = '';
  await act(R, 'Upload "shell.php.jpg" through the Media page: PHP code (<?php echo 1; ?>) with a .jpg name.\nExpected: rejected, because the content is not really an image.', async () => {
    // Keep the page's own error message so it can be shown after the toast fades.
    await R.js(`(()=>{const o=window.adminToast;window.__toast='';window.adminToast=(m,k)=>{window.__toast=String(m);return o&&o(m,k);};})()`);
    await R.setFile('#media-upload-input', SHELL);
    for (let t = 0; t < 80 && !toast; t++) { await sleep(150); toast = await R.js('window.__toast'); }
    rej = events('upload_rejected');
    await R.result(`Rejected by the Media page: "${toast || '(no message)'}"\nThe server read the real content with finfo (text/x-php), not the .jpg name, and logged it: ${rej} × upload_rejected.`, /Unsupported file type/.test(toast) && rej === 1);
  });
  let rej1 = rej;
  await act(R, 'Same file through the direct upload request that UPL-01 tested in Burp (files[] with Content-Type image/jpeg).\nExpected: rejected and logged.', async () => {
    direct = await R.js(`(()=>{const fd=new FormData();fd.append('action','upload');
      fd.append('files[]',new File(['<?php echo 1; ?>\\n'],'shell.php.jpg',{type:'image/jpeg'}));
      return fetch('/api/media.php',{method:'POST',headers:{'X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content,'X-Requested-With':'XMLHttpRequest'},body:fd}).then(async r=>r.status+' '+await r.text());})()`);
    rej = events('upload_rejected');
    await R.panel('Request sent → server answer', `POST /api/media.php\nfiles[] = shell.php.jpg   (Content-Type: image/jpeg, content: <?php echo 1; ?>)\n\nHTTP ${direct}`);
    await R.result(`Rejected (HTTP 400): upload_check_image() found PHP, not an image, and logged it (${rej} × upload_rejected in total).`, direct.startsWith('400') && rej === rej1 + 1);
  });
  await L.banner('Step 6 · Malicious upload (UPL-01)', 'Second layer: could a script in the uploads folder ever run?');
  await act(L, 'Second layer: even IF a .php file got into the uploads folder, could it run?\nThe attacker opens /assets/x.php. Expected: refused.', async () => {
    await L.go(`${SITE}/assets/x.php`); code = await L.js('document.title');
    await L.result(`"${code}" (HTTP 403). assets/.htaccess refuses to run any .php under assets/, so an uploaded script could never execute.`, /403|denied/i.test(code));
  });
  await act(R, 'The admin opens Security Events. Expected: two upload_rejected rows, one per attempt.', async () => {
    await R.go(`${SITE}/admin/security.php`); await R.highlight('upload_rejected');
    await R.result(`${events('upload_rejected')} × upload_rejected logged (H3 + C1): both upload paths are blocked and recorded.`, events('upload_rejected') === 2);
  });
  check(6, 'UPL-01', /Unsupported file type/.test(toast) && direct.startsWith('400') && rej1 === 1 && rej === 2 && /403|denied/i.test(code), `mediaPage="${toast.slice(0, 50)}" direct=${direct.slice(0, 3)} rejected=${rej} assetsPhp="${code}"`);
}

async function step7(L, R) {
  header(7, 'C3: errors show a generic page, details only in the log');
  await L.banner('Step 7 · Secure error handling (C3)', 'Errors must not leak versions, paths or SQL');
  let b404 = true, b403 = true, ref = '', sqlOnPage = true, detail = '';
  await act(L, 'Open a page that does not exist: /does-not-exist.php.\nExpected: our own 404 page, no server or PHP version.', async () => {
    await L.go(`${SITE}/does-not-exist.php`); b404 = await L.js(`/Apache\\/|PHP\\//.test(document.documentElement.outerHTML)`);
    await L.result(`Branded 404 page. Server/PHP version in the page: ${b404 ? 'YES' : 'none'}.`, !b404);
  });
  await act(L, 'Try to open a protected folder: /includes/ (application code).\nExpected: our own 403 page, nothing listed.', async () => {
    await L.go(`${SITE}/includes/`); b403 = await L.js(`/Apache\\/|PHP\\//.test(document.documentElement.outerHTML)`);
    await L.result(`Branded 403 page; the folder is blocked by .htaccess. Server/PHP version shown: ${b403 ? 'YES' : 'none'}.`, !b403);
  });
  await R.banner('Step 7 · Secure error handling (C3)', 'Force a real database error');
  await act(R, 'The admin adds a shipping zone named "Cairo", which already exists, so the database raises an error.\nExpected: a short message with a reference number, no SQL.', async () => {
    await R.go(`${SITE}/admin/shipping.php`);
    await R.type('#zone-form [name=name]', 'Cairo'); await R.type('#zone-form [name=cost]', '10');
    await R.click('#zone-form button[type=submit]');
    const msg = await R.js(`(document.body.innerText.match(/[^\\n]*Reference: [a-f0-9]{8}[^\\n]*/)||[''])[0]`);
    ref = (msg.match(/Reference: ([a-f0-9]{8})/) || [])[1] || '';
    sqlOnPage = await R.js(`/SQLSTATE/.test(document.body.innerText)`);
    await R.highlight('Reference:');
    await R.result(`On screen: "${msg}"\nNo SQL, no table names. Before C3 this showed the raw SQLSTATE error.`, !!ref && !sqlOnPage);
  });
  await act(R, `Where did the details go? Look up reference ${ref || '?'} in the server error log.`, async () => {
    const logLine = ref ? (readFileSync(ERRLOG, 'utf8').split(/\r?\n/).reverse().find(l => l.includes(`ref ${ref}`)) || '') : '';
    detail = (logLine.match(/\[ref [a-f0-9]{8}\].*/) || [''])[0].replace(/\\\\/g, '\\').replace(/, referer:.*/, '');
    await R.panel(`C:\\Apache24\\logs\\lumeegy-error.log  (server only)`, detail || '(not found)');
    await R.result('The full database error is only in the server log, matched by the reference number. Users never see it.', /SQLSTATE/.test(detail));
    console.log(`  log: ${detail}`);
  });
  check(7, 'C3', !b404 && !b403 && !!ref && !sqlOnPage && /SQLSTATE/.test(detail), `ref=${ref} sqlOnPage=${sqlOnPage} logged=${/SQLSTATE/.test(detail)}`);
}

async function step8(L, R) {
  header(8, 'Tool results: ZAP baseline and Semgrep before → after');
  await L.banner('Step 8 · Tool results (Part 3)', 'OWASP ZAP baseline scan of the local site');
  await act(L, 'Open the OWASP ZAP baseline summary.', async () => {
    await L.go(fileUrl(ZAP));
    await L.result('ZAP baseline: 0 High · 7 Medium · 6 Low · 16 Informational alert types, each with our verdict. (No ZAP rescan was run after the fixes.)');
  });
  await R.banner('Step 8 · Tool results (Part 3)', 'Semgrep before → after');
  await act(R, 'Open the report at "Final results": Semgrep before → after and the 16/16 test results.', async () => {
    await R.go(fileUrl(REPORT) + '#page=17');
  });
  check(8, 'Tool results', existsSync(ZAP) && existsSync(REPORT), 'ZAP summary and report opened');
  await L.clearCards();
  await L.card('NEXT', 'End of the demo. Click Continue to reset the test database and close both windows.');
  await waitClick(L, 'Finish the demo');
}

// ── Main ──
const urlOk = await fetch(SITE + '/').then(r => r.ok).catch(() => false);
if (!urlOk) { console.error(`${SITE} is not answering. Start Apache2.4 and MySQL80 first.`); process.exit(1); }
if (START === 1) {
  console.log('Resetting the database from the seed…');
  reseed(); sql("UPDATE orders SET payment_method='instapay', status='pending_payment' WHERE id IN (1,2)");
  console.log(`  security_events = ${sql('SELECT COUNT(*) FROM security_events')}`);
}
if (SHOTS) mkdirSync(SHOTS, { recursive: true });
const poc = spawn('php', ['-S', 'localhost:9000', '-t', TMP], { stdio: 'ignore' });
const steps = [step1, step2, step3, step4, step5, step6, step7, step8];
let L, R;
try {
  L = await Win.open('left', 'LEFT · attacker / customer', 9351, 0, BURP ? ['--proxy-server=127.0.0.1:8082', '--proxy-bypass-list=<-loopback>'] : []);
  R = await Win.open('right', 'RIGHT · admin', 9352, 960);
  await R.go(`${SITE}/admin/login.php`);
  await R.type('[name=email]', ADMIN_EMAIL); await R.type('[name=password]', ADMIN_PW); await R.click('button[type=submit]');
  const p = await R.js('location.pathname');
  if (!p.startsWith('/admin/') || p === '/admin/login.php') throw new Error('admin sign-in failed');
  await R.banner('Admin window', 'Signed in as the test admin');
  await L.banner('Attacker / customer window', BURP ? 'Traffic goes through Burp (127.0.0.1:8082)' : 'Not signed in (guest)');
  await L.go(`${SITE}/`);
  console.log('Windows ready: LEFT = attacker/customer, RIGHT = admin.');
  for (let i = START - 1; i < steps.length; i++) {
    try { await steps[i](L, R); }
    catch (e) { check(i + 1, `step ${i + 1}`, false, e.message); }
  }
} catch (e) {
  check(0, 'setup', false, e.message);
} finally {
  const passed = results.filter(r => r.pass).length;
  console.log(`\n\x1b[1m${passed} / ${results.length} steps passed\x1b[0m`);
  console.log('Re-seeding the database…'); reseed(); console.log(`  security_events = ${sql('SELECT COUNT(*) FROM security_events')}`);
  if (!KEEP) { await L?.close(); await R?.close(); }
  poc.kill(); rl.close(); process.exit(passed === results.length ? 0 : 1);
}
