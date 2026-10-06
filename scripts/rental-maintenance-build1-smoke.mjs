#!/usr/bin/env node
/**
 * Maintenance flow BUILD 1 — real-browser smoke (spec .ai/specs/rental-work-orders.md §17.4, §17.5, §17.11, BUILD_STANDARD §0a).
 *
 * A passing PHPUnit suite proves the server contract; it cannot see a dead button, a script that throws on construction, or a
 * phone-width page that overflows. This drives a REAL headless Chromium against a REAL deployed site as three different people:
 *
 *   OFFICE admin  (desktop 1366x768)  add a line with a cost → see cost / selling / margin → apply a parts % → ask the crew
 *                                     to price → see the crew's lines awaiting → accept one, reject one → quote (blocked when a
 *                                     line is unpriced, sent when priced, estimate wording in the PDF)
 *   CREW          (phone 390x844, no login)  open the link → banner → add a part + an extra with a photo → send to the office →
 *                                     see Accepted / Not accepted — reason; the crew page lists the card under "To price";
 *                                     NO selling, markup, margin or owner amount anywhere in the page text
 *   OFFICE clerk  (no costs, no price right)  sees selling only: no cost, margin, markup or cost boxes in the page
 *
 * Every run makes its OWN throwaway agency / property / landlord / crew / DRAFT job card (scripts/rental-maintenance-build1-fixture.php,
 * @example.invalid addresses only — mail goes to Mailpit) and soft-deletes all of it at the end, pass or fail. It never touches
 * job card 1 or any real record. A 200 is never a pass signal: each step asserts real data on the page, and the console must be clean.
 *
 *   node scripts/rental-maintenance-build1-smoke.mjs [--base-url=https://qatesting1.corexos.co.za] [--app-root=/corex-qa1]
 *        [--shots=/tmp/dir] [--keep]   (--keep leaves the fixture in place for inspection)
 */
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';
import os from 'node:os';

const require = createRequire('/corex-qa1/package.json'); // puppeteer lives in the QA1 checkout's node_modules
const puppeteer = require('puppeteer');
const __dirname = path.dirname(fileURLToPath(import.meta.url));

const arg = (name, dflt) => (process.argv.find((a) => a.startsWith(`--${name}=`)) || '').split('=').slice(1).join('=') || dflt;
const BASE_URL = arg('base-url', 'https://qatesting1.corexos.co.za').replace(/\/$/, '');
const APP_ROOT = arg('app-root', '/corex-qa1');
const SHOTS = arg('shots', path.join(os.tmpdir(), 'b1-shots'));
const KEEP = process.argv.includes('--keep');
const PHP = 'php8.2';
fs.mkdirSync(SHOTS, { recursive: true });

