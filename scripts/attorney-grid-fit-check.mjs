#!/usr/bin/env node
/**
 * Attorney portal "My deals" grid fit gate (Johan's brief 28 Oct 2026, .ai/specs/attorney-portal.md 7.1).
 *
 * A real Chromium opens /attorney/deals with a signed-in attorney session cookie (cx_attorney) at 1366x768 and 1920x1080 and checks:
 *   - the page itself does not scroll (the grid scrolls inside its own frame), the header row stays pinned while it scrolls;
 *   - one line per deal (every row the same single-line height);
 *   - a click on a row opens its documents in place (no navigation) as single lines with a Download link, a second click closes them.
 *
 *   node scripts/attorney-grid-fit-check.mjs --cookie=<cx_attorney value> [--base=https://qatesting1.corexos.co.za] [--shots=DIR]
 *
 * Read-only: it never clicks Download. Exit code 1 when anything fails.
 */
import fs from 'node:fs';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=').slice(1).join('=') || d;
const BASE = arg('base', 'https://qatesting1.corexos.co.za');
const COOKIE = arg('cookie', '');
const SHOTS = arg('shots', '');
if (!COOKIE) { console.error('--cookie=<cx_attorney value> is required'); process.exit(2); }
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu'] });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
let bad = 0;
const say = (ok, msg) => { if (!ok) bad++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${msg}`); };

for (const [w, h] of [[1366, 768], [1920, 1080]]) {
  const page = await browser.newPage();
  await page.setViewport({ width: w, height: h, deviceScaleFactor: 1 });
  await page.setCookie({ name: 'cx_attorney', value: decodeURIComponent(COOKIE), domain: new URL(BASE).hostname, path: '/attorney', httpOnly: true, secure: true });
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(BASE + '/attorney/deals', { waitUntil: 'networkidle2', timeout: 60000 });
  await page.waitForSelector('[data-ap-grid]', { timeout: 15000 });
  await wait(300);
  const tag = `${w}x${h}`;

  const m = await page.evaluate(() => {
    const de = document.documentElement, f = document.querySelector('[data-ap-frame]');
    const rows = [...document.querySelectorAll('[data-ap-grid] tbody tr.deal')];
    const hs = rows.map((r) => Math.round(r.getBoundingClientRect().height));
    return {
      pageH: Math.max(de.scrollHeight, document.body.scrollHeight), vh: window.innerHeight, pageW: de.scrollWidth, vw: window.innerWidth,
      frameClientH: f.clientHeight, frameScrollH: f.scrollHeight, rows: rows.length, minH: Math.min(...hs), maxH: Math.max(...hs),
      footBottom: document.querySelector('.ap-foot').getBoundingClientRect().bottom,
      heads: [...document.querySelectorAll('[data-ap-grid] thead th')].map((t) => t.textContent.trim()).filter(Boolean),
    };
  });
  say(m.pageH <= m.vh + 1, `${tag}: page does not scroll vertically (${m.pageH} <= ${m.vh})`);
  say(m.pageW <= m.vw + 1, `${tag}: page does not scroll sideways (${m.pageW} <= ${m.vw})`);
  say(m.footBottom <= m.vh, `${tag}: pager is on screen (bottom ${Math.round(m.footBottom)} <= ${m.vh})`);
  say(m.maxH - m.minH <= 2, `${tag}: one line per deal - ${m.rows} rows, heights ${m.minH}-${m.maxH}px`);
  console.log(`      columns: ${m.heads.join(' | ')}; frame ${m.frameClientH}px high, content ${m.frameScrollH}px (${m.frameScrollH > m.frameClientH + 1 ? 'scrolls inside its frame' : 'fits entirely'})`);

  if (m.frameScrollH > m.frameClientH + 1) {
    const pin = await page.evaluate(async () => {
      const f = document.querySelector('[data-ap-frame]');
      const th = document.querySelector('[data-ap-grid] thead th:nth-child(3)');
      const t0 = th.getBoundingClientRect().top;
      f.scrollTop = 300; await new Promise((r) => setTimeout(r, 100));
      const t1 = th.getBoundingClientRect().top;
      f.scrollTop = 0;
      return { t0, t1 };
    });
    say(Math.abs(pin.t1 - pin.t0) < 1.5, `${tag}: header row stays pinned while the grid scrolls`);
  }

  // open the first deal that has documents, in place
  const before = page.url();
  const idx = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('[data-ap-grid] tbody tr.deal')];
    const i = rows.findIndex((r) => Number(r.children[8].textContent.trim()) > 0);
    return i < 0 ? 0 : i;
  });
  await page.evaluate((i) => document.querySelectorAll('[data-ap-grid] tbody tr.deal')[i].children[2].click(), idx);
  await wait(200);
  const o = await page.evaluate((i) => {
    const row = document.querySelectorAll('[data-ap-grid] tbody tr.deal')[i];
    const files = row.nextElementSibling;
    const lines = [...files.querySelectorAll('table.ap-files tbody tr')];
    const vis = !files.hasAttribute('hidden') && getComputedStyle(files).display !== 'none';
    return { vis, n: lines.length, hs: lines.map((l) => Math.round(l.getBoundingClientRect().height)), dl: files.querySelectorAll('a[data-ap-download]').length, empty: files.textContent.includes('No documents have been shared'), expanded: row.getAttribute('aria-expanded') };
  }, idx);
  say(page.url() === before && o.vis && o.expanded === 'true', `${tag}: clicking a row opens its documents in place (no page change)`);
  say(o.empty || (o.n > 0 && o.dl === o.n && Math.max(...o.hs) - Math.min(...o.hs) <= 2), `${tag}: ${o.empty ? 'deal has no documents - plain message shown' : `${o.n} document lines, each with a Download link, one line high (${Math.min(...o.hs)}-${Math.max(...o.hs)}px)`}`);
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/attorney-grid-${tag}.png` });
  await page.evaluate((i) => document.querySelectorAll('[data-ap-grid] tbody tr.deal')[i].children[2].click(), idx);
  await wait(150);
  const closed = await page.evaluate((i) => document.querySelectorAll('[data-ap-grid] tbody tr.deal')[i].nextElementSibling.hasAttribute('hidden'), idx);
  say(closed, `${tag}: a second click closes it`);
  say(errors.length === 0, `${tag}: no script errors${errors.length ? ' - ' + errors.join('; ') : ''}`);
  await page.close();
}
await browser.close();
process.exit(bad ? 1 : 0);
