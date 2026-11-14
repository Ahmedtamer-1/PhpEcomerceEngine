// LUMEEGY Part 5: automated live-demo runner.
//
// Opens two visible Edge windows side by side (left: guest/customer, right: admin)
// and plays the 8 steps of demo-script.md in them, pausing before each step so the
// narrator can explain it. Talks to Edge over the DevTools protocol, so it needs no
// npm package. LOCAL TEST COPY ONLY: it refuses any site other than localhost:8090.
//
// Run from the repo root (PowerShell or Git Bash):
//   node docs/security/demo-runner.mjs            pause before each step (Enter)
//   node docs/security/demo-runner.mjs --auto     no pauses (rehearsal)
//   node docs/security/demo-runner.mjs --burp     route the left window through Burp (127.0.0.1:8082)
//   node docs/security/demo-runner.mjs --step 3   start at step 3 (no seed reset)
//   node docs/security/demo-runner.mjs --keep     leave the windows open at the end
//   node docs/security/demo-runner.mjs --shots D  save a screenshot of both windows after each step in folder D
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
const SHOTS = args.includes('--shots') ? resolve(args[args.indexOf('--shots') + 1]) : '';
const SITE = 'http://localhost:8090';
const ROOT = resolve('.');
const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const MYSQL = 'C:/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe';
const ERRLOG = 'C:/Apache24/logs/lumeegy-error.log';
const POC_DIR = join(ROOT, 'docs/security/evidence/burp');
const REPORT = join(ROOT, 'docs/security/Vulnerability-Assessment-Report.pdf');
const ZAP = join(ROOT, 'docs/security/evidence/zap/zap-before-summary.html');
const fileUrl = p => 'file:///' + p.split('\\').join('/');
const sleep = ms => new Promise(r => setTimeout(r, ms));

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
const events = t => sql(`SELECT COUNT(*) FROM security_events WHERE event_type='${t}'`);
const reseed = () => { sql('source database/seed-security-test.sql'); };

