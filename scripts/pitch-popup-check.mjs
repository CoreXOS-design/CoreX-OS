#!/usr/bin/env node
/**
 * Real-browser check of the pitch popup (road-to-sold.md 23.23) as a signed-in TEST user.
 *
 *   node scripts/pitch-popup-check.mjs --user-id=127 --search=Bidstone [--base=...] [--shots=DIR]        shallow: opens screens, never writes
 *   ... --walk    moves Owner -> Pitch/Contact: this WRITES test data (a claim + pitch lock the old Pitch now button also wrote, a contact "ZZ Popup Test",
 *                 and the property the composer is about); nothing is ever sent. Clean up with the printed ids.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const arg = (k, d) => { const a = process.argv.find((x) => x.startsWith(`--${k}=`)); return a ? a.split('=').slice(1).join('=') : d; };
const BASE = arg('base', 'https://qatesting1.corexos.co.za'), USER_ID = arg('user-id', '127'), SEARCH = arg('search', 'Bidstone'), SHOTS = arg('shots', '/tmp/mic-cc2/pitch-shots');
const WALK = process.argv.includes('--walk');
fs.mkdirSync(SHOTS, { recursive: true });
let fails = 0;
const ok = (c, m) => { console.log((c ? '  ok   ' : '  FAIL ') + m); if (!c) fails++; };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), '--app-root=/corex-qa1', `--user-id=${USER_ID}`], { encoding: 'utf8' });
const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: 'new', args: ['--no-sandbox', '--disable-gpu'] });
const page = await browser.newPage();
await page.setViewport({ width: 1500, height: 950 });
await page.setCookie({ name: cookie.name, value: cookie.value, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
await page.setCacheEnabled(false);
page.on('pageerror', (e) => { if (!/ViewTransition/.test(e.message)) console.log('  pageerror:', e.message); });
const steps = () => page.evaluate(() => [...document.querySelectorAll('[data-qa="pitch-steps"] .cxp-step')].map((b) => b.dataset.step + ':' + b.dataset.state).join());
const frameOf = async () => (await (await page.$('[data-qa="pitch-frame"]')).contentFrame());
const waitFrame = async (re, ms = 60000) => { const t = Date.now(); while (Date.now() - t < ms) { const f = await frameOf(); if (f && re.test(f.url())) { await wait(1500); return f; } await wait(400); } return null; };

await page.goto(`${BASE}/corex/market-intelligence?view=adverts&clear=1&search=${encodeURIComponent(SEARCH)}`, { waitUntil: 'networkidle2', timeout: 120000 });
await page.screenshot({ path: path.join(SHOTS, 'a-list.png') });
const link = await page.evaluate(() => { const a = document.querySelector('a[data-pitch-popup]'); return a ? { href: a.href, listing: a.dataset.listing, start: a.dataset.start } : null; });
console.log('  chip:', JSON.stringify(link));
ok(!!link && link.start === 'owner', 'MIC "Pitch now" is set to open at step 2, Owner');

// ---- 1. MIC: opens at Owner, Property ticked and clickable -------------------------------------------------------------------------------------------
await page.$eval('a[data-pitch-popup]', (a) => a.scrollIntoView({ block: 'center' }));
await wait(400);
await (await page.$('a[data-pitch-popup]')).click();
await page.waitForSelector('[data-qa="pitch-popup"]', { timeout: 15000 });
let f = await waitFrame(/outreach\/compose/);
ok(!!f, 'the popup went straight to the Owner screen (no Property step first)');
await page.screenshot({ path: path.join(SHOTS, 'b-mic-opens-at-owner.png') });
let s = await steps();
console.log('  steps:', s);
ok(s === 'property:done,owner:current,pitch:todo,contact:todo', 'step bar: Property ticked, Owner current');
ok(await page.$eval('[data-step="property"]', (b) => b.dataset.click === '1'), 'Property is still clickable');
ok(!(await f.$('a[href*="url-previous"]')) && !(await f.evaluate(() => /← Back/.test(document.body.innerText))), 'no "← Back" button inside the popup');

// ---- 2. the header unfolds the card in place, a second click folds it -------------------------------------------------------------------------------
const hdr = await f.$('[data-qa="listing-header"][data-pitch-peek]');
ok(!!hdr, 'the Listing-from header is a control');
if (hdr) {
    await hdr.click();
    await f.waitForSelector('[data-qa="peek-expander"]', { timeout: 10000 });
    const inner = await (await f.$('[data-qa="peek-frame"]')).contentFrame();
    await inner.waitForSelector('[data-qa="advert-card"]', { timeout: 60000 });
    await wait(1200);
    await page.screenshot({ path: path.join(SHOTS, 'c-header-unfolded.png') });
    ok(!(await page.$('.cxp-back ~ .cxp-back')) && (await page.$$('.cxp-back')).length === 1, 'it did NOT open another popup');
    ok(await f.evaluate(() => document.querySelector('[data-pitch-peek]').getAttribute('aria-expanded') === 'true'), 'the header says it is open (aria-expanded)');
    await hdr.click();
    await wait(400);
    ok(!(await f.$('[data-qa="peek-expander"]')), 'a second click folds it again');
    await page.screenshot({ path: path.join(SHOTS, 'd-header-folded.png') });
}

// ---- 3. Property step can be re-opened, Next comes back to Owner --------------------------------------------------------------------------------------
await page.click('[data-step="property"]');
f = await waitFrame(/_pitch_card/);
ok(!!f && (await steps()) === 'property:current,owner:todo,pitch:todo,contact:todo', 'Property re-opens the card; the bar follows');
await page.screenshot({ path: path.join(SHOTS, 'e-property-reopened.png') });
await page.click('[data-qa="pitch-next"]');
f = await waitFrame(/outreach\/compose/);
ok(!!f && (await steps()) === 'property:done,owner:current,pitch:todo,contact:todo', 'Next goes back to Owner');

if (WALK) {
    // ---- 4. Owner -> next steps (writes test data) ----------------------------------------------------------------------------------------------------
    const hasAddress = await f.evaluate(() => !/\(no address\)/.test(document.querySelector('[data-qa="listing-header"]').innerText));
    console.log('  listing has an address:', hasAddress);
    await f.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /Create new/.test(x.innerText)); if (b) b.click(); });
    await f.type('input[name="first_name"]', 'ZZ');
    await f.type('input[name="last_name"]', 'Popup Test');
    await f.type('input[name="phone"]', '0821110099');
    await page.screenshot({ path: path.join(SHOTS, 'f-owner-filled.png') });
    await f.evaluate(() => { const b = [...document.querySelectorAll('button[type="submit"]')].find((x) => /continue/i.test(x.innerText)); b.scrollIntoView(); b.click(); });
    if (!hasAddress) { console.log('  (this listing has no street address: pick another with --search=)'); }
    const next = await waitFrame(/outreach\/pitch-ready|\/contacts\/\d+\/outreach/, 90000);
    ok(!!next, 'Create & continue moved on inside the popup: ' + (next ? next.url().replace(BASE, '') : '-'));
    s = await steps();
    console.log('  steps after Owner:', s);
    ok(/^property:done,owner:done,/.test(s), 'Owner ticked');
    await page.screenshot({ path: path.join(SHOTS, 'g-after-owner.png') });
    let cur = next;
    if (cur && /pitch-ready/.test(cur.url())) {
        ok(/pitch:current/.test(s), 'Pitch step shown (choose the seller)');
        ok(!(await cur.evaluate(() => /Back to Market Intelligence/.test(document.body.innerText))), 'no "Back to Market Intelligence" link inside the popup');
        await cur.evaluate(() => document.querySelector('a[href*="/outreach/compose"]').click());
        cur = await waitFrame(/\/contacts\/\d+\/outreach\/compose/, 60000);
    }
    ok(!!cur && /\/contacts\/\d+\/outreach\/compose/.test(cur.url()), 'the outreach screen is inside the popup');
    s = await steps();
    ok(/contact:current/.test(s), 'step bar: Contact is current (' + s + ')');
    await page.screenshot({ path: path.join(SHOTS, 'h-contact-step.png') });
    ok(!!cur && !(await cur.evaluate(() => /← Back to/.test(document.body.innerText))), 'no "← Back to contact" inside the popup');
    const ids = await cur.evaluate(() => ({ url: location.pathname + location.search }));
    console.log('  composer:', JSON.stringify(ids));
}

await page.click('[data-qa="pitch-close"]');
await wait(2500);
ok(!(await page.$('[data-qa="pitch-popup"]')) && /market-intelligence/.test(page.url()), 'Close returns the agent to the MIC list');
await page.screenshot({ path: path.join(SHOTS, 'i-back-on-list.png') });

// ---- Road to Sold: a line opens at Property when it has an advert (Owner when it has none) --------------------------------------------------
await page.goto(`${BASE}/corex/road-to-sold`, { waitUntil: 'networkidle2', timeout: 120000 });
const rtsBtn = await page.evaluate(() => { const bs = [...document.querySelectorAll('[data-qa="intake-step"]')]; const b = bs.find((x) => x.dataset.tracked) || bs[0]; return b ? { tracked: b.dataset.tracked || '' } : null; });
console.log('  road to sold step button:', JSON.stringify(rtsBtn));
if (rtsBtn) {
    const sel = '[data-qa="intake-step"]' + (rtsBtn.tracked ? `[data-tracked="${rtsBtn.tracked}"]` : '');
    await page.$eval(sel, (b) => b.scrollIntoView({ block: 'center' }));
    await (await page.$(sel)).click();
    await page.waitForSelector('[data-qa="pitch-popup"]', { timeout: 15000 });
    await wait(4500);
    await page.screenshot({ path: path.join(SHOTS, 'j-rts-popup.png') });
    s = await steps();
    console.log('  rts popup steps:', s);
    ok(rtsBtn.tracked ? s.startsWith('property:current') : s.startsWith('property:done,owner:current'), rtsBtn.tracked ? 'Road to Sold starts at step 1, the MIC card' : 'no advert on this row: starts at Owner, Property shown done');
    await page.click('[data-qa="pitch-close"]');
    await wait(2500);
    ok(/road-to-sold/.test(page.url()) && !(await page.$('[data-qa="pitch-popup"]')), 'Close leaves the agent on Road to Sold');
}
await browser.close();
console.log(fails ? `\n${fails} FAILED` : '\nall passed');
process.exit(fails ? 1 : 0);