const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass: !!pass, detail }); console.log(`${pass ? '  PASS' : '  FAIL'}  ${name}${detail ? '  — ' + detail : ''}`); return !!pass; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function fixture(args) {
  return execFileSync('sudo', ['-n', '-u', 'www-data', PHP, path.join(__dirname, 'rental-maintenance-build1-fixture.php'), `--app-root=${APP_ROOT}`, ...args], { encoding: 'utf8' });
}
function mintCookie(userId) {
  const out = execFileSync(PHP, [path.join(__dirname, 'mint-session-cookie.php'), `--app-root=${APP_ROOT}`, `--user-id=${userId}`], { encoding: 'utf8' });
  return { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
}

async function newPage(browser, { cookie = null, width, height, mobile = false }) {
  const page = await browser.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('UNCAUGHT: ' + e.message));
  page.on('dialog', async (d) => { await d.accept(); }); // the screens use confirm() before archive / send; accept and carry on
  if (cookie) await page.setCookie({ ...cookie, domain: new URL(BASE_URL).hostname, path: '/', httpOnly: true, secure: true });
  await page.setViewport({ width, height, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: mobile ? 2 : 1 });
  page.errors = errors;
  return page;
}
const go = async (page, url) => { const r = await page.goto(url, { waitUntil: 'networkidle0', timeout: 30000 }); await sleep(900); return r.status(); };
const text = (page) => page.evaluate(() => document.body.innerText);
const shot = (page, name) => page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true });
async function submitAndWait(page, clickFn) {
  try { await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 20000 }), clickFn()]); }
  catch (e) {
    const where = page.url();
    const msgs = await page.evaluate(() => [...document.querySelectorAll('.alert-error, .alert-success, [role=alert], :invalid')].map((n) => (n.name || '') + ':' + (n.innerText || n.validationMessage || '').slice(0, 120)).slice(0, 6)).catch(() => []);
    await page.screenshot({ path: path.join(SHOTS, 'timeout-' + Date.now() + '.png'), fullPage: true }).catch(() => {});
    throw new Error(`${e.message} at ${where}; page says: ${JSON.stringify(msgs)}`);
  }
  await sleep(900);
}
async function clickByText(page, selector, label) {
  const handle = await page.evaluateHandle((sel, lab) => [...document.querySelectorAll(sel)].find((el) => el.textContent.trim().startsWith(lab) && el.offsetParent !== null) || null, selector, label);
  const el = handle.asElement();
  if (!el) throw new Error(`no visible ${selector} starting with "${label}"`);
  await el.evaluate((e) => e.scrollIntoView({ block: 'center' }));
  await el.click();
}

