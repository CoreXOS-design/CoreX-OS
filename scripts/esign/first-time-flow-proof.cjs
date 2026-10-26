// Walks the recipient signing page as a first-time signer would (esign-signing-modes.md §13) in a real Chromium and FAILS (exit 1) if
//   - the page does not become idle within 2s of load (main thread answers, DOM stops changing, no recount loop), or
//   - any native browser dialog (alert / confirm / prompt) opens at any point, or
//   - a page error is thrown, or
//   - a COLD load (fresh browser profile, no cache) with every third-party host held for 20s does not paint and become
//     interactive within 2s - the page must never wait on someone else's server (it used to: two render-blocking Google Fonts links).
// usage: NODE_PATH=<app>/node_modules node first-time-flow-proof.cjs <base> <token> <out-prefix> <width> <saved|each> [headful]
//   headful: run a visible Chromium (use xvfb-run on a server).
const puppeteer = require('puppeteer');
const fs = require('fs'), os = require('os'), path = require('path');
const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const [base, token, out, widthArg, mode, headful] = process.argv.slice(2);
  const failures = [];

  // ── Cold-profile load: fresh profile, cache off, every request to ANOTHER host held for 20s ──
  async function coldLoadCheck() {
    const prof = fs.mkdtempSync(path.join(os.tmpdir(), 'corex-cold-profile-'));
    let br;
    try {
      br = await puppeteer.launch({ headless: headful ? false : 'new', userDataDir: prof, executablePath: '/usr/bin/chromium',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--disk-cache-size=1', '--window-size=1920,1080'] });
      const pg = await br.newPage();
      await pg.setViewport({ width: 1920, height: 1080 });
      const cdp = await pg.createCDPSession();
      await cdp.send('Network.enable'); await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
      const ownHost = new URL(base).host;
      await pg.setRequestInterception(true);
      const held = [];
      pg.on('request', async r => {
        const host = new URL(r.url()).host;
        if (host && host !== ownHost && !r.url().startsWith('data:')) { held.push(host); await sleep(20000); try { await r.abort('failed'); } catch (e) {} } else { r.continue().catch(() => {}); }
      });
      pg.on('dialog', async d => { failures.push('cold load: native dialog opened: ' + d.type()); await d.dismiss(); });
      await pg.evaluateOnNewDocument(() => {
        window.__t = {};
        new PerformanceObserver(l => l.getEntries().forEach(e => { if (e.name === 'first-contentful-paint') window.__t.fcp = Math.round(e.startTime); })).observe({ entryTypes: ['paint'] });
        document.addEventListener('alpine:initialized', () => { window.__t.alpine = Math.round(performance.now()); });
      });
      pg.goto(base + '/sign/' + token, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
      let t = null;
      for (let i = 0; i < 12; i++) { await sleep(250); t = await Promise.race([pg.evaluate(() => window.__t).catch(() => null), sleep(1500).then(() => null)]); if (t && t.fcp && t.alpine) break; }
      console.log('cold load', JSON.stringify({ firstPaintMs: t && t.fcp, interactiveMs: t && t.alpine, thirdPartyHostsHeld: [...new Set(held)] }));
      if (!t || !t.fcp || t.fcp > 2000) failures.push('cold load: first paint ' + (t && t.fcp ? t.fcp + 'ms' : 'never') + ' (must be within 2000ms with third parties held)');
      if (!t || !t.alpine || t.alpine > 2000) failures.push('cold load: page interactive ' + (t && t.alpine ? t.alpine + 'ms' : 'never') + ' (must be within 2000ms with third parties held)');
      const ok = await Promise.race([pg.evaluate(() => 1).then(() => true), sleep(2000).then(() => false)]);
      if (!ok) failures.push('cold load: main thread not responding');
    } finally {
      try { if (br) await Promise.race([br.close(), new Promise(r => setTimeout(r, 5000))]); } catch (e) {}
      try { const pr = br && br.process && br.process(); if (pr && !pr.killed) pr.kill('SIGKILL'); } catch (e) {}
      try { fs.rmSync(prof, { recursive: true, force: true }); } catch (e) {}
    }
  }
  await coldLoadCheck();
  const width = parseInt(widthArg, 10);
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'corex-ui-profile-'));
  let browser;
  try {
    browser = await puppeteer.launch({ headless: headful ? false : 'new', userDataDir: profile, executablePath: '/usr/bin/chromium',
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--disable-crash-reporter', '--disable-breakpad'] });
    const page = await browser.newPage();
    await page.setViewport({ width, height: width < 600 ? 800 : 900, isMobile: width < 600, hasTouch: width < 600 });
    const log = [];
    page.on('pageerror', e => log.push('PAGEERROR ' + e.message.slice(0, 200)));
    page.on('dialog', async d => { failures.push('native dialog opened: ' + d.type() + ' "' + d.message().slice(0, 60) + '"'); log.push('DIALOG ' + d.message().slice(0, 50)); await d.dismiss(); });
    page.on('pageerror', e => failures.push('page error: ' + e.message.slice(0, 120)));
    await page.evaluateOnNewDocument(() => { window.__mut = 0; document.addEventListener('DOMContentLoaded', () => { new MutationObserver(m => { window.__mut += m.length; }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, characterData: true }); }); });
    page.on('response', r => { if (/\/sign\/[^/]+\/(adopt|place|place-all|place\/remove)$/.test(r.url())) log.push('HTTP ' + r.url().split('/sign/')[1].replace(/^[^/]+/, '') + ' ' + r.status()); });
    await page.goto(base + '/sign/' + token, { waitUntil: 'networkidle2', timeout: 60000 });
    await sleep(1200);
    const choose = await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /Sign Electronically/.test(x.textContent)); if (b && b.getBoundingClientRect().width > 0) { b.click(); return true; } return false; });
    if (choose) await sleep(3500);
    // Idle check: after the page has settled the main thread must answer promptly and the DOM must stop changing.
    const assertIdle = async (label) => {
      await sleep(1000);
      const m1 = await page.evaluate(() => window.__mut);
      let slow = 0;
      for (let i = 0; i < 4; i++) { const t = Date.now(); const ok = await Promise.race([page.evaluate(() => 1).then(() => true), sleep(2000).then(() => false)]); if (!ok || Date.now() - t > 500) slow++; await sleep(250); }
      const m2 = await page.evaluate(() => window.__mut);
      console.log('idle check', label, JSON.stringify({ unresponsiveChecks: slow, domChangesWhileIdle: m2 - m1 }));
      if (slow > 0) failures.push(label + ': the page did not stay responsive');
      if (m2 - m1 > 5) failures.push(label + ': the page keeps changing itself while idle (' + (m2 - m1) + ' DOM changes in 1s)');
    };
    const snap = async (label, shot) => {
      const s = await page.evaluate(() => {
        const vis = el => { if (!el) return false; const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
        const txt = sel => { const e = document.querySelector(sel); return e && vis(e) ? e.innerText.replace(/\s+/g, ' ').trim() : null; };
        const hdr = document.querySelector('[data-signing-header]');
        const banned = (document.body.innerText.match(/\b(adopt\w*|marks?|places|stamp\w*)\b/gi) || []);
        const btns = [...(hdr ? hdr.querySelectorAll('button') : [])].filter(vis).map(b => b.innerText.trim());
        return {
          status: txt('[data-header-status]'), progress: txt('[data-header-progress]'), blocker: txt('[data-header-blocker]'), buttons: btns,
          hdrH: hdr ? Math.round(hdr.getBoundingClientRect().height) : null, hdrTop: hdr ? Math.round(hdr.getBoundingClientRect().top) : null,
          finishEnabled: !!(hdr && hdr.querySelector('[data-header-finish]') && !hdr.querySelector('[data-header-finish]').disabled),
          floatingBar: [...document.querySelectorAll('div')].some(d => vis(d) && /items? remaining|Go to next/.test(d.innerText || '') && getComputedStyle(d).position === 'fixed'),
          popupVisible: vis([...document.querySelectorAll('h2')].find(h => /Add your signature and initials/.test(h.textContent))),
          confirmVisible: vis([...document.querySelectorAll('h2')].find(h => /^Sign everything$/.test(h.textContent.trim()))),
          railLive: (document.querySelector('[data-steps-rail] .is-live') || {}).innerText ? document.querySelector('[data-steps-rail] .is-live').innerText.split('\n')[1] : null,
          toFill: txt('[data-details-to-fill]'),
          amendPanelVisible: vis(document.querySelector('[data-amend-panel]')), amendLinkVisible: vis(document.querySelector('[data-amend-link]')),
          bannedWords: banned, overflowX: document.documentElement.scrollWidth > window.innerWidth,
        };
      });
      console.log(label, JSON.stringify(s));
      if (shot) await page.screenshot({ path: out + '-' + shot + '.png' });
    };
    const click = async sel => page.evaluate(sel => { const b = document.querySelector(sel); if (b) b.click(); return !!b; }, sel);
    await assertIdle('after load');
    await snap('1 open', 'open');
    if (mode === 'saved') {
      await page.evaluate(PNG => { const d = Alpine.$data(document.querySelector('[x-ref="webDocContent"]').closest('[x-data]')); d.adoptionSig = PNG; d.adoptionInit = PNG; d.adoptionAgree = true; d.submitAdoption(); }, PNG);
      await sleep(1500);
    } else {
      await click('[data-header-next]'); await sleep(800);
    }
    await snap('2 after step 1', 'step2');
    // type into every empty detail using Next
    for (let i = 0; i < 12; i++) {
      const st = await page.evaluate(() => document.querySelector('[data-header-status]').innerText);
      if (!/Step 2/.test(st) || /press Next/.test(st)) break;
      await click('[data-header-next]'); await sleep(700);
      await page.keyboard.type('Test value ' + i); await sleep(250);
    }
    await snap('3 details typed', 'step2b');
    await click('[data-header-next]'); await sleep(900);
    await snap('4 step 3', 'step3');
    await click('[data-header-next]'); await sleep(900);
    await snap('5 walked to first spot', 'walk');
    await click('[data-header-next]'); await sleep(1800);   // press again: sign it
    await snap('6 signed first spot', 'signed1');
    if (mode === 'saved') {
      await click('[data-header-sign-everything]'); await sleep(900);
      await snap('7 sign everything confirm', 'confirm');
      await page.evaluate(() => { Alpine.$data(document.querySelector('[x-ref="webDocContent"]').closest('[x-data]')).confirmFillAll(); }); await sleep(2000);
      await snap('8 after sign everything', 'all');
    }
    // A pack: after Sign everything the signer must still be taken to every document - Next opens the next one, and the tick + Finish
    // only appear once the last document has been reached.
    const visible = sel => page.evaluate(sel => { const e = document.querySelector(sel); return !!e && e.offsetParent !== null; }, sel);
    const statusNow = () => page.evaluate(() => (document.querySelector('[data-header-status]') || {}).innerText || '');
    if (/Document \d+ of \d+/.test(await statusNow()) || mode === 'saved') {
      let sawLast = false, sawAny = false;
      for (let i = 0; i < 14; i++) {
        const st = await statusNow();
        const m = st.match(/Document (\d+) of (\d+)/);
        if (m) { sawAny = true; if (m[1] === m[2]) sawLast = true; }
        const finishShown = await visible('[data-header-finish]');
        if (sawAny && !sawLast && finishShown) failures.push('Finish was shown before the last document of the pack was reached: ' + st);
        if (!(await visible('[data-header-next]'))) break;
        await click('[data-header-next]'); await sleep(1300);
        if (i === 13) failures.push('Next never finished walking the pack');
      }
      if (sawAny && !sawLast) failures.push('the pack never reached its last document');
      await snap('8b last document reached', 'lastdoc');
      if (sawAny && !(await visible('[data-header-finish]'))) failures.push('Finish is not shown after the last document was reached');
    }
    await page.evaluate(() => { const c = document.getElementById('consent-checkbox'); if (c && !c.checked) c.click(); }); await sleep(800);
    await snap('9 consent ticked', 'final');
    await assertIdle('after the walk-through');
    console.log('log', JSON.stringify(log));
    if (failures.length) { console.log('FAIL', JSON.stringify(failures)); process.exitCode = 1; } else { console.log('PASS'); }
  } finally {
    try { if (browser) await Promise.race([browser.close(), new Promise(r => setTimeout(r, 5000))]); } catch (e) {}
    try { const p = browser && browser.process && browser.process(); if (p && !p.killed) p.kill('SIGKILL'); } catch (e) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) {}
  }
})();
