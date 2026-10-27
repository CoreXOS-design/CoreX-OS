// CoreX Desk - the AGENT's tab while a session is open (spec .ai/specs/corex-desk.md sections 3, 7.2, 7.5).
// Loaded on demand by index.js, so Echo and rrweb cost nothing until a session exists.
//
// Nothing is recorded or sent until support has joined (desk.joined). From then on rrweb records ONLY this CoreX tab; passwords and
// anything inside [data-desk-mask] are masked here, in the browser, before a single byte is whispered. Desk's own UI carries
// .rr-block so it is never part of what support sees.
import { getEcho, dropEcho } from './echo.js';
import { encodeBatch } from './frames.js';

// How long events wait to be batched before they are whispered. Short on purpose: a keystroke or a menu opening reaches the console in well under a
// second (500 ms here made typing feel 0.5 s behind - measured 27 Oct, spec section 4e).
const FLUSH_MS = 60;
const POINTER_IDLE_MS = 4000;

let st = null;

/** Protocol 1.1 - no tab named (or "main") means the CoreX page itself. */
const forMain = (tab) => !tab || tab === 'main';

/** Every input type rrweb can mask. maskInputFn below decides which ones really are (password + [data-desk-mask]). */
const ALL_INPUT_TYPES = {
    color: true, date: true, 'datetime-local': true, email: true, month: true, number: true, range: true, search: true,
    tel: true, text: true, time: true, url: true, week: true, textarea: true, password: true,   // (not select: rrweb then drops option.selected and the mirror shows the wrong choice)
};

function maskInput(text, el) {
    const must = el && ((el.type === 'password') || (el.closest && el.closest('[data-desk-mask]')));
    return must ? '*'.repeat((text || '').length) : text;
}

export function start(ctx) {
    if (st) return st;
    const { cfg, session, api, ui } = ctx;
    const channelName = 'desk.session.' + session.id;
    const echo = getEcho();
    const channel = echo.private(channelName);

    st = { cfg, session, api, ui, echo, channel, channelName, timers: [], buffer: [], flushing: false, subscribed: false, ctl: { state: 'none', asked: false, exec: null, reporter: null, idle: null }, stopRecord: null, recording: false, drv: null, ended: false, pointerTimer: null };

    channel
        .listen('.desk.joined', (e) => { if (e.support_name) st.session.support_name = e.support_name; ui.joined(e.support_name); beginRecording(); })
        .listen('.desk.ended', (e) => finish(e.by))
        // Protocol 1.1: events and whispers may name a tab (an extension tab, "x<id>"). This client is the CoreX page, "main": it only
        // reacts to its own.
        .listen('.desk.highlight', (e) => { if (forMain(e.tab)) showHighlight(e.selector, e.note); })
        .listen('.desk.highlight_cleared', (e) => { if (forMain(e.tab)) clearHighlight(); })
        .listen('.desk.pointer_state', (e) => { if (forMain(e.tab) && !e.on) hidePointer(); })
        .listenForWhisper('pointer', (p) => { if (forMain(p && p.t)) movePointer(p); })
        .listenForWhisper('resync', (r) => { if (forMain(r && r.t)) resync(); })
        // Take-over (protocol 1.2): support asks, the agent answers, and only after the agent's own Allow does anything get replayed.
        .listen('.desk.control_requested', (e) => { if (forMain(e.tab)) askControl(e.support_name); })
        .listen('.desk.control_granted', (e) => { if (forMain(e.tab)) beginControl(e.idle_seconds); })
        .listen('.desk.control_declined', (e) => { if (forMain(e.tab)) endControlLocal('declined'); })
        .listen('.desk.control_revoked', (e) => { if (forMain(e.tab)) endControlLocal('revoked'); })
        .listen('.desk.control_ended', (e) => { if (forMain(e.tab)) endControlLocal(e.reason || 'ended'); })
        .listenForWhisper('action', (a) => { if (forMain(a && a.t)) runAction(a); })
        .subscribed(() => { if (st) { st.subscribed = true; emitViewport(); if (st.buffer.length) scheduleFlush(); } })
        .error(() => ui.error('Could not open the live connection. Check your internet connection and try again.'));

    // Keep the session alive; learn if the other side (or a timeout) already ended it.
    const beat = async () => {
        const r = await api('POST', cfg.urls.sessions + '/' + session.id + '/heartbeat', { page: location.pathname });
        if (r.ok && r.json.status === 'ended') finish(r.json.ended_by);
    };
    beat();
    st.timers.push(setInterval(beat, Math.max(5, cfg.heartbeatSeconds || 30) * 1000));

    // The page is closing or changing: say so, so the next page gets a short grace instead of the full timeout.
    window.addEventListener('pagehide', leaving);
    window.addEventListener('resize', onViewportChange);
    if (window.visualViewport) window.visualViewport.addEventListener('resize', onViewportChange);

    if (session.status === 'live') {
        ui.joined(session.support_name);
        beginRecording();
        // A page change must not drop a take-over (or a pending request): pick it up again from the session.
        const c = session.control || {};
        if (forMain(c.tab) && c.state === 'requested') askControl(session.support_name);
        if (forMain(c.tab) && c.state === 'granted') { st.ctl.asked = true; beginControl(cfg.controlIdleSeconds); }
    }

    return st;
}

