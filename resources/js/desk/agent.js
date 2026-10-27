// CoreX Desk - the AGENT's tab while a session is open (spec .ai/specs/corex-desk.md sections 3, 4f, 7.2, 7.5).
// Loaded on demand by index.js, so Echo and rrweb cost nothing until a session exists.
//
// A session belongs to the USER: every CoreX tab the agent has joins it, each under its own tab key ("main" for the tab that started the session,
// "c<letters>" for the others). Each tab runs this module on its own and talks on the same private channel; every whisper names its tab, and a tab
// only reacts to what is addressed to it.
//
// Nothing is recorded or sent until support has joined (desk.joined). From then on rrweb records ONLY this CoreX tab; passwords and
// anything inside [data-desk-mask] are masked here, in the browser, before a single byte is whispered. Desk's own UI carries
// .rr-block so it is never part of what support sees.
import { getEcho, dropEcho } from './echo.js';
import { encodeBatch } from './frames.js';
import { slimEvent, resetSlim } from './slim.js';

// How long events wait to be batched before they are whispered. After a quiet moment: about one screen frame, so a keystroke or a menu opening is on its way
// at once. In the middle of a burst (the last message went out less than BURST_GAP_MS ago): BURST_MS, so a stream of changes goes out as one message per
// few frames rather than one per change - a small message costs nearly as many bytes on the wire as a bigger one (spec section 4e).
const FLUSH_MS = 16;
const BURST_MS = 50;
const BURST_GAP_MS = 120;
const POINTER_IDLE_MS = 4000;
const REPLAY_KEY = 'corex.desk.replay';   // the tab that last replayed a click for support (a click that opens a new tab hands control to it)
const REPLAY_WINDOW_MS = 10000;

let st = null;

