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
const retry = async (fn) => { for (let i = 0; i < 8; i++) { try { return await fn(); } catch (e) { if (!/context was destroyed|detached/i.test(String(e))) throw e; await wait(1000); } } return fn(); };
const tinker = (php) => { fs.writeFileSync('/tmp/mic-cc2/walk-q.php', '<?php\n' + php); return execFileSync('sudo', ['-u', 'www-data', 'env', 'HOME=/tmp/mic-cc2', 'php8.2', 'artisan', 'tinker', "--execute=include '/tmp/mic-cc2/walk-q.php';"], { cwd: '/corex-qa1', encoding: 'utf8' }).trim(); };
const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), '--app-root=/corex-qa1', `--user-id=${USER_ID}`], { encoding: 'utf8' });
const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: 'new', args: ['--no-sandbox', '--disable-gpu'] });
const page = await browser.newPage();
const VP = (arg('vp', '1366x768')).split('x').map(Number);
await page.setViewport({ width: VP[0], height: VP[1] });
await page.setCookie({ name: cookie.name, value: cookie.value, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
await page.setCacheEnabled(false);
page.on('pageerror', (e) => { if (!/ViewTransition/.test(e.message)) console.log('  pageerror:', e.message); });
const steps = () => page.evaluate(() => [...document.querySelectorAll('[data-qa="pitch-steps"] .cxp-step')].map((b) => b.dataset.step + ':' + b.dataset.state).join());
const frameOf = async () => (await (await page.$('[data-qa="pitch-frame"]')).contentFrame());
const waitFrame = async (re, ms = 60000) => { const t = Date.now(); while (Date.now() - t < ms) { const f = await frameOf(); if (f && re.test(f.url())) { await wait(1500); return f; } await wait(400); } return null; };

await page.goto(`${BASE}/corex/market-intelligence?view=adverts&clear=1&search=${encodeURIComponent(SEARCH)}`, { waitUntil: 'networkidle2', timeout: 120000 });
await page.screenshot({ path: path.join(SHOTS, 'a-list.png') });
const link = await page.evaluate(() => { const a = document.querySelector('a[data-pitch-popup]'); return a ? { href: a.href, listing: a.dataset.listing, start: a.dataset.start } : null; });
const LISTING = link ? link.listing : 0;
console.log('  chip:', JSON.stringify(link));
ok(!!link && link.start === 'owner', 'MIC "Pitch now" is set to open at step 2, Owner');

// ---- 1. MIC: opens at Owner, Property ticked and clickable -------------------------------------------------------------------------------------------
await page.$eval('a[data-pitch-popup]', (a) => a.scrollIntoView({ block: 'center' }));
await wait(400);
await (await page.$('a[data-pitch-popup]')).click();
await page.waitForSelector('[data-qa="pitch-popup"]', { timeout: 15000 });
let f = await waitFrame(/outreach\/compose/);
ok(!!f, 'the popup went straight to the Owner screen (no Property step first)');
const claimToast = await page.evaluate(() => (document.querySelector('[data-qa="pitch-toast"]') || {}).innerText || null);
console.log('  first-open notice:', claimToast);
ok(!!claimToast && /^Claimed · .* · yours for \d+ working days$/.test(claimToast), 'the first open says it claimed the property: "' + claimToast + '"');
const heading = await f.evaluate(() => (document.querySelector('[data-qa="step-heading"]') || {}).innerText || null);
ok(heading === 'Owner', 'the Owner screen has one heading, "' + heading + '"');
await page.screenshot({ path: path.join(SHOTS, 'b-mic-opens-at-owner.png') });
let s = await steps();
console.log('  steps:', s);
ok(s === 'property:done,owner:current,pitch:todo,contact:todo,evaluation:todo', 'step bar: Property ticked, Owner current');
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
ok(!!f && (await steps()) === 'property:current,owner:done,pitch:todo,contact:todo,evaluation:todo', 'Property re-opens the card; Owner (visited) stays ticked');
await page.screenshot({ path: path.join(SHOTS, 'e-property-reopened.png') });
await page.click('[data-qa="pitch-next"]');
f = await waitFrame(/outreach\/compose/);
ok(!!f && (await steps()) === 'property:done,owner:current,pitch:todo,contact:todo,evaluation:todo', 'Next goes back to Owner');

if (WALK) {
    // ---- 4. Owner -> next steps (writes test data) ----------------------------------------------------------------------------------------------------
    const hasAddress = await f.evaluate(() => !/\(no address\)/.test(document.querySelector('[data-qa="listing-header"]').innerText));
    console.log('  listing has an address:', hasAddress);
    const snap = () => JSON.parse(tinker(`$l = \\DB::table('prospecting_listings')->where('id', ${LISTING})->first(['tracked_property_id']); echo json_encode(['tp' => \\DB::table('tracked_properties')->where('agency_id', 1)->count(), 'props' => \\DB::table('properties')->where('agency_id', 1)->count(), 'tasks' => \\DB::table('command_tasks')->where('agency_id', 1)->count(), 'sends' => \\DB::table('seller_outreach_sends')->where('agency_id', 1)->count(), 'owner' => \\DB::table('tracked_properties')->where('id', $l->tracked_property_id)->value('owner_contact_id'), 'claim' => \\DB::table('prospecting_claims')->where('user_id', ${USER_ID})->where('prospecting_listing_id', ${LISTING})->where('is_active', 1)->value('status'), 'pitched' => \\DB::table('prospecting_listings')->where('id', ${LISTING})->value('pitched_at'), 'credits' => \\DB::table('daily_activity_entries as e')->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')->where('e.user_id', ${USER_ID})->whereNull('e.revoked_at')->selectRaw('d.name as n, count(*) as c')->groupBy('d.name')->pluck('c', 'n')]);`));
    const before = snap();
    await f.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /Create new/.test(x.innerText)); if (b) b.click(); });
    await f.type('input[name="first_name"]', 'ZZ');
    await f.type('input[name="last_name"]', 'Popup Test');
    await f.type('input[name="phone"]', '08211' + String(Math.floor(10000 + Math.random() * 89999)));
    await page.screenshot({ path: path.join(SHOTS, 'f-owner-filled.png') });
    await f.evaluate(() => { const b = [...document.querySelectorAll('button[type="submit"]')].find((x) => /continue/i.test(x.innerText)); b.scrollIntoView(); b.click(); });
    if (!hasAddress) { console.log('  (this listing has no street address: pick another with --search=)'); }
    // the deed is not on record: the Owner step says so and stays on the compose screen (never the pitch page, never a property made behind the agent's back)
    let cur = await waitFrame(/outreach\/compose.*need=deed/, 90000);
    ok(!!cur, 'Create & continue saved the owner and stayed on the Owner step because the deed is missing: ' + (cur ? cur.url().replace(BASE, '') : '-'));
    await wait(1200);
    const after = snap();
    console.log('  before:', JSON.stringify(before), ' after:', JSON.stringify(after));
    ok(after.tp === before.tp && after.props === before.props, 'no second tracked property, no property made (' + before.tp + '/' + before.props + ' -> ' + after.tp + '/' + after.props + ')');
    ok(after.tasks === before.tasks, 'no "Upload signed mandate / owner ID / proof of ownership" tasks');
    const gained = Object.keys(after.credits).filter((k) => (after.credits[k] || 0) > (before.credits[k] || 0));
    console.log('  credits gained by the Owner step:', JSON.stringify(gained));
    ok(!gained.some((k) => /claim_taken|property\.captured|pitch_sent/.test(k)), 'no claim credit, no property credit, no "pitch sent" credit from the Owner step (gained: ' + gained.join(', ') + ')');
    ok(after.sends === before.sends && !/sent|contacted/i.test(String(after.claim)) && after.pitched === null, 'nothing "sent", the claim is not "contacted" (' + after.claim + '), not stamped pitched');
    ok(!!after.owner && after.owner !== before.owner, 'the owner is on the record\'s own file (contact ' + after.owner + ')');
    s = await steps();
    console.log('  steps after Owner:', s);
    ok(/owner:current/.test(s), 'the Owner step is still the current one');
    ok(await cur.evaluate(() => !!document.querySelector('[data-qa="need-deed"]')), 'it says the deed record is what is missing');
    await page.screenshot({ path: path.join(SHOTS, 'g-after-owner.png') });
    // the deed arrives (the way linking it would put it on record); the Contact pill now has a screen: the belt makes the property, once, for the record
    tinker(`$l = \\DB::table('prospecting_listings')->where('id', ${LISTING})->first(['tracked_property_id']); \\DB::table('tracked_properties')->where('id', $l->tracked_property_id)->update(['deeds_captured_at' => now()]); echo 'deed on record';`);
    for (let i = 0; i < 40 && (await page.$eval('[data-step="contact"]', (b) => b.dataset.click)) !== '1'; i++) { await page.evaluate(() => {}); await wait(400); if (i % 5 === 4) { await cur.evaluate(() => location.reload()); await wait(1500); cur = await frameOf(); } }
    ok((await page.$eval('[data-step="contact"]', (b) => b.dataset.click)) === '1', 'the Contact pill became a control once the owner and the deed were on record');
    await page.click('[data-step="contact"]');
    cur = await waitFrame(/\/contacts\/\d+\/outreach\/compose/, 90000);
    const made = snap();
    ok(made.props === before.props + 1 && made.tp === before.tp && made.tasks === before.tasks, 'the property was made once, for the record, on its own file, with no chase tasks (' + made.props + '/' + made.tp + '/' + made.tasks + ')');
    ok(!!cur && /\/contacts\/\d+\/outreach\/compose/.test(cur.url()), 'the outreach screen is inside the popup');
    s = await steps();
    ok(/contact:current/.test(s), 'step bar: Contact is current (' + s + ')');
    await page.screenshot({ path: path.join(SHOTS, 'h-contact-step.png') });
    ok(!!cur && !(await cur.evaluate(() => /← Back to/.test(document.body.innerText))), 'no "← Back to contact" inside the popup');
    const where = await cur.evaluate(() => ({ path: location.pathname, qs: location.search }));
    console.log('  composer:', JSON.stringify(where));
    const propertyId = (where.qs.match(/property_id=(\d+)/) || [])[1], contactId = (where.path.match(/contacts\/(\d+)/) || [])[1];

    // ---- 5. the Contact step: one action per tab, no side panels, three tabs -----------------------------------------------------------------------
    const c1 = await cur.evaluate(() => ({
        text: document.body.innerText,
        tabs: [...document.querySelectorAll('[data-tour="oc-channel"] button')].map((b) => b.innerText.trim()),
        queue: !!document.querySelector('[data-tour="oc-queue"]'), side: !!document.querySelector('[data-tour="oc-facts"]'), templatePicker: !!document.querySelector('[data-tour="oc-template"]'),
        cancel: [...document.querySelectorAll('a')].some((a) => /^Cancel$/.test(a.innerText.trim())),
        token: /\{opt_(out|in)_link\}|\{tracking_link\}/.test(document.body.innerText),
        sendBottom: (() => { const b = [...document.querySelectorAll('button')].find((x) => /record send/.test(x.innerText)); return b ? Math.round(b.getBoundingClientRect().bottom) : null; })(), vh: window.innerHeight,
    }));
    ok(!c1.token, 'no raw {token} in the message the agent sees');
    ok(c1.sendBottom !== null && c1.sendBottom <= c1.vh - 56, 'the send button is visible without scrolling (' + c1.sendBottom + ' of ' + c1.vh + ')');
    const pillState = await page.evaluate(() => [...document.querySelectorAll('[data-qa="pitch-steps"] .cxp-step')].map((b) => b.dataset.step + ':' + b.dataset.click).join());
    ok(/^property:1,owner:1,pitch:[01],contact:1,evaluation:1$/.test(pillState), 'every pill with a screen opens it, the rest are plain labels: ' + pillState);
    ok(c1.tabs.join('|') === 'WhatsApp|Email|Phone call', 'three tabs: ' + c1.tabs.join(' | '));
    console.log('  contact flags:', JSON.stringify({ queue: c1.queue, cancel: c1.cancel, side: c1.side, templatePicker: c1.templatePicker }));
    ok(!c1.queue && !c1.cancel && !c1.side && !c1.templatePicker, 'no Add to queue, no Cancel, no demand/tracking panels, no template picker');
    ok((c1.text.match(/Salmon Drive/g) || []).length === 1, 'the address is shown once (' + (c1.text.match(/Salmon Drive/g) || []).length + ')');
    ok(!/No property linked/.test(c1.text), 'no wrong "No property linked" line');
    await page.screenshot({ path: path.join(SHOTS, 'k-contact-whatsapp.png') });

    // ONE gate: the owner is linked but the deed is not on record -> the Phone call tab says so and the Owner step is the live deed picker
    tinker(`$p = \\DB::table('properties')->where('id', ${propertyId})->first(); \\DB::table('tracked_properties')->where('id', $p->tracked_property_id)->update(['owner_contact_id' => ${contactId}, 'deeds_captured_at' => null]); echo 'owner linked, no deed';`);
    await cur.evaluate(() => document.querySelector('[data-qa="tab-phone"]').click());
    cur = await waitFrame(/channel=phone/, 60000);
    await wait(1200);
    const g = await cur.evaluate(() => ({ gate: !!document.querySelector('[data-qa="phone-gate"]'), text: (document.querySelector('[data-qa="phone-gate"]') || {}).innerText || '' }));
    ok(g.gate && /deed record/.test(g.text), 'the Phone call tab says what is missing: ' + g.text.replace(/\s+/g, ' ').trim());
    await page.screenshot({ path: path.join(SHOTS, 'w-phone-gate-deed.png') });
    await cur.evaluate(() => document.querySelector('[data-qa="phone-gate-go"]').click());
    cur = await waitFrame(/outreach\/compose.*need=deed/, 60000);
    ok(!!cur, 'the button leads to the Owner step (the compose screen), not the pitch page');
    const dp = await cur.evaluate(() => ({ banner: !!document.querySelector('[data-qa="need-deed"]'), picker: [...document.querySelectorAll('button')].some((b) => /Link a deed/.test(b.innerText)) }));
    ok(dp.banner && dp.picker, 'it shows the live deed picker and says the deed is what is missing');
    await page.screenshot({ path: path.join(SHOTS, 'x-owner-deed-picker.png') });
    await page.click('[data-step="contact"]');
    cur = await waitFrame(/\/contacts\/\d+\/outreach\/compose/, 60000);
    await cur.evaluate(() => document.querySelector('[data-tour="oc-channel"] button').click());   // back to the WhatsApp tab
    cur = await waitFrame(/channel=whatsapp/, 60000);

    // the owner gate is the belt's: open it for this test property the way finding the owner and the deed would
    console.log('  ', tinker(`$p = \\DB::table('properties')->where('id', ${propertyId})->first(); \\DB::table('tracked_properties')->where('id', $p->tracked_property_id)->update(['owner_contact_id' => ${contactId}, 'deeds_captured_at' => now()]); echo 'owner opened on tracked ' . $p->tracked_property_id;`));

    // ---- 6. WhatsApp: record the send, confirm it went -> the contact is on the record ---------------------------------------------------------------
    const waBefore = tinker(`echo \\DB::table('rts_contact_outcomes')->where('user_id', ${USER_ID})->count();`);
    await cur.evaluate(() => { [...document.querySelectorAll('button')].find((b) => /record send/.test(b.innerText)).click(); });
    cur = await waitFrame(/\/outreach\/sent\//, 90000);
    for (const extra of await browser.pages()) { if (extra !== page) { await extra.close().catch(() => {}); } }   // the WhatsApp tab the click opened
    await page.bringToFront();
    ok(!!cur, 'the sent page opened inside the popup');
    await wait(1500);
    await page.screenshot({ path: path.join(SHOTS, 'l-sent-confirm.png') });
    await cur.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /Yes/i.test(x.innerText)); b.click(); });
    await wait(3000);
    const waAfter = tinker(`echo \\DB::table('rts_contact_outcomes')->where('user_id', ${USER_ID})->count() . '|' . \\DB::table('rts_contact_outcomes')->where('user_id', ${USER_ID})->orderByDesc('id')->value('channel');`);
    ok(waAfter === (Number(waBefore) + 1) + '|whatsapp', 'confirming the WhatsApp wrote ONE contact on channel whatsapp (' + waBefore + ' -> ' + waAfter + ')');
    await page.screenshot({ path: path.join(SHOTS, 'm-sent-confirmed.png') });

    // ---- 7. Phone call: when, how it went, a note -> the same record, channel phone --------------------------------------------------------------------
    await cur.evaluate(() => { const a = [...document.querySelectorAll('a')].find((x) => /Send another/.test(x.innerText)); a.click(); });
    cur = await waitFrame(/\/contacts\/\d+\/outreach\/compose/, 60000);
    await cur.evaluate(() => { document.querySelector('[data-qa="tab-phone"]').click(); });
    cur = await waitFrame(/channel=phone/, 60000);
    await cur.waitForSelector('[data-qa="phone-outcomes"]', { timeout: 30000 });
    await page.screenshot({ path: path.join(SHOTS, 'n-phone-tab.png') });
    const p1 = await cur.evaluate(() => ({ groups: [...document.querySelectorAll('[data-qa="phone-outcomes"] .uppercase')].map((e) => e.innerText), when: document.getElementById('pc-when-date').value + ' ' + document.getElementById('pc-when-time').value, dmy: /^\d\d\/\d\d\/\d{4}$/.test(document.getElementById('pc-when-date').value), required: !!document.querySelector('[data-qa="call-when"]').previousElementSibling.querySelector('[title="Required"]'), usDate: !!document.querySelector('input[type="datetime-local"]'), saveDisabled: document.querySelector('[data-qa="phone-save"]').disabled }));
    console.log('  phone form:', JSON.stringify(p1));
    ok(p1.groups.map((g) => g.toLowerCase()).join('|') === 'moves it forward|try again later|stops here (say why)', 'outcomes grouped: ' + p1.groups.join(' | '));
    ok(p1.saveDisabled, 'Log this call is off until an outcome is picked');
    ok(p1.dmy && p1.required && !p1.usDate, 'the call date is dd/mm/yyyy (' + p1.when + '), marked required, no bare US date box');
    await cur.evaluate(() => { const r = [...document.querySelectorAll('[data-qa="phone-outcomes"] label')].find((l) => /Spoke - call back/.test(l.innerText)); r.querySelector('input').click(); });
    const hourAgo = await cur.evaluate(() => { const d = new Date(Date.now() - 3600 * 1000); const pad = (n) => String(n).padStart(2, '0'); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); });
    await cur.evaluate((v) => { const i = document.getElementById('pc-when-time'); i.value = v.slice(11); i.dispatchEvent(new Event('input', { bubbles: true })); }, hourAgo);
    await cur.waitForSelector('#pc-next', { visible: true, timeout: 10000 });
    const nextDmy = await cur.evaluate(() => { const d = new Date(Date.now() + 3 * 864e5); const pad = (n) => String(n).padStart(2, '0'); return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear(); });
    await cur.type('#pc-next', nextDmy);
    await cur.evaluate(() => document.getElementById('pc-next').blur());
    await cur.type('#pc-note', 'Walk check: wants a call back after the weekend');
    await page.screenshot({ path: path.join(SHOTS, 'o-phone-filled.png') });
    await cur.evaluate(() => document.querySelector('[data-qa="phone-save"]').click());
    await cur.waitForSelector('[data-qa="phone-done"]', { visible: true, timeout: 30000 });
    await page.screenshot({ path: path.join(SHOTS, 'p-phone-logged.png') });
    const phoneRow = tinker(`$r = \\DB::table('rts_contact_outcomes')->where('user_id', ${USER_ID})->orderByDesc('id')->first(['channel','outcome_label','occurred_at','note','next_action_at']); echo json_encode($r);`);
    console.log('  phone row:', phoneRow);
    const pr = JSON.parse(phoneRow);
    ok(pr.channel === 'phone' && /Spoke - call back/.test(pr.outcome_label) && /weekend/.test(pr.note), 'the call is on the record: channel phone, "' + pr.outcome_label + '"');
    ok(pr.occurred_at.slice(11, 16) === hourAgo.slice(11), 'it carries the time the agent gave: ' + pr.occurred_at);

    // ---- 7b. Book evaluation: step 5, the calendar's own form inside the popup --------------------------------------------------------------------
    const canBook = await cur.evaluate(() => { const b = document.querySelector('[data-qa="phone-book-next"]'); return !!b && getComputedStyle(b).display !== 'none'; });
    ok(canBook, 'after a conversation the done panel offers "Book evaluation →"');
    await cur.evaluate(() => [...document.querySelectorAll('button')].find((b) => /Log another call/.test(b.innerText)).click());
    await cur.evaluate(() => { const r = [...document.querySelectorAll('[data-qa="phone-outcomes"] label')].find((l) => /Spoke - evaluation booked/.test(l.innerText)); r.querySelector('input').click(); });
    await wait(500);
    const hint = await cur.evaluate(() => ({ hint: !!document.querySelector('[data-qa="phone-book-hint"]') && getComputedStyle(document.querySelector('[data-qa="phone-book-hint"]')).display !== 'none', btn: document.querySelector('[data-qa="phone-save"]').innerText.trim(), appt: !!document.getElementById('pc-appt') && getComputedStyle(document.getElementById('pc-appt').closest('div.rounded-md')).display !== 'none' }));
    ok(hint.hint && /book the evaluation/.test(hint.btn) && !hint.appt, 'an evaluation outcome says the date is picked next: "' + hint.btn + '"');
    await cur.type('#pc-note', 'Walk check: evaluation booked in the calendar form');
    const pill = await page.$eval('[data-step="evaluation"]', (b) => b.dataset.click);
    ok(pill === '1', 'the step 5 pill is a control before the evaluation is booked');
    await cur.evaluate(() => document.querySelector('[data-qa="phone-save"]').click());
    cur = await waitFrame(/command-center\/calendar.*pitch_book/, 90000);
    await cur.waitForSelector('#createEventFormV2', { visible: true, timeout: 60000 });
    await wait(1500);
    s = await steps();
    ok(/evaluation:current/.test(s), 'the step bar moves to 5 Evaluation: ' + s);
    const form = await cur.evaluate(() => ({ title: document.querySelector('#createEventFormV2 input[name="title"]').value, type: document.querySelector('#createEventFormV2 select[name="category"]')?.selectedOptions?.[0]?.innerText?.trim(), chips: document.querySelector('#createEventFormV2').innerText, cancel: [...document.querySelectorAll('aside[data-tour="cal-event-form"] .corex-btn-outline')].some((b) => getComputedStyle(b).display !== 'none') }));
    console.log('  form:', JSON.stringify({ title: form.title, type: form.type, cancel: form.cancel }));
    ok(/Evaluation: .*Salmon Drive/.test(form.title), 'the title is prefilled: ' + form.title);
    ok(/Property evaluation/.test(form.type || ''), 'the type is Property evaluation');
    ok(/ZZ Popup Test/.test(form.chips) && /Salmon Drive/.test(form.chips), 'the property and the owner (as the seller) are on the form');
    ok(!form.cancel, 'no Cancel / close into an empty calendar');
    const evHeading = await cur.evaluate(() => (document.querySelector('aside[data-tour="cal-event-form"] [data-qa="step-heading"]') || {}).innerText || null);
    ok(evHeading === 'Appointment', 'the form is headed "Appointment": "' + evHeading + '"');
    ok(await cur.evaluate(() => !document.querySelector('[data-tour="cal-layers"]') || getComputedStyle(document.querySelector('aside[data-tour="cal-event-form"]')).position === 'fixed'), 'only the form is shown (no calendar grid, layers or deck in view)');
    await page.screenshot({ path: path.join(SHOTS, 'r-book-form.png') });
    const evDate = await cur.evaluate(() => { const root = document.querySelector('[x-data*="calendarPage"]'); const d = Alpine.$data(root); const dt = new Date(Date.now() + 10 * 864e5); const iso = dt.toISOString().slice(0, 10); d.form.startDate = iso; d.form.endDate = iso; d.form.startTime = '10:00'; d.form.endTime = '11:00'; return iso; });
    await wait(400);
    await cur.evaluate(() => document.querySelector('[data-tour="cal-event-save"]').click());
    cur = await waitFrame(/outreach\/evaluation-booked/, 90000);
    ok(!!cur, 'saving went to the Evaluation booked page inside the popup');
    await wait(1500);
    await page.screenshot({ path: path.join(SHOTS, 's-evaluation-booked.png') });
    const bookedText = await cur.evaluate(() => document.querySelector('[data-qa="evaluation-booked"]').innerText.replace(/\s+/g, ' '));
    console.log('  booked page:', bookedText);
    const evRow = tinker(`$e = \\DB::table('calendar_events')->where('user_id', ${USER_ID})->where('category','property_evaluation')->orderByDesc('id')->first(['id','event_date','tracked_property_id','rts_prospect_id','property_id']); $o = \\DB::table('rts_contact_outcomes')->where('user_id', ${USER_ID})->orderByDesc('id')->first(['outcome_label','calendar_event_id','channel']); $n = \\DB::table('calendar_events')->where('user_id', ${USER_ID})->where('category','property_evaluation')->whereNull('deleted_at')->where('created_at','>=',now()->subMinutes(30))->count(); $st = \\DB::table('rts_prospects')->where('user_id', ${USER_ID})->where('tracked_property_id', $e->tracked_property_id ?? 0)->orderByDesc('id')->value('stage'); echo json_encode(['event'=>$e,'outcome'=>$o,'events_in_30min'=>$n,'stage'=>$st]);`);
    console.log('  rows:', evRow);
    const er = JSON.parse(evRow);
    ok(er.events_in_30min === 1, 'ONE appointment was made (the call used it, no second)');
    ok(er.outcome.calendar_event_id === er.event.id && er.outcome.channel === 'phone' && /Spoke - evaluation booked/.test(er.outcome.outcome_label), 'the call is logged against that appointment: ' + er.outcome.outcome_label);
    ok(er.event.tracked_property_id && er.event.rts_prospect_id, 'the appointment carries the property file and the record (cc4 keys)');
    ok(er.stage === 'appointment_set', 'the record is at Evaluation booked: ' + er.stage);

    // ---- 8. Done says what happened and the list is refreshed ---------------------------------------------------------------------------------------
    await cur.evaluate(() => document.querySelector('[data-embed-done]').click());
    await wait(1500);
    const toast = await page.evaluate(() => (document.querySelector('[data-qa="pitch-toast"]') || {}).innerText || null);
    console.log('  notice:', toast);
    ok(!!toast && /^Appointment booked \u00B7 \d+ \w+ \d\d:\d\d \u00B7 .*Salmon/.test(toast), 'Done shows a notice: "' + toast + '"');
    await page.screenshot({ path: path.join(SHOTS, 'q-done-notice.png') });
    await wait(4000);
    ok(!(await retry(() => page.$('[data-qa="pitch-popup"]'))), 'the popup is closed and the agent is on the list');
    let claimed = false; for (let i = 0; i < 30 && !claimed; i++) { claimed = await retry(() => page.evaluate(() => /Mine · \d+ days left/.test(document.body.innerText) && !!document.querySelector('[data-qa="advert-card"]'))); if (!claimed) await wait(500); }
    ok(claimed, 'the card on the list shows the claim ("Mine") without a reload');
    console.log('  ids for cleanup:', JSON.stringify({ propertyId, contactId }));

    // ---- 9. a row whose owner is already known goes straight to the composer (cc6's 73 Marine Drive): the bar must not tick steps nobody did -----------
    const direct = `${BASE}/corex/contacts/${contactId}/outreach/compose?property_id=${propertyId}`;
    await page.evaluate((u) => window.CorexPitch.open({ next: u, label: 'Pitch - 1233 Salmon Drive' }), direct);
    await page.waitForSelector('[data-qa="pitch-popup"]');
    const early = await page.evaluate(() => ({ loading: getComputedStyle(document.querySelector('[data-qa="pitch-loading"]')).display !== 'none', steps: [...document.querySelectorAll('[data-qa="pitch-steps"] .cxp-step')].map((b) => b.dataset.step + ':' + b.dataset.state).join() }));
    console.log('  loading line while opening:', early.loading);
    ok(!/current/.test(early.steps) && !/done/.test(early.steps), 'and no step is claimed before the screen is known: ' + early.steps);
    await waitFrame(/\/contacts\/\d+\/outreach\/compose/);
    const late = await steps();
    ok(late === 'property:todo,owner:todo,pitch:todo,contact:current,evaluation:todo', 'it opens on the real step, Contact, with no ticks for steps never done: ' + late);
    ok(!(await page.evaluate(() => getComputedStyle(document.querySelector('[data-qa="pitch-loading"]')).display !== 'none')), 'the loading line is gone');
    await page.click('[data-step="contact"]');
    await wait(800);
    ok(!!(await page.$('[data-qa="pitch-popup"]')), 'the current pill can be clicked (it reloads the screen)');
    await page.click('[data-qa="pitch-close"]');
    await wait(2000);
}

if (await page.$('[data-qa="pitch-popup"]')) { await page.click('[data-qa="pitch-close"]'); await wait(2500); }
ok(!(await page.$('[data-qa="pitch-popup"]')) && /market-intelligence/.test(page.url()), 'the agent is back on the MIC list');
await page.screenshot({ path: path.join(SHOTS, 'i-back-on-list.png') });

// (the Road to Sold start is checked by scripts/pitch-popup-rts-check.mjs, read-only: opening a row's Find owner step here would claim a real prospect's advert)
await browser.close();
console.log(fails ? `\n${fails} FAILED` : '\nall passed');
process.exit(fails ? 1 : 0);
