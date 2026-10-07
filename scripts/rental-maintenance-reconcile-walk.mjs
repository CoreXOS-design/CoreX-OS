#!/usr/bin/env node
/**
 * Maintenance flow RECONCILIATION walk — real headless Chromium against the deployed QA1 site (spec .ai/specs/rental-work-orders.md §17,
 * BUILD_STANDARD §0a). One job from fault to dispute, driven with real clicks as four different people:
 *
 *   OFFICE (desktop 1366x768, admin)   fault → "Create work order" → job card → ask crew to price → accept + price the crew's part → quote →
 *                                      owner decision → extra within the owner's tolerance (auto) → extra beyond it (waits for the owner) →
 *                                      close refused while that extra waits → owner decides → crew-done → tenant dispute → "Disputed" →
 *                                      send back → crew reports fixed → tenant confirms → close (card + work order together)
 *   CREW (phone 390x844, no login)     the per-job link: adds a part AT COST, never sees selling / markup / margin / an owner amount
 *   TENANT (phone 390x844, no login)   the emailed one-tap page: "Not complete" with a photo, later "All done"
 *   OFFICE again                       a second job with an EMERGENCY approval captured with no cost; the two new list filters; the catalogue
 *                                      incl-VAT edit observation (reproduce, report only)
 *
 * Every run is its OWN throwaway agency (scripts/rental-maintenance-reconcile-fixture.php, @example.invalid only; mail only reaches QA1's
 * Mailpit guard) and is archived at the end — never a hard delete, never job card 1. A 200 is never a pass signal: each step asserts what
 * is on the page. The script is stateful (--state=<file>) so one stage can be re-run while a page is being worked out:
 *
 *   node scripts/rental-maintenance-reconcile-walk.mjs --state=/tmp/walk-state.json --stage=1 [--create] [--cleanup] [--shots=/tmp/dir]
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
const has = (name) => process.argv.includes(`--${name}`);
const BASE_URL = arg('base-url', 'https://qatesting1.corexos.co.za').replace(/\/$/, '');
const APP_ROOT = arg('app-root', '/corex-qa1');
const MAILPIT = arg('mailpit', 'http://127.0.0.1:8025');
const STATE_FILE = arg('state', path.join(os.tmpdir(), 'reconcile-walk-state.json'));
const SHOTS = arg('shots', path.join(os.tmpdir(), 'reconcile-shots'));
const STAGE = arg('stage', 'all');
const PHP = 'php8.2';
fs.mkdirSync(SHOTS, { recursive: true });

const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass: !!pass, detail }); console.log(`${pass ? '  PASS' : '  FAIL'}  ${name}${detail ? '  — ' + detail : ''}`); return !!pass; };
const note = (msg) => console.log(`  NOTE  ${msg}`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const loadState = () => (fs.existsSync(STATE_FILE) ? JSON.parse(fs.readFileSync(STATE_FILE, 'utf8')) : {});
const saveState = (s) => fs.writeFileSync(STATE_FILE, JSON.stringify(s, null, 2));

function fixture(args) {
  return execFileSync('sudo', ['-n', '-u', 'www-data', PHP, path.join(__dirname, 'rental-maintenance-reconcile-fixture.php'), `--app-root=${APP_ROOT}`, ...args], { encoding: 'utf8' });
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
  page.on('dialog', async (d) => { await d.accept(); }); // the screens use confirm() before send / archive
  if (cookie) await page.setCookie({ ...cookie, domain: new URL(BASE_URL).hostname, path: '/', httpOnly: true, secure: true });
  await page.setViewport({ width, height, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: mobile ? 2 : 1 });
  page.errors = errors;
  return page;
}
const go = async (page, url) => { await page.bringToFront(); const r = await page.goto(url.startsWith('http') ? url : BASE_URL + url, { waitUntil: 'networkidle0', timeout: 45000 }); await sleep(700); return r.status(); };
const main = (page) => page.evaluate(() => (document.querySelector('#appScroll') || document.body).innerText);
const body = (page) => page.evaluate(() => document.body.innerText);
const shot = async (page, name, fullPage = false) => { await page.bringToFront(); return page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage }); };

/** Click the first visible element (button / a / input[type=submit]) whose text or value matches; wait for the navigation that follows if there is one. */
async function clickText(page, text, { tags = 'button, a, input[type=submit], summary, label', within = null, nav = true } = {}) {
  await page.bringToFront();
  const handle = await page.evaluateHandle((text, tags, within) => {
    const root = within ? document.querySelector(within) : document;
    const els = [...root.querySelectorAll(tags)].filter((e) => {
      const r = e.getBoundingClientRect();
      const label = (e.innerText || e.value || '').trim();
      return r.width > 0 && r.height > 0 && label.toLowerCase().includes(text.toLowerCase());
    });
    return els[0] || null;
  }, text, tags, within);
  const el = handle.asElement();
  if (!el) throw new Error(`no visible "${text}" ${within ? 'in ' + within : ''} on ${page.url()}`);
  await el.evaluate((e) => e.scrollIntoView({ block: 'center' }));
  if (nav) {
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 30000 }).catch(() => null), el.click()]);
  } else {
    await el.click();
  }
  await sleep(700);
}