/** Is this event / whisper addressed to this tab? No tab named (or "main") means the tab that started the session. */
const forMe = (tab) => !!st && (tab || 'main') === st.tab;

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
    const tab = ctx.tabKey || 'main';
    const channelName = 'desk.session.' + session.id;
    const echo = getEcho();
    const channel = echo.private(channelName);

    st = { cfg, session, api, ui, tab, echo, channel, channelName, timers: [], buffer: [], flushing: false, subscribed: false, live: false, registered: false,
        ctl: { state: 'none', asked: false, exec: null, reporter: null, idle: null }, stopRecord: null, recording: false, hl: null, ended: false, pointerTimer: null,
        haveListener: false, pendingResync: false, lastSnap: 0 };

    channel
        .listen('.desk.joined', (e) => { if (e.support_name) st.session.support_name = e.support_name; st.live = true; ui.joined(e.support_name); beginRecording(); registerTab('join'); })
        .listen('.desk.ended', (e) => finish(e.by))
        // Events and whispers name the tab they are for. This client only reacts to its own.
        .listen('.desk.highlight', (e) => { if (forMe(e.tab)) showHighlight(e.selector, e.note); })
        .listen('.desk.highlight_cleared', (e) => { if (forMe(e.tab)) clearHighlight(); })
        .listen('.desk.pointer_state', (e) => { if (forMe(e.tab) && !e.on) hidePointer(); })
        .listenForWhisper('pointer', (p) => { if (forMe(p && p.t)) movePointer(p); })
        .listenForWhisper('resync', (r) => { if (forMe(r && r.t)) resync(true); })
        // Take-over (protocol 1.2): support asks, the agent answers, and only after the agent's own Allow does anything get replayed.
        .listen('.desk.control_requested', (e) => { if (forMe(e.tab)) askControl(e.support_name); })
        .listen('.desk.control_granted', (e) => { if (forMe(e.tab)) beginControl(e.idle_seconds); })
        .listen('.desk.control_declined', (e) => { if (forMe(e.tab)) endControlLocal('declined'); })
        .listen('.desk.control_revoked', (e) => { if (forMe(e.tab)) endControlLocal('revoked'); })
        .listen('.desk.control_ended', (e) => { if (forMe(e.tab)) endControlLocal(e.reason || 'ended'); })
        // A click support replayed opened a new CoreX tab: control moves there. This tab goes back to "viewing"; the new one starts replaying.
        .listen('.desk.control_moved', (e) => {
            if (e.from === st.tab) endControlLocal('moved');
            else if (forMe(e.tab)) { st.ctl.asked = true; beginControl(e.idle_seconds); }
        })
        .listenForWhisper('action', (a) => { if (forMe(a && a.t)) runAction(a); })
        .listenForWhisper('tping', (p) => { if (st.cfg.timing && p) { try { st.channel.whisper('tpong', { id: p.id, c: p.c, a: Date.now(), t: st.tab }); } catch (e) { /* not subscribed */ } } })
        .subscribed(() => {
            if (!st) return;
            st.subscribed = true;
            emitViewport();
            if (st.buffer.length) scheduleFlush();
            if (st.live && !st.registered) registerTab('join');
        })
        .error(() => ui.error('Could not open the live connection. Check your internet connection and try again.'));

    // Keep the session alive; learn if the other side (or a timeout) already ended it.
    const beat = async () => {
        const r = await api('POST', cfg.urls.sessions + '/' + session.id + '/heartbeat', { page: location.pathname, tab: st.tab });
        if (r.ok && r.json.status === 'ended') finish(r.json.ended_by);
    };
    beat();
    st.timers.push(setInterval(beat, Math.max(5, cfg.heartbeatSeconds || 30) * 1000));

    // The page is closing or changing: say so, so the next page gets a short grace instead of the full timeout.
    window.addEventListener('pagehide', leaving);
    window.addEventListener('resize', onViewportChange);
    if (window.visualViewport) window.visualViewport.addEventListener('resize', onViewportChange);
    document.addEventListener('click', onDocClick, true);
    window.addEventListener('hashchange', onNavigate);
    window.addEventListener('popstate', onNavigate);
    watchTitle();

    if (session.status === 'live') {
        st.live = true;
        st.listenerKnown = true;   // support is already in the session (this is a page change, or a tab opened later): its console is listening, so the first picture goes out at once
        ui.joined(session.support_name);
        beginRecording();
        // (the page's own inline script already sent this tab's join call as the page started; its answer is read here)
        if (ctx.earlyJoin) { st.registered = 'inflight'; ctx.earlyJoin.then((r) => joined(r)); } else registerTab('join');
        // A page change must not drop a take-over (or a pending request): pick it up again from the session.
        const c = session.control || {};
        if (forMe(c.tab) && c.state === 'requested') askControl(session.support_name);
        if (forMe(c.tab) && c.state === 'granted') { st.ctl.asked = true; beginControl(cfg.controlIdleSeconds); }
    }

    return st;
}

export async function endSession() {
    if (!st) return;
    const { api, cfg, session } = st;
    await api('POST', cfg.urls.sessions + '/' + session.id + '/end');
    finish('agent');
}

/** This tab joins (or re-announces itself to) the session: support's tab bar lists it by title and path. */
async function registerTab(action) {
    // (needs the session to be live, not the live connection: the HTTP call goes out at once and the console learns of this tab a round trip sooner)
    if (!st || !st.live) return;
    if (action === 'join' && st.registered === 'inflight') return;
    st.registered = 'inflight';
    const body = { action, tab: st.tab, path: location.pathname, title: document.title || '' };
    // The tab a replayed click was run in a moment ago: if that click opened THIS tab, control follows it.
    try {
        const rec = JSON.parse(localStorage.getItem(REPLAY_KEY) || 'null');
        if (rec && rec.tab && rec.tab !== st.tab && Date.now() - rec.t < REPLAY_WINDOW_MS) body.from = rec.tab;
    } catch (e) { /* storage blocked */ }
    joined(await st.api('POST', st.cfg.urls.sessions + '/' + st.session.id + '/tabs', body));
}

