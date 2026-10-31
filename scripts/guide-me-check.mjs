#!/usr/bin/env node
/**
 * Narrated guided tour - the in-page player, proven in a real Chromium (AT-526, spec .ai/specs/training-videos.md 9.23).
 *
 *   node scripts/guide-me-check.mjs                      # fixture page: done-conditions, controls, off-path pause (what NarratedTourBrowserTest runs)
 *   node scripts/guide-me-check.mjs --live --app-root=/corex-qa1 --user-id=22 --screen=agency-setup-identity [--base=https://qatesting1.corexos.co.za] [--shots=DIR]
 *                                                        # the REAL screen on QA1 as that user: starts the tour, does each step the way an agent would (typing only - nothing is saved)
 *
 * The player (public/js/corex-guide-me.js) and driver.js are loaded unchanged; only the sound (a recording stand-in for Audio) and the tour data (fixture) are replaced
 * in fixture mode. Exit code 1 on any failed expectation; one line per expectation.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.join(__dirname, '..');
const arg = (k, d) => { const a = process.argv.find((x) => x === `--${k}` || x.startsWith(`--${k}=`)); return a === undefined ? d : (a.includes('=') ? a.split('=').slice(1).join('=') : true); };
const LIVE = !!arg('live', false);
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const CHROMIUM = ['/usr/bin/chromium', '/usr/bin/chromium-browser'].find((p) => fs.existsSync(p));
if (!CHROMIUM) { console.log('SKIP no chromium on this machine'); process.exit(0); }

let failed = 0;
const ok = (cond, msg) => { console.log((cond ? 'PASS ' : 'FAIL ') + msg); if (!cond) failed++; };

const browser = await puppeteer.launch({ executablePath: CHROMIUM, headless: true, args: ['--no-sandbox', '--disable-gpu', '--autoplay-policy=no-user-gesture-required'] });

// ---- a recording stand-in for the browser's Audio: remembers what was played, pauses and "ends" a clip after 150 ms --------------------------------------
const FAKE_AUDIO = `
window.__played = []; window.__paused = 0;
window.Audio = class { constructor() { this._src = ''; this.onended = null; }
  set src(v) { this._src = v; } get src() { return this._src; }
  play() { if (!String(this._src).startsWith('data:')) window.__played.push(this._src); const self = this; setTimeout(() => { if (self.onended) { const f = self.onended; f(); } }, 150); return Promise.resolve(); }
  pause() { window.__paused++; } };
window.__toasts = []; window.addEventListener('toast', (e) => window.__toasts.push(e.detail && e.detail.message));
window.__ended = []; window.addEventListener('corex-guide:ended', (e) => window.__ended.push(e.detail));
`;

const text = async (page, sel) => page.$eval(sel, (e) => e.innerText).catch(() => '');
const title = (page) => text(page, '.driver-popover-title');
const has = async (page, sel) => !!(await page.$(sel));
const clickSel = async (page, sel) => page.$eval(sel, (e) => e.click());

async function fixture() {
    const driverJs = fs.readFileSync(path.join(ROOT, 'public/vendor/driverjs/driver.js.iife.js'), 'utf8');
    const driverCss = fs.readFileSync(path.join(ROOT, 'public/vendor/driverjs/driver.css'), 'utf8');
    const playerJs = fs.readFileSync(path.join(ROOT, 'public/js/corex-guide-me.js'), 'utf8');
    const tour = {
        key: 'fixture-screen', title: 'Fixture', url: '/fixture', voice: 'v',
        intro: { narration: 'Here is how.', audio_url: '/a/intro.mp3' }, outro: { narration: 'All done.', audio_url: '/a/outro.mp3' },
        steps: [
            { n: 1, selector: '#add', target: '', action: 'click', done: 'click', wait_for: '', narration: 'Press Add.', why: 'It starts a viewing.', audio_url: '/a/1.mp3' },
            { n: 2, selector: '#title', target: '', action: 'type', value: 'Viewing with Bianca', done: 'input', wait_for: '#title', narration: 'Type a name.', why: 'Your team sees it.', audio_url: '/a/2.mp3' },
            { n: 3, selector: '#kind', target: '', action: 'select', done: 'change', wait_for: '', narration: 'Choose Viewing.', why: 'Then we ask for feedback.', audio_url: '/a/3.mp3' },
            { n: 4, selector: '#later', target: '', action: 'highlight', done: 'appear:#ready', wait_for: '', narration: 'Wait for the green box.', why: 'It shows when the page is ready.', audio_url: null },
            { n: 5, selector: '#save', target: '', action: 'highlight', done: 'none', wait_for: '', narration: 'Press Save.', why: 'It keeps your work.', audio_url: '/a/5.mp3' },
        ],
    };
    const html = `<!doctype html><html><head><meta charset="utf-8"><style>${driverCss}</style></head><body style="font-family:sans-serif;padding:40px">
      <button id="add">Add event</button>
      <div id="panel" style="display:none;margin-top:30px"><input id="title" type="text" required> <select id="kind"><option value="">--</option><option value="viewing">Viewing</option></select></div>
      <div id="later" style="margin-top:30px">Later box</div><div id="ready" style="display:none">Ready</div>
      <button id="save" style="margin-top:30px">Save</button>
      <button id="guide-me">Guide me</button>
      <script>${driverJs}</script><script>${playerJs}</script>
      <script>
        document.getElementById('add').addEventListener('click', function () { document.getElementById('panel').style.display = 'block'; });
        window.__cfg = { screen: 'fixture-screen', api: '/api/tour' };
        document.getElementById('guide-me').addEventListener('click', function () { window.CoreXGuideMe.start(window.__cfg); });
        window.__tour = ${JSON.stringify(tour)};
      </script></body></html>`;
    const page = await browser.newPage();
    await page.setViewport({ width: 1100, height: 800 });
    await page.evaluateOnNewDocument(FAKE_AUDIO);
    await page.setRequestInterception(true);
    page.on('request', (req) => {
        const u = new URL(req.url());
        if (u.pathname === '/api/tour') return req.respond({ status: 200, contentType: 'application/json', body: JSON.stringify(tour) });
        if (u.pathname === '/fixture') return req.respond({ status: 200, contentType: 'text/html', body: html });
        return req.respond({ status: 404, body: '' });
    });
    await page.goto('http://fixture.test/fixture');
    return page;
}

if (!LIVE) {
    // ===== 1. the happy path: every step waits for the agent, then speaks the next =============================================================================
    let page = await fixture();
    await clickSel(page, '#guide-me');
    await page.waitForSelector('.driver-popover', { timeout: 5000 });
    ok((await title(page)) === 'Step 1 of 5', 'the first press of Guide me opens "Step 1 of 5"');
    ok(await has(page, '[data-cgm-pause]') && await has(page, '[data-cgm-replay]') && await has(page, '[data-cgm-skip]') && await has(page, '[data-cgm-mute]') && await has(page, '[data-cgm-close]'), 'Pause, Replay, Skip, Voice and Close are on the bubble');
    ok((await text(page, '.driver-popover-description')).includes('Press Add.') && (await text(page, '.driver-popover-description')).includes('It starts a viewing.'), 'the bubble carries the same words as the voice (what to do, then why)');
    await wait(600);
    let played = await page.evaluate(() => window.__played);
    ok(played[0] === '/a/intro.mp3' && played[1] === '/a/1.mp3', 'the voice speaks the intro and then step 1 (' + played.join(', ') + ')');
    await wait(1500);
    ok((await title(page)) === 'Step 1 of 5', 'the tour WAITS: nothing done, so it has not moved on');

    await clickSel(page, '#add');
    await page.waitForFunction(() => (document.querySelector('.driver-popover-title') || {}).innerText === 'Step 2 of 5', { timeout: 4000 }).catch(() => {});
    ok((await title(page)) === 'Step 2 of 5', 'clicking the button moves on to step 2');
    await wait(500);
    played = await page.evaluate(() => window.__played);
    ok(played.includes('/a/2.mp3'), 'step 2 is spoken');
    ok((await text(page, '.driver-popover-description')).includes('Viewing with Bianca'), 'a typing step shows the example');

    await page.type('#title', 'Viewing with Thandi');
    await wait(900);
    ok((await title(page)) === 'Step 2 of 5', 'typing alone does not rush the tour on (it waits until the field is left)');
    ok(await page.$eval('[data-cgm-confirm]', (e) => e.style.display !== 'none'), 'a valid value offers "This is right"');
    await page.$eval('#title', (e) => e.blur());
    await page.waitForFunction(() => (document.querySelector('.driver-popover-title') || {}).innerText === 'Step 3 of 5', { timeout: 4000 }).catch(() => {});
    ok((await title(page)) === 'Step 3 of 5', 'a valid value in the field moves on to step 3');

    await page.select('#kind', 'viewing');
    await page.waitForFunction(() => (document.querySelector('.driver-popover-title') || {}).innerText === 'Step 4 of 5', { timeout: 4000 }).catch(() => {});
    ok((await title(page)) === 'Step 4 of 5', 'choosing in the list moves on to step 4');
    ok(!((await page.evaluate(() => window.__played)).includes('/a/4.mp3')), 'a step with no clip yet shows its words and plays nothing');
    await wait(900);
    ok((await title(page)) === 'Step 4 of 5', 'step 4 waits for the green box to appear');
    await page.$eval('#ready', (e) => { e.style.display = 'block'; });
    await page.waitForFunction(() => (document.querySelector('.driver-popover-title') || {}).innerText === 'Step 5 of 5', { timeout: 4000 }).catch(() => {});
    ok((await title(page)) === 'Step 5 of 5', 'the expected element appearing moves on to step 5');
    ok(await has(page, '[data-cgm-next]') && (await text(page, '[data-cgm-next]')).includes('Finish'), 'a read-only last step ends with a Finish button');
    await clickSel(page, '[data-cgm-next]');
    await wait(500);
    ok(!(await has(page, '.driver-popover')), 'Finish closes the tour');
    const end = await page.evaluate(() => ({ ended: window.__ended, toasts: window.__toasts, played: window.__played }));
    ok(end.ended.length === 1 && end.ended[0].completed === true, 'the tour reports it was completed');
    ok(end.played.includes('/a/outro.mp3'), 'the outro is spoken');
    await page.close();

    // ===== 2. the controls ====================================================================================================================================
    page = await fixture();
    await clickSel(page, '#guide-me'); await page.waitForSelector('.driver-popover'); await wait(500);
    await clickSel(page, '[data-cgm-pause]');
    ok((await text(page, '[data-cgm-pause]')) === 'Resume' && (await text(page, '[data-cgm-note]')).includes('Paused'), 'Pause says Resume and says it is paused');
    await clickSel(page, '#add'); await wait(900);
    ok((await title(page)) === 'Step 1 of 5', 'while paused a click does not move the tour on');
    await clickSel(page, '[data-cgm-pause]');
    ok((await text(page, '[data-cgm-pause]')) === 'Pause', 'Resume puts the tour back on');

    const before = (await page.evaluate(() => window.__played)).length;
    await clickSel(page, '[data-cgm-replay]'); await wait(300);
    ok((await page.evaluate(() => window.__played)).length === before + 1, 'Replay speaks the step again');

    await clickSel(page, '[data-cgm-mute]'); await wait(200);
    ok((await page.evaluate(() => window.localStorage.getItem('corex.guide.voice'))) === 'off' && (await text(page, '[data-cgm-mute]')) === 'Voice: off', 'Voice off is remembered in the browser');
    const muted = (await page.evaluate(() => window.__played)).length;
    await clickSel(page, '[data-cgm-replay]'); await wait(300);
    ok((await page.evaluate(() => window.__played)).length === muted, 'with the voice off nothing is spoken (text only)');
    ok((await text(page, '.driver-popover-description')).includes('Press Add.'), 'the words are still in the bubble');
    await clickSel(page, '[data-cgm-mute]');

    await clickSel(page, '[data-cgm-skip]');
    await page.waitForFunction(() => (document.querySelector('.driver-popover-title') || {}).innerText === 'Step 2 of 5', { timeout: 4000 }).catch(() => {});
    ok((await title(page)) === 'Step 2 of 5', 'Skip moves to the next step without doing this one');
    await clickSel(page, '[data-cgm-close]'); await wait(300);
    ok(!(await has(page, '.driver-popover')), 'Close ends the tour') ;
    ok((await page.evaluate(() => window.__ended)).some((e) => e.completed === false), 'and says it was not completed');
    await page.close();

    // ===== 3. off the path: the box the tour points at vanishes ===============================================================================================
    page = await fixture();
    await clickSel(page, '#guide-me'); await page.waitForSelector('.driver-popover');
    await clickSel(page, '#add');
    await page.waitForFunction(() => (document.querySelector('.driver-popover-title') || {}).innerText === 'Step 2 of 5', { timeout: 4000 }).catch(() => {});
    await page.$eval('#panel', (e) => { e.style.display = 'none'; });
    await page.waitForSelector('[data-cgm-prompt]', { timeout: 5000 }).catch(() => {});
    ok(await has(page, '[data-cgm-prompt]'), 'when the box disappears the tour pauses and asks');
    ok((await text(page, '[data-cgm-prompt]')).includes('Carry on where you left off?'), 'the question is "Carry on where you left off?"');
    ok(!(await has(page, '.driver-popover-title')), 'the spotlight is gone while it waits');
    await page.$eval('#panel', (e) => { e.style.display = 'block'; });
    await clickSel(page, '[data-cgm-carry-on]');
    await page.waitForFunction(() => (document.querySelector('.driver-popover-title') || {}).innerText === 'Step 2 of 5', { timeout: 4000 }).catch(() => {});
    ok((await title(page)) === 'Step 2 of 5' && !(await has(page, '[data-cgm-prompt]')), 'Carry on puts the tour back on the same step');
    await page.$eval('#panel', (e) => { e.style.display = 'none'; });
    await page.waitForSelector('[data-cgm-prompt]', { timeout: 5000 }).catch(() => {});
    await clickSel(page, '[data-cgm-stop]');
    ok(!(await has(page, '[data-cgm-prompt]')) && (await page.evaluate(() => window.sessionStorage.getItem('corex.guide.narrated'))) === null, 'Stop the guide ends it and forgets the place');
    await page.close();

    // ===== 4. coming back to the screen later =================================================================================================================
    page = await fixture();
    await page.evaluate(() => window.sessionStorage.setItem('corex.guide.narrated', JSON.stringify({ screen: 'fixture-screen', i: 2, at: Date.now() })));
    const offered = await page.evaluate(() => window.CoreXGuideMe.offerResume(window.__cfg));
    ok(offered === true && (await has(page, '[data-cgm-prompt]')), 'back on the screen part-way through, it offers to carry on');
    ok((await text(page, '[data-cgm-prompt]')).includes('step 3'), 'and names the step');
    const other = await page.evaluate(() => { window.sessionStorage.setItem('corex.guide.narrated', JSON.stringify({ screen: 'another-screen', i: 2, at: Date.now() })); document.querySelector('[data-cgm-prompt]').remove(); return window.CoreXGuideMe.offerResume(window.__cfg); });
    ok(other === false, 'a place saved on another screen is not offered here');
    await page.close();
} else {
    // ===== the REAL screen as a real user (QA1) ===============================================================================================================
    const APP_ROOT = arg('app-root', '/corex-qa1'), USER_ID = arg('user-id', '22'), BASE = arg('base', 'https://qatesting1.corexos.co.za');
    const SHOTS = arg('shots', ''); const SCREEN = arg('screen', 'agency-setup-identity');
    if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });
    const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), `--app-root=${APP_ROOT}`, `--user-id=${USER_ID}`], { encoding: 'utf8' });
    const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
    const urlPath = { 'agency-setup-identity': '/corex/agency-setup/step/identity', 'book-a-viewing': '/corex/command-center/calendar' }[SCREEN];
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });
    await page.evaluateOnNewDocument(FAKE_AUDIO);
    await page.setCookie({ name: cookie.name, value: cookie.value, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
    await page.goto(BASE + urlPath, { waitUntil: 'networkidle2', timeout: 60000 });
    ok(await has(page, '[data-guide-me-button]'), 'the Guide me button is on the real screen');
    await page.click('[data-guide-me-button]');
    await page.waitForSelector('.driver-popover', { timeout: 15000 }).catch(() => {});
    const t1 = await title(page);
    ok(/^Step 1 of \d+$/.test(t1), 'the guide opens: ' + t1);
    if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `${SCREEN}-1.png`) });
    const total = Number((t1.match(/of (\d+)/) || [0, 0])[1]);
    const played = await page.evaluate(() => window.__played);
    ok(played.length >= 1 && played.every((u) => u.includes('/api/v1/training-tours/')), 'the voice clips come from the tour API: ' + played.join(', '));
    // do each step like an agent: type into a field, press a button, change a list
    for (let n = 1; n <= total; n++) {
        const want = `Step ${n} of ${total}`;
        await page.waitForFunction((w) => (document.querySelector('.driver-popover-title') || {}).innerText === w, { timeout: 15000 }, want).catch(() => {});
        if ((await title(page)) !== want) { ok(false, 'reached ' + want + ' (saw "' + (await title(page)) + '")'); break; }
        const ex = (await text(page, '.driver-popover-description')).match(/For example: (.+)/);
        const sel = await page.evaluate(() => { const el = document.querySelector('.driver-active-element'); return el ? (el.id ? '#' + el.id : null) : null; });
        if (SHOTS && n < total) await page.screenshot({ path: path.join(SHOTS, `${SCREEN}-${n}.png`) });
        if (ex && sel) {
            await page.$eval(sel, (e) => { e.focus(); e.value = ''; });
            await page.type(sel, ex[1].trim());
            await page.$eval(sel, (e) => e.blur());
        } else if (await has(page, '[data-cgm-next]')) {
            await clickSel(page, '[data-cgm-next]');
        } else { await clickSel(page, '[data-cgm-skip]'); }
        await wait(700);
    }
    await wait(800);
    ok(!(await has(page, '.driver-popover')), 'the tour finished on the real screen');
    const end = await page.evaluate(() => window.__ended);
    ok(end.length >= 1 && end[end.length - 1].completed === true, 'and reported it was completed');
    if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `${SCREEN}-end.png`) });
    await page.close();
}

await browser.close();
console.log(failed ? `\n${failed} FAILED` : '\nall passed');
process.exit(failed ? 1 : 0);