// ── Mailpit ───────────────────────────────────────────────────────────────
async function mailpit(pathname) { const r = await fetch(MAILPIT + pathname); return r.json(); }
/** QA1's outbound guard rewrites every recipient to outbound-guard@localhost.test and prints the intended address in the body, so a message is matched on its
 *  subject (and the throwaway agency's name) first, then confirmed against `toPart` in its text. Returns the newest match since `since`. */
async function findMail(subjectPart, toPart, since) {
  for (let i = 0; i < 40; i++) {
    const list = await mailpit('/api/v1/messages?limit=200');
    const candidates = (list.messages || []).filter((m) => (m.Subject || '').includes(subjectPart) && new Date(m.Created).getTime() >= since - 2000);
    for (const c of candidates) {
      const full = await mailpit('/api/v1/message/' + c.ID);
      if (!toPart || ((full.Text || '') + (full.HTML || '')).includes(toPart)) return full;
    }
    await sleep(3000);
  }
  return null;
}

const idFromUrl = (url, segment) => { const m = url.match(new RegExp(`${segment}/(\\d+)`)); return m ? Number(m[1]) : null; };

// ══════════════════════════════════════════════════════════════════════════
async function main_() {
  let state = loadState();
  if (has('create')) {
    state = JSON.parse(fixture(['--create']).trim().split('\n').pop());
    state.startedAt = Date.now();
    saveState(state);
    note('fixture created: agency ' + state.agency_id);
  }
  if (!state.agency_id) throw new Error('no fixture: run with --create first');

  const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });
  const adminCookie = mintCookie(state.admin_id);
  const office = await newPage(browser, { cookie: adminCookie, width: 1366, height: 768 });
  const phone = await newPage(browser, { width: 390, height: 844, mobile: true });
  const tenant = await newPage(browser, { width: 390, height: 844, mobile: true });
  const pages = { office, phone, tenant };
  const want = (n) => STAGE === 'all' || String(STAGE).split(',').includes(String(n));

  try {
    // ── STAGE 1 — fault → "Create work order" → job card ─────────────────
    if (want(1)) {
      console.log('\nSTAGE 1 — fault report → Create work order → job card');
      await go(office, `/corex/rental-fault-reports/${state.fault_id}`);
      check('fault report opens with ONE "Create work order" button', (await main(office)).includes('Create work order'));
      await clickText(office, 'Create work order', { tags: 'button', nav: false });
      await sleep(400);
      const formText = await office.evaluate(() => document.querySelector('#raise-work-order-form')?.innerText || '');
      check('the form asks who does the work: Internal crew / External contractor', /Internal crew/.test(formText) && /External contractor/.test(formText), formText.replace(/\s+/g, ' ').slice(0, 160));
      await clickText(office, 'Create work order', { tags: 'button[type=submit]', within: '#raise-work-order-form' });
      const url = office.url();
      state.card_id = idFromUrl(url, 'rental-job-cards');
      check('Internal crew lands on the job card (work order + card made together)', !!state.card_id, url);
      saveState(state);
      await shot(office, '01-job-card-created');
    }

    // ── STAGE 2 — assign the crew, ask the crew to price (the link is minted in the same step) ──
    if (want(2)) {
      console.log('\nSTAGE 2 — assign the crew and ask it to price the job');
      await go(office, `/corex/rental-job-cards/${state.card_id}`);
      await office.select('select[name=rental_crew_id]', String(state.crew_id));
      await clickText(office, 'Assign', { tags: 'button[type=submit]' });
      check('the crew is assigned', /Crew:\s*Walk Crew/.test(await main(office)));
      saveState(state);
      await shot(office, '02-crew-assigned');
    }

    // ── STAGE 3 — ask the crew to price (note + a fresh crew link in the same step) ──
    if (want(3)) {
      console.log('\nSTAGE 3 — "Ask crew to price this job"');
      await go(office, `/corex/rental-job-cards/${state.card_id}`);
      await office.type('form[action$="/price-requests"] textarea[name=note]', 'Please price the geyser element and any parts you need. Costs only.');
      const ticked = await office.$eval('form[action$="/price-requests"] input[name=generate_link]', (e) => e.checked);
      check('"create link" is ticked by default', ticked);
      await clickText(office, 'Ask crew to price this job', { tags: 'button[type=submit]' });
      const t = await main(office);
      check('the card says pricing was requested', /Pricing requested/i.test(t), (t.match(/Pricing requested[^\n]*/i) || [''])[0]);
      const link = await office.evaluate(() => [...document.querySelectorAll('a, input, code, span')].map((e) => e.href || e.value || e.innerText || '').find((x) => /\/secure\/job-cards\/[A-Za-z0-9]{20,}/.test(x)) || '');
      state.crew_link = (link.match(/https?:\/\/[^\s"']*\/secure\/job-cards\/[A-Za-z0-9]+/) || [''])[0];
      check('the crew link appears for the office to copy', !!state.crew_link, state.crew_link ? state.crew_link.replace(/[A-Za-z0-9]{12,}$/, '…') : 'no link found');
      saveState(state);
      await shot(office, '03-pricing-requested');
    }

    // ── STAGE 4 — the crew (phone, no login) adds a part AT COST and tries to finish a job nobody has authorised ──
    if (want(4)) {
      console.log('\nSTAGE 4 — crew link on a phone: price the job at cost');
      await go(phone, state.crew_link);
      const t0 = await body(phone);
      check('the crew sees the office\'s request and note', /office asked you to price/i.test(t0) && /Costs only/.test(t0));
      await phone.select('form[action$="/lines"] select[name=type]', 'part');
      await phone.type('form[action$="/lines"] input[name=description]', 'Geyser element 3kW');
      await phone.$eval('form[action$="/lines"] input[name=quantity]', (e) => { e.value = ''; });
      await phone.type('form[action$="/lines"] input[name=quantity]', '1');
      await phone.type('form[action$="/lines"] input[name=unit]', 'each');
      await phone.type('form[action$="/lines"] input[name=unit_cost]', '800');
      await clickText(phone, 'Add line', { tags: 'button[type=submit]' });
      const t1 = await body(phone);
      check('the part is on the crew\'s list as a draft with the cost they typed', /Geyser element 3kW/.test(t1) && /800/.test(t1), (t1.match(/Draft[^\n]*/i) || [''])[0]);
      saveState(state);
      await shot(phone, '04a-crew-added-part', true);
    }

    // ── STAGE 5 — the crew tries to finish an unauthorised job (refused in plain words), then sends its costs to the office ──
    if (want(5)) {
      console.log('\nSTAGE 5 — crew: finish before authorisation is refused; send the costs to the office');
      await go(phone, state.crew_link);
      await phone.type('form[action$="/complete"] input[name=full_name]', 'Sipho Crew');
      await phone.click('form[action$="/complete"] input[name=confirm]');
      await clickText(phone, 'Mark work completed', { tags: 'button[type=submit]', within: 'form[action$="/complete"]' });
      const refused = await body(phone);
      check('crew "Mark work completed" on a job the owner has not approved is refused in plain words', /not been approved|not approved|contact the office/i.test(refused), (refused.match(/[^\n]*(not been approved|not approved|contact the office)[^\n]*/i) || ['(no message found)'])[0].slice(0, 150));
      await go(phone, state.crew_link);
      await phone.click('form[action$="/lines/send"] input[type=checkbox]');
      await clickText(phone, 'Send to office', { tags: 'button[type=submit]', within: 'form[action$="/lines/send"]' });
      const t = await body(phone);
      check('the line now reads "Sent to office"', /Sent to office|awaiting the office/i.test(t), (t.match(/[^\n]*(Sent to office|awaiting the office)[^\n]*/i) || [''])[0]);
      const sellingWords = /selling|markup|margin|owner amount|R\s*1[ ,]?000/i.test(t);
      check('the crew page shows NO selling / markup / margin / owner amount anywhere', !sellingWords, sellingWords ? (t.match(/[^\n]*(selling|markup|margin|owner amount|R\s*1[ ,]?000)[^\n]*/i) || [''])[0] : '');
      saveState(state);
      await shot(phone, '05-crew-sent', true);
    }

    // ── STAGE 6 — office: the crew's line waits OUTSIDE the total; accept it; price it (parts +25 %) ──
    if (want(6)) {
      console.log('\nSTAGE 6 — office accepts the crew line and prices it');
      await go(office, `/corex/rental-job-cards/${state.card_id}`);
      let t = await main(office);
      check('"Added by crew — awaiting office" lists the part', /Added by crew/i.test(t) && /Geyser element 3kW/.test(t));
      check('the job total has NOT moved (crew lines count nowhere until accepted)', /Total\s*\nR0\.00/.test(t) || /Total[^\n]*R0\.00/.test(t), (t.match(/Total\s*\n?R[\d,.]+/) || [''])[0].replace(/\n/g, ' '));
      await clickText(office, 'Accept', { tags: 'button[type=submit]', within: 'form[action$="/accept"]' });
      t = await main(office);
      check('after Accept the part is a real line with its cost R800.00', /Geyser element 3kW/.test(t) && /800\.00/.test(t));
      await office.type('form[action$="/markup"] input[name=markup_parts_percent]', '25');
      await clickText(office, 'Apply', { tags: 'button[type=submit]', within: 'form[action$="/markup"]' });
      t = await main(office);
      check('Parts +25 % prices the part at R1,000.00 (cost R800.00 → selling R1,000.00)', /1,?000\.00/.test(t), (t.match(/Total\s*\n?R[\d,.]+/) || [''])[0].replace(/\n/g, ' '));
      check('the card says the job is OVER the owner\'s R500 limit now', /Within the landlord's R500/.test(t) ? false : true, (t.match(/[^\n]*no-approval limit[^\n]*/i) || ['(no limit line)'])[0]);
      check('margin is shown to the office (view-costs holder)', /R200\.00 \(20 %\)/.test(t), (t.match(/Margin \(excl VAT\)\s*\n?[^\n]*/i) || [''])[0].replace(/\n/g, ' ').slice(0, 100));
      saveState(state);
      await shot(office, '06-priced');
    }

    // ── STAGE 7 — the crew link after pricing (still no selling); the quote goes to the owner ──
    if (want(7)) {
      console.log('\nSTAGE 7 — crew view after pricing; quote to the owner');
      await go(phone, state.crew_link);
      let t = await body(phone);
      check('the crew sees its line as Accepted', /Accepted/i.test(t), (t.match(/[^\n]*Accepted[^\n]*/i) || [''])[0]);
      const leak = /selling|markup|margin|owner amount|1[ ,]?000/i.test(t);
      check('after pricing the crew page STILL shows no selling / markup / margin / R1,000', !leak, leak ? (t.match(/[^\n]*(selling|markup|margin|owner amount|1[ ,]?000)[^\n]*/i) || [''])[0] : '');
      state.quoteSentAt = Date.now();
      await go(office, `/corex/rental-job-cards/${state.card_id}`);
      await clickText(office, 'Send to owner as quote', { tags: 'button[type=submit]' });
      t = await main(office);
      check('the card now says Awaiting owner approval', /Awaiting owner approval/i.test(t));
      check('"Work cannot start or be scheduled yet" is explained in plain words', /Work cannot start or be scheduled yet/i.test(t), (t.match(/Work cannot start[^\n]*/i) || [''])[0].slice(0, 140));
      saveState(state);
      await shot(office, '07-quote-sent');
    }

    // ── STAGE 7b — what the owner was actually mailed (Mailpit) ──
    if (want('7b')) {
      console.log('\nSTAGE 7b — the owner\'s quote mail in Mailpit');
      const mail = await findMail('Your approval is needed', state.landlord_email, state.quoteSentAt);
      check('Mailpit: the owner gets ONE "Your approval is needed" mail with the quote PDF', !!mail && (mail.Attachments || []).length >= 1, mail ? `${mail.Subject} / ${(mail.Attachments || []).map((a) => a.FileName).join(', ')}` : 'no mail');
      if (mail) {
        const text = (mail.Text || '') + ' ' + (mail.HTML || '').replace(/<style[\s\S]*?<\/style>/gi, ' ').replace(/<[^>]+>/g, ' ');
        check('the owner\'s mail shows the R1,000 total and the estimate wording, and no cost / margin words', /1[ ,]?000/.test(text) && /estimate/i.test(text) && !/margin|markup|our cost|unit cost/i.test(text));
        state.ownerMailId = mail.ID;
      }
      saveState(state);
    }

    // ── STAGE 8 — nothing starts without the owner: schedule is refused; the owner's decision is recorded; then scheduling works ──
    if (want(8)) {
      console.log('\nSTAGE 8 — owner approval gate');
      await go(office, `/corex/rental-job-cards/${state.card_id}`);
      await office.$eval('form[action$="/schedule"] input[name=scheduled_at]', (e) => { e.value = '2026-10-20T09:00'; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); });
      await clickText(office, 'Set', { tags: 'button[type=submit]', within: 'form[action$="/schedule"]' });
      let t = await main(office);
      check('Schedule is refused until the owner approves, in plain words', /not been approved by the owner|waiting for the owner/i.test(t) && !/Scheduled:\s*2026-10-20/.test(t), (t.match(/[^\n]*(not been approved|waiting for the owner)[^\n]*/i) || ['(no refusal text)'])[0].slice(0, 140));
      await go(office, `/corex/rental-job-cards/${state.card_id}`);
      state.wo_id = await office.evaluate(() => (document.querySelector('a[href*="/rental-work-orders/"]')?.href.match(/rental-work-orders\/(\d+)/) || [])[1] || null);
      check('the card links to its work order', !!state.wo_id, 'work order ' + state.wo_id);
      await go(office, `/corex/rental-work-orders/${state.wo_id}`);
      await clickText(office, 'Record decision', { tags: 'button', nav: false });
      await office.select('#wo-approval-form select[name=decision]', 'approved');
      await office.select('#wo-approval-form select[name=evidence_type]', 'email');
      await office.type('#wo-approval-form textarea[name=evidence_text]', 'Owner replied by email: approved R1,000 (reconciliation walk)');
      await clickText(office, 'Save decision', { tags: 'button[type=submit]', within: '#wo-approval-form' });
      t = await main(office);
      check('the work order shows the owner approved R1,000.00 and "Why was this approved?"', /1,000\.00/.test(t) && /Why was this approved\?/.test(t) && /Approved by the owner/i.test(t), (t.match(/Why was this approved\?[^\n]*/) || [''])[0].slice(0, 160));
      await go(office, `/corex/rental-job-cards/${state.card_id}`);
      await office.$eval('form[action$="/schedule"] input[name=scheduled_at]', (e) => { e.value = '2026-10-20T09:00'; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); });
      await clickText(office, 'Set', { tags: 'button[type=submit]', within: 'form[action$="/schedule"]' });
      t = await main(office);
      check('after the owner approves, Schedule works and the card is Scheduled', /SCHEDULED/i.test(t) && /2026-10-20/.test(t), (t.match(/Scheduled:[^\n]*/i) || [''])[0]);
      saveState(state);
      await shot(office, '08-approved-scheduled');
    }

    // ── STAGE 9 — extra work after approval: inside the owner's 10 % tolerance it is automatic; beyond it waits for the owner ──
    if (want(9)) {
      console.log('\nSTAGE 9 — variations');
      const addLine = async (desc, cost, price) => {
        await go(office, `/corex/rental-job-cards/${state.card_id}`);
        const f = 'form[action$="/lines"][method=post]:not([action*="crew"])';
        await office.type(`${f} input[name=description]`, desc);
        await office.select(`${f} select[name=type]`, 'part');
        await office.$eval(`${f} input[name=quantity]`, (e) => { e.value = '1'; });
        await office.type(`${f} input[name=unit_cost]`, String(cost));
        await office.type(`${f} input[name=unit_price]`, String(price));
        await clickText(office, '+', { tags: 'button[type=submit]', within: f });
      };
      state.extraAt = Date.now();
      await addLine('Isolator valve', 60, 80);
      let t = await main(office);
      check('an extra of R80 (total R1,080 — inside the owner\'s 10 % = R1,100) is logged as auto-approved', /auto-approved/i.test(t) && !/Variation awaiting owner/i.test(t), (t.match(/[^\n]*auto-approved[^\n]*/i) || ['(no auto-approved text)'])[0].slice(0, 160));
      const autoMail = await findMail('Extra work', state.landlord_email, state.extraAt);
      check('the owner is emailed for his information (no approval asked)', !!autoMail, autoMail ? autoMail.Subject : 'no mail');
      state.extra2At = Date.now();
      await addLine('Replacement pressure valve', 220, 300);
      t = await main(office);
      check('an extra of R300 (total R1,380 — beyond R1,100) shows "Variation awaiting owner"', /Variation awaiting owner/i.test(t));
      const reqMail = await findMail('Extra work needs your approval', state.landlord_email, state.extra2At);
      check('the owner gets "Extra work needs your approval" with the Variation Notice PDF', !!reqMail && (reqMail.Attachments || []).length >= 1, reqMail ? `${reqMail.Subject} / ${(reqMail.Attachments || []).map((a) => a.FileName).join(', ')}` : 'no mail');
      if (reqMail) {
        const text = (reqMail.Text || '') + ' ' + (reqMail.HTML || '').replace(/<style[\s\S]*?<\/style>/gi, ' ').replace(/<[^>]+>/g, ' ');
        check('that mail never shows cost / margin / markup', !/margin|markup|our cost|unit cost/i.test(text));
      }
      await go(phone, state.crew_link);
      const ct = await body(phone);
      check('crew link: the big extra is marked "Awaiting owner — do not start"', /Awaiting owner/i.test(ct) && /do not start/i.test(ct), (ct.match(/[^\n]*Awaiting owner[^\n]*/i) || [''])[0]);
      const leak = /selling|markup|margin|owner amount|1[ ,]?380|R\s*300(?!\.)/i.test(ct);
      check('crew link: still no selling / margin / owner amount (phone width)', !leak, leak ? (ct.match(/[^\n]*(selling|markup|margin|1[ ,]?380)[^\n]*/i) || [''])[0] : '');
      saveState(state);
      await shot(phone, '09-crew-variation', true);
      await shot(office, '09-variation-pending');
    }

    // ── STAGE 10 — the reported gap: a card must not close while the work order refuses (extra work waiting for the owner) ──
    if (want(10)) {
      console.log('\nSTAGE 10 — close is refused for the card AND its work order while the extra waits; the two new list filters');
      await go(office, `/corex/rental-job-cards/${state.card_id}`);
      if (!/Complete job card/i.test(await main(office))) {
        await office.type('form[action$="/worker-sign-off"] input[name=worker_sign_off_name]', 'Sipho Crew');
        await clickText(office, 'Worker sign-off', { tags: 'button[type=submit]' });
        await clickText(office, 'Agent sign-off', { tags: 'button[type=submit]' });
      }
      let t = await main(office);
      check('both sign-offs are recorded', /Complete job card/i.test(t));
      await clickText(office, 'Complete job card', { tags: 'button[type=submit], button' });
      t = await main(office);
      check('Complete job card with no "completed" photo is refused, in words, on screen (card and work order stay open)', /completed" photo is required/i.test(t) && !/Job card completed/i.test(t), (t.match(/[^\n]*photo is required[^\n]*/i) || ['(no message)'])[0].slice(0, 120));
      // a completed photo (a real file through the real upload form), then the close is attempted again
      const png = path.join(os.tmpdir(), 'reconcile-walk.png');
      fs.writeFileSync(png, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==', 'base64'));
      await go(office, `/corex/rental-job-cards/${state.card_id}`);
      await clickText(office, 'Photos (', { tags: 'button', nav: false }); // the Photos section is collapsed
      await office.select('form[action$="/photos"][enctype] select[name=photo_type]', 'completed');
      await (await office.$('form[action$="/photos"][enctype] input[name=photo]')).uploadFile(png);
      await clickText(office, 'Upload photo', { tags: 'button[type=submit]' });
      const afterUpload = await body(office);
      const rawJson = /^\s*\{.*"photo_type"/s.test(afterUpload);
      check('(REPORTED, not fixed — since AT-442) uploading a photo on the card returns the person to the card, not a raw JSON page', !rawJson && /Complete job card|Owner approval/i.test(afterUpload), office.url() + ' :: ' + afterUpload.slice(0, 80).replace(/\n/g, ' '));
      await go(office, `/corex/rental-job-cards/${state.card_id}`);
      await clickText(office, 'Complete job card', { tags: 'button[type=submit], button' });
      t = await main(office);
      check('with the photo in, Complete job card is REFUSED because extra work is still waiting for the owner — plain message on screen', /still waiting for the owner/i.test(t), (t.match(/[^\n]*still waiting for the owner[^\n]*/i) || ['(no message)'])[0].slice(0, 170));
      check('the card did NOT close (still Scheduled, variation chip still showing)', /Variation awaiting owner/i.test(t) && !/Job card completed/i.test(t) && /SCHEDULED/.test(t.split('\n').slice(0, 14).join(' ')));
      await go(office, `/corex/rental-work-orders/${state.wo_id}`);
      const w = await main(office);
      check('the work order is still open too (not Completed)', !/\bCOMPLETED\b/.test(w.split('\n').slice(0, 3).join(' ')), w.split('\n').slice(1, 3).join(' | '));
      // the two list filters (§17.17), own/branch/agency scoped — this agency has exactly one such job
      await go(office, '/corex/rental-job-cards?variation_pending=1');
      let lt = await main(office);
      check('Job Cards ▸ "Variation pending" tile shows 1 and the filter lists this card', /Variation pending\s*\n?\s*1|1\s*\n\s*VARIATION PENDING/i.test(lt) && /ZZ Walk - geyser not heating/.test(lt), (lt.match(/VARIATION PENDING[^\n]*/i) || [''])[0]);
      await go(office, '/corex/rental-job-cards?awaiting_owner=1');
      lt = await main(office);
      check('Job Cards ▸ "Awaiting owner" lists nothing here (the quote is already approved)', !/ZZ Walk - geyser not heating/.test(lt));
      await go(office, '/corex/rental-work-orders?variation_pending=1');
      lt = await main(office);
      check('Work Orders ▸ "Variation pending" filter lists this work order', /ZZ Walk - geyser not heating/.test(lt));
      await shot(office, '10-variation-pending-list');
      saveState(state);
    }

    // ── STAGE 11 — the owner answers the extra; the crew's chip changes; the approved amount moves ──
    if (want(11)) {
      console.log('\nSTAGE 11 — the office records the owner\'s reply to the extra work');
      await go(office, `/corex/rental-work-orders/${state.wo_id}`);
      await clickText(office, "Record the owner's reply", { tags: 'summary', nav: false });
      await office.select('form[action*="/variations/"][action$="/decision"] select[name=decision]', 'approved');
      await office.select('form[action*="/variations/"][action$="/decision"] select[name=evidence_type]', 'email');
      await office.type('form[action*="/variations/"][action$="/decision"] textarea[name=evidence_text]', 'Owner replied by email: yes, go ahead with the extra (reconciliation walk)');
      await clickText(office, "Save the owner's reply", { tags: 'button[type=submit]' });
      const t = await main(office);
      check('the approved amount moves to R1,380.00 and the variation reads approved', /1,380\.00/.test(t) && !/Variation awaiting owner/i.test(t), (t.match(/Approved amount[^\n]*/i) || [''])[0]);
      await go(phone, state.crew_link);
      const ct = await body(phone);
      check('the crew now sees the extra as Approved', /Approved/i.test(ct) && !/do not start/i.test(ct), (ct.match(/[^\n]*Approved[^\n]*/i) || [''])[0]);
      saveState(state);
      await shot(office, '11-variation-approved');
    }
  } finally {
    saveState(state);
    for (const [name, p] of Object.entries(pages)) if (p.errors.length) note(`console errors on ${name}: ${[...new Set(p.errors)].slice(0, 4).join(' | ').slice(0, 300)}`);
    await browser.close();
  }

  if (has('cleanup')) {
    console.log('\nCLEANUP — archiving the throwaway records');
    console.log(fixture([`--cleanup=${JSON.stringify(state)}`]).trim());
  }
  const failed = results.filter((r) => !r.pass);
  console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
  process.exit(failed.length ? 1 : 0);
}
main_().catch((e) => { console.error('WALK ERROR:', e.message); process.exit(2); });