/** The answer to this tab's join call. */
function joined(r) {
    if (!st) return;
    st.registered = r.ok ? true : false;
    if (!r.ok) {
        if (r.status === 409) finish('ended');   // the session is not live any more
        return;
    }
    // Control may already have moved here (the event can arrive before this answer, or not at all): the server's word settles it.
    const c = r.json.session && r.json.session.control;
    if (c && c.state === 'granted' && forMe(c.tab) && st.ctl.state !== 'granted') { st.ctl.asked = true; beginControl(st.cfg.controlIdleSeconds); }
}

let titleObs = null, titleTimer = null;
function watchTitle() {
    const el = document.querySelector('title');
    if (!el || !window.MutationObserver) return;
    titleObs = new MutationObserver(() => { clearTimeout(titleTimer); titleTimer = setTimeout(() => registerTab('update'), 600); });
    titleObs.observe(el, { childList: true, characterData: true, subtree: true });
}

function leaving() {
    if (!st || st.ended) return;
    try {
        const fd = new FormData();
        fd.append('_token', st.cfg.csrf);
        fd.append('tab', st.tab);
        navigator.sendBeacon(st.cfg.urls.sessions + '/' + st.session.id + '/leaving', fd);
        if (st.hl) {   // leaving the page clears the highlight on the console as well
            const h = new FormData();
            h.append('_token', st.cfg.csrf); h.append('tab', st.tab); h.append('reason', 'navigated');
            navigator.sendBeacon(st.cfg.urls.sessions + '/' + st.session.id + '/highlight/clear', h);
        }
    } catch (e) { /* best effort */ }
}

function finish(by) {
    if (!st || st.ended) return;
    st.ended = true;
    endControlLocal('session', true);
    stopRecording();
    clearHighlight();
    hidePointer();
    st.timers.forEach((t) => { clearInterval(t); clearTimeout(t); });
    window.removeEventListener('pagehide', leaving);
    window.removeEventListener('resize', onViewportChange);
    if (window.visualViewport) window.visualViewport.removeEventListener('resize', onViewportChange);
    document.removeEventListener('click', onDocClick, true);
    window.removeEventListener('hashchange', onNavigate);
    window.removeEventListener('popstate', onNavigate);
    if (titleObs) { titleObs.disconnect(); titleObs = null; }
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
    resetSlim();
    // On the FIRST join nobody is listening until support's console has subscribed and asked for a picture (its "resync"): a snapshot sent before that goes
    // nowhere. So the recorder starts, but its first snapshot is only kept if the console already asked (or support was already here - a page change or a
    // newly opened tab); otherwise it waits for the ask. (If no ask comes, send one anyway.)
    st.haveListener = st.pendingResync || !!st.listenerKnown;
    st.pendingResync = false;
    st.stopRecord = record({
        emit: (event) => { if (!st || !st.haveListener) return; const e = slimEvent(event); if (e) { st.buffer.push(e); scheduleFlush(); } },
        blockClass: 'rr-block',
        maskInputOptions: ALL_INPUT_TYPES,
        maskInputFn: maskInput,
        maskTextSelector: '[data-desk-mask]',
        // The mirror loads the page's stylesheets itself (same site, so they come from its own cache) instead of receiving ~half a megabyte of CSS inside
        // every snapshot (measured 27 Oct, spec section 4e).
        inlineStylesheet: false,
        recordCanvas: false,
        collectFonts: false,
        sampling: { mousemove: 50, scroll: 50 }, // every keystroke is mirrored (input:'last' would wait for the field to lose focus)
        // A fresh full snapshot now and then: properties the page sets from script (a select's value, a checkbox) raise no DOM mutation, so a mirror can
        // drift; this puts it right again. (Each one is the whole page again - kept rare.)
        checkoutEveryNms: 120000,
    });
    // The console's ask can be lost; do not leave it with a blank window.
    setTimeout(() => { if (st && !st.haveListener) resync(true); }, 3000);
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
    try { st.channel.whisper('viewport', { t: st.tab, w: window.innerWidth, h: window.innerHeight, dpr: window.devicePixelRatio || 1 }); } catch (e) { /* not subscribed yet */ }
}
let vpTimer = null;
function onViewportChange() { clearTimeout(vpTimer); vpTimer = setTimeout(emitViewport, 80); }

