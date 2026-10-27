// CoreX Desk - the AGENT's tab while a session is open (spec .ai/specs/corex-desk.md sections 3, 7.2, 7.5).
// Loaded on demand by index.js, so Echo and rrweb cost nothing until a session exists.
//
// Nothing is recorded or sent until support has joined (desk.joined). From then on rrweb records ONLY this CoreX tab; passwords and
// anything inside [data-desk-mask] are masked here, in the browser, before a single byte is whispered. Desk's own UI carries
// .rr-block so it is never part of what support sees.
import { getEcho, dropEcho } from './echo.js';
import { encodeBatch } from './frames.js';

const FLUSH_MS = 500;
const POINTER_IDLE_MS = 4000;

let st = null;

/** Every input type rrweb can mask. maskInputFn below decides which ones really are (password + [data-desk-mask]). */
const ALL_INPUT_TYPES = {
    color: true, date: true, 'datetime-local': true, email: true, month: true, number: true, range: true, search: true,
    tel: true, text: true, time: true, url: true, week: true, textarea: true, select: true, password: true,
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

    st = { cfg, session, api, ui, echo, channel, channelName, timers: [], buffer: [], flushing: false, subscribed: false, stopRecord: null, recording: false, drv: null, ended: false, pointerTimer: null };

    channel
        .listen('.desk.joined', (e) => { ui.joined(e.support_name); beginRecording(); })
        .listen('.desk.ended', (e) => finish(e.by))
        .listen('.desk.highlight', (e) => showHighlight(e.selector, e.note))
        .listen('.desk.highlight_cleared', () => clearHighlight())
        .listen('.desk.pointer_state', (e) => { if (!e.on) hidePointer(); })
        .listenForWhisper('pointer', (p) => movePointer(p))
        .listenForWhisper('resync', () => resync())
        .subscribed(() => { if (st) { st.subscribed = true; if (st.buffer.length) scheduleFlush(); } })
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

    if (session.status === 'live') {
        ui.joined(session.support_name);
        beginRecording();
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
    stopRecording();
    clearHighlight();
    hidePointer();
    st.timers.forEach(clearInterval);
    window.removeEventListener('pagehide', leaving);
    try { st.echo.leave(st.channelName); } catch (e) { /* gone already */ }
    dropEcho();
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
    st.stopRecord = record({
        emit: (event) => { if (st) { st.buffer.push(event); scheduleFlush(); } },
        blockClass: 'rr-block',
        maskInputOptions: ALL_INPUT_TYPES,
        maskInputFn: maskInput,
        maskTextSelector: '[data-desk-mask]',
        inlineStylesheet: true,
        recordCanvas: false,
        collectFonts: false,
        sampling: { mousemove: 50, scroll: 100, input: 'last' },
    });
}

function stopRecording() {
    if (st && st.stopRecord) { try { st.stopRecord(); } catch (e) { /* ignore */ } }
    if (st) { st.stopRecord = null; st.recording = false; st.buffer = []; }
}

function resync() {
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

// ── Support's pointer ────────────────────────────────────────────────────────────

function pointerEl() { return document.getElementById('corex-desk-pointer'); }

function movePointer(p) {
    const el = pointerEl();
    if (!el || !p || typeof p.x !== 'number' || typeof p.y !== 'number') return;
    el.hidden = false;
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
            ? { title: 'CoreX support', description: note.replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c])), side: 'bottom' }
            : undefined,
    });
}

function clearHighlight() {
    if (st && st.drv) { try { st.drv.destroy(); } catch (e) { /* ignore */ } st.drv = null; }
    document.body.classList.remove('corex-guide-live');
}