export async function endSession() {
    if (!st) return;
    const { api, cfg, session } = st;
    await api('POST', cfg.urls.sessions + '/' + session.id + '/end');
    finish('agent');
}

function leaving() {
    if (!st || st.ended) return;
    try {
        const fd = new FormData();
        fd.append('_token', st.cfg.csrf);
        navigator.sendBeacon(st.cfg.urls.sessions + '/' + st.session.id + '/leaving', fd);
    } catch (e) { /* best effort */ }
}

function finish(by) {
    if (!st || st.ended) return;
    st.ended = true;
    endControlLocal('session', true);
    stopRecording();
    clearHighlight();
    hidePointer();
    st.timers.forEach(clearInterval);
    window.removeEventListener('pagehide', leaving);
    window.removeEventListener('resize', onViewportChange);
    if (window.visualViewport) window.visualViewport.removeEventListener('resize', onViewportChange);
    dropEcho(); // disconnecting drops the subscription server-side; a separate leave() would send on a socket that is closing
    const ui = st.ui;
    st = null;
    ui.ended(by);
}

// ── Recording (only while support is joined) ─────────────────────────────────────

async function beginRecording() {
    if (!st || st.recording) return;
    st.recording = true;
    const { record } = await import('rrweb');
    if (!st || st.ended) return;
    st.record = record;
    // ...and one just after the page has finished drawing itself (menus, lists filled by script), so a support person joining a busy page sees it complete.
    setTimeout(() => { if (st && st.recording) resync(); }, 2500);
    st.stopRecord = record({
        emit: (event) => { if (st) { st.buffer.push(event); scheduleFlush(); } },
        blockClass: 'rr-block',
        maskInputOptions: ALL_INPUT_TYPES,
        maskInputFn: maskInput,
        maskTextSelector: '[data-desk-mask]',
        inlineStylesheet: true,
        recordCanvas: false,
        collectFonts: false,
        sampling: { mousemove: 50, scroll: 50 }, // every keystroke is mirrored (input:'last' would wait for the field to lose focus)
        // A fresh full snapshot every so often: properties the page sets from script (a select's value, a checkbox) raise no DOM mutation, so a
        // mirror can drift; this puts it right again.
        checkoutEveryNms: 45000,
    });
}

function stopRecording() {
    if (st && st.stopRecord) { try { st.stopRecord(); } catch (e) { /* ignore */ } }
    if (st) { st.stopRecord = null; st.recording = false; st.buffer = []; }
}

/**
 * The agent's own window size, whispered on join / resync / every resize or zoom (spec section 4e): the console lays the mirror out at THIS size and
 * recomputes its scale each time, never from a size it learned once. dpr is informational (browser zoom shows up as a changed innerWidth).
 */
function emitViewport() {
    if (!st || !st.subscribed) return;
    try { st.channel.whisper('viewport', { t: 'main', w: window.innerWidth, h: window.innerHeight, dpr: window.devicePixelRatio || 1 }); } catch (e) { /* not subscribed yet */ }
}
let vpTimer = null;
function onViewportChange() { clearTimeout(vpTimer); vpTimer = setTimeout(emitViewport, 80); }

