#!/usr/bin/env node
/**
 * Title Deed Register - signature box check (AT-450 round 2, item 9).
 *
 * The first build's tests only proved the SERVER side of a signature (a correct data-URL is accepted). Nobody ever drew on the
 * box in a real browser, so "the box cannot be drawn on" could not have been caught. This is that missing check: a real
 * headless Chromium opens the page, draws a stroke with the MOUSE (desktop) and with TOUCH (phone), and then proves the
 * thing the server will actually receive - the signature field is populated with a real PNG - and that pixels are on screen.
 *
 * Nothing is ever handed over or signed by default: every outgoing POST on the staff screen is aborted and the form's own
 * submit is cancelled after the field is read. Pass --submit with --sign-url to also complete the public phone page.
 *
 * Usage:
 *   node scripts/verify-title-deed-signature.mjs --base=https://qatesting1.corexos.co.za --app-root=/corex-qa1 --user-id=22 \
 *        --deed=<id of a deed that is waiting to be collected> [--sign-url=<public signing link> [--submit]]
 * Exit code 0 = every check passed, 1 = a check failed (the failing line says which).
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';

const arg = (n, d = null) => (process.argv.find(a => a.startsWith(`--${n}=`)) || '').split('=').slice(1).join('=') || d;
const flag = n => process.argv.includes(`--${n}`);
const base = arg('base');
const appRoot = arg('app-root', '/corex-qa1');
const userId = arg('user-id');
const deed = arg('deed');
const signUrl = arg('sign-url');
if (!base || (!signUrl && !(deed && userId))) {
  console.error('Need --base and either --deed + --user-id (staff screen) or --sign-url (public page).');
  process.exit(2);
}
const puppeteer = createRequire(`${appRoot}/package.json`)('puppeteer');
let failed = 0;
const check = (ok, text) => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${text}`); if (!ok) failed++; };

const painted = page => page.evaluate(() => {
  const c = document.querySelector('canvas');
  if (!c) return -1;
  const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
  let n = 0;
  for (let i = 3; i < d.length; i += 4) if (d[i] > 0) n++;
  return n;
});

async function stroke(page, how) {
  await page.evaluate(() => document.querySelector('canvas').scrollIntoView({ block: 'center' }));
  const b = await page.evaluate(() => { const r = document.querySelector('canvas').getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; });
  const pts = [[20, 40], [b.w * 0.35, b.h * 0.7], [b.w * 0.6, b.h * 0.3], [b.w - 20, b.h * 0.6]];
  if (how === 'mouse') {
    await page.mouse.move(b.x + pts[0][0], b.y + pts[0][1]);
    await page.mouse.down();
    for (const p of pts.slice(1)) await page.mouse.move(b.x + p[0], b.y + p[1], { steps: 10 });
    await page.mouse.up();
  } else {
    await page.touchscreen.touchStart(b.x + pts[0][0], b.y + pts[0][1]);
    for (const p of pts.slice(1)) await page.touchscreen.touchMove(b.x + p[0], b.y + p[1]);
    await page.touchscreen.touchEnd();
  }
}

async function staffScreen(label, viewport, how) {
  const out = execFileSync('php8.2', [`${appRoot}/scripts/mint-session-cookie.php`, `--app-root=${appRoot}`, `--user-id=${userId}`], { encoding: 'utf8' });
  const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
  const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage();
    await page.setViewport(viewport);
    await page.setCookie({ ...cookie, domain: new URL(base).hostname, path: '/', httpOnly: true, secure: base.startsWith('https') });
    await page.setRequestInterception(true);
    const posts = [];
    page.on('request', req => { if (req.method() === 'POST') { posts.push(req.url()); req.abort(); } else req.continue(); });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));

    await page.goto(`${base}/title-deeds/${deed}/handover`, { waitUntil: 'networkidle0' });
    // Cancel the real submit after the screen has done its own work, but read the field first.
    await page.evaluate(() => {
      window.__sig = null;
      document.addEventListener('submit', e => { window.__sig = (document.getElementById('signature_image') || {}).value || ''; e.preventDefault(); });
    });
    await page.evaluate(() => { [...document.querySelectorAll('button')].find(b => b.textContent.trim() === 'Owner, ID check')?.click(); });
    await new Promise(r => setTimeout(r, 300));
    await page.type('#typed_name', 'Browser Check');
    await page.evaluate(() => { const o = document.querySelector('#id_number_seen'); if (o) { o.value = '8001015009087'; o.dispatchEvent(new Event('input')); } });

    const ready = await page.evaluate(() => { const c = document.querySelector('canvas'); const p = c && window.tdSignaturePad && window.tdSignaturePad.of(c); return !!(p && p.ready()); });
    check(ready, `${label}: the signature box started (it measured itself and is ready)`);
    check((await painted(page)) === 0, `${label}: the box starts empty`);

    await stroke(page, how);
    const n = await painted(page);
    check(n > 200, `${label}: drawing with the ${how} leaves ink on the box (${n} painted pixels)`);

    await page.evaluate(() => document.querySelector('#handover-submit').scrollIntoView({ block: 'center' }));
    await page.evaluate(() => document.querySelector('#handover-submit').click());
    await new Promise(r => setTimeout(r, 600));
    const sig = await page.evaluate(() => window.__sig);
    const clientError = await page.evaluate(() => (document.querySelector('[x-text="clientError"]') || {}).textContent || '');
    check(typeof sig === 'string' && sig.startsWith('data:image/png;base64,') && sig.length > 800, `${label}: the signature field the server receives is a real PNG (${(sig || '').length} characters)`);
    check(clientError.trim() === '', `${label}: the screen shows no "nothing drawn" error${clientError.trim() ? ` (it says: ${clientError.trim()})` : ''}`);
    check(errors.length === 0, `${label}: no script errors${errors.length ? ` (${errors[0]})` : ''}`);
    check(posts.length === 0, `${label}: nothing was actually submitted by this check`);
  } finally {
    await browser.close();
  }
}

async function publicPage(label, viewport, how) {
  const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage();
    await page.setViewport(viewport);
    await page.setRequestInterception(true);
    let body = null;
    page.on('request', req => {
      if (req.method() === 'POST' && req.url().includes('/submit')) {
        try { body = JSON.parse(req.postData() || '{}'); } catch (e) { body = {}; }
        if (flag('submit')) req.continue(); else req.abort();
      } else req.continue();
    });
    await page.goto(signUrl, { waitUntil: 'networkidle0' });
    check(!!(await page.$('canvas')), `${label}: the public page shows the signature box`);
    await page.type('#typed_name', 'Browser Check');
    await stroke(page, how);
    check((await painted(page)) > 200, `${label}: drawing with the ${how} leaves ink on the box`);
    await page.click('#confirmed');
    await page.click('#go');
    await new Promise(r => setTimeout(r, 1200));
    check(!!body && typeof body.signature_image === 'string' && body.signature_image.startsWith('data:image/png;base64,') && body.signature_image.length > 800,
      `${label}: what the page sends is a real PNG signature (${body && body.signature_image ? body.signature_image.length : 0} characters)`);
    if (flag('submit')) {
      const done = await page.$('#done');
      check(!!done, `${label}: the page ends on "your signature is recorded"`);
    }
  } finally {
    await browser.close();
  }
}

if (deed && userId) {
  await staffScreen('desktop 1280 / mouse', { width: 1280, height: 900 }, 'mouse');
  await staffScreen('desktop 1280 @2x / mouse', { width: 1280, height: 900, deviceScaleFactor: 2 }, 'mouse');
  await staffScreen('phone 390 / touch', { width: 390, height: 844, deviceScaleFactor: 3, hasTouch: true, isMobile: true }, 'touch');
}
if (signUrl) {
  await publicPage('public page, phone 390 / touch', { width: 390, height: 844, deviceScaleFactor: 3, hasTouch: true, isMobile: true }, 'touch');
}
console.log(failed ? `\n${failed} CHECK(S) FAILED` : '\nAll signature checks passed.');
process.exit(failed ? 1 : 0);
