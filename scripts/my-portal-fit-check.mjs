#!/usr/bin/env node
/**
 * My Portal "no scrolling" gate (Johan's ruling 26 Oct 2026, .ai/specs/my-portal-restyle.md section 9).
 *
 * A real Chromium opens the real /corex/my-portal page as a real user at the two laptop viewports the ruling names
 * (1366x768 and 1536x735, sidebar open) and, for EVERY top tab and EVERY sub-tab of that tab, measures the page's one
 * scroller (#appScroll): scrollHeight must be <= clientHeight (nothing below the fold, no vertical scroll) and
 * scrollWidth <= clientWidth. The header block is inside that scroller, so a pass means header + tabs + content all fit.
 *
 *   node scripts/my-portal-fit-check.mjs --app-root=/corex-qa1 --user-id=24 [--base=https://qatesting1.corexos.co.za] [--shots=DIR]
 *
 * Exit code 1 when any screen overflows. Prints one line per screen; read-only (clicks tabs only, submits nothing).
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=').slice(1).join('=') || d;
const APP_ROOT = arg('app-root', '/corex-qa1');
const USER_ID = arg('user-id', '24');
const BASE = arg('base', 'https://qatesting1.corexos.co.za');
const SHOTS = arg('shots', '');
const PATH_ = arg('path', '/corex/my-portal');
const VIEWPORTS = [[1366, 768], [1536, 735]];

const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), `--app-root=${APP_ROOT}`, `--user-id=${USER_ID}`], { encoding: 'utf8' });
const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu'] });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
let bad = 0, total = 0;

for (const [w, h] of VIEWPORTS) {
  const page = await browser.newPage();
  await page.setViewport({ width: w, height: h, deviceScaleFactor: 1 });
  await page.setCookie({ name: cookie.name, value: cookie.value, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  // Fresh load per viewport; the sidebar stays in whatever state the layout opens it in (open on desktop widths).
  await page.goto(BASE + PATH_ + '#overview', { waitUntil: 'networkidle2', timeout: 60000 });
  await page.waitForSelector('[data-portal-tab]', { timeout: 15000 });

  const tabs = await page.$$eval('[data-portal-tab]', (els) => els.map((e) => e.getAttribute('data-portal-tab')));
  for (const tab of tabs) {
    await page.evaluate((t) => document.querySelector(`[data-portal-tab="${t}"]`).click(), tab);
    await wait(150);
    const subs = await page.evaluate((t) => {
      const pane = document.querySelector(`[data-portal-pane="${t}"]`);
      if (!pane) return [];
      return [...pane.querySelectorAll('[data-portal-sub]')].filter((b) => b.offsetParent !== null).map((b) => b.getAttribute('data-portal-sub'));
    }, tab);
    const list = subs.length ? subs : [''];
    for (const sub of list) {
      if (sub) {
        await page.evaluate((t, s) => document.querySelector(`[data-portal-pane="${t}"] [data-portal-sub="${s}"]`).click(), tab, sub);
        await wait(120);
      }
      const m = await page.evaluate(() => {
        const s = document.getElementById('appScroll');
        return { sh: s.scrollHeight, ch: s.clientHeight, sw: s.scrollWidth, cw: s.clientWidth };
      });
      const over = m.sh - m.ch, overW = m.sw - m.cw;
      const ok = over <= 0 && overW <= 0;
      total++; if (!ok) bad++;
      console.log(`${ok ? '  ok  ' : ' FAIL '} ${w}x${h}  ${tab}${sub ? ' / ' + sub : ''}  height ${m.sh}/${m.ch}${over > 0 ? '  (+' + over + 'px below the fold)' : ''}${overW > 0 ? '  (+' + overW + 'px sideways)' : ''}`);
      if (!ok && SHOTS) await page.screenshot({ path: path.join(SHOTS, `${w}-${tab}-${sub || 'main'}.png`), fullPage: false });
    }
  }
  if (errors.length) console.log('  page errors: ' + [...new Set(errors)].join(' | '));
  await page.close();
}
await browser.close();
console.log(`\n${total - bad}/${total} screens fit without scrolling`);
process.exit(bad ? 1 : 0);
