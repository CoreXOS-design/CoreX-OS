// REAL-BROWSER REGRESSION SCRIPT (QA1) - a STAFF session in one tab must survive portal links in other tabs of the SAME browser.
// Rule: .ai/specs/rental-portal-access.md "The staff login is never touched by a portal call". Test twin: tests/Feature/RentalPortalAccess/PortalNeverEndsStaffSessionTest.php.
//
// Scenario (one Chromium window = one cookie jar): staff tab; a REAL owner link tab; a REAL tenant link tab; the two QA demo-link tabs;
// 12 switches with reloads; staff tab reload; portal logout in two tabs and re-open the links; finally a staff SAVE with the CSRF token the staff page
// loaded at the very start (before any portal activity) - a photo upload on a draft inspection when INSPECTION is set, otherwise a CSRF-protected POST.
//
// Usage (on the QA1 box, from the repo root):
//   LINKS=/path/links.json DEMO=/path/demo.json STAFF_USER=46 INSPECTION=111 FIRSTTIME_EMAIL=can.assurance+x@gmail.com node scripts/qa1/portal-staff-survives.mjs
//   links.json = {"owner":"<emailed owner portal link>","tenant":"<emailed tenant portal link>"}   demo.json = {"owner":"<demo-signin url>","tenant":"<demo-signin url>"}
// Exits non-zero when the staff user is ever signed out.
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';
const BASE = 'https://qatesting1.corexos.co.za';
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const L = JSON.parse(fs.readFileSync(process.env.LINKS, 'utf8'));
const D = JSON.parse(fs.readFileSync(process.env.DEMO, 'utf8'));
const USER = process.env.STAFF_USER || '46';
const minted = execFileSync('php8.2', ['scripts/mint-session-cookie.php', '--app-root=/corex-qa1', '--user-id=' + USER]).toString();
const cname = minted.match(/COOKIE_NAME=(.*)/)[1].trim(), cval = minted.match(/COOKIE_VALUE=(.*)/)[1].trim();
const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu'] });
const ctx = await browser.createBrowserContext();           // ONE window = one cookie jar for staff and portal tabs
await ctx.setCookie({ name: cname, value: cval, domain: 'qatesting1.corexos.co.za', path: '/', secure: true, httpOnly: true });
const events = []; const t0 = Date.now(); const ts = () => ((Date.now() - t0) / 1000).toFixed(1).padStart(5);
async function tab(label) {
  const p = await ctx.newPage(); await p.setViewport({ width: 1280, height: 900 });
  p.on('response', (r) => { const u = new URL(r.url()); const s = r.status();
    const sc = r.headers()['set-cookie'] || '';
    if (/client-auth|demo-signin|\/logout/.test(u.pathname) || s === 419 || s === 401 || s === 403 || s >= 500) events.push(`${ts()}s [${label}] ${r.request().method()} ${u.pathname} -> ${s}${/qa1.*_session|session=/i.test(sc) ? '  (sets session cookie)' : ''}`); });
  p.on('dialog', async (d) => { await d.dismiss().catch(() => {}); });
  return p;
}
const A = await tab('STAFF');
// "is the agent still signed in?" - a real staff page request from tab A, redirects NOT followed
const staffAlive = () => A.evaluate(async () => { const r = await fetch('/corex/platform-esign/documents', { credentials: 'same-origin', redirect: 'manual' }); return r.type === 'opaqueredirect' ? 'SIGNED OUT (redirect)' : (r.status === 200 ? 'signed in' : 'status ' + r.status); });
const staffPage = async () => { await A.bringToFront(); await A.goto(BASE + '/corex/platform-esign/documents', { waitUntil: 'networkidle2' }); return A.url().includes('/login') ? 'SIGNED OUT (login page)' : 'signed in, page ' + (await A.title()).slice(0, 30); };
const portalState = (p) => p.evaluate(() => { const t = (s) => ((document.querySelector(s) || {}).innerText || '').trim(); const who = t('[data-who-name]'); return who ? who + ' ' + t('[data-who-role]') : (/Sign in/.test(document.body.innerText) ? 'SIGN-IN SCREEN' : 'no header'); }).catch((e) => 'ERR');
const bad = [];
const check = async (label, extra = '') => { const s = await staffAlive(); const ok = s === 'signed in'; if (!ok) bad.push(`${label}: staff ${s}`);
  if (/@/.test(extra)) bad.push(`${label}: the portal header shows an email, not the person: ${extra}`);   // header = the contact the link was written for (14 Oct) console.log(`${ok ? 'ok ' : 'BAD'} ${label.padEnd(46)} staff: ${s} ${extra}`); };
console.log('staff tab first load:', await staffPage()); await check('baseline');
const csrfAtStart = await A.evaluate(() => (document.querySelector('meta[name=csrf-token]') || {}).content);   // the token the staff page was loaded with, before any portal activity
const go = async (p, url) => { await p.bringToFront(); await p.goto(url, { waitUntil: 'networkidle2' }); await wait(1800); };
const O = await tab('owner'), T = await tab('tenant'), MO = await tab('demo-owner'), MT = await tab('demo-tenant');
await go(O, L.owner); await check('after REAL owner link opened', '| owner tab: ' + await portalState(O));
await go(T, L.tenant); await check('after REAL tenant link opened', '| tenant tab: ' + await portalState(T));
await go(MO, D.owner); await check('after DEMO owner link opened', '| ' + await portalState(MO));
await go(MT, D.tenant); await check('after DEMO tenant link opened', '| ' + await portalState(MT));
const tabs = { O, T, MO, MT }; const order = ['O', 'T', 'MO', 'MT'];
for (let i = 1; i <= 12; i++) {
  const k = order[(i - 1) % 4]; const p = tabs[k]; await p.bringToFront(); await wait(500);
  const reload = i % 2 === 0; if (reload) { await p.reload({ waitUntil: 'networkidle2' }); await wait(1500); }
  await check(`switch ${String(i).padStart(2)} -> ${k}${reload ? ' (reloaded)' : ''}`, '| ' + await portalState(p));
}
// staff tab reload mid-way, then portal logout in the tenant tab, then re-open the link
console.log('staff tab reload:', await staffPage()); await check('after staff tab reloaded');
await T.bringToFront();
await T.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /Log out/.test(x.textContent) && x.getBoundingClientRect().width > 0); if (b) b.click(); });
await wait(3000); await check('after PORTAL LOGOUT (tenant tab)', '| tenant tab: ' + await portalState(T));
await go(T, L.tenant); await check('after tenant link RE-OPENED', '| tenant tab: ' + await portalState(T));
await MO.bringToFront();
await MO.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /Log out/.test(x.textContent) && x.getBoundingClientRect().width > 0); if (b) b.click(); });
await wait(3000); await check('after PORTAL LOGOUT (demo owner tab)');
await go(MO, D.owner); await check('after demo owner link RE-OPENED', '| ' + await portalState(MO));
// the REAL first-time path (emailed code + chosen password), outside the auto sign-in: opened WITHOUT the signed link reference, then driven through the
// same endpoints the sign-in screen uses, from a portal tab of this browser. Needs FIRSTTIME_EMAIL = a contact's email whose login has no password yet
// (QA1 mail goes only to Mailpit; the code is in the mail SUBJECT).
if (process.env.FIRSTTIME_EMAIL) {
  const em = process.env.FIRSTTIME_EMAIL; const F = await tab('first-time');
  await F.goto(BASE + '/portal?email=' + encodeURIComponent(em), { waitUntil: 'networkidle2' }); await wait(1200);
  await F.evaluate(() => fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }));
  const api = (path, body, bearer) => F.evaluate(async (path, body, bearer) => {
    const xs = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch('/api/v1/client-auth/' + path, { method: 'POST', credentials: 'same-origin', headers: Object.assign({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xs }, bearer ? { Authorization: 'Bearer ' + bearer } : {}), body: JSON.stringify(body) });
    return { status: r.status, json: await r.json().catch(() => ({})) };
  }, path, body, bearer);
  const sent = await api('otp/send', { email: em, purpose: 'activation' }); await check('first-time: code requested (' + sent.status + ')');
  let code = null; const local = em.split('@')[0];
  for (let i = 0; i < 20 && !code; i++) {
    await wait(1000);
    const list = await (await fetch('http://127.0.0.1:8025/api/v1/messages?limit=15')).json();
    const m = (list.messages || []).find((x) => new Date(x.Created).getTime() > t0 - 5000 && (x.Snippet || '').includes(local));
    code = m && (m.Subject.match(/\b(\d{6})\b/) || [])[1];
  }
  if (!code) { bad.push('first-time: no code mail found in Mailpit'); } else {
    const v = await api('otp/verify', { email: em, code, purpose: 'activation' }); await check('first-time: code verified (' + v.status + ')');
    const sp = await api('password/set', { password: 'Staff-Survives-2026!', password_confirmation: 'Staff-Survives-2026!', device_name: 'regression', tab: true }, v.json.activation_token);
    await check('first-time: password set, tab signed in (' + sp.status + ', token ' + (sp.json.token ? 'yes' : 'NO') + ')');
    if (sp.status !== 200 || !sp.json.token) bad.push('first-time: password/set did not return a tab token: ' + sp.status);
    const lg = await api('login', { email: em, password: 'Staff-Survives-2026!', tab: true }); await check('first-time: password sign-in (' + lg.status + ')');
    if (lg.json.token) { const lo = await api('logout', {}, lg.json.token); await wait(500); await check('first-time: portal logout (' + lo.status + ')'); if (lo.status !== 200) bad.push('first-time logout status ' + lo.status); }
  }
}

