#!/usr/bin/env node
/**
 * AT-452 — headless-browser check of the shared photo viewer on the staff fault page and BOTH portals, at 390 / 1280 / 1920 px.
 * .ai/specs/rentals-faults-work-orders.md §18.7. A real Chromium drives the REAL pages served by nginx from QA1:
 *   - the staff fault page: the real HTML fetched through nginx/php-fpm as an agent (scripts/fetch-authenticated-page.php) is handed to the
 *     browser for the real page URL; the three mark/note save calls are answered by a stand-in so nothing on QA1 changes, and every request
 *     the viewer sends is captured and checked (shape, 0..1 coordinates);
 *   - the owner and tenant portals: the portal's own API calls are answered with the REAL API answers captured for the test fault
 *     (/tmp/at452/fixture.php `dump`).
 * Scratchpad inputs only; nothing here writes to QA1. Zero console errors is the pass mark; a 200 is never treated as a pass signal.
 *
 *   node scripts/photo-viewer-browser-check.mjs --staff-html=/tmp/at452/staff-92.html --staff-url=https://…/corex/rental-fault-reports/92 \
 *        --api-off=/tmp/at452/api-off.json --api-on=/tmp/at452/api-on.json --out=/tmp/at452/shots --fault=91
 */
import fs from 'node:fs';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || '').split('=').slice(1).join('=') || d;
const STAFF_HTML = arg('staff-html', '/tmp/at452/staff-92.html');
const STAFF_URL = arg('staff-url', 'https://qatesting1.corexos.co.za/corex/rental-fault-reports/92');
const API_OFF = JSON.parse(fs.readFileSync(arg('api-off', '/tmp/at452/api-off.json'), 'utf8'));
const API_ON = JSON.parse(fs.readFileSync(arg('api-on', '/tmp/at452/api-on.json'), 'utf8'));
const OUT = arg('out', '/tmp/at452/shots');
const BASE = new URL(STAFF_URL).origin;
const WIDTHS = [[390, 844], [1280, 900], [1920, 1080]];
fs.mkdirSync(OUT, { recursive: true });

const results = [];
const note = (width, area, name, ok, detail = '') => { results.push({ width, area, name, ok: !!ok, detail }); console.log(`${ok ? '  ok  ' : '  FAIL'} [${width}] ${area}: ${name}${detail ? '  — ' + detail : ''}`); };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu'] });

async function newPage(width, height, errors) {
  const page = await browser.newPage();
  await page.setViewport({ width, height, deviceScaleFactor: 1 });
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource: the server responded with a status of 401/.test(m.text())) errors.push(m.text()); });   // 401s = this harness browser has no staff login
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  return page;
}
const viewerOpen = (page) => page.evaluate(() => !!document.querySelector('[data-photo-viewer]:not([hidden])'));
const counter = (page) => page.evaluate(() => (document.querySelector('[data-pv-count]') || {}).textContent || '');
const marksCount = (page) => page.evaluate(() => document.querySelectorAll('[data-pv-overlay] g[data-mark-id]').length);
const noteText = (page) => page.evaluate(() => { const t = document.querySelector('[data-pv-note-text]'); const i = document.querySelector('[data-pv-note-input]'); return t ? t.textContent : (i ? i.value : ''); });
const figRect = (page) => page.evaluate(() => { const r = document.querySelector('.cxpv-fig').getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; });
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
const inside = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e || e.hidden) return false; const r = e.getBoundingClientRect(); return r.width > 0 && r.left >= -1 && r.right <= window.innerWidth + 1 && r.top >= -1 && r.bottom <= window.innerHeight + 1; }, sel);
const settle = () => wait(350);