/** The console asks for a picture. Asks that arrive close together (a retry, a tab switch) are one picture, not several. */
function resync(fromConsole) {
    if (!st) return;
    emitViewport();
    if (!st.record || !st.recording || !st.stopRecord) { if (fromConsole) st.pendingResync = true; return; }
    const now = Date.now();
    if (st.haveListener && now - st.lastSnap < 900) return;
    st.haveListener = true;
    st.lastSnap = now;
    try { resetSlim(); st.record.takeFullSnapshot(true); } catch (e) { /* ignore */ }
}

function scheduleFlush() {
    if (!st || st.flushTimer) return;
    st.flushTimer = setTimeout(flush, Date.now() - (st.lastFlushAt || 0) > BURST_GAP_MS ? FLUSH_MS : BURST_MS);
}

/** What kind of batch is this, for the timing overlay. */
function categoryOf(events) {
    let k = 'other';
    const rank = { snapshot: 5, mutation: 4, input: 3, scroll: 2, pointer: 1, other: 0 };
    for (const e of events) {
        let c = 'other';
        if (e.type === 2) c = 'snapshot';
        else if (e.type === 3 && e.data) c = { 0: 'mutation', 1: 'pointer', 3: 'scroll', 5: 'input' }[e.data.source] || 'other';
        if (rank[c] > rank[k]) k = c;
    }
    return k;
}

async function flush() {
    if (!st) return;
    st.flushTimer = null;
    // Nothing is whispered before the private channel is subscribed (a page change re-subscribes first); events wait in the buffer.
    if (!st.subscribed || st.flushing || !st.buffer.length) { if (st.buffer.length) scheduleFlush(); return; }
    st.flushing = true;
    st.lastFlushAt = Date.now();
    const events = st.buffer;
    st.buffer = [];
    try {
        const meta = st.cfg.timing ? { ts: Date.now(), k: categoryOf(events), ev: events.length } : null;
        const chunks = await encodeBatch(events, 'a', { t: st.tab, meta });
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
    try { st.channel.whisper('control_ack', { t: st.tab }); } catch (e) { /* not subscribed yet */ }   // tells the console the question is on the agent's screen
}

export async function controlRespond(allow) {
    if (!st) return;
    // The answer is the one call that must not be lost: a busy minute (429), a dropped connection (0) or a server hiccup (5xx) is retried, and if it still
    // cannot be sent the question STAYS on screen with a plain "try again" - never silently dropped (the console would wait on "Requested" for good).
    let r = null;
    for (let attempt = 0; attempt < 3; attempt++) {
        r = await st.api('POST', controlUrl('respond'), { allow: !!allow });
        if (r.ok || !(r.status === 429 || r.status === 0 || r.status >= 500)) break;
        await new Promise((res) => setTimeout(res, 1000 * (attempt + 1)));
        if (!st) return;
    }
    if (!r.ok) {
        if (r.status === 429 || r.status === 0 || r.status >= 500) { st.ui.controlAsk(st.session.support_name, 'could not send your answer - press ' + (allow ? 'Allow' : 'Decline') + ' again'); return; }
        endControlLocal('lapsed');   // the request already lapsed or was withdrawn
        return;
    }
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
    c.reporter = window.CoreXDeskExec.createReporter((actions) => st && st.api('POST', controlUrl('actions'), { tab: st.tab, actions }), 1500);
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
    // A click that opens a new tab must be traceable to this tab: note it BEFORE it runs (the new tab reads this when it joins).
    if (a && (a.type === 'click' || a.type === 'dblclick' || (a.type === 'key' && a.k === 'Enter'))) {
        try { localStorage.setItem(REPLAY_KEY, JSON.stringify({ tab: st.tab, t: Date.now() })); } catch (e) { /* storage blocked */ }
    }
    const res = st.ctl.exec.run(a);
    st.ctl.reporter.add(res.report);
    if (res.ok) st.ctl.idle.touch();
    // Tell the console when it could not be done (the screen it clicked on is out of date, the field is private...): it refreshes and says so.
    else { try { st.channel.whisper('action_result', { t: st.tab, id: a.id, ok: false, reason: res.reason }); } catch (e) { /* not subscribed */ } }
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

// ── Support's highlight: a CoreX ring + label and a small note card (spec section 4g) ─────────────────────────

const esc = (t) => String(t).replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]));

