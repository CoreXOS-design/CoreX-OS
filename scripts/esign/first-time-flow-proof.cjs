// usage: node flow2.cjs <base> <token> <out> <width> <mode: saved|each>
const puppeteer = require('puppeteer');
const fs = require('fs'), os = require('os'), path = require('path');
const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const [base, token, out, widthArg, mode] = process.argv.slice(2);
  const width = parseInt(widthArg, 10);
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'corex-ui-profile-'));
  let browser;
  try {
    browser = await puppeteer.launch({ headless: 'new', userDataDir: profile, executablePath: '/usr/bin/chromium',
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--disable-crash-reporter', '--disable-breakpad'] });
    const page = await browser.newPage();
    await page.setViewport({ width, height: width < 600 ? 800 : 900, isMobile: width < 600, hasTouch: width < 600 });
    const log = [];
    page.on('pageerror', e => log.push('PAGEERROR ' + e.message.slice(0, 200)));
    page.on('dialog', async d => { log.push('DIALOG ' + d.message().slice(0, 50)); await d.dismiss(); });
    page.on('response', r => { if (/\/sign\/[^/]+\/(adopt|place|place-all|place\/remove)$/.test(r.url())) log.push('HTTP ' + r.url().split('/sign/')[1].replace(/^[^/]+/, '') + ' ' + r.status()); });
    await page.goto(base + '/sign/' + token, { waitUntil: 'networkidle2', timeout: 60000 });
    await sleep(1200);
    const choose = await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /Sign Electronically/.test(x.textContent)); if (b && b.getBoundingClientRect().width > 0) { b.click(); return true; } return false; });
    if (choose) await sleep(3500);
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
    await page.evaluate(() => { const c = document.getElementById('consent-checkbox'); if (c && !c.checked) c.click(); }); await sleep(800);
    await snap('9 consent ticked', 'final');
    console.log('log', JSON.stringify(log));
  } finally {
    try { if (browser) await Promise.race([browser.close(), new Promise(r => setTimeout(r, 5000))]); } catch (e) {}
    try { const p = browser && browser.process && browser.process(); if (p && !p.killed) p.kill('SIGKILL'); } catch (e) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) {}
  }
})();
