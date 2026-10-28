#!/usr/bin/env node
/**
 * "Fits the screen" gate for the Deal Distribution Rules page and the Attorney Portal overview page
 * (.ai/specs/signed-document-distribution.md "The rules screen", .ai/specs/attorney-portal.md 16).
 *
 * A real Chromium opens each page as a real user at 1366x768 and 1920x1080 (sidebar open) and checks:
 *   - the page's one scroller (#appScroll) does not scroll (vertically or sideways) and the pager is on screen;
 *   - the list frame holds a header row that stays pinned while the frame scrolls (when it has to scroll);
 *   - rules page: "Add a rule" opens the dialog, Cancel closes it (nothing is saved).
 *
 *   node scripts/deal-settings-fit-check.mjs --user-id=22 [--app-root=/corex-qa1] [--base=https://qatesting1.corexos.co.za] [--shots=DIR]
 *
 * Read-only: it never submits a form. Exit code 1 when anything fails.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=').slice(1).join('=') || d;
const APP_ROOT = arg('app-root', '/corex-qa1');
const USER_ID = arg('user-id', '22');
const BASE = arg('base', 'https://qatesting1.corexos.co.za');
const SHOTS = arg('shots', '');
const PAGES = [
  { name: 'rules', url: '/admin/settings/deal-distribution-rules', ready: '[data-dr-page]', frame: '[data-dr-frame]', head: '[data-dr-grid] thead th:nth-child(2)', pager: '[data-dr-pager]' },
  { name: 'overview', url: '/deals-v2/attorney-portal', ready: '[data-po-page]', frame: '[data-po-frame]', head: '[data-po-grid] thead th:nth-child(2)', pager: '[data-po-pager]' },
];

const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), `--app-root=${APP_ROOT}`, `--user-id=${USER_ID}`], { encoding: 'utf8' });
const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu'] });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
let bad = 0;
const say = (ok, msg) => { if (!ok) bad++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${msg}`); };

for (const p of PAGES) {
  for (const [w, h] of [[1366, 768], [1920, 1080]]) {
    const page = await browser.newPage();
    await page.setViewport({ width: w, height: h, deviceScaleFactor: 1 });
    await page.setCookie({ name: cookie.name, value: cookie.value, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(BASE + p.url, { waitUntil: 'networkidle2', timeout: 60000 });
    await page.waitForSelector(p.ready, { timeout: 15000 });
    await wait(400);
    const tag = `${p.name} ${w}x${h}`;

    const m = await page.evaluate((p) => {
      const s = document.getElementById('appScroll');
      const f = document.querySelector(p.frame);
      const pg = document.querySelector(p.pager);
      return {
        pageScrollH: s.scrollHeight, pageClientH: s.clientHeight, pageScrollW: s.scrollWidth, pageClientW: s.clientWidth,
        frameClientH: f.clientHeight, frameScrollH: f.scrollHeight, rows: document.querySelectorAll(p.frame + ' tbody tr').length,
        pagerBottom: pg ? pg.getBoundingClientRect().bottom : 0, vh: window.innerHeight,
      };
    }, p);
    say(m.pageScrollH <= m.pageClientH + 1, `${tag}: page does not scroll vertically (${m.pageScrollH} <= ${m.pageClientH})`);
    say(m.pageScrollW <= m.pageClientW + 1, `${tag}: page does not scroll sideways (${m.pageScrollW} <= ${m.pageClientW})`);
    say(m.pagerBottom > 0 && m.pagerBottom <= m.vh, `${tag}: pager is on screen (bottom ${Math.round(m.pagerBottom)} <= ${m.vh})`);
    console.log(`      ${m.rows} rows; list frame ${m.frameClientH}px high, content ${m.frameScrollH}px (${m.frameScrollH > m.frameClientH + 1 ? 'scrolls inside its frame' : 'fits entirely'})`);

    if (m.rows > 0 && m.frameScrollH > m.frameClientH + 1) {
      const pin = await page.evaluate(async (p) => {
        const f = document.querySelector(p.frame);
        const th = document.querySelector(p.head);
        const t0 = th.getBoundingClientRect().top;
        f.scrollTop = 250; await new Promise((r) => setTimeout(r, 100));
        const t1 = th.getBoundingClientRect().top;
        f.scrollTop = 0;
        return { t0, t1 };
      }, p);
      say(Math.abs(pin.t1 - pin.t0) < 1.5, `${tag}: header row stays pinned while the list scrolls`);
    }

    if (p.name === 'rules') {
      await page.click('[data-dr-add]');
      await wait(250);
      const open = await page.evaluate(() => getComputedStyle(document.querySelector('[data-dr-form-modal]')).display !== 'none');
      say(open, `${tag}: "Add a rule" opens the dialog`);
      if (SHOTS) await page.screenshot({ path: `${SHOTS}/${p.name}-dialog-${w}x${h}.png` });
      await page.evaluate(() => [...document.querySelectorAll('[data-dr-form-modal] button')].find((b) => b.textContent.trim() === 'Cancel').click());
      await wait(200);
      const closed = await page.evaluate(() => getComputedStyle(document.querySelector('[data-dr-form-modal]')).display === 'none');
      say(closed, `${tag}: Cancel closes it (nothing saved)`);
    }
    if (SHOTS) await page.screenshot({ path: `${SHOTS}/${p.name}-${w}x${h}.png` });
    say(errors.length === 0, `${tag}: no script errors${errors.length ? ' - ' + errors.join('; ') : ''}`);
    await page.close();
  }
}
await browser.close();
process.exit(bad ? 1 : 0);