// ── Minimal DevTools-protocol client for one Edge window ──
class Win {
  static async open(name, port, x, extra = []) {
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
    const w = new Win(name, proc);
    w.browserWs = ver.webSocketDebuggerUrl;
    await w.connect(tab.webSocketDebuggerUrl);
    await w.send('Page.bringToFront');
    return w;
  }
  constructor(name, proc) { this.name = name; this.proc = proc; this.id = 0; this.pend = new Map(); this.loads = 0; }
  connect(url) {
    this.ws = new WebSocket(url);
    this.ws.onmessage = m => {
      const d = JSON.parse(m.data);
      if (d.id && this.pend.has(d.id)) { this.pend.get(d.id)(d); this.pend.delete(d.id); }
      else if (d.method === 'Page.loadEventFired') this.loads++;
      else if (d.method === 'Page.javascriptDialogOpening') this.send('Page.handleJavaScriptDialog', { accept: true });
    };
    return new Promise(r => this.ws.onopen = async () => { await this.send('Page.enable'); await this.send('Runtime.enable'); r(); });
  }
  send(method, params = {}) {
    return new Promise(r => { const i = ++this.id; this.pend.set(i, r); this.ws.send(JSON.stringify({ id: i, method, params })); });
  }
  async js(expr) {
    const r = await this.send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
    if (r.result?.exceptionDetails) throw new Error(`[${this.name}] ${r.result.exceptionDetails.exception?.description || expr}`);
    return r.result?.result?.value;
  }
  async waitLoad(before, ms = 20000) {
    for (let t = 0; t < ms && this.loads <= before; t += 100) await sleep(100);
    await sleep(400);
  }
  async go(url) {
    if (!url.startsWith(SITE) && !url.startsWith('file:') && !url.startsWith('http://localhost:9000')) throw new Error('refusing non-local URL ' + url);
    const n = this.loads; await this.send('Page.navigate', { url }); await this.waitLoad(n);
  }
  async type(sel, text) {
    await this.js(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});e.scrollIntoView({block:'center'});e.focus();e.value='';})()`);
    for (const ch of text) { await this.send('Input.insertText', { text: ch }); await sleep(AUTO ? 5 : 35); }
  }
  async click(sel, nav = true) {
    const n = this.loads;
    await this.js(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});e.scrollIntoView({block:'center'});e.click();})()`);
    if (nav) await this.waitLoad(n); else await sleep(1200);
  }
  text(sel) { return this.js(`(document.querySelector(${JSON.stringify(sel)})||{}).innerText||''`); }
  // Banner at the top of the page: which step, which control.
  banner(title, sub = '') {
    return this.js(`(()=>{let b=document.getElementById('__demo');if(!b){b=document.createElement('div');b.id='__demo';
      b.style.cssText='position:fixed;left:0;right:0;top:0;z-index:2147483647;background:#1f3a5f;color:#fff;font:600 16px Segoe UI,Arial;padding:8px 14px;box-shadow:0 2px 8px #0006';
      document.documentElement.appendChild(b);}b.innerHTML='<div>'+${JSON.stringify(title)}+'</div><div style="font-weight:400;font-size:13px;opacity:.85">'+${JSON.stringify(sub)}+'</div>';})()`);
  }
  // Result box at the bottom: what the audience should notice.
  note(text, ok = true) {
    return this.js(`(()=>{let b=document.getElementById('__note');if(!b){b=document.createElement('div');b.id='__note';
      b.style.cssText='position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483647;color:#fff;font:600 15px Segoe UI,Arial;padding:10px 14px;border-radius:6px;white-space:pre-wrap;box-shadow:0 2px 10px #0007';
      document.documentElement.appendChild(b);}b.style.background=${ok ? "'#1b5e20'" : "'#9b1c1c'"};b.textContent=${JSON.stringify(text)};})()`);
  }
  async highlight(textToFind) {
    await this.js(`(()=>{const w=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);let n;while(n=w.nextNode()){if(n.nodeValue.includes(${JSON.stringify(textToFind)})){const el=n.parentElement;el.scrollIntoView({block:'center'});el.style.outline='3px solid #f9a825';el.style.background='#fff3c4';el.style.color='#000';return true;}}return false;})()`);
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

// ── Pacing ──
const rl = createInterface({ input: process.stdin });
const lines = []; rl.on('line', l => lines.push(l));
async function pause(msg) {
  console.log(`\n\x1b[1;36m▶ ${msg}\x1b[0m`);
  if (AUTO) { await sleep(800); return; }
  process.stdout.write('  Press Enter to run it… ');
  while (!lines.length) await sleep(100);
  lines.shift();
}
const results = [];
const check = (step, label, pass, detail) => {
  results.push({ step, label, pass, detail });
  console.log(`  ${pass ? '\x1b[32mPASS\x1b[0m' : '\x1b[31mFAIL\x1b[0m'}  ${label}  ${detail}`);
};
const cards = w => w.js(`document.querySelectorAll('.lume-product-card').length`);

// ── Steps ──
async function step1(L) {
  await pause('Step 1 · SQLI-01: SQL injection in the shop search');
  await L.go(`${SITE}/shop.php?q=ring`); await L.banner('Step 1 · SQL injection (SQLI-01)', 'Control first: a normal search for "ring"');
  const c = await cards(L); await L.note(`Normal search "ring": ${c} products`); await sleep(AUTO ? 500 : 2500);
  const payload = "ring' OR '1'='1' -- -";
  await L.go(`${SITE}/shop.php?q=${encodeURIComponent(payload)}`);
  // Server time for this request (a working SLEEP(5) injection would push this past 5000 ms).
  const ms = Math.round(await L.js(`(e=>e.responseStart-e.requestStart)(performance.getEntriesByType('navigation')[0])`));
  const a = await cards(L); const err = await L.js(`/SQLSTATE|syntax error|mysql/i.test(document.body.innerText)`);
  await L.banner('Step 1 · SQL injection (SQLI-01)', `Payload: ${payload}  →  blocked by prepared statements`);
  await L.note(`Payload returned ${a} products, SQL error shown: ${err ? 'YES' : 'no'}, server answered in ${ms} ms.\nThe input is bound as a parameter, never run as SQL.`, a === 0 && !err);
  check(1, 'SQLI-01', c === 2 && a === 0 && !err, `control=${c} payload=${a} sqlError=${err}`);
}

async function step2(L, R) {
  await pause('Step 2 · XSS-02: stored XSS through the contact form, viewed by the admin');
  const P = '<img src=x onerror=alert(1)>';
  await L.go(`${SITE}/contact.php`); await L.banner('Step 2 · Stored XSS (XSS-02)', 'A guest sends the payload as name and message');
  await L.type('#contact-form [name=name]', P); await L.type('#contact-form [name=email]', 'guest@lumeegy.test');
  await L.type('#contact-form [name=subject]', P); await L.type('#contact-form [name=message]', P);
  await L.click('#contact-form button[type=submit]', false);
  await L.note(await L.text('#contact-msg') || 'Message sent');
  await R.go(`${SITE}/admin/messages.php`); await R.banner('Step 2 · Admin opens Messages', 'Payload is shown as text: output escaped with h()');
  await R.highlight(P);
  const raw = await R.js(`document.documentElement.innerHTML.split('<img src=x onerror').length-1`);
  await R.note(`Payload displayed as plain text. Executable <img onerror> tags in the page: ${raw}. No alert ran.`, raw === 0);
  check(2, 'XSS-02', raw === 0, `raw tags=${raw}`);
}

async function step3(L, R) {
  await pause('Step 3 · AUTH-01: brute force → lockout → Security Events → admin Unlock');
  await R.go(`${SITE}/admin/security.php`); await R.banner('Step 3 · Admin: Security Events (C1)', 'Watch this page after the attack');
  await L.go(`${SITE}/account.php`); await L.banner('Step 3 · Brute force (AUTH-01)', `Attacker tries wrong passwords for ${B_EMAIL}`);
  let last = '';
  for (let i = 1; i <= 6; i++) {
    await L.type('form:has(input[name=action][value=login]) [name=email]', B_EMAIL);
    await L.type('form:has(input[name=action][value=login]) [name=password]', `wrong${i}`);
    const t0 = Date.now(); await L.click('form:has(input[name=action][value=login]) button[type=submit]'); const ms = Date.now() - t0;
    last = await L.js(`(document.body.innerText.match(/Too many attempts[^\\n]*|Invalid email or password\\.?/)||[''])[0]`);
    await L.banner('Step 3 · Brute force (AUTH-01)', `Attempt ${i}/6 with "wrong${i}"  ·  ${ms} ms`);
    await L.note(`Attempt ${i}: ${last}  (${ms} ms, delay grows after the 2nd failure)`, true);
    await sleep(AUTO ? 100 : 700);
  }
  const locked = /Too many attempts/.test(last);
  await L.note(`${last}\nAccount locked by C4; remaining minutes are shown.`, locked);
  await R.go(`${SITE}/admin/security.php`); await R.banner('Step 3 · Security Events (C1)', 'login_failed ×5 and login_locked (critical) were logged');
  await R.highlight('login_locked');
  const f = events('login_failed'), k = events('login_locked');
  await R.note(`Logged: ${f} × login_failed, ${k} × login_locked`, f === '5' && k === '1');
  await sleep(AUTO ? 300 : 2500);
  await R.go(`${SITE}/admin/users.php?id=3`); await R.banner('Step 3 · Admin unlocks the account (C4)', 'Sign-in lock box → Unlock account');
  await R.highlight('Unlock account'); await sleep(AUTO ? 200 : 1500);
  await R.click('form:has(input[name=unlock_user_id]) button[type=submit]');
  await R.banner('Step 3 · Admin unlocked the account (C4)', 'login_unlock() cleared the counters');
  await R.highlight('Sign-in lock cleared'); await R.note('Sign-in lock cleared; account_unlocked logged.');
  await L.go(`${SITE}/account.php`);
  await L.type('form:has(input[name=action][value=login]) [name=email]', B_EMAIL);
  await L.type('form:has(input[name=action][value=login]) [name=password]', B_PW);
  await L.click('form:has(input[name=action][value=login]) button[type=submit]');
  const signedIn = await L.js(`/Welcome back|Logout/i.test(document.body.innerText)`);
  await L.banner('Step 3 · The real owner signs in again', 'After the admin unlock'); await L.note(signedIn ? 'Customer B is signed in.' : 'Sign-in failed', signedIn);
  check(3, 'AUTH-01', locked && f === '5' && k === '1' && signedIn, `locked=${locked} failed=${f} lockedEvt=${k} signedInAfterUnlock=${signedIn}`);
  await L.go(`${SITE}/account.php?action=logout`);
}

async function step4(L, R) {
  await pause('Step 4 · RBAC-01/02: a customer opens /admin and another customer\'s order');
  await L.go(`${SITE}/account.php`);
  await L.type('form:has(input[name=action][value=login]) [name=email]', A_EMAIL);
  await L.type('form:has(input[name=action][value=login]) [name=password]', A_PW);
  await L.click('form:has(input[name=action][value=login]) button[type=submit]');
  await L.go(`${SITE}/admin/orders.php`);
  const url1 = await L.js('location.href');
  await L.banner('Step 4 · Customer A opens /admin/orders.php (RBAC-01)', 'Role check in admin/includes/auth.php');
  await L.note(`Redirected to ${url1.replace(SITE, '')}: no admin data.`, url1.includes('/admin/login.php')); await sleep(AUTO ? 300 : 2500);
  await L.go(`${SITE}/api/admin-products.php?action=list`);
  const api = await L.js('document.body.innerText'); await L.banner('Step 4 · Customer A calls an admin API', 'api/admin-products.php');
  await L.note(`Answer: ${api.trim()}`, /Unauthorized/.test(api)); await sleep(AUTO ? 300 : 2500);
  await L.go(`${SITE}/instapay-upload.php?order_id=1`);
  const own = await L.js(`document.body.innerText.includes('AAA111')`);
  await L.banner('Step 4 · Control: A opens her own order (id=1)', 'Works for the owner'); await L.note(own ? 'Own order LME-AAA111 shown.' : 'Own order not shown', own); await sleep(AUTO ? 300 : 2500);
  await L.go(`${SITE}/instapay-upload.php?order_id=2`);
  const url2 = await L.js('location.href'); const leak = await L.js(`document.body.innerText.includes('BBB222')`);
  await L.banner('Step 4 · IDOR: A changes order_id to 2 (customer B) (RBAC-02)', 'Ownership check can_view_order()');
  await L.note(`Redirected to ${url2.replace(SITE, '')}. B's order shown: ${leak ? 'YES' : 'no'}.`, !leak && !url2.includes('order_id=2'));
  const d = events('access_denied');
  await R.go(`${SITE}/admin/security.php`); await R.banner('Step 4 · Security Events', 'access_denied logged (C1)'); await R.highlight('access_denied');
  await R.note(`${d} × access_denied logged`, Number(d) >= 1);
  check(4, 'RBAC-01/02', url1.includes('/admin/login.php') && /Unauthorized/.test(api) && own && !leak, `adminRedirect=${url1.includes('/admin/login.php')} api403=${/Unauthorized/.test(api)} own=${own} leakB=${leak} denied=${d}`);
}

async function step5(L, R, poc) {
  await pause('Step 5 · CSRF-01/02: forged requests without the CSRF token');
  await L.go(`${SITE}/shop.php`); await L.banner('Step 5 · CSRF (CSRF-01)', 'Add-to-cart request sent WITHOUT the token');
  const send = tok => L.js(`fetch('/api/cart.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams(Object.assign({action:'add',product_id:'3',quantity:'1'},${tok ? "{csrf:document.getElementById('lume-csrf').value}" : '{}'}))}).then(async r=>r.status+' '+await r.text())`);
  const before = events('csrf_rejected');
  const noTok = await send(false); await L.note(`No token → ${noTok}`, false); await sleep(AUTO ? 300 : 2500);
  await L.banner('Step 5 · Cross-site attack page (CSRF-02)', 'http://localhost:9000/csrf-poc.html auto-submits a forged add-to-cart');
  await sleep(AUTO ? 200 : 1500);
  await L.go('http://localhost:9000/csrf-poc.html');
  await L.waitLoad(L.loads - 1, 3000);
  const where = await L.js('location.href');
  await L.banner('Step 5 · Forged POST arrived without a token', 'csrf_verify() refused it; the cart was not changed');
  await L.note(`Browser ended on ${where.replace(SITE, '')}: request refused, nothing added.`, true); await sleep(AUTO ? 300 : 2500);
  await L.go(`${SITE}/shop.php`); await L.banner('Step 5 · Control: the real page sends its token', 'Same request with the valid token');
  const withTok = await send(true); await L.note(`With token → ${withTok}`, withTok.startsWith('200'));
  const after = events('csrf_rejected');
  await R.go(`${SITE}/admin/security.php`); await R.banner('Step 5 · Security Events', 'csrf_rejected logged (C1)'); await R.highlight('csrf_rejected');
  await R.note(`csrf_rejected: ${before} → ${after}`, Number(after) > Number(before));
  check(5, 'CSRF-01/02', noTok.startsWith('400') && withTok.startsWith('200') && Number(after) >= Number(before) + 2, `noToken="${noTok.slice(0, 60)}" withToken=${withTok.slice(0, 3)} rejected ${before}->${after}`);
}

async function step6(L, R) {
  await pause('Step 6 · UPL-01: upload shell.php.jpg as the admin');
  await R.go(`${SITE}/admin/media.php`); await R.banner('Step 6 · File upload (UPL-01)', 'Uploading shell.php.jpg: PHP code with a .jpg name and image/jpeg type');
  await sleep(AUTO ? 200 : 1500);
  const res = await R.js(`(()=>{const fd=new FormData();fd.append('action','upload');
    fd.append('files[]',new File(['<?php echo 1; ?>\\n'],'shell.php.jpg',{type:'image/jpeg'}));
    return fetch('/api/media.php',{method:'POST',headers:{'X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content,'X-Requested-With':'XMLHttpRequest'},body:fd}).then(async r=>r.status+' '+await r.text());})()`);
  const rej = events('upload_rejected');
  await R.note(`Server answer: ${res}\nfinfo saw PHP, not an image → rejected; ${rej} × upload_rejected logged.`, res.startsWith('400'));
  await sleep(AUTO ? 300 : 2500);
  await L.go(`${SITE}/assets/x.php`); const code = await L.js('document.title');
  await L.banner('Step 6 · Even a stored script could not run', 'assets/.htaccess denies .php under assets/'); await L.note(`GET /assets/x.php → ${code}`, /403|denied/i.test(code));
  await R.go(`${SITE}/admin/security.php`); await R.banner('Step 6 · Security Events', 'upload_rejected logged (H3 + C1)'); await R.highlight('upload_rejected');
  check(6, 'UPL-01', res.startsWith('400') && rej === '1', `answer="${res.slice(0, 70)}" rejected=${rej}`);
}

async function step7(L, R) {
  await pause('Step 7 · C3: errors show a generic page, details only in the log');
  await L.go(`${SITE}/does-not-exist.php`); await L.banner('Step 7 · 404 page (C3)', 'Branded page, no server banner');
  const b404 = await L.js(`/Apache\\/|PHP\\//.test(document.documentElement.outerHTML)`); await L.note(`Version banner in page: ${b404 ? 'YES' : 'no'}`, !b404);
  await sleep(AUTO ? 300 : 2000);
  await L.go(`${SITE}/includes/`); await L.banner('Step 7 · 403 page for a blocked folder (C3)', '/includes/ is denied by .htaccess');
  const b403 = await L.js(`/Apache\\/|PHP\\//.test(document.documentElement.outerHTML)`); await L.note(`Version banner in page: ${b403 ? 'YES' : 'no'}`, !b403);
  await R.go(`${SITE}/admin/shipping.php`); await R.banner('Step 7 · Force a database error', 'Add a shipping zone named "Cairo" (already exists)');
  await R.type('#zone-form [name=name]', 'Cairo'); await R.type('#zone-form [name=cost]', '10');
  await R.click('#zone-form button[type=submit]');
  const msg = await R.js(`(document.body.innerText.match(/[^\\n]*Reference: [a-f0-9]{8}[^\\n]*/)||[''])[0]`);
  const ref = (msg.match(/Reference: ([a-f0-9]{8})/) || [])[1];
  const sqlOnPage = await R.js(`/SQLSTATE/.test(document.body.innerText)`);
  const logLine = ref ? (readFileSync(ERRLOG, 'utf8').split(/\r?\n/).reverse().find(l => l.includes(`ref ${ref}`)) || '') : '';
  const detail = (logLine.match(/\[ref [a-f0-9]{8}\].*/) || [''])[0];
  await R.highlight('Reference:');
  await R.note(`On screen: ${msg}\nServer log only: ${detail.slice(0, 170)}`, !!ref && !sqlOnPage && !!detail);
  console.log(`  log: ${detail}`);
  check(7, 'C3', !b404 && !b403 && !!ref && !sqlOnPage && /SQLSTATE/.test(detail), `ref=${ref} sqlOnPage=${sqlOnPage} logged=${/SQLSTATE/.test(detail)}`);
}

async function step8(L, R) {
  await pause('Step 8 · Tool results: ZAP baseline and Semgrep before → after');
  await L.go(fileUrl(ZAP)); await L.banner('Step 8 · OWASP ZAP baseline', '0 High · 7 Medium · 6 Low · 16 Informational, each with our verdict');
  await R.go(fileUrl(REPORT) + '#page=17');
  check(8, 'Tool results', existsSync(ZAP) && existsSync(REPORT), 'ZAP summary and report opened (report §6: Semgrep 201 → 199, SAST-01/02/03 closed)');
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
const poc = spawn('php', ['-S', 'localhost:9000', '-t', POC_DIR], { stdio: 'ignore' });
const steps = [step1, step2, step3, step4, step5, step6, step7, step8];
let L, R;
try {
  L = await Win.open('left', 9351, 0, BURP ? ['--proxy-server=127.0.0.1:8082', '--proxy-bypass-list=<-loopback>'] : []);
  R = await Win.open('right', 9352, 960);
  await R.go(`${SITE}/admin/login.php`);
  await R.type('[name=email]', ADMIN_EMAIL); await R.type('[name=password]', ADMIN_PW); await R.click('button[type=submit]');
  if (!(await R.js('location.pathname')).startsWith('/admin/') || (await R.js('location.pathname')) === '/admin/login.php') throw new Error('admin sign-in failed');
  await R.banner('Admin window', 'Signed in as the test admin'); await L.go(`${SITE}/`); await L.banner('Guest / customer window', BURP ? 'Traffic goes through Burp (127.0.0.1:8082)' : 'Guest');
  console.log('Windows ready: left = guest/customer, right = admin.');
  for (let i = START - 1; i < steps.length; i++) {
    try { await steps[i](L, R, poc); }
    catch (e) { check(i + 1, `step ${i + 1}`, false, e.message); }
    if (SHOTS) for (const w of [L, R]) {
      const s = await w.send('Page.captureScreenshot', { format: 'png' });
      if (s.result) writeFileSync(join(SHOTS, `step${i + 1}-${w.name}.png`), Buffer.from(s.result.data, 'base64'));
    }
  }
} catch (e) {
  check(0, 'setup', false, e.message);
} finally {
  const passed = results.filter(r => r.pass).length;
  console.log(`\n\x1b[1m${passed} / ${results.length} steps passed\x1b[0m`);
  if (!AUTO && KEEP) await pause('Demo finished. Windows stay open');
  console.log('Re-seeding the database…'); reseed(); console.log(`  security_events = ${sql('SELECT COUNT(*) FROM security_events')}`);
  if (!KEEP) { await L?.close(); await R?.close(); }
  poc.kill(); rl.close(); process.exit(passed === results.length ? 0 : 1);
}
