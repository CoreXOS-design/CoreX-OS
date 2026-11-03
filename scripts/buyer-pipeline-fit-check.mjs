#!/usr/bin/env node
/**
 * Buyer Pipeline (Kanban) fit gate (Johan, 29 Oct 2026; .ai/specs/buyer-pipeline.md "Kanban fits the screen").
 *
 * A real Chromium opens the real /corex/command-center/buyers/pipeline?view=kanban page as a real user (agent,
 * branch manager, owner) at 1366x768 and 1920x1080 and checks, with Won / Success collapsed (the default) AND expanded:
 *   - the page does not scroll (#appScroll scrollHeight <= clientHeight) and nothing sticks out sideways;
 *   - the four working columns own the height: each column body is tall enough to show real cards
 *     (>= MIN_BODY px at 768 tall) and, with Won collapsed, the strip sits at the bottom of the content block;
 *   - the columns scroll inside themselves when they hold more cards than fit;
 *   - Won expanded is one line per buyer (row height <= 40px), a table, not cards, and it scrolls inside itself.
 *
 *   node scripts/buyer-pipeline-fit-check.mjs --app-root=/corex-staging --base=https://staging.corexos.co.za \
 *        --users=agent:24,manager:25,owner:22 [--shots=DIR] [--path=/corex/command-center/buyers/pipeline] [--fake-won=37]
 *
 * --fake-won=N: when the data has no Won buyers, injects N rows (same markup as the Blade, long addresses) into the
 * opened Won list in the browser only — a layout proof with a full list; nothing is written anywhere.
 *
 * Exit code 1 when any check fails. Read-only (loads pages, clicks the Won strip, submits nothing).
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=').slice(1).join('=') || d;
const APP_ROOT = arg('app-root', '/corex-staging');
const BASE = arg('base', 'https://staging.corexos.co.za');
const SHOTS = arg('shots', '');
const PATH_ = arg('path', '/corex/command-center/buyers/pipeline');
const USERS = arg('users', 'agent:24,manager:25,owner:22').split(',').map((s) => s.split(':'));
const FAKE_WON = parseInt(arg('fake-won', '0'), 10) || 0;
const SCOPES = { agent: 'own', manager: 'branch', owner: 'agency' };
const VIEWPORTS = [[1366, 768], [1920, 1080]];
const MIN_BODY_768 = 240;
const secure = BASE.startsWith('https');
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu'] });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
let bad = 0, total = 0;
const check = (label, ok, detail) => { total++; if (!ok) bad++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`); };

const measure = () => {
  const sc = document.getElementById('appScroll');
  const cols = [...document.querySelectorAll('[data-tour="buyers-board"] > div')].map((c) => {
    const body = c.querySelector('.corex-brand-scroll');
    const r = c.getBoundingClientRect();
    return { h: Math.round(r.height), bottom: Math.round(r.bottom), body: Math.round(body.clientHeight), content: body.scrollHeight, scrolls: body.scrollHeight > body.clientHeight + 1, cards: body.querySelectorAll('a[draggable], a.block').length };
  });
  const wonHead = [...document.querySelectorAll('span')].find((s) => s.textContent.trim() === 'Won / Success');
  const won = wonHead ? wonHead.closest('.rounded-md') : null;
  const wr = won ? won.getBoundingClientRect() : null;
  const rows = won ? [...won.querySelectorAll('tbody tr')].map((t) => Math.round(t.getBoundingClientRect().height)) : [];
  const scr = sc.getBoundingClientRect();
  return {
    pageScroll: sc.scrollHeight - sc.clientHeight, sideways: sc.scrollWidth - sc.clientWidth, areaBottom: Math.round(scr.bottom - parseFloat(getComputedStyle(sc).paddingBottom)),
    cols, won: wr ? { top: Math.round(wr.top), bottom: Math.round(wr.bottom), h: Math.round(wr.height), cards: won.querySelectorAll('a.block').length, tables: won.querySelectorAll('table').length, rows, maxRow: rows.length ? Math.max(...rows) : 0 } : null,
  };
};

for (const [role, uid] of USERS) {
  const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), `--app-root=${APP_ROOT}`, `--user-id=${uid}`], { encoding: 'utf8' });
  const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
  for (const [w, h] of VIEWPORTS) {
    const tag = `${role} ${w}x${h}`;
    const page = await browser.newPage();
    await page.setViewport({ width: w, height: h, deviceScaleFactor: 1 });
    await page.setCookie({ name: cookie.name, value: cookie.value, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(`${BASE}${PATH_}?view=kanban&scope=${SCOPES[role] || 'own'}`, { waitUntil: 'networkidle2', timeout: 90000 });
    await page.evaluate(() => { try { localStorage.clear(); } catch (_) {} });
    await page.reload({ waitUntil: 'networkidle2', timeout: 90000 });
    await wait(300);
    const shot = (n) => SHOTS && page.screenshot({ path: path.join(SHOTS, `pipeline-${role}-${w}-${n}.png`) });

    const a = await page.evaluate(measure);
    await shot('collapsed');
    console.log(`-- ${tag}: columns ${a.cols.map((c) => `${c.body}px/${c.cards}cards`).join(' | ')}; won ${a.won ? a.won.h + 'px' : 'none'}`);
    check(`${tag}: page does not scroll (Won collapsed)`, a.pageScroll <= 1 && a.sideways <= 1, `scroll ${a.pageScroll}px, sideways ${a.sideways}px`);
    const minBody = h <= 800 ? MIN_BODY_768 : 520;
    check(`${tag}: four columns each ${minBody}px+ tall inside`, a.cols.length === 4 && a.cols.every((c) => c.body >= minBody), a.cols.map((c) => c.body).join('/'));
    check(`${tag}: Won is a collapsed strip at the bottom`, !!a.won && a.won.h <= 64 && a.won.cards === 0 && Math.abs(a.won.bottom - a.areaBottom) <= 2, a.won ? `strip ${a.won.h}px, bottom gap ${a.areaBottom - a.won.bottom}px` : 'no Won block');
    check(`${tag}: columns end right above the strip`, a.won && a.cols.every((c) => c.bottom <= a.won.top), '');
    check(`${tag}: a long column scrolls inside itself`, a.cols.some((c) => c.scrolls) || a.cols.every((c) => c.content <= c.body), a.cols.map((c) => `${c.content}/${c.body}`).join(' '));

    const toggle = await page.$('[data-bp-won-toggle]');
    if (toggle) {
      await toggle.click(); await wait(300);
      if (FAKE_WON) {
        await page.evaluate((n) => {
          const body = document.getElementById('bp-won-body');
          if (body.querySelector('table')) return;
          const rows = Array.from({ length: n }, (_, i) => `<tr><td><a href="#">Won Buyer Number ${i + 1} With A Long Name</a></td><td>Falan Du Bois</td><td>2${i % 9} Oct 2026</td><td>${i + 1} Marine Drive, Uvongo Beach, Margate, KwaZulu-Natal (long address)</td><td><span class="ds-badge ds-badge-success text-[10px] whitespace-nowrap">Won</span></td></tr>`).join('');
          body.innerHTML = '<table class="bp-won-table"><colgroup><col style="width: 24%"><col style="width: 20%"><col style="width: 14%"><col><col style="width: 90px"></colgroup><thead><tr><th>Name</th><th>Agent</th><th>Last activity</th><th>Property</th><th>Status</th></tr></thead><tbody>' + rows + '</tbody></table>';
        }, FAKE_WON);
        await wait(200);
      }
      const b = await page.evaluate(measure);
      await shot('won-open');
      check(`${tag}: Won expanded — page still does not scroll`, b.pageScroll <= 1 && b.sideways <= 1, `scroll ${b.pageScroll}px`);
      check(`${tag}: Won expanded — a list, one line per buyer, no cards`, !!b.won && b.won.cards === 0 && (b.won.rows.length === 0 || (b.won.tables === 1 && b.won.maxRow <= 40)), b.won ? `${b.won.rows.length} rows, tallest ${b.won.maxRow}px` : '');
      check(`${tag}: Won expanded — columns keep ${h <= 800 ? 140 : 300}px+`, b.cols.every((c) => c.body >= (h <= 800 ? 140 : 300)), b.cols.map((c) => c.body).join('/'));
      await page.reload({ waitUntil: 'networkidle2', timeout: 90000 }); await wait(300);
      const c = await page.evaluate(measure);
      check(`${tag}: open state is remembered after reload`, !!c.won && c.won.h > 80, `strip ${c.won ? c.won.h : 'none'}px`);
      await page.click('[data-bp-won-toggle]'); await wait(300);
      await page.reload({ waitUntil: 'networkidle2', timeout: 90000 }); await wait(300);
      const d = await page.evaluate(measure);
      check(`${tag}: collapsed state is remembered after reload`, !!d.won && d.won.h <= 64, `strip ${d.won ? d.won.h : 'none'}px`);
    } else {
      console.log(`SKIP  ${tag}: no Won toggle on this build (baseline)`);
    }
    check(`${tag}: no script errors`, errors.length === 0, errors.slice(0, 2).join(' | '));
    await page.close();
  }
}
await browser.close();
console.log(`\n${total - bad}/${total} checks passed`);
process.exit(bad ? 1 : 0);
