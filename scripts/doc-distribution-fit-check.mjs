#!/usr/bin/env node
/**
 * Deal Document Distribution "fits the screen" gate (Johan's ruling 28 Oct 2026, .ai/specs/signed-document-distribution.md "The settings screen").
 *
 * A real Chromium opens /admin/settings/document-distribution as a real user at 1366x768 and 1920x1080 (sidebar open) and checks:
 *   - the page's one scroller (#appScroll) does not scroll (scrollHeight <= clientHeight, scrollWidth <= clientWidth);
 *   - the matrix frame scrolls only INSIDE itself, with the party header row and the document column still pinned after scrolling it;
 *   - ticking a box shows the change count, and the Review & save dialog lists it (nothing is saved - the page is left unchanged).
 *
 *   node scripts/doc-distribution-fit-check.mjs --app-root=/corex-qa1 --user-id=22 [--base=https://qatesting1.corexos.co.za] [--shots=DIR]
 *
 * Exit code 1 when anything fails. Read-only: it never presses Save.
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
const VIEWPORTS = [[1366, 768], [1920, 1080]];

const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), `--app-root=${APP_ROOT}`, `--user-id=${USER_ID}`], { encoding: 'utf8' });
const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu'] });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
let bad = 0;
const say = (ok, msg) => { if (!ok) bad++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${msg}`); };

for (const [w, h] of VIEWPORTS) {
  const page = await browser.newPage();
  await page.setViewport({ width: w, height: h, deviceScaleFactor: 1 });
  await page.setCookie({ name: cookie.name, value: cookie.value, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(BASE + '/admin/settings/document-distribution', { waitUntil: 'networkidle2', timeout: 60000 });
  await page.waitForSelector('[data-dd-grid]', { timeout: 15000 });
  await wait(400);
  const tag = `${w}x${h}`;

  const m = await page.evaluate(() => {
    const s = document.getElementById('appScroll');
    const f = document.querySelector('[data-dd-frame]');
    return {
      pageScrollH: s.scrollHeight, pageClientH: s.clientHeight, pageScrollW: s.scrollWidth, pageClientW: s.clientWidth,
      frameScrollH: f.scrollHeight, frameClientH: f.clientHeight, frameW: f.clientWidth, frameScrollW: f.scrollWidth,
      rows: document.querySelectorAll('[data-dd-grid] tbody tr[data-type-id]').length,
      saveBottom: document.querySelector('[data-dd-save]').getBoundingClientRect().bottom,
      vh: window.innerHeight,
    };
  });
  say(m.pageScrollH <= m.pageClientH + 1, `${tag}: page does not scroll vertically (${m.pageScrollH} <= ${m.pageClientH})`);
  say(m.pageScrollW <= m.pageClientW + 1, `${tag}: page does not scroll sideways (${m.pageScrollW} <= ${m.pageClientW})`);
  say(m.saveBottom <= m.vh, `${tag}: Save button is on screen (bottom ${Math.round(m.saveBottom)} <= ${m.vh})`);
  console.log(`      ${m.rows} document types; matrix frame ${m.frameW}x${m.frameClientH}, content ${m.frameScrollW}x${m.frameScrollH} (${m.frameScrollH > m.frameClientH + 1 ? 'scrolls inside its frame' : 'fits entirely'})`);

  if (m.frameScrollH > m.frameClientH + 1) {
    const pin = await page.evaluate(async () => {
      const f = document.querySelector('[data-dd-frame]');
      const head = document.querySelector('[data-dd-grid] thead th:nth-child(2)');
      const first = document.querySelector('[data-dd-grid] tbody tr[data-type-id] td:first-child');
      const top0 = head.getBoundingClientRect().top, left0 = first.getBoundingClientRect().left;
      f.scrollTop = 200; f.scrollLeft = 40;
      await new Promise((r) => setTimeout(r, 100));
      const top1 = head.getBoundingClientRect().top;
      const left1 = first.getBoundingClientRect().left;
      f.scrollTop = 0; f.scrollLeft = 0;
      return { top0, top1, left0, left1 };
    });
    say(Math.abs(pin.top1 - pin.top0) < 1.5, `${tag}: party header row stays pinned while the matrix scrolls`);
    say(Math.abs(pin.left1 - pin.left0) < 1.5 || m.frameScrollW <= m.frameW + 1, `${tag}: document column stays pinned`);
  }

  if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `matrix-${tag}.png`) });

  // Tick one empty box: the count shows, the dialog lists it, then Discard - nothing is saved.
  const ticked = await page.evaluate(() => {
    const box = [...document.querySelectorAll('[data-dd-grid] tbody input[type=checkbox]')].find((b) => !b.checked);
    if (!box) return false;
    box.click();
    return box.getAttribute('aria-label');
  });
  await wait(200);
  const sum = await page.evaluate(() => document.querySelector('[data-dd-summary]').innerText.trim());
  say(ticked && /1 change/.test(sum), `${tag}: ticking a box shows "${sum}"`);
  await page.evaluate(() => document.querySelector('[data-dd-save]').click());
  await wait(250);
  const dialog = await page.evaluate(() => { const d = document.querySelector('[data-dd-confirm]'); return d && d.offsetParent !== null ? d.innerText.replace(/\s+/g, ' ').trim() : ''; });
  say(/Save these changes\?/.test(dialog) && /Add/.test(dialog), `${tag}: Review & save lists the change ("${dialog.slice(0, 110)}...")`);
  if (SHOTS && w === 1366) await page.screenshot({ path: path.join(SHOTS, `matrix-${tag}-review.png`) });
  await page.keyboard.press('Escape');
  await page.evaluate(() => document.querySelector('[data-dd-discard]').click());
  await wait(150);
  say(errors.length === 0, `${tag}: no script errors${errors.length ? ' - ' + errors[0] : ''}`);
  await page.close();
}

await browser.close();
process.exit(bad ? 1 : 0);