// the staff SAVE, with the token the staff page loaded at the start (a portal call must not have invalidated it)
await A.bringToFront();
let save;
if (process.env.INSPECTION) {
  save = await A.evaluate(async (id, tok) => {
    const png = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), (c) => c.charCodeAt(0));
    const fd = new FormData(); fd.append('photos[]', new File([png], 'regression.png', { type: 'image/png' }));
    const r = await fetch('/corex/rental-inspections/' + id + '/photos', { method: 'POST', credentials: 'same-origin', redirect: 'manual', headers: { 'X-CSRF-TOKEN': tok, Accept: 'application/json' }, body: fd });
    return r.status;
  }, process.env.INSPECTION, csrfAtStart);
  console.log('staff photo upload on inspection ' + process.env.INSPECTION + ' with the page\'s ORIGINAL CSRF token ->', save, '(200/201 = saved, 419 = stale CSRF, 302/401 = signed out)');
  if (![200, 201].includes(save)) bad.push('staff photo upload status ' + save);
} else {
  save = await A.evaluate(async (tok) => (await fetch('/corex/platform-esign/agreements', { method: 'POST', credentials: 'same-origin', redirect: 'manual', headers: { 'X-CSRF-TOKEN': tok, Accept: 'application/json', 'Content-Type': 'application/json' }, body: '{}' })).status, csrfAtStart);
  console.log('staff POST with the page\'s ORIGINAL CSRF token ->', save, '(422 = accepted and validated, 419 = stale CSRF, 302/401 = signed out)');
  if (save !== 422) bad.push('staff POST status ' + save);
}
console.log('\nrelevant responses:'); events.forEach((e) => console.log('  ' + e));
console.log(bad.length ? '\nPROBLEMS:\n' + bad.join('\n') : '\nALL CHECKS PASSED');
await browser.close();
process.exit(bad.length ? 1 : 0);
