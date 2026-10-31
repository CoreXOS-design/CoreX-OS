#!/usr/bin/env node
/**
 * AT-525 - the training-video RECORDER (spec .ai/specs/training-videos.md section 9.5).
 * Opens one screen in headless Chromium as the training user (session cookie handed in), plays a script's steps (spotlight + cursor + real click / typing),
 * records the screen with page.screencast, and writes a timeline so the voice-over can be placed exactly. Never decides anything itself: every duration comes
 * from the job file (the length of each spoken line was measured BEFORE this runs).
 *
 *   node scripts/training-video-record.mjs <job.json>
 * job.json: { baseUrl, url, cookie:{name,value}, chromium, viewport:{width,height}, out, timeline, intro:{title,subtitle,seconds}|null, outro:{...}|null,
 *             steps:[{selector,action,value,seconds}], probe:bool }
 * Writes <out> (mp4/webm via ffmpeg) and <timeline> {recording_started, intro:{start,end}, steps:[{i,start,end,found,error}], outro:{start,end}, total}.
 * With "probe": true it only checks every selector exists (no recording) and writes the list of missing ones to <timeline>.
 */
import fs from 'node:fs';
import puppeteer from 'puppeteer-core';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const t0 = Date.now();
const now = () => (Date.now() - t0) / 1000;
const log = (m) => console.log(`[${now().toFixed(1)}s] ${m}`);

const browser = await puppeteer.launch({ executablePath: job.chromium, headless: true, args: ['--no-sandbox', '--disable-gpu', '--hide-scrollbars'] });
const page = await browser.newPage();
await page.setViewport({ width: job.viewport.width, height: job.viewport.height, deviceScaleFactor: 1 });
await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);   // readable on every screen, whatever the training user's OS theme
const host = new URL(job.baseUrl).hostname;
await page.setCookie({ name: job.cookie.name, value: job.cookie.value, domain: host, path: '/', httpOnly: true, secure: job.baseUrl.startsWith('https') });
const resp = await page.goto(job.baseUrl.replace(/\/$/, '') + job.url, { waitUntil: 'networkidle2', timeout: 90000 });
log(`opened ${job.url} -> ${resp.status()} ${page.url()}`);
if (page.url().includes('/login')) { console.error('NOT SIGNED IN: the training session was refused'); await browser.close(); process.exit(3); }

// ── helpers injected into the page ──────────────────────────────────────────────────────────────────────────────
const inject = () => page.evaluate(() => {
  if (window.__tvCard) return;
  const st = document.createElement('style');
  st.textContent = `
    .__tv-spot { position: relative !important; z-index: 2147483000 !important; outline: 4px solid #00b4d8 !important; outline-offset: 5px !important; border-radius: 8px; box-shadow: 0 0 0 100vmax rgba(8,18,38,.55) !important; transition: box-shadow .3s; }
    #__tv-cursor { position: fixed; z-index: 2147483600; width: 22px; height: 22px; margin: -4px 0 0 -4px; border-radius: 50%; background: rgba(0,180,216,.35); border: 3px solid #00b4d8; pointer-events: none; transition: left .6s ease, top .6s ease; left: 50%; top: 60%; }
    #__tv-cursor.click { animation: __tv-click .45s ease-out; }
    @keyframes __tv-click { 0% { transform: scale(1); } 40% { transform: scale(.6); } 100% { transform: scale(1.6); opacity: .3; } }
    #__tv-card { position: fixed; inset: 0; z-index: 2147483647; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px; background: linear-gradient(135deg, #0b2a4a, #0e5a7a); color: #fff; font-family: system-ui, sans-serif; text-align: center; padding: 60px; }
    #__tv-card h1 { font-size: 46px; margin: 0; line-height: 1.15; max-width: 980px; } #__tv-card p { font-size: 22px; margin: 0; opacity: .85; max-width: 900px; }
    #__tv-card .brand { font-size: 18px; letter-spacing: .2em; text-transform: uppercase; opacity: .7; }`;
  document.head.appendChild(st);
  const c = document.createElement('div'); c.id = '__tv-cursor'; document.body.appendChild(c);
  window.__tvCard = (title, subtitle) => { let d = document.getElementById('__tv-card'); if (!d) { d = document.createElement('div'); d.id = '__tv-card'; document.body.appendChild(d); } d.innerHTML = `<div class="brand">CoreX OS · How-to</div><h1></h1><p></p>`; d.querySelector('h1').textContent = title || ''; d.querySelector('p').textContent = subtitle || ''; };
  window.__tvCardOff = () => { const d = document.getElementById('__tv-card'); if (d) d.remove(); };
  window.__tvSpot = (sel) => { document.querySelectorAll('.__tv-spot').forEach((e) => e.classList.remove('__tv-spot')); if (!sel) return null; const e = document.querySelector(sel); if (!e) return null; e.scrollIntoView({ block: 'center', behavior: 'instant' }); e.classList.add('__tv-spot'); const r = e.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; };
  window.__tvMove = (x, y) => { const c = document.getElementById('__tv-cursor'); c.style.left = x + 'px'; c.style.top = y + 'px'; };
  window.__tvClickFx = () => { const c = document.getElementById('__tv-cursor'); c.classList.remove('click'); void c.offsetWidth; c.classList.add('click'); };
});
await inject();