/** The common viewer behaviour, run on whichever page opened it. `edit` adds the staff-only drawing checks. */
async function exerciseViewer(page, w, area, { total, firstMarks, firstNote, edit, shot }) {
  note(w, area, 'opens as a popup over the page, not a new tab', await viewerOpen(page) && (await browser.pages()).length === 2 /* about:blank + this page */);
  note(w, area, `counter reads "1 of ${total}"`, (await counter(page)) === `1 of ${total}`, await counter(page));
  note(w, area, `agent marks drawn on the first photo (${firstMarks})`, (await marksCount(page)) === firstMarks, String(await marksCount(page)));
  if (firstNote !== undefined) note(w, area, 'description shown under the photo', (await noteText(page)).includes(firstNote), (await noteText(page)).slice(0, 60));
  const fr = await figRect(page);
  note(w, area, 'photo fits inside the screen', fr.w > 50 && fr.x >= -1 && fr.x + fr.w <= w + 1, JSON.stringify(fr));
  note(w, area, 'every control is on screen (close, zoom, tick, next)', (await inside(page, '[data-pv-close]')) && (await inside(page, '[data-pv-zoom-in]')) && (await inside(page, '[data-pv-marks-toggle]')) && (await inside(page, '[data-pv-next]')), '');
  note(w, area, 'no sideways page scroll with the viewer open', await noSideScroll(page));
  await page.screenshot({ path: `${OUT}/${shot}-viewer.png` });

  // next / previous by keys and buttons
  await page.keyboard.press('ArrowRight'); await settle();
  note(w, area, 'ArrowRight goes to "2 of N"', (await counter(page)) === `2 of ${total}`, await counter(page));
  await page.click('[data-pv-prev]'); await settle();
  note(w, area, 'previous button goes back to "1 of N"', (await counter(page)) === `1 of ${total}`, await counter(page));
  await page.click('[data-pv-next]'); await settle();
  await page.keyboard.press('ArrowLeft'); await settle();
  note(w, area, 'ArrowLeft goes back', (await counter(page)) === `1 of ${total}`);

  // swipe: a horizontal drag at 100%
  const r0 = await figRect(page);
  await page.mouse.move(r0.x + r0.w * 0.8, r0.y + 8); await page.mouse.down(); await page.mouse.move(r0.x + r0.w * 0.2, r0.y + 12, { steps: 6 }); await page.mouse.up(); await settle();
  note(w, area, 'swiping left goes to the next photo', (await counter(page)) === `2 of ${total}`, await counter(page));
  await page.keyboard.press('ArrowLeft'); await settle();

  // zoom: button, wheel, reset
  await page.click('[data-pv-zoom-in]'); await settle();
  const z1 = await page.evaluate(() => document.querySelector('[data-pv-zoom-reset]').textContent);
  note(w, area, 'zoom in raises the zoom', z1 === '150%', z1);
  const c = await figRect(page);
  await page.mouse.move(c.x + c.w / 2, c.y + c.h / 2); await page.mouse.wheel({ deltaY: -300 }); await settle();
  const z2 = await page.evaluate(() => parseInt(document.querySelector('[data-pv-zoom-reset]').textContent, 10));
  note(w, area, 'the mouse wheel zooms', z2 > 150, String(z2));
  // pan: drag while zoomed moves the picture, it does not change photo
  const before = await figRect(page);
  const stage = await page.evaluate(() => { const r = document.querySelector('[data-pv-stage]').getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; });
  const cx = stage.x + stage.w / 2, cy = stage.y + stage.h / 2;
  await page.mouse.move(cx, cy); await page.mouse.down(); await page.mouse.move(cx - 40, cy - 30, { steps: 4 }); await page.mouse.up(); await settle();
  const after = await figRect(page);
  note(w, area, 'dragging while zoomed pans (and stays on the same photo)', (Math.abs(after.x - before.x) > 5 || Math.abs(after.y - before.y) > 5) && (await counter(page)) === `1 of ${total}`);
  await page.click('[data-pv-zoom-reset]'); await settle();
  note(w, area, 'reset returns to 100%', (await page.evaluate(() => document.querySelector('[data-pv-zoom-reset]').textContent)) === '100%');
  await page.keyboard.press('+'); await settle(); await page.keyboard.press('-'); await settle(); await page.keyboard.press('0'); await settle();

  // the tick
  const hadNote = firstNote !== undefined;
  await page.click('[data-pv-marks-toggle]'); await settle();
  note(w, area, 'tick off: the photo shows exactly as sent (no marks)', (await marksCount(page)) === 0);
  note(w, area, 'tick off: the description is hidden', !hadNote || (await noteText(page)) === '');
  await page.click('[data-pv-marks-toggle]'); await settle();
  note(w, area, 'tick on again: marks come back', (await marksCount(page)) === firstMarks);

  if (edit) await edit(page);

  // focus trap: Tab many times, focus never leaves the viewer
  let leaked = false;
  for (let i = 0; i < 25; i++) { await page.keyboard.press('Tab'); if (!(await page.evaluate(() => !!document.activeElement.closest('[data-photo-viewer]')))) { leaked = true; break; } }
  note(w, area, 'focus is trapped inside the viewer', !leaked);
}

