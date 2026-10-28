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
import { verifyAction, cryptoAvailable } from './sign.js';

// How long events wait to be batched before they are whispered. After a quiet moment: about one screen frame, so a keystroke or a menu opening is on its way
// at once. In the middle of a burst (the last message went out less than BURST_GAP_MS ago): BURST_MS, so a stream of changes goes out as one message per
// few frames rather than one per change - a small message costs nearly as many bytes on the wire as a bigger one (spec section 4e).
const FLUSH_MS = 16;
const BURST_MS = 50;
const BURST_GAP_MS = 120;
const POINTER_IDLE_MS = 4000;
const REPLAY_KEY = 'corex.desk.replay';   // the tab that last replayed a click for support (a click that opens a new tab hands control to it)
const REPLAY_WINDOW_MS = 10000;
// Frames wait here until the channel is subscribed; if it never is, the oldest are dropped past this many (and a fresh full picture is taken once it is).
const BUFFER_MAX = 4000;

let st = null;

/** Is this event / whisper addressed to this tab? No tab named (or "main") means the tab that started the session. */
const forMe = (tab) => !!st && (tab || 'main') === st.tab;

/**
 * Every input type rrweb can mask. maskInputFn below decides which ones really are: the SAME default-deny rule the extension uses on third-party sites
 * (desk-lib.js isSensitiveField - passwords, [data-desk-mask], hidden inputs, and anything whose name / id / autocomplete / placeholder / label looks like an
 * ID number, passport, bank account, branch code, tax number, card, PIN, one-time code, secret, token, key, date of birth...). `hidden` is masked too: hidden
 * inputs carry CSRF tokens and record ids.
 */
const ALL_INPUT_TYPES = {
    color: true, date: true, 'datetime-local': true, email: true, month: true, number: true, range: true, search: true,
    tel: true, text: true, time: true, url: true, week: true, textarea: true, password: true, hidden: true,   // (not select: rrweb then drops option.selected and the mirror shows the wrong choice)
};

// The page's CSRF token sits in a <meta>: it must never be part of a snapshot.
const BLOCK_SELECTOR = 'meta[name="csrf-token"]';

/** desk-lib.js is loaded as a plain script before recording starts. If it is not there (it failed to load) EVERYTHING is treated as private: fail closed. */
const lib = () => window.CoreXDeskLib || null;
const sensitive = (el) => { const L = lib(); return L ? L.isSensitiveField(el) : true; };

function maskInput(text, el) {
    return sensitive(el) ? '*'.repeat((text || '').length) : text;
}