// A recording shows the SCREEN, not the environment around it: dismiss the app's own first-visit guided tour (it would sit on top of everything) and hide the
// test-site banners that QA/Staging put above the page.
async function tidy() {
  await page.addStyleTag({ content: '.driver-popover, .driver-overlay, .driver-stage { display: none !important; }' }).catch(() => {});
  await page.evaluate(() => {
    const cb = document.querySelector('.driver-popover input[type=checkbox]'); if (cb && !cb.checked) cb.click();
    const btn = [...document.querySelectorAll('.driver-popover button')].find((b) => /close tour/i.test(b.textContent)); if (btn) btn.click();
    document.querySelectorAll('body *').forEach((e) => { const t = (e.textContent || '').trim(); if (e.offsetHeight > 0 && e.offsetHeight < 60 && e.children.length < 4 && (/^QA ·|^STAGING ·|This is a test site/i.test(t.slice(0, 40)) )) e.style.display = 'none'; });
  }).catch(() => {});
}
await sleep(2500);
await tidy();

if (job.probe) {
  const missing = [];
  for (const [i, s] of job.steps.entries()) {
    if (!s.selector) continue;
    const ok = await page.$(s.selector).then((h) => !!h).catch(() => false);
    if (!ok) missing.push({ step: i + 1, selector: s.selector });
  }
  fs.writeFileSync(job.timeline, JSON.stringify({ probe: true, missing }, null, 2));
  log(`probe: ${missing.length} selector(s) missing`);
  await browser.close();
  process.exit(missing.length ? 4 : 0);
}

