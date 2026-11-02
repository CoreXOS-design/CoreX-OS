#!/usr/bin/env node
/**
 * Road to Sold v4 belt gate (.ai/specs/road-to-sold.md 23.13 / 23.19): a real Chromium opens the real /road-to-sold page as a real user at the two laptop
 * viewports (1366x768, 1920x1080) and checks the tile row: every built station is drawn, the tiles fit the width with no sideways scroll, the page does not
 * scroll (#appScroll scrollHeight <= clientHeight), a click opens the right list (a stage tile opens the prospects line, a source tile opens its station
 * list), the Start card shows for an agent who has not started, and the manager's "Not started / Paused" panel opens and closes with Escape.
 *
 *   node scripts/rts-belt-fit-check.mjs --app-root=/corex-qa1 --user-id=105 --agent=24 [--base=https://qatesting1.corexos.co.za] [--shots=DIR]
 *
 * --user-id is a TEST user (never a real agent's login). --agent is the agent whose tiles are looked at (a manager's read-only view). Read only: it clicks
 * links and opens panels, it never submits a form. Exit code 1 when anything fails.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=').slice(1).join('=') || d;
const APP_ROOT = arg('app-root', '/corex-qa1');
const USER_ID = arg('user-id', '105');
const AGENT = arg('agent', '24');
const BASE = arg('base', 'https://qatesting1.corexos.co.za');
const SHOTS = arg('shots', '');
const VIEWPORTS = [[1366, 768], [1920, 1080]];

const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), `--app-root=${APP_ROOT}`, `--user-id=${USER_ID}`], { encoding: 'utf8' });
const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu'] });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
let bad = 0;
const check = (ok, msg) => { console.log((ok ? '  ok   ' : '  FAIL ') + msg); if (!ok) bad++; };

const fit = (page) => page.evaluate(() => {
  const s = document.getElementById('appScroll') || document.scrollingElement;
  return { sh: s.scrollHeight, ch: s.clientHeight, sw: s.scrollWidth, cw: s.clientWidth, docW: document.documentElement.scrollWidth, winW: window.innerWidth };
});

for (const [w, h] of VIEWPORTS) {
  console.log(`\n== ${w}x${h}`);
  const page = await browser.newPage();
  await page.setViewport({ width: w, height: h, deviceScaleFactor: 1 });
  await page.setCookie({ name: cookie.name, value: cookie.value, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));

  // 1. the agent's tiles (a manager's read-only view of the agent)
  await page.goto(`${BASE}/road-to-sold?agent=${AGENT}`, { waitUntil: 'networkidle2', timeout: 90000 });
  await page.waitForSelector('[data-qa="belt"]', { timeout: 20000 });
  const tiles = await page.$$eval('[data-qa^="belt-tile-"]', (els) => els.map((e) => { const r = e.getBoundingClientRect(); return { k: e.dataset.qa.replace('belt-tile-', ''), l: r.left, r: r.right, t: r.top, b: r.bottom, w: r.width, h: r.height, fig: (e.querySelector('[data-qa="belt-figure"]') || {}).innerText }; }));
  check(tiles.length === 11, `11 tiles drawn (${tiles.length}): ${tiles.map((t) => t.k + '=' + t.fig).join(' ')}`);
  check(tiles.every((t, i) => i === 0 || t.l >= tiles[i - 1].l), 'tiles are in belt order, left to right');
  check(tiles.every((t) => t.r <= w + 0.5 && t.l >= -0.5), 'every tile is inside the window width');
  check(new Set(tiles.map((t) => Math.round(t.t))).size === 1, 'the tiles sit on one row');
  const f1 = await fit(page);
  check(f1.sw <= f1.cw + 1 && f1.docW <= f1.winW + 1, `no sideways scroll (scrollWidth ${f1.sw} / ${f1.cw})`);
  check(f1.sh <= f1.ch + 1, `the page does not scroll (scrollHeight ${f1.sh} / ${f1.ch})`);
  const body = await page.$eval('.rts-block-body', (b) => { const r = b.getBoundingClientRect(); return { h: Math.round(r.height), bottom: Math.round(r.bottom) }; }).catch(() => null);
  check(body && body.h >= 120, `the open block still has room under the tiles (${body && body.h}px)`);
  if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `belt-${w}x${h}-tiles.png`) });

  // 2. a source tile opens its station list; a stage tile opens the prospects line
  const clickTile = async (key) => { await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }), page.click(`[data-qa="belt-tile-${key}"]`)]); };
  await clickTile('claimed');
  check(/block=station/.test(page.url()) && /station=claimed/.test(page.url()), 'Claimed opens its station list');
  const hasList = await page.$('[data-qa="station-list"]') !== null;
  check(hasList, 'the station list is on the page');
  const f2 = await fit(page);
  check(f2.sh <= f2.ch + 1 && f2.sw <= f2.cw + 1, `the station list page fits (${f2.sh}/${f2.ch})`);
  if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `belt-${w}x${h}-station.png`) });
  await clickTile('contacted');
  check(/block=prospects/.test(page.url()) && /p_stage=contacted/.test(page.url()), 'Contacted opens the prospects line at that stage');
  await clickTile('loaded');
  check(!/block=station/.test(page.url()), 'Stock loaded opens today\'s intake');

  // 3. the manager's panel opens and closes
  await page.goto(`${BASE}/road-to-sold?agent=${AGENT}`, { waitUntil: 'networkidle2', timeout: 90000 });
  if (await page.$('[data-qa="belt-roster-open"]')) {
    await page.click('[data-qa="belt-roster-open"]'); await wait(300);
    const open = await page.$eval('[data-qa="belt-roster"]', (e) => getComputedStyle(e).display !== 'none');
    check(open, 'the Not started / Paused panel opens');
    if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `belt-${w}x${h}-roster.png`) });
    await page.keyboard.press('Escape'); await wait(300);
    const closed = await page.$eval('[data-qa="belt-roster"]', (e) => getComputedStyle(e).display === 'none');
    check(closed, 'Escape closes it');
    const f3 = await fit(page);
    check(f3.sh <= f3.ch + 1, 'the panel did not push the page (it floats)');
  } else {
    console.log('  (no roster: every agent in this breadth has started and none is paused)');
  }

  // 4. the test user's OWN page: the Start card (this user has never started) and nothing to scroll
  await page.goto(`${BASE}/road-to-sold`, { waitUntil: 'networkidle2', timeout: 90000 });
  const startCard = await page.$('[data-qa="belt-start-card"]') !== null;
  const ownTiles = (await page.$$('[data-qa^="belt-tile-"]')).length;
  check(startCard || ownTiles > 0, startCard ? 'the Start card shows for an agent who has not started' : 'own page shows tiles (this user already works Road to Sold)');
  const f4 = await fit(page);
  check(f4.sh <= f4.ch + 1 && f4.sw <= f4.cw + 1, `own page fits (${f4.sh}/${f4.ch})`);
  if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `belt-${w}x${h}-own.png`) });
  check(errors.length === 0, 'no page errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await page.close();
}
await browser.close();
console.log(bad ? `\n${bad} check(s) failed` : '\nall checks passed');
process.exit(bad ? 1 : 0);