export function start(ctx) {
    if (st) return st;
    const { cfg, session, api, ui } = ctx;
    const tab = ctx.tabKey || 'main';
    const channelName = 'desk.session.' + session.id;
    const echo = getEcho();
    const channel = echo.private(channelName);

    st = { cfg, session, api, ui, tab, echo, channel, channelName, timers: [], buffer: [], flushing: false, subscribed: false, live: false, registered: false,
        ctl: newCtl(), droppedActions: 0, supporters: [], stopRecord: null, recording: false, hl: null, ended: false, pointerTimer: null,
        haveListener: false, pendingResync: false, lastSnap: 0, thread: [], threadIds: new Set() };

    channel
        .listen('.desk.joined', (e) => { if (e.support_name) st.session.support_name = e.support_name; st.supporters = Array.isArray(e.supporters) && e.supporters.length ? e.supporters : [e.support_name]; st.live = true; ui.joined(e.support_name, st.supporters); beginRecording(); registerTab('join'); })
        // Someone else attached to the live session: the agent is told, by name, and the bar lists everyone who is in it.
        .listen('.desk.supporter_joined', (e) => { if (Array.isArray(e.supporters) && e.supporters.length) { st.supporters = e.supporters; ui.supporters(e.supporters, e.support_name); } })
        .listen('.desk.ended', (e) => finish(e.by))
        // Events and whispers name the tab they are for. This client only reacts to its own.
        .listen('.desk.highlight', (e) => { if (forMe(e.tab)) showHighlight(e.selector, e.note); })
        .listen('.desk.highlight_cleared', (e) => { if (forMe(e.tab)) clearHighlight(); })
        .listen('.desk.pointer_state', (e) => { if (forMe(e.tab) && !e.on) hidePointer(); })
        .listenForWhisper('pointer', (p) => { if (forMe(p && p.t)) movePointer(p); })
        .listenForWhisper('resync', (r) => { if (forMe(r && r.t)) resync(true); })
        // Take-over (protocol 1.2): support asks, the agent answers, and only after the agent's own Allow does anything get replayed.
        .listen('.desk.control_requested', (e) => { if (forMe(e.tab)) askControl(e.support_name, e.request_id); })
        // (starts only if THIS page's own Allow (or the server's word on a page change / a move) has already been taken - beginControl checks)
        .listen('.desk.control_granted', (e) => { if (forMe(e.tab)) beginControl(e.idle_seconds); })
        .listen('.desk.control_declined', (e) => { if (forMe(e.tab)) endControlLocal('declined'); })
        .listen('.desk.control_revoked', (e) => { if (forMe(e.tab)) endControlLocal('revoked'); })
        .listen('.desk.control_ended', (e) => { if (forMe(e.tab)) endControlLocal(e.reason || 'ended'); })
        // A click support replayed opened a new CoreX tab: control moves there. This tab goes back to "viewing"; the new one starts replaying.
        .listen('.desk.control_moved', (e) => {
            if (e.from === st.tab) endControlLocal('moved');
            else if (forMe(e.tab)) { st.ctl.allowed = true; beginControl(e.idle_seconds); }   // (control was granted on the tab that opened this one: the server's own event says so)
        })
        // A take-over action: signed by the console with the grant key; verified, in order, before anything is replayed (spec 14b).
        .listenForWhisper('action', (a) => { if (forMe(a && a.t)) onActionWhisper(a); })
        // The message strip (spec section 4h): only what the SERVER broadcast is shown (name, text and sender are the server's, the same as the audit row).
        // A whispered line is never rendered - any member of the channel could write one with another name.
        .listen('.desk.message', (m) => onMessage(m))
        .listenForWhisper('tping', (p) => { if (st.cfg.timing && p) { try { st.channel.whisper('tpong', { id: p.id, c: p.c, a: Date.now(), t: st.tab }); } catch (e) { /* not subscribed */ } } })
        .subscribed(() => {
            if (!st) return;
            st.subscribed = true;
            emitViewport();
            if (st.needSnap) { st.needSnap = false; st.lastSnap = 0; resync(true); }   // frames were dropped while waiting: a fresh full picture puts the mirror right
            if (st.buffer.length) scheduleFlush();
            if (st.live && !st.registered) registerTab('join');
        })
        .error(() => {
            // The channel would not open: nothing can be whispered, so stop recording instead of filling the buffer for a connection that is not there.
            stopRecording();
            ui.error('Could not open the live connection. Check your internet connection and try again.');
        });

    // Keep the session alive; learn if the other side (or a timeout) already ended it.
    const beat = async () => {
        const r = await api('POST', cfg.urls.sessions + '/' + session.id + '/heartbeat', { page: location.pathname, tab: st.tab });
        if (r.ok && r.json.status === 'ended') finish(r.json.ended_by);
        // The server does not list this tab as open (a "leaving" that reached it after the next page's "joined"): say so again.
        else if (r.ok && st && st.live && Array.isArray(r.json.tabs) && !r.json.tabs.includes(st.tab)) registerTab('join');
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
    // The tab's join call goes out as the page starts (before its heading exists, with the app title); once the page has drawn, say what the page is called.
    const rename = () => { if (st && st.live && st.registered === true && pageName() !== st.sentName) registerTab('update'); };
    if (document.readyState === 'complete') setTimeout(rename, 0); else { window.addEventListener('DOMContentLoaded', () => setTimeout(rename, 50)); window.addEventListener('load', () => setTimeout(rename, 50)); }
    st.timers.push(setTimeout(rename, 2500));

    if (session.status === 'live') {
        st.live = true;
        st.listenerKnown = true;   // support is already in the session (this is a page change, or a tab opened later): its console is listening, so the first picture goes out at once
        ui.joined(session.support_name, Array.isArray(session.supporters) && session.supporters.length ? session.supporters : [session.support_name]);
        beginRecording();
        // (the page's own inline script already sent this tab's join call as the page started; its answer is read here)
        if (ctx.earlyJoin) { st.registered = 'inflight'; ctx.earlyJoin.then((r) => joined(r)); } else registerTab('join');
        // A page change must not drop a take-over (or a pending request): pick it up again from the session.
        const c = session.control || {};
        st.supporters = Array.isArray(session.supporters) && session.supporters.length ? session.supporters : [session.support_name];
        if (forMe(c.tab) && c.state === 'requested') askControl(session.support_name, c.request_id);
        if (forMe(c.tab) && c.state === 'granted') { st.ctl.allowed = true; beginControl(cfg.controlIdleSeconds); }   // (the grant key is fetched from the server: it is never kept across page loads)
    }

    return st;
}

export async function endSession() {
    if (!st) return;
    const { api, cfg, session } = st;
    await api('POST', cfg.urls.sessions + '/' + session.id + '/end');
    finish('agent');
}

/**
 * What this tab is called in support's tab bar. CoreX's own <title> is the same on every page (the app name), so the page's name is its heading - the first
 * <h1> (Properties, Contacts, an address...) - and the <title> only when there is none.
 */
function pageName() {
    if (/^\/corex\/?$/.test(location.pathname)) return 'Home';   // (the home page's heading is a greeting)
    const h = Array.from(document.querySelectorAll('h1')).find((e) => !e.closest('[id^="corex-desk"]') && e.textContent.trim());
    const t = h ? h.textContent.replace(/\s+/g, ' ').trim() : '';
    return (t || document.title || '').slice(0, 120);
}

/** This tab joins (or re-announces itself to) the session: support's tab bar lists it by title and path. */
async function registerTab(action) {
    // (needs the session to be live, not the live connection: the HTTP call goes out at once and the console learns of this tab a round trip sooner)
    if (!st || !st.live) return;
    if (action === 'join' && st.registered === 'inflight') return;
    st.registered = 'inflight';
    const name = pageName();
    st.sentName = name;
    const body = { action, tab: st.tab, path: location.pathname, title: name, at: Date.now() };
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
    // The thread so far (a tab that has just opened or changed page).
    if (Array.isArray(r.json.messages) && r.json.messages.length) { r.json.messages.forEach((m) => addLine(m)); st.ui.thread(st.thread, false); }
    // Control may already have moved here (the event can arrive before this answer, or not at all): the server's word settles it.
    const c = r.json.session && r.json.session.control;
    if (c && c.state === 'granted' && forMe(c.tab) && st.ctl.state !== 'granted' && st.ctl.state !== 'starting') { st.ctl.allowed = true; beginControl(st.cfg.controlIdleSeconds); }
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
        fd.append('at', String(Date.now()));
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
    await loadScript(st && st.cfg.urls.lib).catch(() => {});   // the masking rules (desk-lib.js); without them everything is masked
    if (!st || st.ended || !st.recording) return;
    st.record = record;
    resetSlim();
    // On the FIRST join nobody is listening until support's console has subscribed and asked for a picture (its "resync"): a snapshot sent before that goes
    // nowhere. So the recorder starts, but its first snapshot is only kept if the console already asked (or support was already here - a page change or a
    // newly opened tab); otherwise it waits for the ask. (If no ask comes, send one anyway.)
    st.haveListener = st.pendingResync || !!st.listenerKnown;
    st.pendingResync = false;
    st.stopRecord = record({
        emit: (event) => {
            if (!st || !st.haveListener) return;
            const e = slimEvent(event);
            if (!e) return;
            st.buffer.push(e);
            // Not subscribed yet (or a send is slow): never let the wait grow without bound. The oldest go; a fresh full picture follows once the channel is up.
            if (st.buffer.length > BUFFER_MAX) { st.buffer.splice(0, st.buffer.length - BUFFER_MAX); st.needSnap = true; }
            scheduleFlush();
        },
        blockClass: 'rr-block',
        blockSelector: BLOCK_SELECTOR,
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
    if (!st.buffer.length) return;
    if (!st.subscribed) { st.flushTimer = setTimeout(flush, 1000); return; }   // (wait quietly - not a 16 ms loop - until the channel opens)
    if (st.flushing) { scheduleFlush(); return; }
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

// ── Take-over (phase 2, spec sections 14 and 14b) ────────────────────────────────

/** The state of a take-over in THIS tab. `allowed` is set only by this page's own Allow (or by the server's word, over an authenticated call, on a page change / a move to this tab). */
function newCtl() {
    return { state: 'none', requestId: null, allowed: false, grant: null, key: null, lastSeq: 0, exec: null, reporter: null, idle: null, q: Promise.resolve() };
}

/** What the recorder masks, the executor refuses to touch: the same default-deny rule (passwords, [data-desk-mask], ID / bank / card / tax / secret looking fields). */
const blockedField = (el) => sensitive(el);

/** Load a plain script once (the executor, the masking rules). */
const scripts = {};
function loadScript(url) {
    if (!url) return Promise.reject(new Error('no url'));
    if (scripts[url]) return scripts[url];
    scripts[url] = new Promise((resolve, reject) => {
        const el = document.createElement('script');
        el.src = url;
        el.onload = () => resolve();
        el.onerror = () => { delete scripts[url]; reject(new Error('script failed to load')); };
        document.head.appendChild(el);
    });
    return scripts[url];
}

async function loadExec() {
    if (!window.CoreXDeskExec) await loadScript(st.cfg.urls.exec);
    if (!window.CoreXDeskLib) await loadScript(st.cfg.urls.lib);
    if (!window.CoreXDeskExec || !window.CoreXDeskLib) throw new Error('executor failed to load');
}

function controlUrl(what) { return st.cfg.urls.sessions + '/' + st.session.id + '/control/' + what; }

/** Support asked: show "wants to take control - Allow / Decline" in the red bar. Nothing moves until Allow. */
function askControl(supportName, requestId) {
    if (!st || st.ctl.state === 'granted' || st.ctl.state === 'starting') return;
    // (a new question replaces an old one: only the request being shown can be answered)
    if (st.ctl.requestId !== (requestId || null)) st.ctl = newCtl();
    st.ctl.state = 'asking';
    st.ctl.requestId = requestId || null;
    st.ui.controlAsk(supportName || st.session.support_name);
    try { st.channel.whisper('control_ack', { t: st.tab }); } catch (e) { /* not subscribed yet */ }   // tells the console the question is on the agent's screen
}

export async function controlRespond(allow) {
    if (!st) return;
    const c = st.ctl;
    if (c.state !== 'asking' || !c.requestId) return;
    // The answer is the one call that must not be lost: a busy minute (429), a dropped connection (0) or a server hiccup (5xx) is retried, and if it still
    // cannot be sent the question STAYS on screen with a plain "try again" - never silently dropped (the console would wait on "Requested" for good).
    let r = null;
    for (let attempt = 0; attempt < 3; attempt++) {
        r = await st.api('POST', controlUrl('respond'), { allow: !!allow, request_id: c.requestId });
        if (r.ok || !(r.status === 429 || r.status === 0 || r.status >= 500)) break;
        await new Promise((res) => setTimeout(res, 1000 * (attempt + 1)));
        if (!st || st.ctl !== c) return;
    }
    if (!st || st.ctl !== c) return;
    if (!r.ok) {
        if (r.status === 429 || r.status === 0 || r.status >= 500) { st.ui.controlAsk(st.session.support_name, 'could not send your answer - press ' + (allow ? 'Allow' : 'Decline') + ' again'); return; }
        endControlLocal('lapsed');   // the request already lapsed, was withdrawn, or was replaced by another
        return;
    }
    if (!allow) { endControlLocal('declined'); return; }
    // Allow: the answer carries the grant key. THIS press is what lets control start here (c.allowed); the server's desk.control_granted event also calls
    // beginControl, whichever of the two arrives first - it starts once.
    c.allowed = true;
    if (r.json.grant && r.json.grant.key) c.grant = r.json.grant;
    beginControl(st.cfg.controlIdleSeconds);
}

export async function controlRevoke() {
    if (!st) return;
    endControlLocal('agent');
    await st.api('POST', controlUrl('revoke'), { reason: 'agent' });
}

/** The grant key again, from the server (page change, control moved to this tab). Only the agent can ask; only for the tab control is granted on. */
async function fetchGrant() {
    const r = await st.api('POST', controlUrl('key'), { tab: st.tab });
    return r.ok && r.json.grant && r.json.grant.key ? r.json.grant : null;
}

/**
 * Start replaying - idempotent. Runs only after this page's own Allow (or the server's word on a resume), needs the grant key and WebCrypto (without either:
 * no control, never unsigned control), and re-checks after every await that it is still the same take-over (an end, a decline or a new question in between
 * leaves nothing running and no timer behind).
 */
async function beginControl(idleSeconds) {
    const c = st && st.ctl;
    if (!c || !c.allowed || c.state === 'starting' || c.state === 'granted') return;
    c.state = 'starting';
    const alive = () => st && st.ctl === c && c.state === 'starting';
    try {
        if (!cryptoAvailable()) throw new Error('no WebCrypto');
        if (!c.grant) c.grant = await fetchGrant();
        if (!alive()) return;
        if (!c.grant) throw new Error('no grant');
        await loadExec();
    } catch (e) {
        if (!st || st.ctl !== c) return;
        console.warn('[corex-desk] take-over could not start:', e && e.message);
        endControlLocal('agent');
        st.api('POST', controlUrl('revoke'), { reason: 'agent' });   // tell the server and the console: nothing will be replayed here
        return;
    }
    if (!alive()) return;
    c.key = c.grant.key;
    c.lastSeq = 0;
    c.state = 'granted';
    c.exec = window.CoreXDeskExec.create({ doc: document, win: window, isBlocked: blockedField });
    c.reporter = window.CoreXDeskExec.createReporter((actions) => st && st.api('POST', controlUrl('actions'), { tab: st.tab, actions }), 1500);
    const secs = Math.max(60, idleSeconds || st.cfg.controlIdleSeconds || 300);
    if (c.idle) c.idle.stop();   // (never two idle clocks)
    c.idle = window.CoreXDeskExec.createIdleTimer(secs, () => { if (st && st.ctl === c) { endControlLocal('idle'); st.api('POST', controlUrl('revoke'), { reason: 'idle' }); } });
    c.idle.start();
    st.ui.controlOn(st.session.support_name);
}

/** Control is over (any reason): stop replaying, flush the report, forget the key, put the bar back to "viewing". */
function endControlLocal(reason, quiet) {
    if (!st) return;
    const c = st.ctl;
    const wasOn = c.state === 'granted' || c.state === 'asking' || c.state === 'starting';
    if (c.reporter) c.reporter.flush();
    if (c.idle) c.idle.stop();
    c.state = 'none';   // (a beginControl still awaiting something sees this and stops)
    st.ctl = newCtl();
    if (wasOn && !quiet) st.ui.controlOff(reason);
}

/** A support action whisper. It is queued so verification (async) and replay keep the order they were sent in. Anything else is dropped and counted. */
function onActionWhisper(a) {
    const c = st && st.ctl;
    if (!c || c.state !== 'granted' || !c.exec || !c.key) { st.droppedActions++; return; }
    c.q = c.q.then(() => verifyAndRun(c, a)).catch(() => { if (st) st.droppedActions++; });
}

async function verifyAndRun(c, msg) {
    if (!st || st.ctl !== c || c.state !== 'granted') return;
    const v = await verifyAction(c.key, msg, c.lastSeq);
    if (!st || st.ctl !== c || c.state !== 'granted') return;
    // Signed, in order, and meant for THIS tab (the signed payload names its tab, so a recorded action cannot be pointed at another one).
    if (!v.ok || v.action.t !== st.tab) { st.droppedActions++; return; }
    c.lastSeq = v.seq;
    runAction(v.action);
}

/** One verified action. Only replayed while control is granted HERE; refusals are reported like anything else. */
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

// ── The message strip (spec section 4h) ─────────────────────────────────────────

const THREAD_MAX = 30;
const uid = () => (Date.now().toString(36) + Math.random().toString(36).slice(2, 6)).slice(0, 14);

/** Add a line to the thread once (by its id). Only the server's broadcast calls this. */
function addLine(m) {
    if (!st || !m || typeof m.text !== 'string' || !m.text.trim() || !m.id || st.threadIds.has(m.id)) return null;
    const max = st.cfg.messageMax || 300;
    const line = { id: String(m.id), from: m.from === 'agent' ? 'agent' : 'support', name: String(m.name || '').slice(0, 120), text: m.text.slice(0, max), tab: m.tab || 'main', at: m.at || Date.now() };
    st.threadIds.add(line.id);
    st.thread.push(line);
    if (st.thread.length > THREAD_MAX) st.threadIds.delete(st.thread.shift().id);
    return line;
}

function onMessage(m) {
    const line = addLine(m);
    if (!line) return;
    st.ui.thread(st.thread, line.from === 'support');
    // A highlight showing on this tab carries the line too, on its card.
    if (line.from === 'support' && st.hl && forMe(line.tab)) setHighlightNote(line.text);
}

/**
 * The agent's reply from the red bar. It goes to the server, which records it and broadcasts it; the line appears in this thread (and the console's) from
 * that broadcast - the one copy everyone sees is the audited one. No whisper: nothing a channel member whispers is ever shown as a message.
 */
export function sendMessage(text) {
    if (!st) return false;
    text = String(text || '').replace(/\s+/g, ' ').trim().slice(0, st.cfg.messageMax || 300);
    if (!text) return false;
    st.api('POST', st.cfg.urls.sessions + '/' + st.session.id + '/message', { text, tab: st.tab, id: uid() }).then((r) => {
        if (!r.ok) console.warn('[corex-desk] reply not recorded', r.status);
    });
    return true;
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

/** The card beside the ring: made on first use, then just re-worded. */
function setHighlightNote(text) {
    const h = st && st.hl;
    if (!h || !text) return;
    let n = h.ring.querySelector('.cd-hl-note');
    if (!n) { n = document.createElement('div'); n.className = 'cd-hl-note'; h.ring.appendChild(n); }
    n.textContent = String(text);
}

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
    ring.innerHTML = '<span class="cd-hl-label"></span>';
    ring.querySelector('.cd-hl-label').textContent = name;
    document.body.appendChild(ring);
    const h = { target, ring, raf: 0, last: '' };
    st.hl = h;
    // The card carries what support just wrote (the message strip), unless the highlight came with a note of its own.
    const recent = st.thread.filter((l) => l.from === 'support' && forMe(l.tab) && Date.now() - l.at < 60000).pop();
    if (note || recent) setHighlightNote(note || recent.text);
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
