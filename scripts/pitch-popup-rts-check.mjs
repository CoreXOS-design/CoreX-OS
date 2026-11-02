#!/usr/bin/env node
/**
 * Read-only check of the pitch popup from a Road to Sold row whose owner is already known (cc6's 73 Marine Drive, prospect 217), as a TEST user at 1366x768:
 * how long "Opening..." lasts, the pills, the one gate (row and popup agree), the trimmed Contact step. It writes nothing: it opens screens and a tab.
 *
 *   node scripts/pitch-popup-rts-check.mjs --user-id=127 --prospect=217
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const arg = (k, d) => { const a = process.argv.find((x) => x.startsWith(`--${k}=`)); return a ? a.split('=').slice(1).join('=') : d; };
const BASE = arg('base', 'https://qatesting1.corexos.co.za'), USER_ID = arg('user-id', '127'), PROSPECT = arg('prospect', '217'), SHOTS = arg('shots', '/tmp/mic-cc2/pitch-shots');
fs.mkdirSync(SHOTS, { recursive: true });
let fails = 0;
const ok = (c, m) => { console.log((c ? '  ok   ' : '  FAIL ') + m); if (!c) fails++; };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), '--app-root=/corex-qa1', `--user-id=${USER_ID}`], { encoding: 'utf8' });
const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: 'new', args: ['--no-sandbox', '--disable-gpu'] });
const page = await browser.newPage();
await page.setViewport({ width: 1366, height: 768 });
await page.setCookie({ name: cookie.name, value: cookie.value, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
await page.setCacheEnabled(false);
page.on('pageerror', (e) => { if (!/ViewTransition/.test(e.message)) console.log('  pageerror:', e.message); });
const steps = () => page.evaluate(() => [...document.querySelectorAll('[data-qa="pitch-steps"] .cxp-step')].map((b) => b.dataset.step + ':' + b.dataset.state + (b.dataset.click === '1' ? '*' : '')).join());
const frameOf = async () => (await (await page.$('[data-qa="pitch-frame"]')).contentFrame());

await page.goto(`${BASE}/corex/road-to-sold`, { waitUntil: 'networkidle2', timeout: 120000 });
const t0 = Date.now();
await page.evaluate((id) => window.dispatchEvent(new CustomEvent('rts-step', { detail: { stepUrl: `/corex/road-to-sold/prospects/${id}/step/pitch`, stepLabel: 'Pitch - 73 Marine Drive' } })), PROSPECT);
await page.waitForSelector('[data-qa="pitch-popup"]');
let ms = null;
for (let i = 0; i < 200; i++) { const shown = await page.evaluate(() => getComputedStyle(document.querySelector('[data-qa="pitch-loading"]')).display !== 'none'); if (!shown) { ms = Date.now() - t0; break; } await wait(50); }
console.log('  "Opening..." lasted ' + ms + ' ms');
ok(ms !== null && ms < 1500, 'the first screen is up in ' + ms + ' ms (the popup opens and the step is on screen)');
await wait(1200);
let s = await steps();
console.log('  steps:', s);
ok(/contact:current/.test(s), 'it opens on the real step, Contact');
ok(!/done/.test(s), 'no tick for a step nobody did');
const f = await frameOf();
await page.screenshot({ path: path.join(SHOTS, 'u-rts73-contact.png') });

// pills: every one is a control (with a screen) or a plain label
const pills = await page.evaluate(() => [...document.querySelectorAll('[data-qa="pitch-steps"] .cxp-step')].map((b) => ({ step: b.dataset.step, click: b.dataset.click, cursor: b.style.cursor })));
console.log('  pills:', JSON.stringify(pills));
ok(pills.every((p) => (p.click === '1' && p.cursor === 'pointer') || (p.click === '0' && p.cursor === 'default')), 'every pill is a control or a plain label (no half-state)');
ok(['property', 'owner', 'contact', 'evaluation'].every((k) => pills.find((p) => p.step === k).click === '1'), 'property (the card), owner (the deed picker), contact and evaluation all open a screen; pitch is a plain label (no pitch-to-sellers screen for this record: ' + pills.find((p) => p.step === 'pitch').click + ')');

// Contact step, trimmed, at 1366x768
const c = await f.evaluate(() => {
    const btn = [...document.querySelectorAll('button')].find((b) => /record send|Send email/.test(b.innerText));
    const r = btn ? btn.getBoundingClientRect() : null;
    return { text: document.body.innerText, picker: /Property this pitch is about/.test(document.body.innerText), stafford: /Stafford/.test(document.body.innerText), token: /\{opt_(out|in)_link\}|\{tracking_link\}/.test(document.body.innerText),
        sendBottom: r ? Math.round(r.bottom) : null, sendTop: r ? Math.round(r.top) : null, vh: window.innerHeight, addr: (document.body.innerText.match(/73 Marine/g) || []).length };
});
console.log('  contact step:', JSON.stringify({ picker: c.picker, stafford: c.stafford, token: c.token, sendTop: c.sendTop, sendBottom: c.sendBottom, vh: c.vh, addr: c.addr }));
ok(!c.picker && !c.stafford, 'no "Property this pitch is about" picker and no second property');
ok(!c.token, 'no raw {token} in the message');
ok(c.sendBottom !== null && c.sendBottom <= c.vh - 56, 'the send button is visible without scrolling (bottom ' + c.sendBottom + ' of ' + c.vh + ', Done bar above it)');
ok(c.addr === 1, 'the address once (' + c.addr + ')');

// one gate: the phone tab opens the form for the owner the row shows
await f.evaluate(() => document.querySelector('[data-qa="tab-phone"]').click());
for (let i = 0; i < 60; i++) { const u = (await frameOf()).url(); if (/channel=phone/.test(u)) break; await wait(300); }
const f2 = await frameOf();
await wait(1500);
const gate = await f2.evaluate(() => ({ gate: !!document.querySelector('[data-qa="phone-gate"]'), form: !!document.querySelector('[data-qa="phone-outcomes"]'), text: document.body.innerText.slice(0, 200) }));
console.log('  phone tab:', JSON.stringify({ gate: gate.gate, form: gate.form }));
ok(!gate.gate && gate.form, 'the Phone call tab agrees with the row: the form, not "Find the owner first"');
await page.screenshot({ path: path.join(SHOTS, 'v-rts73-phone.png') });
// the Property pill opens the card (when the advert has one)
if ((await steps()).includes('property:todo*')) {
    await page.click('[data-step="property"]');
    for (let i = 0; i < 60; i++) { const u = (await frameOf()).url(); if (/_pitch_card/.test(u)) break; await wait(300); }
    ok(/_pitch_card/.test((await frameOf()).url()), 'the Property pill opens the MIC card');
}
// ---- the belt row's Book evaluation: the prefilled form only, fast (cc6 round 3: it opened the whole company calendar in 6.6 s) -------------------------
await page.click('[data-qa="pitch-close"]');
await wait(1500);
// an agent has the page's scripts and styles cached after the first time: open it once to warm them, close it, and time the second open
await page.setCacheEnabled(true);
await page.evaluate((id) => window.dispatchEvent(new CustomEvent('rts-step', { detail: { stepUrl: `/corex/road-to-sold/prospects/${id}/step/evaluation`, stepLabel: 'Book evaluation - 73 Marine Drive' } })), PROSPECT);
await page.waitForSelector('[data-qa="pitch-popup"]');
await wait(5000);
await page.click('[data-qa="pitch-close"]');
await wait(1500);
const t1 = Date.now();
await page.evaluate((id) => window.dispatchEvent(new CustomEvent('rts-step', { detail: { stepUrl: `/corex/road-to-sold/prospects/${id}/step/evaluation`, stepLabel: 'Book evaluation - 73 Marine Drive' } })), PROSPECT);
await page.waitForSelector('[data-qa="pitch-popup"]');
let formMs = null, fr = null;
for (let i = 0; i < 200; i++) {
    fr = await (await page.$('[data-qa="pitch-frame"]')).contentFrame();
    const visible = fr ? await fr.evaluate(() => { const f = document.querySelector('#createEventFormV2'); return !!f && f.offsetHeight > 0 && getComputedStyle(f).visibility !== 'hidden'; }).catch(() => false) : false;
    if (visible) { formMs = Date.now() - t1; break; }
    await wait(50);
}
console.log('  Book evaluation: the prefilled form was on screen after ' + formMs + ' ms');
ok(formMs !== null && formMs < 1500, 'the belt row lands on the prefilled form in ' + formMs + ' ms (the whole calendar took 6.6 s)');
await wait(800);
const cal = await fr.evaluate(() => ({ title: document.querySelector('#createEventFormV2 input[name="title"]').value, grid: !!document.querySelector('[data-tour="cal-layers"]') && getComputedStyle(document.querySelector('[data-tour="cal-layers"]')).display !== 'none' && document.querySelector('[data-tour="cal-layers"]').getBoundingClientRect().width > 0 && !document.querySelector('aside[data-tour="cal-event-form"]').contains(document.querySelector('[data-tour="cal-layers"]')), heading: (document.querySelector('aside[data-tour="cal-event-form"] [data-qa="step-heading"]') || {}).innerText, fixed: getComputedStyle(document.querySelector('aside[data-tour="cal-event-form"]')).position }));
console.log('  calendar frame:', JSON.stringify(cal));
ok(/^Evaluation: /.test(cal.title) && cal.heading === 'Evaluation' && cal.fixed === 'fixed', 'the form is prefilled and headed "Evaluation": "' + cal.title + '"');
await page.screenshot({ path: path.join(SHOTS, 'y-rts-book-form.png') });
await browser.close();
console.log(fails ? `\n${fails} FAILED` : '\nall passed');
process.exit(fails ? 1 : 0);