async function closeChecks(page, w, area, opener) {
  await page.keyboard.press('Escape'); await settle();
  note(w, area, 'Esc closes', !(await viewerOpen(page)));
  const back = await page.evaluate((sel) => document.activeElement && document.activeElement.matches(sel), opener);
  note(w, area, 'focus goes back to the photo that was clicked', back);
  await page.click(opener); await settle();
  await page.click('[data-pv-close]'); await settle();
  note(w, area, 'the X closes', !(await viewerOpen(page)));
  await page.click(opener); await settle();
  const sb = await page.evaluate(() => { const r = document.querySelector('[data-pv-stage]').getBoundingClientRect(); return { x: r.left, y: r.top }; });
  await page.mouse.click(sb.x + 3, sb.y + 3); await settle();   // the dark backdrop around the picture
  note(w, area, 'a click on the backdrop closes', !(await viewerOpen(page)));
}

// ───────────────────────────────────── staff page ─────────────────────────────────────
async function staff(w, h) {
  const errors = [];
  const page = await newPage(w, h, errors);
  const calls = [];
  await page.setRequestInterception(true);
  let nextId = 9000;
  page.on('request', (req) => {
    const u = new URL(req.url());
    if (req.url() === STAFF_URL && req.method() === 'GET') return req.respond({ status: 200, contentType: 'text/html; charset=utf-8', body: fs.readFileSync(STAFF_HTML, 'utf8') });
    if (u.pathname.startsWith('/corex/rental-fault-reports/') && /\/photos\/\d+\/(marks|note)/.test(u.pathname) && req.method() !== 'GET') {
      let body = {}; try { body = JSON.parse(req.postData() || '{}'); } catch (e) { /* none */ }
      calls.push({ method: req.method(), path: u.pathname, body });
      const json = (status, o) => req.respond({ status, contentType: 'application/json', body: JSON.stringify(o) });
      if (u.pathname.endsWith('/note')) return req.method() === 'PUT' ? json(200, { note: { text: body.description, by: 'Test agent', at: new Date().toISOString() } }) : json(200, { removed: true });
      if (u.pathname.endsWith('/restore')) return json(200, { mark: { id: Number(u.pathname.split('/').slice(-2)[0]), shape: 'circle', geometry: { cx: 0.5, cy: 0.5, r: 0.1 }, colour: '#e11d48', by: 'Test agent', at: new Date().toISOString() } });
      if (req.method() === 'POST') return json(201, { mark: { id: nextId++, ...body, by: 'Test agent', at: new Date().toISOString() } });
      if (req.method() === 'PUT') return json(200, { mark: { id: Number(u.pathname.split('/').pop()), ...body, by: 'Test agent', at: new Date().toISOString() } });
      return json(200, { removed: true });
    }
    // this browser has no staff session: the page's own background calls (connection ping, reminder poll …) would answer 401 and its
    // connection guard would then refuse to save anything ("offline") - answer them as a logged-in browser would get them
    if (u.pathname.startsWith('/api/v1/') && req.method() === 'GET') return req.respond({ status: 200, contentType: 'application/json', body: '{"data":[],"reminders":[],"token":"x"}' });
    return req.continue();
  });
  await page.goto(STAFF_URL, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  await wait(800);
  const area = 'staff fault page';
  note(w, area, 'page rendered (owner-version card present)', await page.evaluate(() => !!document.getElementById('owner-version-card')));
  note(w, area, 'item 9: "No contractor loaded for …" warning shown above the buttons', await page.evaluate(() => { const e = document.querySelector('[data-no-contractor-warning]'); return !!e && /No contractor loaded for .+\./.test(e.textContent) && !!e.querySelector('[data-no-contractor-add]') && !!e.querySelector('[data-no-contractor-types]'); }));
  note(w, area, 'item 9: both controls lead to the Supplier Directory with a way back', await page.evaluate(() => [...document.querySelectorAll('[data-no-contractor-add],[data-no-contractor-types]')].every((a) => /deals-v2\/suppliers\?fault=\d+&mode=(add|types)/.test(a.getAttribute('href')))));
  await page.screenshot({ path: `${OUT}/staff-${w}-page.png` });

  // the Share ticks: clicking a thumbnail opens the viewer and does NOT tick the box
  const ticksBefore = await page.evaluate(() => [...document.querySelectorAll('input[name="owner_photo_ids[]"]')].map((c) => c.checked).join());
  await page.click('#owner-version-card [data-open-photo]'); await settle();
  const ticksAfter = await page.evaluate(() => [...document.querySelectorAll('input[name="owner_photo_ids[]"]')].map((c) => c.checked).join());
  note(w, area, 'a Share thumbnail opens the viewer and leaves its tick alone', (await viewerOpen(page)) && ticksBefore === ticksAfter);
  await exerciseViewer(page, w, area, {
    total: 3, firstMarks: 2, firstNote: 'water is coming through', shot: `staff-${w}`,
    edit: async (p) => {
      note(w, area, 'the marks tools are offered to an editor', await p.evaluate(() => !!document.querySelector('[data-pv-tools]:not([hidden])')));
      // draw a circle
      await p.click('[data-pv-tool="circle"]'); await settle();
      const r = await figRect(p);
      await p.mouse.move(r.x + r.w * 0.25, r.y + r.h * 0.75); await p.mouse.down(); await p.mouse.move(r.x + r.w * 0.25 + 70, r.y + r.h * 0.75 + 20, { steps: 5 }); await p.mouse.up(); await wait(500);
      const post = calls.filter((c) => c.method === 'POST' && c.path.endsWith('/marks')).pop();
      note(w, area, 'drawing a circle saves shape + normalised 0..1 coordinates', post && post.body.shape === 'circle' && post.body.geometry.cx > 0 && post.body.geometry.cx < 1 && post.body.geometry.r > 0 && post.body.geometry.r < 1, post ? JSON.stringify(post.body) : 'no call');
      note(w, area, 'the new mark is drawn (3 now)', (await marksCount(p)) === 3, String(await marksCount(p)));
      // draw an arrow
      await p.click('[data-pv-tool="arrow"]'); await settle();
      await p.mouse.move(r.x + r.w * 0.6, r.y + r.h * 0.8); await p.mouse.down(); await p.mouse.move(r.x + r.w * 0.8, r.y + r.h * 0.6, { steps: 5 }); await p.mouse.up(); await wait(500);
      const ap = calls.filter((c) => c.method === 'POST' && c.path.endsWith('/marks')).pop();
      note(w, area, 'drawing an arrow saves x1,y1,x2,y2', ap && ap.body.shape === 'arrow' && ['x1', 'y1', 'x2', 'y2'].every((k) => ap.body.geometry[k] >= 0 && ap.body.geometry[k] <= 1), ap ? JSON.stringify(ap.body) : 'no call');
      // move the arrow (select tool; it is selected right after drawing)
      await p.click('[data-pv-tool="select"]'); await settle();
      const mid = { x: r.x + r.w * 0.7, y: r.y + r.h * 0.7 };
      await p.mouse.move(mid.x, mid.y); await p.mouse.down(); await p.mouse.move(mid.x - 40, mid.y - 30, { steps: 5 }); await p.mouse.up(); await wait(500);
      const put = calls.filter((c) => c.method === 'PUT' && /\/marks\/\d+$/.test(c.path)).pop();
      note(w, area, 'dragging a mark moves it (saved with new coordinates)', !!put && put.body.shape === 'arrow', put ? JSON.stringify(put.body.geometry) : 'no call');
      // colour change + remove + undo
      await p.click('[data-pv-colour="#16a34a"]'); await wait(400);
      note(w, area, 'a mark\'s colour can be changed', calls.filter((c) => c.method === 'PUT' && /\/marks\/\d+$/.test(c.path) && c.body.colour === '#16a34a').length > 0);
      const nBefore = await marksCount(p);
      await p.click('[data-pv-remove-mark]'); await wait(400);
      note(w, area, 'Remove mark takes the selected mark off', (await marksCount(p)) === nBefore - 1 && calls.some((c) => c.method === 'DELETE' && /\/marks\/\d+$/.test(c.path)));
      note(w, area, 'Undo remove is offered, and brings it back', await p.evaluate(() => !document.querySelector('[data-pv-undo-remove]').hidden));
      await p.click('[data-pv-undo-remove]'); await wait(500);
      note(w, area, 'after Undo the mark is back', (await marksCount(p)) === nBefore && calls.some((c) => c.path.endsWith('/restore')));
      // description
      await p.focus('[data-pv-note-input]'); await p.evaluate(() => { document.querySelector('[data-pv-note-input]').value = ''; });
      await p.keyboard.type('Check the pipe behind the wall'); await p.click('[data-pv-note-save]'); await wait(500);
      const np = calls.filter((c) => c.method === 'PUT' && c.path.endsWith('/note')).pop();
      note(w, area, 'a description is saved per photo', !!np && np.body.description === 'Check the pipe behind the wall', np ? JSON.stringify(np.body) : 'no call');
      await p.screenshot({ path: `${OUT}/staff-${w}-edit.png` });
    },
  });
  await closeChecks(page, w, area, '#owner-version-card [data-open-photo]');
  // the side Photos block opens it too
  const sideOpener = 'a-side';
  await page.evaluate(() => { const b = [...document.querySelectorAll('[data-open-photo]')].pop(); b.setAttribute('data-test-side', '1'); });
  await page.click('[data-test-side]'); await settle();
  note(w, area, 'the side Photos block opens the viewer too', await viewerOpen(page));
  await page.keyboard.press('Escape');
  note(w, area, 'no browser dialog was shown and the page had no console errors', errors.length === 0, errors.slice(0, 3).join(' | '));
  await page.close();
}

// ───────────────────────────────────── portals ─────────────────────────────────────
async function portal(w, h, role, api, variant) {
  const errors = [];
  const page = await newPage(w, h, errors);
  const clientId = api.api['client/me'].body.client.id;
  await page.evaluateOnNewDocument((id, r) => { try { localStorage.setItem('portal.role.' + id, r); } catch (e) { /* none */ } }, clientId, role);
  await page.setRequestInterception(true);
  const missed = [];
  page.on('request', (req) => {
    const u = new URL(req.url());
    if (u.pathname.startsWith('/api/v1/')) {
      const k = u.pathname.replace('/api/v1/', '').replace(/^client\/rentals\//, 'rentals/');
      const hit = api.api[k];
      if (hit) return req.respond({ status: hit.status, contentType: 'application/json', body: JSON.stringify(hit.body).replaceAll('http://localhost', BASE) });
      missed.push(u.pathname);
      return req.respond({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], documents: [], work_orders: [], fault_reports: [], decisions: [] }) });
    }
    return req.continue();
  });
  await page.goto(BASE + '/portal', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  await wait(1200);
  const clickText = (sel, text) => page.evaluate((s, t) => { const b = [...document.querySelectorAll(s)].filter((x) => x.offsetParent !== null).find((x) => x.textContent.trim().startsWith(t)); if (b) { b.click(); return true; } return false; }, sel, text);
  await clickText('button', 'Faults'); await wait(1200);

  if (role === 'landlord') {
    const area = 'owner portal';
    note(w, area, '"Review and decide" is offered for the waiting fault', await clickText('button', 'Review and decide'));
    await wait(1000);
    note(w, area, 'the decision screen opened', await page.evaluate(() => !!document.querySelector('[data-fault-detail]')));
    const secs = await page.evaluate(() => [...document.querySelectorAll('[data-fault-section]')].map((s) => ({ key: s.getAttribute('data-fault-section'), head: (s.querySelector('h3') || {}).textContent, border: getComputedStyle(s).borderTopWidth + ' ' + getComputedStyle(s).borderTopStyle, helperParas: [...s.querySelectorAll('p.muted')].length })));
    note(w, area, 'sections in order: Progress; What the tenant reported; Photos; What your agent says; Your decision', JSON.stringify(secs.map((s) => s.head)) === JSON.stringify(['Progress', 'What the tenant reported', 'Photos', 'What your agent says', 'Your decision']), JSON.stringify(secs.map((s) => s.head)));
    note(w, area, 'every section has a dividing line', secs.length === 5 && secs.every((s) => /^1px solid/.test(s.border)), JSON.stringify(secs.map((s) => s.border)));
    await page.evaluate(() => { const s = document.querySelector('[data-fault-section="decision"] select'); s.value = 'approve'; s.dispatchEvent(new Event('change', { bubbles: true })); s.dispatchEvent(new Event('input', { bubbles: true })); });
    await wait(500);
    const note1 = await page.evaluate(() => { const e = document.querySelector('[data-no-contractor-note]'); return e ? { shown: e.offsetParent !== null, text: e.textContent } : null; });
    note(w, area, 'the owner-side "no contractor on file" note still shows (nothing is loaded at the moment)', !!note1 && note1.shown && /no contractor on file/.test(note1.text), JSON.stringify(note1));
    await page.screenshot({ path: `${OUT}/owner-${w}-decision.png`, fullPage: true });
    await page.click('[data-fault-detail] .photo-btn'); await settle();
    // the owner sees only the 2 shared photos; marks + notes on them; no editing tools
    await exerciseViewer(page, w, area, { total: 2, firstMarks: 2, firstNote: 'water is coming through', shot: `owner-${w}` });
    note(w, area, 'no marks tools for an owner', await page.evaluate(() => document.querySelector('[data-pv-tools]').hidden));
    note(w, area, 'the "Agent\'s marks and notes" tick is there and on by default', await page.evaluate(() => { const c = document.querySelector('[data-pv-marks-toggle]'); return !!c && c.checked; }));
    await closeChecks(page, w, area, '[data-fault-detail] .photo-btn');
  } else {
    const area = `tenant portal (${variant})`;
    const mine = await page.evaluate(() => { const c = [...document.querySelectorAll('[data-tenant-fault]')].find((x) => x.textContent.includes('AT-452 TEST - photo viewer')); if (!c) return { cards: document.querySelectorAll('[data-tenant-fault]').length, photos: -1 }; const b = c.querySelectorAll('[data-tenant-fault-photos] .photo-btn'); b.forEach((x, i) => x.setAttribute('data-test-photo', String(i))); return { photos: b.length }; });
    note(w, area, 'the fault card shows the tenant\'s own 3 photos', mine.photos === 3, JSON.stringify(mine));
    await page.screenshot({ path: `${OUT}/tenant-${variant}-${w}-faults.png`, fullPage: true });
    await page.click('[data-test-photo="0"]'); await settle();
    const expectMarks = variant === 'marks-on' ? 2 : 0;
    await exerciseViewer(page, w, area, { total: 3, firstMarks: expectMarks, firstNote: variant === 'marks-on' ? 'water is coming through' : undefined, shot: `tenant-${variant}-${w}` });
    note(w, area, variant === 'marks-on' ? 'tenant sees the agent\'s marks (agency allows it)' : 'tenant does NOT see the agent\'s marks or notes (default)', (await page.evaluate(() => document.querySelectorAll('[data-pv-overlay] g').length)) === expectMarks && (variant === 'marks-on' || (await noteText(page)) === ''));
    await closeChecks(page, w, area, '[data-test-photo="0"]');
    if (variant === 'marks-off') {
      // "Try this first"
      await clickText('button', 'Report a fault'); await wait(800);
      await page.evaluate(() => { const s = document.querySelector('[data-fault-wizard] select'); const o = [...s.options].find((x) => x.value); s.value = o.value; s.dispatchEvent(new Event('change', { bubbles: true })); });
      await wait(800);
      const aid = await page.evaluate(() => {
        const q = (s) => document.querySelector(s); const box = (e) => { if (!e) return null; const r = e.getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; };
        const btns = [...document.querySelectorAll('[data-fault-aid-buttons] .btn')];
        return {
          safety: q('[data-fault-aid-safety]') ? { text: q('[data-fault-aid-safety]').textContent.trim(), icon: !!q('[data-fault-aid-safety] svg'), bg: getComputedStyle(q('[data-fault-aid-safety]')).backgroundColor } : null,
          heading: [...document.querySelectorAll('[data-fault-aid-step] h2')].map((h) => h.tagName + ':' + h.textContent.trim()),
          listItems: [...document.querySelectorAll('[data-fault-aid-steps] li')].map((l) => l.textContent.trim()),
          listBg: q('[data-fault-aid-steps]') ? getComputedStyle(q('[data-fault-aid-steps]')).backgroundColor : null,
          listTag: q('[data-fault-aid-steps]') ? q('[data-fault-aid-steps]').tagName : null,
          buttons: btns.map((b) => ({ text: b.textContent.trim(), ...box(b), bg: getComputedStyle(b).backgroundColor })),
        };
      });
      const area2 = 'tenant "Try this first"';
      note(w, area2, 'the safety line is in its own warning box with an icon', !!aid.safety && aid.safety.icon && /call emergency services/.test(aid.safety.text), aid.safety && aid.safety.bg);
      note(w, area2, '"Try this first" is a real heading', aid.heading.includes('H2:Try this first'), JSON.stringify(aid.heading));
      note(w, area2, 'the steps are a list in a highlighted box', aid.listTag === 'OL' && aid.listItems.length >= 2 && aid.listBg !== 'rgba(0, 0, 0, 0)', `${aid.listItems.length} steps`);
      const [b1, b2] = aid.buttons;
      const sameSize = b1 && b2 && Math.abs(b1.w - b2.w) < 2 && Math.abs(b1.h - b2.h) < 2;
      const sideBySide = b1 && b2 && Math.abs(b1.y - b2.y) < 2 && b2.x > b1.x;
      const stacked = b1 && b2 && b2.y > b1.y + b1.h - 1 && Math.abs(b1.x - b2.x) < 2;
      note(w, area2, `"That fixed it" and "Still a problem" are equal size and ${w <= 420 ? 'stacked' : 'side by side'}`, sameSize && (w <= 420 ? stacked : sideBySide), JSON.stringify(aid.buttons.map((b) => [b.text, Math.round(b.w), Math.round(b.h)])));
      note(w, area2, 'both buttons are filled (equal visual weight)', b1 && b2 && b1.bg !== 'rgba(0, 0, 0, 0)' && b2.bg !== 'rgba(0, 0, 0, 0)', `${b1 && b1.bg} / ${b2 && b2.bg}`);
      await page.screenshot({ path: `${OUT}/tenant-aid-${w}.png`, fullPage: true });
    }
  }
  note(w, role === 'landlord' ? 'owner portal' : `tenant portal (${variant})`, 'no console errors', errors.length === 0, errors.slice(0, 3).join(' | '));
  if (missed.length) console.log('       (unanswered API calls: ' + [...new Set(missed)].join(', ') + ')');
  await page.close();
}

for (const [w, h] of WIDTHS) {
  console.log(`\n=== ${w}px ===`);
  await staff(w, h);
  await portal(w, h, 'landlord', API_OFF, 'owner');
  await portal(w, h, 'tenant', API_OFF, 'marks-off');
  await portal(w, h, 'tenant', API_ON, 'marks-on');
}
await browser.close();
const failed = results.filter((r) => !r.ok);
fs.writeFileSync(`${OUT}/results.json`, JSON.stringify(results, null, 1));
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) { console.log('FAILED:'); failed.forEach((f) => console.log(`  [${f.width}] ${f.area}: ${f.name} — ${f.detail}`)); process.exit(1); }
