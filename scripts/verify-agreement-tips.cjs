// Real-browser check of the "Fills in automatically" tips on the recipient form (spec §11.5).
// Usage: node scripts/verify-agreement-tips.cjs <recipient-url> [screenshot-dir]
// Confirms: three tips (plan ticks, section 1 branches, section 3 branches) with the link text "Monthly fee at start"; clicking a link scrolls to
// and focuses the agents box; the three read-only places cannot be typed in; typing agents/branches fills all three. Exit code non-zero on any failure.
let puppeteer;
for (const p of [process.env.PUPPETEER_PATH, 'puppeteer', '/corex-qa1/node_modules/puppeteer']) {
  if (!p) continue;
  try { puppeteer = require(p); break; } catch (e) { /* next */ }
}
if (!puppeteer) { console.error('puppeteer not found'); process.exit(2); }
const [url, shots] = process.argv.slice(2);
let bad = 0;
const check = (ok, s) => { if (!ok) bad++; console.log((ok ? 'OK   ' : 'FAIL ') + s); };

(async () => {
  const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/chromium', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1100, height: 900 });
  await page.goto(url, { waitUntil: 'networkidle2' });
  await new Promise((r) => setTimeout(r, 1200));

  const tips = await page.evaluate(() => [...document.querySelectorAll('.auto-tip')].map((t) => ({ text: t.textContent.trim(), link: (t.querySelector('a') || {}).textContent, visible: t.getBoundingClientRect().width > 0 })));
  check(tips.length === 3, 'three tips on the recipient page (found ' + tips.length + ')');
  check(tips.every((t) => t.visible && t.link === 'Monthly fee at start' && t.text === 'Fills in automatically — enter your number of agents and branches in the Monthly fee at start section (section 3).'), 'each tip reads as briefed, link text "Monthly fee at start"');

  const ro = await page.evaluate(() => ({
    branches1: !!document.querySelector('[data-mirror="branches"]').readOnly,
    branchesStart: !!document.querySelector('[data-field="branches_start"]').readOnly,
    planDisabled: [...document.querySelectorAll('input[data-field="plan"]')].every((r) => r.disabled),
  }));
  check(ro.branches1 && ro.branchesStart && ro.planDisabled, 'the three places are read-only (section 1 branches, section 3 branches, plan ticks)');
  // typing into a read-only place does nothing
  await page.evaluate(() => document.querySelector('[data-mirror="branches"]').scrollIntoView({ block: 'center' }));
  await page.click('[data-mirror="branches"]');
  await page.keyboard.type('9');
  check((await page.evaluate(() => document.querySelector('[data-mirror="branches"]').value)) === '', 'typing into section 1 "Number of branches" changes nothing');

  if (shots) {
    const h = await page.evaluateHandle(() => document.querySelector('[data-mirror="branches"]').closest('.sheet'));
    await h.asElement().screenshot({ path: shots + '/tip-section1.png' });
    const h2 = await page.evaluateHandle(() => document.querySelector('input[data-field="plan"]').closest('.sheet'));
    await h2.asElement().screenshot({ path: shots + '/tip-section3.png' });
  }

  // click the link in the first tip (section 1 area): scrolls to and focuses the agents box
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.evaluate(() => document.querySelector('.auto-tip a').scrollIntoView({ block: 'center' }));
  await page.click('.auto-tip a');
  await new Promise((r) => setTimeout(r, 1200));
  const jumped = await page.evaluate(() => { const f = document.querySelector('#fld-agents'); const r = f.getBoundingClientRect(); return { focused: document.activeElement === f, inView: r.top >= 0 && r.bottom <= innerHeight }; });
  check(jumped.focused && jumped.inView, 'clicking the link scrolls to the agents box, in view and focused');

  // the links of the other two tips work too
  for (const i of [1, 2]) {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.evaluate((n) => document.querySelectorAll('.auto-tip a')[n].scrollIntoView({ block: 'center' }), i);
    await page.evaluate((n) => document.querySelectorAll('.auto-tip a')[n].click(), i);
    await new Promise((r) => setTimeout(r, 1000));
    check(await page.evaluate(() => document.activeElement === document.querySelector('#fld-agents')), 'tip ' + (i + 1) + ' link focuses the agents box');
  }

  // type agents / branches: the three places fill
  const type = async (sel, v) => { await page.click(sel, { clickCount: 3 }); await page.keyboard.press('Backspace'); await page.keyboard.type(v); await page.keyboard.press('Tab'); await new Promise((r) => setTimeout(r, 400)); };
  await type('#fld-agents', '13');
  await type('#fld-branches', '3');
  const filled = await page.evaluate(() => ({
    s1: document.querySelector('[data-mirror="branches"]').value,
    s3: document.querySelector('[data-field="branches_start"]').value,
    plan: ([...document.querySelectorAll('input[data-field="plan"]')].find((r) => r.checked) || {}).value,
  }));
  check(filled.s1 === '3' && filled.s3 === '3' && filled.plan === 'agency', 'typing 13 agents / 3 branches fills section 1 (' + filled.s1 + '), section 3 (' + filled.s3 + ') and ticks ' + filled.plan);
  await browser.close();
  console.log(bad ? bad + ' FAILURE(S)' : 'ALL OK');
  process.exit(bad ? 1 : 0);
})();