function resync() {
    emitViewport();
    if (st && st.record && st.recording) {
        try { st.record.takeFullSnapshot(true); } catch (e) { /* ignore */ }
    }
}

function scheduleFlush() {
    if (!st || st.flushTimer) return;
    st.flushTimer = setTimeout(flush, FLUSH_MS);
}

async function flush() {
    if (!st) return;
    st.flushTimer = null;
    // Nothing is whispered before the private channel is subscribed (a page change re-subscribes first); events wait in the buffer.
    if (!st.subscribed || st.flushing || !st.buffer.length) { if (st.buffer.length) scheduleFlush(); return; }
    st.flushing = true;
    const events = st.buffer;
    st.buffer = [];
    try {
        const chunks = await encodeBatch(events, 'a');
        for (const c of chunks) {
            if (!st) break;
            st.channel.whisper('frame', c);
        }
    } catch (e) {
        console.warn('[corex-desk] frame send failed', e);
    } finally {
        if (st) { st.flushing = false; if (st.buffer.length) scheduleFlush(); }
    }
}

// ── Take-over (phase 2, spec section 14) ─────────────────────────────────────────

/** What the recorder masks, the executor refuses to touch: passwords and [data-desk-mask]. */
const blockedField = (el) => !!(el && ((el.type === 'password') || (el.closest && el.closest('[data-desk-mask]'))));

function loadExec() {
    if (window.CoreXDeskExec) return Promise.resolve();
    return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = st.cfg.urls.exec;
        s.onload = () => resolve();
        s.onerror = () => reject(new Error('executor failed to load'));
        document.head.appendChild(s);
    });
}

function controlUrl(what) { return st.cfg.urls.sessions + '/' + st.session.id + '/control/' + what; }

/** Support asked: show "wants to take control - Allow / Decline" in the red bar. Nothing moves until Allow. */
function askControl(supportName) {
    if (!st || st.ctl.state === 'granted') return;
    st.ctl.state = 'asking';
    st.ctl.asked = true;
    st.ui.controlAsk(supportName || st.session.support_name);
    try { st.channel.whisper('control_ack', { t: 'main' }); } catch (e) { /* not subscribed yet */ }   // tells the console the question is on the agent's screen
}

export async function controlRespond(allow) {
    if (!st) return;
    const r = await st.api('POST', controlUrl('respond'), { allow: !!allow });
    if (!r.ok) { endControlLocal('lapsed'); return; }   // the request already lapsed or was withdrawn
    if (!allow) endControlLocal('declined');
    // Allow: the server's desk.control_granted event starts it (so the bar and the executor change together).
}

export async function controlRevoke() {
    if (!st) return;
    endControlLocal('agent');
    await st.api('POST', controlUrl('revoke'), { reason: 'agent' });
}

async function beginControl(idleSeconds) {
    if (!st || !st.ctl.asked) return;   // a "granted" nobody here asked for is ignored: only this page's own Allow can start control
    await loadExec();
    if (!st) return;
    const c = st.ctl;
    c.state = 'granted';
    c.exec = window.CoreXDeskExec.create({ doc: document, win: window, isBlocked: blockedField });
    c.reporter = window.CoreXDeskExec.createReporter((actions) => st && st.api('POST', controlUrl('actions'), { tab: 'main', actions }), 1500);
    const secs = Math.max(60, idleSeconds || st.cfg.controlIdleSeconds || 300);
    c.idle = window.CoreXDeskExec.createIdleTimer(secs, () => { if (st) { endControlLocal('idle'); st.api('POST', controlUrl('revoke'), { reason: 'idle' }); } });
    c.idle.start();
    st.ui.controlOn(st.session.support_name);
}

/** Control is over (any reason): stop replaying, flush the report, put the bar back to "viewing". */
function endControlLocal(reason, quiet) {
    if (!st) return;
    const c = st.ctl;
    const wasOn = c.state === 'granted' || c.state === 'asking';
    if (c.reporter) c.reporter.flush();
    if (c.idle) c.idle.stop();
    st.ctl = { state: 'none', asked: false, exec: null, reporter: null, idle: null };
    if (wasOn && !quiet) st.ui.controlOff(reason);
}

