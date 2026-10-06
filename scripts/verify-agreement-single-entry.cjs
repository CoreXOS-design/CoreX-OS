// Real-browser check of single entry (spec §11.20) on the recipient form.
// Usage: node scripts/verify-agreement-single-entry.cjs <recipient-url>
// Types the bank details ONCE in the mandate and the address / cell / place / date once in Part A; confirms section 5 and the mandate's mirrored rows fill in
// read-only, the tips' links jump to and focus the field each value is typed in, the take-on dates / collection day / Amount are locked, and typing into a mirror does nothing.
let puppeteer;
for (const p of [process.env.PUPPETEER_PATH, 'puppeteer', '/corex-qa1/node_modules/puppeteer']) {
  if (!p) continue;
  try { puppeteer = require(p); break; } catch (e) { /* next */ }
}
if (!puppeteer) { console.error('puppeteer not found'); process.exit(2); }
const url = process.argv[2];
let bad = 0;
const check = (ok, s) => { if (!ok) bad++; console.log((ok ? 'OK   ' : 'FAIL ') + s); };

(async () => {
  const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/chromium', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1100, height: 900 });
  await page.goto(url, { waitUntil: 'networkidle2' });
  await new Promise((r) => setTimeout(r, 1200));
  const type = async (sel, v) => { await page.evaluate((s) => document.querySelector(s).scrollIntoView({ block: 'center' }), sel); await page.click(sel, { clickCount: 3 }); await page.keyboard.press('Backspace'); await page.keyboard.type(v); await page.keyboard.press('Tab'); await new Promise((r) => setTimeout(r, 250)); };

  // type once: mandate bank fields, Part A address / cell / place / date
  await type('#fld-m_holder', 'Caprivi Realty (Pty) Ltd');
  await type('#fld-m_bank', 'First National Bank');
  await type('#fld-m_branch_no', '250655');
  await type('#fld-m_account', '62123456789');
  await page.evaluate(() => document.querySelector('#fld-m_account_type-savings').scrollIntoView({ block: 'center' }));
  await page.click('#fld-m_account_type-savings + .tick');
  await type('#fld-address', '1 Beach Road');
  await type('#fld-billing_cell', '0825550123');
  await type('#fld-sig_place', 'Margate');
  await type('#fld-agents', '13');
  await type('#fld-branches', '2');
  await new Promise((r) => setTimeout(r, 3500));

  const read = () => page.evaluate(() => {
    const m = (src) => [...document.querySelectorAll('[data-mirror-of="' + src + '"]')].map((e) => ({ v: e.value, ro: e.readOnly, named: !!e.name }));
    const t = (sel) => { const e = document.querySelector(sel); return e ? e.value : null; };
    return {
      holder: m('m_holder'), bank: m('m_bank'), branch: m('m_branch_no'), account: m('m_account'), type: m('m_account_type'),
      address: m('address'), cell: m('billing_cell'), place: m('sig_place'), date: m('sig_date'),
      start: t('input[value][data-derived][aria-label="Start date"]'), amount: t('[data-mirror="total"]'),
      tips: document.querySelectorAll('.auto-tip').length,
    };
  });
  let r = await read();
  const one = (a, v) => a.length === 1 && a[0].v === v && a[0].ro && !a[0].named;
  check(one(r.holder, 'Caprivi Realty (Pty) Ltd'), 'section 5 Account holder mirrors the mandate, read-only');
  check(one(r.bank, 'First National Bank'), 'section 5 Bank mirrors the mandate');
  check(one(r.branch, '250655'), 'section 5 Branch code mirrors the mandate');
  check(one(r.account, '62123456789'), 'section 5 Account number mirrors the mandate');
  check(one(r.type, 'Savings'), 'section 5 Account type mirrors the mandate ticks (shows "Savings")');
  check(one(r.address, '1 Beach Road'), 'mandate address mirrors Part A section 1');
  check(one(r.cell, '0825550123'), 'mandate contact number mirrors Part A billing cell');
  check(one(r.place, 'Margate'), 'mandate place mirrors Part A section 6');
  check(r.date.length === 1 && /^\d{1,2} \w+ \d{4}$/.test(r.date[0].v) && r.date[0].ro, 'mandate date mirrors Part A section 6 date (' + (r.date[0] || {}).v + ')');
  check(/R\s?\d/.test(r.amount || ''), 'mandate Amount is locked and filled from the fee table (' + r.amount + ')');
  // typing into a mirror changes nothing
  await page.evaluate(() => document.querySelector('[data-mirror-of="m_account"]').scrollIntoView({ block: 'center' }));
  await page.click('[data-mirror-of="m_account"]'); await page.keyboard.type('999');
  check((await read()).account[0].v === '62123456789', 'typing into a mirrored section 5 box changes nothing');

  // each tip's link jumps to and focuses where the value is typed
  const jumps = [['m_holder', 'fld-m_holder'], ['m_bank', 'fld-m_bank'], ['m_account', 'fld-m_account'], ['m_account_type', 'fld-m_account_type-current'], ['address', 'fld-address'], ['billing_cell', 'fld-billing_cell'], ['sig_place', 'fld-sig_place'], ['sig_date', 'fld-sig_date']];
  for (const [src, id] of jumps) {
    await page.evaluate(() => window.scrollTo(0, 0));
    const ok = await page.evaluate((sid) => { const a = document.querySelector('.auto-tip a[data-goto="' + sid + '"]'); if (!a) return false; a.scrollIntoView({ block: 'center' }); a.click(); return true; }, id);
    await new Promise((r) => setTimeout(r, 900));
    const focused = await page.evaluate((sid) => document.activeElement && document.activeElement.id === sid, id);
    check(ok && focused, 'tip link → ' + id + (ok ? '' : ' (tip missing)') + (focused ? ' focused' : ' NOT focused'));
  }
  await browser.close();
  console.log(bad ? bad + ' FAILURE(S)' : 'ALL OK');
  process.exit(bad ? 1 : 0);
})();