function showHighlight(selector, note) {
    clearHighlight();
    let target = null;
    try { target = document.querySelector(selector); } catch (e) { return; }
    if (!target) return;
    target.scrollIntoView({ block: 'center', behavior: 'smooth' });
    const ring = document.createElement('div');
    ring.id = 'corex-desk-hl';
    ring.className = 'cd-hl';
    ring.setAttribute('aria-hidden', 'true');
    const name = String(st.session.support_name || 'CoreX support');
    ring.innerHTML = '<span class="cd-hl-label"></span>' + (note ? '<div class="cd-hl-note"></div>' : '');
    ring.querySelector('.cd-hl-label').textContent = name;
    if (note) ring.querySelector('.cd-hl-note').textContent = String(note);
    document.body.appendChild(ring);
    const h = { target, ring, raf: 0, last: '' };
    st.hl = h;
    // The ring follows the element while the page scrolls or resizes (the smooth scroll above is still running when it first appears).
    const place = () => {
        if (!st || st.hl !== h) return;
        if (!target.isConnected) { clearHighlight(); return; }
        const r = target.getBoundingClientRect();
        const pad = 4;
        const key = [r.left, r.top, r.width, r.height, innerWidth, innerHeight].map(Math.round).join(',');
        if (key !== h.last) {
            h.last = key;
            ring.style.left = Math.round(r.left - pad) + 'px';
            ring.style.top = Math.round(r.top - pad) + 'px';
            ring.style.width = Math.round(r.width + pad * 2) + 'px';
            ring.style.height = Math.round(r.height + pad * 2) + 'px';
            // The note sits below the element, or above it when there is no room below; the label is on the ring's top edge.
            ring.classList.toggle('cd-hl-up', r.bottom + 120 > innerHeight && r.top > 140);
            ring.classList.toggle('cd-hl-right', r.left > innerWidth - 300);
        }
        h.raf = requestAnimationFrame(place);
    };
    place();
}

function clearHighlight() {
    const h = st && st.hl;
    if (h) { cancelAnimationFrame(h.raf); if (h.ring.parentNode) h.ring.parentNode.removeChild(h.ring); st.hl = null; }
    else { const r = document.getElementById('corex-desk-hl'); if (r && r.parentNode) r.parentNode.removeChild(r); }
}

/** The agent clicked the highlighted element (or anything inside it): it did what was pointed out. Clear it on both sides; the console says "done". */
function onDocClick(ev) {
    const h = st && st.hl;
    if (!h) return;
    const path = ev.composedPath ? ev.composedPath() : [ev.target];
    if (path.includes(h.target) || h.target.contains(ev.target)) {
        clearHighlight();
        st.api('POST', st.cfg.urls.sessions + '/' + st.session.id + '/highlight/clear', { tab: st.tab, reason: 'clicked' });
    }
}

/** A hash / history change inside the page: what was pointed at is probably not there any more. */
function onNavigate() {
    if (!st || !st.hl) return;
    clearHighlight();
    st.api('POST', st.cfg.urls.sessions + '/' + st.session.id + '/highlight/clear', { tab: st.tab, reason: 'navigated' });
}
