// Real-browser proof of the Road to Sold admin-settings rulings (road-to-sold.md section 21), run against a deployed QA1.
//   node scripts/rts-admin-settings-check.mjs --base=https://qatesting1.corexos.co.za --user-id=22 [--agency=1] [--out=/tmp/x]
// Needs a minted session cookie (scripts/mint-session-cookie.php) and puppeteer-core + /usr/bin/chromium. Writes nothing to the app:
// it ticks boxes and moves sliders but never presses Save.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import puppeteer from 'puppeteer-core';

const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || `--${k}=${d}`).split('=').slice(1).join('=');
const BASE = arg('base', 'https://qatesting1.corexos.co.za');
const USER = arg('user-id', '22');
const AGENCY = arg('agency', '');
const OUT = arg('out', '/tmp/rts-admin-check');
const APP = arg('app-root', '/corex-qa1');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];
const ok = (name, pass, detail = '') => { results.push({ name, pass, detail }); console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? '  - ' + detail : ''}`); };

const cookieOut = execFileSync('php8.2', [`${APP}/scripts/mint-session-cookie.php`, `--app-root=${APP}`, `--user-id=${USER}`], { encoding: 'utf8' });
const cookie = { name: cookieOut.match(/COOKIE_NAME=(.+)/)[1].trim(), value: cookieOut.match(/COOKIE_VALUE=(.+)/)[1].trim() };

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu'] });
const page = await browser.newPage();
await page.setViewport({ width: 1440, height: 900 });
const pageErrors = [];
page.on('pageerror', (e) => pageErrors.push(e.message));
page.on('console', (m) => { if (/Alpine Expression Error/.test(m.text())) pageErrors.push(m.text().split('\n')[0]); });
await page.setCookie({ ...cookie, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
await page.goto(BASE + '/corex/my-portal', { waitUntil: 'domcontentloaded' });
if (AGENCY) {
  await page.evaluate(async (a) => { const t = document.querySelector('meta[name=csrf-token]').content; await fetch('/agency/switch/' + a, { method: 'POST', headers: { 'X-CSRF-TOKEN': t }, redirect: 'manual' }); }, AGENCY);
}
const go = async (path) => { await page.goto(BASE + path, { waitUntil: 'networkidle2', timeout: 90000 }); await sleep(1200); };

// ── General: grouped, fits, no "Properties read per window", Views ──
await go('/road-to-sold/settings');
const groups = await page.$$eval('.rts-gen-menu button', (b) => b.map((x) => x.dataset.group));
ok('General has a left menu of groups', groups.length >= 8, groups.join(','));
let allFit = true; const heights = {};
for (const g of groups) {
  await page.evaluate((id) => document.querySelector(`.rts-gen-menu button[data-group="${id}"]`).click(), g);
  await sleep(250);
  const m = await page.evaluate(() => { const s = document.querySelector('#appScroll') || document.scrollingElement; return { sh: s.scrollHeight, ch: s.clientHeight }; });
  heights[g] = `${m.sh}/${m.ch}`;
  if (m.sh > m.ch + 2) allFit = false;
}
ok('every group fits the page without scrolling (1440x900)', allFit, JSON.stringify(heights));
await page.screenshot({ path: `${OUT}/general-calllist.png` });
const text = await page.evaluate(() => document.body.innerText);
ok('"Properties read per window" is gone', !/Properties read per window/i.test(text));
await page.evaluate(() => document.querySelector('.rts-gen-menu button[data-group="ontrack"]').click()); await sleep(200);
ok('back-date box is in working days', await page.evaluate(() => /working days/i.test(document.querySelector('#capture_back_date_working_days')?.parentElement?.innerText || '')));
await page.evaluate(() => document.querySelector('.rts-gen-menu button[data-group="calllist"]').click()); await sleep(200);
ok('View buttons on pockets and standards', !!(await page.$('[data-qa="view-hot-pockets"]')) && !!(await page.$('[data-qa="view-standards"]')));
await go('/road-to-sold/settings?view=pockets&section=calllist');
ok('Hottest pockets view opens a table', !!(await page.$('[data-qa="pockets-table"]')), (await page.$$eval('[data-qa="pockets-table"] tbody tr', (r) => r.length)) + ' rows');
await page.screenshot({ path: `${OUT}/view-pockets.png` });
await go('/road-to-sold/settings?view=standards&section=calllist');
ok('Agency standards view opens a table', !!(await page.$('[data-qa="standards-table"]')));
await page.screenshot({ path: `${OUT}/view-standards.png` });

// ── Areas: tick all / untick all ──
await go('/road-to-sold/settings/areas?view=agents');
const addFor = await page.$$eval('a', (as) => (as.find((a) => /add_for=/.test(a.href) && /Add suburbs/i.test(a.textContent)) || {}).href || null);
if (addFor) {
  await page.goto(addFor, { waitUntil: 'networkidle2' }); await sleep(800);
  const n = await page.$$eval('input[name="add[]"]', (b) => b.length);
  await page.click('[data-tick-all]');
  const ticked = await page.$$eval('input[name="add[]"]:checked', (b) => b.length);
  await page.click('[data-untick-all]');
  const left = await page.$$eval('input[name="add[]"]:checked', (b) => b.length);
  ok('Tick all ticks every suburb in the list, Untick all clears them', n > 0 && ticked === n && left === 0, `${n} suburbs, ${ticked} ticked, ${left} left`);
  await page.screenshot({ path: `${OUT}/areas-tick-all.png` });
} else {
  ok('Areas picker reachable', false, 'no "Add suburbs" link on this agency');
}

// ── Budgets + Targets: the split panel ──
const splitCheck = async (path, label) => {
  await go(path);
  if (!(await page.$('[data-split]'))) { ok(`${label}: split panel present`, false); return; }
  await page.evaluate(() => document.querySelectorAll('[data-split] [data-branch-row] button').forEach((b) => b.click())); await sleep(300);
  const info = await page.evaluate(() => {
    const root = document.querySelector('[data-split]');
    const d = window.Alpine ? window.Alpine.$data(root) : null;
    return { ranges: root.querySelectorAll('input[type=range]').length, rows: root.querySelectorAll('tbody tr').length, ok: d ? d.canSave : null, status: d ? d.status : null };
  });
  ok(`${label}: slider and % box per branch`, info.ranges > 0, `${info.ranges} sliders, ${info.rows} rows, ${info.status}`);
  const amounts = await page.evaluate(() => ({
    first: document.querySelector('[data-split] tbody tr [data-x]')?.textContent ?? [...document.querySelectorAll('[data-split] tbody tr')].find((r) => r.style.display !== 'none')?.lastElementChild?.textContent.trim(),
    total: document.querySelector('[data-split-total]').lastElementChild.textContent.trim(),
    typed: [...document.querySelectorAll('[data-split] input[data-total]')].pop().value,
  }));
  ok(`${label}: live amounts are shown and the branches' total equals the typed total`, !!amounts.first && amounts.first.length > 0 && Number(amounts.total.replace(/[^0-9.]/g, '')) === Number(amounts.typed), `first row "${amounts.first}", branches add up to "${amounts.total}", typed ${amounts.typed}`);
  // break the tally on the first multi-branch row: save must switch off; fix it: back on
  const res = await page.evaluate(() => {
    const root = document.querySelector('[data-split]'); const d = window.Alpine.$data(root);
    if (d.mode !== 'agency' || d.branches.length < 2) { return { skipped: true, single: d.branches.length === 1 }; }
    const before = d.branches[0].pct; d.branches[0].pct = before + 7;
    return { skipped: false, offStatus: d.status, offOk: d.canSave, restore: before };
  });
  if (res.skipped) {
    ok(`${label}: single branch is 100% automatically`, res.single, 'only one branch');
  } else {
    ok(`${label}: Save switches off when the branches do not add up to 100%`, res.offOk === false, res.offStatus);
    await page.evaluate((r) => { window.Alpine.$data(document.querySelector('[data-split]')).branches[0].pct = r; }, res.restore); await sleep(200);
    ok(`${label}: Save is back when they add up again`, await page.evaluate(() => window.Alpine.$data(document.querySelector('[data-split]')).canSave === true));
  }
  await page.screenshot({ path: `${OUT}/${label.toLowerCase().replace(/\W+/g, '-')}.png`, fullPage: false });
};
await splitCheck('/road-to-sold/settings/budgets', 'Budgets');
await splitCheck('/road-to-sold/settings/targets#monthly', 'Targets');
await go('/road-to-sold/settings/targets');
ok('Targets reads "Calls per agent per day"', /Calls per agent per day/.test(await page.evaluate(() => document.body.innerText)));

ok('no script errors on any screen', pageErrors.length === 0, [...new Set(pageErrors)].slice(0, 3).join(' | '));
await browser.close();
const failed = results.filter((r) => !r.pass);
console.log(`\n${results.length - failed.length}/${results.length} checks passed; screenshots in ${OUT}`);
process.exit(failed.length ? 1 : 0);
