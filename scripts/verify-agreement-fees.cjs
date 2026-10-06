// Real-browser proof of the Subscription Agreement fee table, as a recipient sees it (spec §11.5).
// Section 3 completes itself from two entries — number of agents and number of branches.
// Usage: node scripts/verify-agreement-fees.cjs <recipient-url> [agents:branches ...]   (default 8:1 8:3 13:1 13:3 25:1 25:3)
// Types into the real form, reads the plan ticks, every line's quantity / amount, the monthly total and the mandate Amount,
// then reloads the page (the autosave / resume path) and reads them again. Exit code is non-zero on any mismatch.
const fs = require('fs');
let puppeteer;
for (const p of [process.env.PUPPETEER_PATH, 'puppeteer', '/corex-qa1/node_modules/puppeteer']) {
  if (!p) continue;
  try { puppeteer = require(p); break; } catch (e) { /* next */ }
}
if (!puppeteer) { console.error('puppeteer not found'); process.exit(2); }

const url = process.argv[2];
const cases = (process.argv.slice(3).length ? process.argv.slice(3) : ['8:1', '8:3', '13:1', '13:3', '25:1', '25:3']).map((c) => c.split(':').map(Number));
const LINES = ['team_seats', 'agency_base', 'agency_t1', 'agency_t2', 'agency_t3', 'branches'];

// Written out independently of the product code.
function expected(agents, branches) {
  const q = { team_seats: 0, agency_base: 0, agency_t1: 0, agency_t2: 0, agency_t3: 0, branches: 0 };
  if (agents <= 10) { q.team_seats = agents; return { plan: 'team', q, total: 450 * agents }; }
  q.agency_base = 1; q.agency_t1 = 10; q.agency_t2 = Math.min(agents - 10, 10); q.agency_t3 = Math.max(agents - 20, 0); q.branches = Math.max(branches - 1, 0);
  return { plan: 'agency', q, total: 1495 + 295 * 10 + 250 * q.agency_t2 + 195 * q.agency_t3 + 750 * q.branches };
}

const read = (page) => page.evaluate((lines) => {
  const g = (s) => { const e = document.querySelector(s); return e ? (e.tagName === 'INPUT' ? e.value : e.textContent.trim()) : null; };
  const num = (v) => (v === null || v === '' ? 0 : Number(String(v).replace(/\s/g, '')));
  const plan = [...document.querySelectorAll('input[data-field="plan"]')];
  const o = { plan: (plan.find((r) => r.checked) || {}).value || '', planDisabled: plan.every((r) => r.disabled), q: {}, a: {} };
  lines.forEach((l) => { o.q[l] = num(g('[data-calc="q:' + l + '"]')); o.a[l] = num(g('[data-calc="amt:' + l + '"]')); });
  o.total = num(g('[data-calc="amt:total"]')); o.mandate = num(g('[data-field="m_amount"]')); o.branchesStart = g('[data-field="branches_start"]');
  o.branchesStartReadonly = !!(document.querySelector('[data-field="branches_start"]') || {}).readOnly;
  o.extraInput = !!document.querySelector('[name="extra_branches"]');
  return o;
}, LINES);

async function type(page, key, value) {
  const sel = '[data-field="' + key + '"]';
  await page.click(sel, { clickCount: 3 });
  await page.keyboard.press('Backspace');
  await page.keyboard.type(String(value));
  await page.keyboard.press('Tab');
  await new Promise((r) => setTimeout(r, 300));
}

(async () => {
  const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/chromium', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1300, height: 1000 });
  await page.goto(url, { waitUntil: 'networkidle2' });
  let bad = 0;
  const line = (ok, s) => { if (!ok) bad++; console.log((ok ? 'OK   ' : 'FAIL ') + s); };

  // The plan ticks are shown, not tickable.
  const clickable = await page.evaluate(() => [...document.querySelectorAll('input[data-field="plan"]')].map((r) => !r.disabled).some(Boolean));
  line(!clickable, 'plan tick boxes are not tickable by the recipient');

  for (const [agents, branches] of cases) {
    await type(page, 'agents', agents);
    await type(page, 'branches', branches);
    await new Promise((r) => setTimeout(r, 3500)); // let the autosave run
    const e = expected(agents, branches);
    for (const phase of ['typed', 'reloaded']) {
      if (phase === 'reloaded') { await page.goto(url, { waitUntil: 'networkidle2' }); await new Promise((r) => setTimeout(r, 1200)); }
      const g = await read(page);
      // the base fee quantity is printed text ("1") in the contract, not a computed cell — its amount is checked instead
      const qOk = LINES.filter((l) => l !== 'agency_base').every((l) => g.q[l] === e.q[l]) && g.a.agency_base === (e.plan === 'agency' ? 1495 : 0);
      const aOk = g.total === e.total && g.mandate === e.total;
      const pOk = g.plan === e.plan && g.planDisabled && g.branchesStart === String(branches) && g.branchesStartReadonly && !g.extraInput;
      line(qOk && aOk && pOk, agents + ' agents / ' + branches + ' branch' + (branches === 1 ? '' : 'es') + ' [' + phase + ']: plan ' + g.plan + ' · qty ' + LINES.map((l) => l.replace('agency_', '') + '=' + g.q[l]).join(' ') + ' · base fee R' + g.a.agency_base
        + ' · total R' + g.total + ' · mandate Amount R' + g.mandate + ' · "Branches at start" ' + g.branchesStart + ' — expected plan ' + e.plan + ', total R' + e.total);
    }
  }

  // 10 → 11 → 10 switches the plan both ways.
  await type(page, 'branches', 2);
  for (const [n, plan] of [[10, 'team'], [11, 'agency'], [10, 'team']]) {
    await type(page, 'agents', n);
    const g = await read(page);
    line(g.plan === plan && g.total === expected(n, 2).total, 'switch to ' + n + ' agents → ' + g.plan + ', R' + g.total + ' (expected ' + plan + ', R' + expected(n, 2).total + ')');
  }
  await browser.close();
  console.log(bad ? bad + ' MISMATCH(ES)' : 'ALL MATCH');
  process.exit(bad ? 1 : 0);
})();