/** One action whispered by support. Only replayed while control is granted HERE; refusals are reported like anything else. */
function runAction(a) {
    if (!st || st.ctl.state !== 'granted' || !st.ctl.exec) return;
    const res = st.ctl.exec.run(a);
    st.ctl.reporter.add(res.report);
    if (res.ok) st.ctl.idle.touch();
    // Tell the console when it could not be done (the screen it clicked on is out of date, the field is private...): it refreshes and says so.
    else { try { st.channel.whisper('action_result', { t: 'main', id: a.id, ok: false, reason: res.reason }); } catch (e) { /* not subscribed */ } }
}

// ── Support's pointer ────────────────────────────────────────────────────────────

function pointerEl() { return document.getElementById('corex-desk-pointer'); }

function movePointer(p) {
    const el = pointerEl();
    if (!el || !p || typeof p.x !== 'number' || typeof p.y !== 'number') return;
    // The console says what window size it believes this is. If it is out of date (a resize / zoom it has not heard of), tell it now.
    if ((typeof p.vw === 'number' && Math.abs(p.vw - window.innerWidth) > 2) || (typeof p.vh === 'number' && Math.abs(p.vh - window.innerHeight) > 2)) emitViewport();
    el.hidden = false;
    const who = el.querySelector('span');
    if (who) who.textContent = String((p && p.who) || (st && st.session.support_name) || 'Support').slice(0, 90);
    el.style.left = Math.round(Math.min(1, Math.max(0, p.x)) * window.innerWidth) + 'px';
    el.style.top = Math.round(Math.min(1, Math.max(0, p.y)) * window.innerHeight) + 'px';
    if (st) {
        clearTimeout(st.pointerTimer);
        st.pointerTimer = setTimeout(hidePointer, POINTER_IDLE_MS);
    }
}

function hidePointer() {
    const el = pointerEl();
    if (el) el.hidden = true;
}

// ── Support's highlight (the Guided Tour / driver.js look) ───────────────────────

// The Guided Tour's own engine and look: the same vendored driver.js and the same themed CSS (layouts/partials/tour-theme.blade.php,
// included on every page by the Desk partial). A page that has a tour already loaded driver.js; any other page fetches it here.
function loadDriver() {
    if (window.driver && window.driver.js) return Promise.resolve();
    if (st && st.driverLoading) return st.driverLoading;
    const p = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = '/vendor/driverjs/driver.js.iife.js';
        s.onload = () => resolve();
        s.onerror = () => reject(new Error('driver.js failed to load'));
        document.head.appendChild(s);
    });
    if (st) st.driverLoading = p;
    return p;
}

async function showHighlight(selector, note) {
    clearHighlight();
    let target = null;
    try { target = document.querySelector(selector); } catch (e) { return; }
    if (!target) return;
    try { await loadDriver(); } catch (e) { return; }
    if (!st) return;
    target.scrollIntoView({ block: 'center', behavior: 'smooth' });
    // Exactly the Guided Tour's "Spot Help" look: the tour's overlay colour, stage padding/radius and `corex-tour` popover, with
    // body.corex-guide-live so the agent can still click and type on the real page (the dimming does not block the screen).
    const dark = document.documentElement.classList.contains('dark');
    document.body.classList.add('corex-guide-live');
    st.drv = window.driver.js.driver({
        allowClose: false,
        allowKeyboardControl: false,
        showButtons: [],
        showProgress: false,
        animate: true,
        overlayColor: dark ? 'rgba(149,151,153,0.96)' : 'rgba(3,6,12,0.93)',
        overlayOpacity: 0.35,
        stagePadding: 6,
        stageRadius: 8,
        popoverClass: 'corex-tour',
        // A highlight is a pointing finger, not a tour: no Next/Previous/close, no footer.
        onPopoverRender: (popover) => {
            ['footer', 'closeButton'].forEach((k) => { if (popover[k]) popover[k].style.display = 'none'; });
        },
    });
    st.drv.highlight({
        element: target,
        popover: note
            ? { title: String(st.session.support_name || 'CoreX support'), description: note.replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c])), side: 'bottom' }
            : undefined,
    });
}

function clearHighlight() {
    if (st && st.drv) { try { st.drv.destroy(); } catch (e) { /* ignore */ } st.drv = null; }
    document.body.classList.remove('corex-guide-live');
}