// a real (tiny) JPEG for the crew's extra-line photo
function tinyJpeg() {
  const p = path.join(os.tmpdir(), 'b1-smoke-photo.jpg');
  fs.writeFileSync(p, Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AL+AB//Z', 'base64'));
  return p;
}

async function main() {
  const fx = JSON.parse(fixture(['--create']).trim().split('\n').pop());
  console.log('fixture:', JSON.stringify(fx));
  const card = `${BASE_URL}/corex/rental-job-cards/${fx.card_id}`;
  const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });
  let crewLink = null;
  const allErrors = [];

  try {
    // ───────────────────────────── OFFICE (admin, desktop) ─────────────────────────────
    const office = await newPage(browser, { cookie: mintCookie(fx.admin_id), width: 1366, height: 768 });
    check('office: job card opens', (await go(office, card)) === 200);
    let t = await text(office);
    check('office: Pricing panel + "Ask crew to price this job" are on the page', await office.$('[data-pricing-panel]') !== null && t.includes('Ask crew to price this job'));
    check('office: the grid headers include Cost, Selling and Margin (admin holds view-costs)', ['Cost', 'Selling', 'Margin'].every((h) => t.includes(h.toUpperCase()) || t.includes(h)));

    // add a line with a cost through the real add-line row
    await office.waitForSelector('form[action$="/lines"] input[name=description]');
    await office.type('form[action$="/lines"] input[name=description]', 'Smoke geyser element');
    await office.select('form[action$="/lines"] select[name=type]', 'part');
    await office.$eval('form[action$="/lines"] input[name=quantity]', (e) => { e.value = '2'; });
    await office.type('form[action$="/lines"] input[name=unit_cost]', '100');
    await submitAndWait(office, () => office.click('form[action$="/lines"] button[aria-label="Add line"]'));
    t = await text(office);
    check('office: the new line saved and is priced at cost (agency default 0 % = "no markup applied")', t.includes('Smoke geyser element') && t.includes('no markup applied'));
    check('office: cost total R200.00 and margin R0.00 show in the totals', t.includes('Cost total') && t.includes('R200.00') && t.includes('Margin (excl VAT)'));

    // the card's parts markup
    await office.$eval('[data-markup-form] input[name=markup_parts_percent]', (e) => { e.value = '20'; });
    await submitAndWait(office, () => clickByText(office, '[data-markup-form] button', 'Apply'));
    t = await text(office);
    check('office: a 20 % parts markup re-prices the line: selling R120.00 each, label "parts 20 %"', t.includes('R120.00') && t.includes('parts 20 %'));
    check('office: margin on the excl-VAT figures is R40.00 (16.7 %)', t.includes('R40.00') && t.includes('16.7 %'), '240.00 selling − 200.00 cost');
    // 1366 wide: does the cost/selling/margin row fit?
    const fit = await office.evaluate(() => {
      const row = document.querySelector('[data-line-id] > div > div');
      let node = row, scroller = null;
      while (node && node !== document.body) { if (node.scrollWidth > node.clientWidth + 1 && getComputedStyle(node).overflowX !== 'visible') { scroller = node; break; } node = node.parentElement; }
      return { rowWidth: row ? Math.round(row.getBoundingClientRect().width) : null, rowScrollWidth: row ? row.scrollWidth : null, scrollerClient: scroller ? scroller.clientWidth : null, scrollerScroll: scroller ? scroller.scrollWidth : null, pageScrollW: document.documentElement.scrollWidth, pageClientW: document.documentElement.clientWidth };
    });
    check('office: no horizontal page scroll at 1366 px', fit.pageScrollW <= fit.pageClientW + 1, JSON.stringify(fit));
    await shot(office, '01-office-card-cost-selling-margin');

    // ask the crew to price (and mint + email their link in the same step)
    await office.type('[data-ask-form] textarea[name=note]', 'Please price the whole bathroom — smoke test');
    await submitAndWait(office, () => clickByText(office, '[data-ask-form] button', 'Ask crew to price this job'));
    t = await text(office);
    check('office: card shows "Pricing requested"', t.includes('Pricing requested'));
    crewLink = await office.$eval('#jc-crew-link-url', (e) => e.value).catch(() => null);
    check('office: the crew link was created and shown once', !!crewLink && crewLink.includes('/secure/job-cards/'), crewLink || 'none');
    const totalBefore = await office.evaluate(() => document.querySelector('[data-cost-margin-totals]')?.parentElement?.innerText || '');

    // ───────────────────────────── CREW (phone, no login) ─────────────────────────────
    const phone = await newPage(browser, { width: 390, height: 844, mobile: true });
    // the crew PAGE first: a Draft card is only there because the office asked for a price
    const crewPage = `${BASE_URL}/secure/crews/${fx.crew_page_token}`;
    check('crew page: opens', (await go(phone, crewPage)) === 200);
    t = await text(phone);
    check('crew page: the Draft card is listed under "To price"', /to price/i.test(t) && t.includes('ZZ Build 1 smoke'));
    await shot(phone, '02-crew-page-to-price');

    check('crew link: opens', (await go(phone, crewLink)) === 200);
    t = await text(phone);
    check('crew link: banner "The office asked you to price this job" with the office\'s note', t.includes('The office asked you to price this job') && t.includes('Please price the whole bathroom'));
    check('crew link: "What to load" shows the part and quantity but no money (crew costs setting is off)', t.includes('Smoke geyser element') && !t.includes('R 200') && !t.includes('R200'));

    // add a priced part
    await phone.waitForSelector('.cj-line-form input[name=description]', { visible: true });
    await phone.type('.cj-line-form input[name=description]', 'Smoke washer');
    await phone.$eval('.cj-line-form input[name=quantity]', (e) => { e.value = '3'; });
    await phone.type('.cj-line-form input[name=unit]', 'each');
    await phone.type('.cj-line-form input[name=unit_cost]', '30');
    await submitAndWait(phone, () => clickByText(phone, '.cj-line-form button', 'Add line'));
    t = await text(phone);
    check('crew link: the line is a Draft with the crew\'s own cost R 90.00 (3 x 30)', t.includes('Smoke washer') && t.includes('Draft — not sent yet') && t.includes('R 90.00'));

    // add an extra WITH a photo
    await phone.evaluate(() => { const d = [...document.querySelectorAll('details.cj-add')].find((x) => x.querySelector('.cj-line-form')); if (d) d.open = true; });
    await phone.waitForSelector('.cj-line-form input[name=description]', { visible: true });
    await phone.type('.cj-line-form input[name=description]', 'Smoke extra pipe');
    await phone.$eval('.cj-line-form input[name=unit_cost]', (e) => { e.value = '55'; });
    await phone.type('.cj-line-form textarea[name=note]', 'Corroded behind the wall');
    await phone.evaluate(() => { const c = document.querySelector('.cj-line-form input[name=is_extra]'); if (c) c.checked = true; });
    const fileInput = await phone.$('.cj-line-form input[type=file]');
    await fileInput.uploadFile(tinyJpeg());
    await submitAndWait(phone, () => clickByText(phone, '.cj-line-form button', 'Add line'));
    t = await text(phone);
    const photoCount = await phone.$$eval('#cj-pricing .cj-photos img', (els) => els.length);
    check('crew link: the extra is saved with its note and photo', t.includes('Smoke extra pipe') && t.includes('Corroded behind the wall') && photoCount >= 1, `photos on lines: ${photoCount}`);

    // never any selling on the crew's phone
    const phoneText = (await text(phone)).toLowerCase();
    const leaks = ['selling', 'markup', 'margin', 'r120', 'r 120', 'r240', 'r 240', 'r276', 'owner', 'landlord', 'quote'].filter((w) => phoneText.includes(w));
    check('crew link: NO selling, markup, margin, owner or quote wording anywhere in the page text', leaks.length === 0, leaks.join(',') || 'clean');
    const overflow = await phone.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
    check('crew link: no horizontal scroll at phone width (390 px)', overflow.sw <= overflow.cw + 1, JSON.stringify(overflow));
    const smallTargets = await phone.$$eval('#cj-pricing button, #cj-pricing summary, #cj-pricing input:not([type=checkbox]):not([type=file]), #cj-pricing select', (els) => els.filter((e) => e.offsetParent !== null && e.getBoundingClientRect().height < 44).map((e) => e.tagName + ':' + (e.name || e.textContent.trim().slice(0, 20))));
    check('crew link: every tap target on the panel is at least 44 px tall', smallTargets.length === 0, smallTargets.join(' | ') || 'ok');
    await shot(phone, '03-crew-link-drafts');

    // send to office
    await phone.evaluate(() => { document.querySelector('.cj-send-form input[name=confirm]').checked = true; });
    await submitAndWait(phone, () => clickByText(phone, '.cj-send-form button', 'Send to office'));
    t = await text(phone);
    check('crew link: "Sent 2 lines to the office" and both lines now read "Sent to office"', t.includes('Sent 2 lines') && (t.match(/Sent to office/g) || []).length >= 2);
    await shot(phone, '04-crew-link-sent');

    // ───────────────────────────── OFFICE: accept / reject ─────────────────────────────
    await go(office, card);
    t = await text(office);
    check('office: "Added by crew — awaiting office" block lists both lines with the crew\'s cost', await office.$('[data-crew-awaiting]') !== null && t.includes('Smoke washer') && t.includes('Smoke extra pipe') && t.includes('R30.00'));
    check('office: header chip says "Priced by crew — awaiting you"', t.includes('Priced by crew — awaiting you'));
    const totalDuring = await office.evaluate(() => document.querySelector('[data-cost-margin-totals]')?.parentElement?.innerText || '');
    check('office: the crew\'s lines changed NOTHING in the owner-facing total while awaiting', totalDuring === totalBefore, 'total box identical before/after the crew sent');
    await shot(office, '05-office-awaiting-block');

    // accept the washer (selling blank = rules: the card's parts 20 % applies)
    await submitAndWait(office, async () => {
      const handle = await office.evaluateHandle(() => { const blk = [...document.querySelectorAll('[data-awaiting-line]')].find((b) => b.innerText.includes('Smoke washer')); return blk.querySelector('form[action$="/accept"] button'); });
      await handle.asElement().click();
    });
    t = await text(office);
    check('office: accepting prices the washer by the parts 20 % rule: cost R30.00, selling R36.00', t.includes('Smoke washer') && t.includes('R36.00'));
    // reject the extra with a reason
    await office.evaluate(() => { const blk = [...document.querySelectorAll('[data-awaiting-line]')].find((b) => b.innerText.includes('Smoke extra pipe')); blk.querySelector('input[name=reason]').value = 'Already on site — smoke'; });
    await submitAndWait(office, async () => {
      const handle = await office.evaluateHandle(() => [...document.querySelectorAll('[data-awaiting-line]')].find((b) => b.innerText.includes('Smoke extra pipe')).querySelector('form[action$="/reject"] button'));
      await handle.asElement().click();
    });
    t = await text(office);
    check('office: the rejected extra is gone from the awaiting block and counted nowhere', !t.includes('Added by crew — awaiting office') && !(await office.$eval('[data-cost-margin-totals]', (e) => e.parentElement.innerText)).includes('R55'));
    await shot(office, '06-office-after-decisions');

    // the crew sees the outcome
    await go(phone, crewLink);
    t = await text(phone);
    check('crew link: sees "Accepted" and "Not accepted" with the office\'s reason', t.includes('Accepted') && t.includes('Not accepted') && t.includes('Already on site — smoke'));
    check('crew link: still no selling after acceptance', !['selling', 'markup', 'margin', 'r36.00', 'r 36'].some((w) => t.toLowerCase().includes(w)));
    await shot(phone, '07-crew-link-outcome');

    // ───────────────────────────── QUOTE: blocked when unpriced, sent when priced ─────────────────────────────
    await office.waitForSelector('form[action$="/lines"] input[name=description]');
    await office.type('form[action$="/lines"] input[name=description]', 'Smoke unpriced item');
    await submitAndWait(office, () => office.click('form[action$="/lines"] button[aria-label="Add line"]'));
    await submitAndWait(office, () => clickByText(office, '#jc-quote-box button', 'Send to owner as quote'));
    t = await text(office);
    check('office: sending with an unpriced line is refused: "Price every line first"', t.includes('Price every line first'));
    // price it (typed price) via its edit row, then send
    await office.evaluate(() => { const row = [...document.querySelectorAll('[data-line-id]')].find((r) => r.innerText.includes('Smoke unpriced item')); row.querySelector('button[title="Edit line"]').click(); });
    await sleep(500);
    await office.evaluate(() => { const form = document.querySelector('form[id^="jc-edit-line-"]'); form.querySelector('input[name=unit_price]').value = '50'; });
    await submitAndWait(office, () => office.click('form[id^="jc-edit-line-"] button[type=submit].corex-btn-primary'));
    t = await text(office);
    check('office: the typed price saved and is labelled "set by hand"', t.includes('set by hand'));
    await submitAndWait(office, () => clickByText(office, '#jc-quote-box button', 'Send to owner as quote'));
    t = await text(office);
    check('office: the quote went out as Rev 1', t.includes('Quote Rev 1') || t.includes('Rev 1'));
    // read the stored PDF and look for the estimate wording
    const pdfHref = await office.$eval('#jc-quote-box a[href*="/quotes/"]', (a) => a.href);
    const pdfB64 = await office.evaluate(async (u) => { const r = await fetch(u, { credentials: 'same-origin' }); const b = new Uint8Array(await r.arrayBuffer()); let s = ''; b.forEach((x) => { s += String.fromCharCode(x); }); return btoa(s); }, pdfHref);
    const pdfPath = path.join(SHOTS, 'quote-rev1.pdf');
    fs.writeFileSync(pdfPath, Buffer.from(pdfB64, 'base64'));
    const pdfText = execFileSync('pdftotext', ['-layout', pdfPath, '-'], { encoding: 'utf8' });
    check('quote PDF: carries the estimate wording', pdfText.replace(/\s+/g, ' ').includes('This quote is an estimate'));
    check('quote PDF: shows selling (R36.00 washer, R120.00 element) and never the words cost / margin / markup', pdfText.includes('36.00') && pdfText.includes('120.00') && !/\bcost\b|\bmargin\b|\bmarkup\b/i.test(pdfText), (pdfText.match(/\b(cost|margin|markup)\b/gi) || []).join(',') || 'clean');

    // ───────────────────────────── SETTINGS + WIZARD ─────────────────────────────
    check('settings: page opens', (await go(office, `${BASE_URL}/corex/settings/rental-work-orders`)) === 200);
    t = await text(office);
    check('settings: default markups + estimate wording sections with "Restore default"', t.includes('Default markup on parts and labour') && t.includes('Estimate wording on owner quotes') && t.includes('Restore default'));
    await office.$eval('#pricing-markups input[name=default_labour_markup_percent]', (e) => { e.value = '12.5'; });
    await submitAndWait(office, () => clickByText(office, '#pricing-markups button', 'Save'));
    t = await text(office);
    check('settings: saved ("Default markups saved.")', t.includes('Default markups saved'));
    check('wizard: the three controls are on the rentals step', (await go(office, `${BASE_URL}/corex/agency-setup/step/leases`)) === 200 && await office.$('input[name=default_parts_markup_percent]') !== null && await office.$('textarea[name=quote_estimate_term]') !== null);
    const wizardLabour = await office.$eval('input[name=default_labour_markup_percent]', (e) => e.value);
    check('wizard: shows the value just saved on the settings page (12.5), not a stale default', parseFloat(wizardLabour) === 12.5, wizardLabour);

    // ───────────────────────────── CLERK: no costs, no price right ─────────────────────────────
    const clerk = await newPage(browser, { cookie: mintCookie(fx.clerk_id), width: 1366, height: 768 });
    check('clerk: job card opens', (await go(clerk, card)) === 200);
    const html = await clerk.content();
    t = await text(clerk);
    check('clerk: selling is visible', t.includes('R120.00') || t.includes('R36.00'));
    check('clerk: no cost, margin or markup anywhere in the markup (absent, not hidden)', !html.includes('data-cost-margin-totals') && !html.includes('data-line-margin') && !/<input[^>]*name=["']?unit_cost/.test(html) && !html.includes('data-markup-form') && !t.includes('Margin') && !t.includes('R30.00') && !t.includes('R100.00'));
    check('clerk: no "Back to automatic" / markup controls in the edit rows', !html.includes('data-line-markup'));
    await shot(clerk, '08-clerk-view-no-costs');
    check('list: the job cards list has a "Needs pricing" tile', (await go(clerk, `${BASE_URL}/corex/rental-job-cards`)) === 200 && await clerk.$('[data-tile="needs_pricing"]') !== null);

    for (const p of [office, phone, clerk]) allErrors.push(...p.errors);
    check('console: no errors on any page in the run', allErrors.length === 0, allErrors.slice(0, 5).join(' | ') || 'clean');
  } catch (e) {
    check('smoke run completed without an exception', false, e.message);
    console.error(e.stack);
  } finally {
    await browser.close();
    if (!KEEP) {
      try { console.log('cleanup:', fixture(['--cleanup=' + JSON.stringify(fx)]).trim()); } catch (e) { console.error('CLEANUP FAILED — remove agency ' + fx.agency_id + ' by hand:', e.message); }
    } else { console.log('--keep: fixture left in place', JSON.stringify(fx)); }
  }

  const failed = results.filter((r) => !r.pass);
  console.log(`\n${results.length - failed.length}/${results.length} checks passed. Screenshots: ${SHOTS}`);
  fs.writeFileSync(path.join(SHOTS, 'results.json'), JSON.stringify(results, null, 2));
  process.exit(failed.length ? 1 : 0);
}

main();