const timeline = { recording_started: null, intro: null, steps: [], outro: null, total: 0 };
// Frames are captured through the DevTools protocol WITH THEIR REAL TIMESTAMPS and assembled at true timing. (page.screencast encodes frames as they arrive and
// drops frames when the page is busy, which made the video run about 16% faster than the clock the timeline and the voice-over use.)
const framesDir = path.join(path.dirname(job.out), 'frames');
fs.mkdirSync(framesDir, { recursive: true });
const frames = [];
const cdp = await page.createCDPSession();
cdp.on('Page.screencastFrame', async ({ data, metadata, sessionId }) => {
  const n = frames.length;
  fs.writeFileSync(path.join(framesDir, `f${String(n).padStart(6, '0')}.jpg`), Buffer.from(data, 'base64'));
  frames.push(metadata.timestamp);
  await cdp.send('Page.screencastFrameAck', { sessionId }).catch(() => {});
});
const r0 = Date.now(); const at = () => (Date.now() - r0) / 1000;
await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 85, maxWidth: job.viewport.width, maxHeight: job.viewport.height, everyNthFrame: 1 });
const rec = {
  stop: async () => {
    await cdp.send('Page.stopScreencast').catch(() => {});
    const end = Date.now() / 1000;
    if (!frames.length) throw new Error('the screencast produced no frames');
    const start = r0 / 1000;
    const lines = [];
    frames.forEach((t, i) => {
      const from = i === 0 ? start : t;                       // the first frame also covers the time before it arrived
      const to = i + 1 < frames.length ? frames[i + 1] : end;
      lines.push(`file 'f${String(i).padStart(6, '0')}.jpg'`, `duration ${Math.max(0.001, to - from).toFixed(4)}`);
    });
    lines.push(`file 'f${String(frames.length - 1).padStart(6, '0')}.jpg'`);   // concat quirk: the last file is repeated
    fs.writeFileSync(path.join(framesDir, 'frames.txt'), lines.join('\n'));
    const r = spawnSync('ffmpeg', ['-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', path.join(framesDir, 'frames.txt'), '-vf', 'fps=25,format=yuv420p', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', job.out], { encoding: 'utf8' });
    if (r.status !== 0) throw new Error('ffmpeg could not assemble the frames: ' + r.stderr);
    fs.rmSync(framesDir, { recursive: true, force: true });
  },
};
timeline.recording_started = new Date(r0).toISOString();
log('recording');

async function card(c) {
  if (!c) return null;
  const start = at();
  await page.evaluate((t, s) => window.__tvCard(t, s), c.title, c.subtitle || '');
  await sleep(c.seconds * 1000);
  await page.evaluate(() => window.__tvCardOff());
  return { start, end: at() };
}
timeline.intro = await card(job.intro);

for (const [i, s] of job.steps.entries()) {
  const start = at(); const stepEnd = start + s.seconds;
  const rec1 = { i, start, end: null, found: true, error: null };
  try {
    let pt = null;
    if (s.selector) {
      await page.waitForSelector(s.selector, { timeout: 8000 }).catch(() => { rec1.found = false; });
      if (rec1.found) pt = await page.evaluate((sel) => window.__tvSpot(sel), s.selector);
      if (pt) { await page.evaluate((x, y) => window.__tvMove(x, y), pt.x, pt.y); await sleep(700); }
    }
    if (rec1.found) {
      if (s.action === 'click' && pt) { await page.evaluate(() => window.__tvClickFx()); await sleep(250); await page.click(s.selector).catch(async () => { await page.evaluate((sel) => document.querySelector(sel)?.click(), s.selector); }); }
      else if (s.action === 'type' && pt) { await page.click(s.selector).catch(() => {}); await page.type(s.selector, s.value || '', { delay: 70 }); }
      else if (s.action === 'select' && pt) { await page.select(s.selector, s.value); }
      else if (s.action === 'press') { await page.keyboard.press(s.value); }
      else if (s.action === 'goto') { await page.goto(job.baseUrl.replace(/\/$/, '') + s.value, { waitUntil: 'networkidle2', timeout: 60000 }); await inject(); await tidy(); }
    }
    // a click on a link navigates: wait for the new page to settle, then put the spotlight / cursor helpers back (they live in the page that was replaced)
    if (s.action === 'click') {
      await sleep(900);
      await page.waitForNetworkIdle({ idleTime: 600, timeout: 20000 }).catch(() => {});
      if (!(await page.evaluate(() => typeof window.__tvSpot === 'function').catch(() => false))) { await inject(); await tidy(); }
    }
  } catch (e) { rec1.error = String(e.message || e).slice(0, 200); log(`step ${i + 1} error: ${rec1.error}`); }
  const left = stepEnd - at();
  if (left > 0) await sleep(left * 1000);
  await page.evaluate(() => window.__tvSpot(null)).catch(() => {});
  rec1.end = at();
  timeline.steps.push(rec1);
  log(`step ${i + 1}/${job.steps.length} ${s.action} ${rec1.found ? 'ok' : 'NOT FOUND'} (${(rec1.end - rec1.start).toFixed(1)}s)`);
}
await inject().catch(() => {}); await tidy();   // a click may have navigated: make sure the helpers exist for the outro card
timeline.outro = await card(job.outro);
timeline.total = at();
await rec.stop();
fs.writeFileSync(job.timeline, JSON.stringify(timeline, null, 2));
log(`done, ${timeline.total.toFixed(1)}s`);
await browser.close();
