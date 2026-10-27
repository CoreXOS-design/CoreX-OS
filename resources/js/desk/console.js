// CoreX Desk - the SUPPORT console (spec .ai/specs/corex-desk.md sections 4 and 13). Own Vite entry, loaded only by desk/console.blade.php.
//
// Join by code -> subscribe to the session's private channel -> ask the agent's tab for a fresh full snapshot (client-resync) ->
// rebuild the agent's page with rrweb's live-mode Replayer. The mouse over the replayed page is whispered to the agent as a
// labelled cursor; a click resolves the element to a selector and asks the server (audited) to have the agent outline it.
//
// Phase 3 (protocol 1.1): the session can hold several TABS - the agent's CoreX page ("main") plus any Chrome tabs the CoreX
// extension shares ("x<chrome tab id>"). Each tab has its own Replayer; a tab bar switches between them. Every whisper and
// highlight names the tab it is for; a frame with no `t` is the CoreX page.
import { Replayer } from 'rrweb';
import 'rrweb/dist/style.css';
import { getEcho, dropEcho } from './echo.js';
import { Reassembler } from './frames.js';

const cfg = window.CorexDesk || {};
const con = window.CorexDeskConsole || {};
const $ = (id) => document.getElementById(id);

const EVENT_META = 4;
const EVENT_FULL = 2;
const MAIN = 'main';

let cur = null; // { session, channel, name, tabs: Map(key -> tab), selected, started, tick, retry, pointerOn, lastPointer, control: {state, tab}, focusSel, typed }

async function api(method, url, body) {
    try {
        const r = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : undefined,
        });
        return { ok: r.ok, status: r.status, json: await r.json().catch(() => ({})) };
    } catch (e) {
        return { ok: false, status: 0, json: {} };
    }
}

// ── Messages: a line under the header on the list page; a small toast over the window while in the viewer ───────
let toastTimer = null;
function say(msg, bad = false) {
    const viewer = $('cdc-live');
    if (viewer && !viewer.hidden) {
        const t = $('cdc-toast');
        clearTimeout(toastTimer);
        t.hidden = !msg;
        t.textContent = msg || '';
        t.classList.toggle('cdc-bad', !!bad);
        if (msg) toastTimer = setTimeout(() => { t.hidden = true; }, bad ? 5000 : 2800);
        return;
    }
    const el = $('cdc-msg');
    el.textContent = msg || '';
    el.style.color = bad ? 'var(--ds-danger, #b91c1c)' : 'var(--text-muted)';
}

// ── The viewer: the agent's window fills the browser window (spec section 4d) ───────────────────────────────────
// The viewer element lives in <body> so no part of the page layout - menu, header, test-site banner - can sit over it.
document.body.appendChild($('cdc-live'));
let barTimer = null;
function showBar() {
    const bar = $('cdc-bar');
    bar.classList.remove('cdc-away');
    clearTimeout(barTimer);
    barTimer = setTimeout(() => {
        // not while the pointer is on the bar or a field in it has focus (typing a note)
        if (bar.matches(':hover') || bar.contains(document.activeElement) && document.activeElement !== document.body) { showBar(); return; }
        bar.classList.add('cdc-away');
    }, 3000);
}
function openViewer() {
    $('cdc-live').hidden = false;
    document.documentElement.style.overflow = 'hidden';
    showBar();
}
function closeViewer() {
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(() => {});
    $('cdc-live').hidden = true;
    document.documentElement.style.overflow = '';
    clearTimeout(barTimer);
}
$('cdc-bar').addEventListener('mousemove', showBar);
// Leaving the toolbar tucks it away at once (1.2 s), so it is not in the way of what you point at next.
$('cdc-bar').addEventListener('mouseleave', () => {
    clearTimeout(barTimer);
    barTimer = setTimeout(() => { const b = $('cdc-bar'); if (!b.contains(document.activeElement) || document.activeElement === document.body) b.classList.add('cdc-away'); }, 1200);
});
$('cdc-bar').addEventListener('focusin', showBar);
// The toolbar returns when the mouse touches the top edge (8 px) - no strip sits over the picture, so the top of the agent's window can be pointed at and clicked.
$('cdc-pane').addEventListener('mousemove', (ev) => { if (ev.clientY <= 8) showBar(); });
document.addEventListener('fullscreenchange', () => { $('cdc-full').textContent = document.fullscreenElement ? 'Exit full screen' : 'Full screen'; layoutPane(); });
$('cdc-full').addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen();
    else if ($('cdc-live').requestFullscreen) $('cdc-live').requestFullscreen().catch(() => say('This browser would not go full screen.', true));
});

// ── Joining ──────────────────────────────────────────────────────────────────────

async function join(code) {
    say('Joining…');
    const r = await api('POST', con.urls.join, { code });
    if (!r.ok) {
        say(r.json.message || (r.json.errors && r.json.errors.code && r.json.errors.code[0]) || 'Could not join.', true);
        return;
    }
    say('');
    attach(r.json.session);
}

function newTab(key, label, host) {
    const root = document.createElement('div');
    root.className = 'cdc-tab';
    root.hidden = true;
    $('cdc-replay').appendChild(root);
    return { key, label, host: host || '', root, replayer: null, events: [], reassembler: null };
}

function attach(session) {
    if (cur) detach(false);
    const name = 'desk.session.' + session.id;
    const channel = getEcho().private(name);
    cur = { session, channel, name, tabs: new Map(), selected: MAIN, started: Date.parse(session.joined_at || session.started_at), pointerOn: false, lastPointer: 0, control: { state: (session.control && session.control.state) || 'none', tab: session.control && session.control.tab }, focusSel: null, typed: null, hl: !(session.control && session.control.state === 'granted') };
    cur.tabs.set(MAIN, newTab(MAIN, 'CoreX page', ''));
    (session.tabs || []).forEach((t) => addExtTab(t));

    channel
        .listenForWhisper('frame', (chunk) => {
            const tab = cur && cur.tabs.get(chunk && chunk.t ? chunk.t : MAIN);
            if (!tab) return;   // a tab we have not been told about (yet): its tab_joined event creates it, the resync below asks again
            if (!tab.reassembler) tab.reassembler = new Reassembler((events) => onEvents(tab, events));
            tab.reassembler.push(chunk);
        })
        .listen('.desk.tab_joined', (e) => { addExtTab(e); askResync(e.tab); })
        .listen('.desk.tab_updated', (e) => updateExtTab(e))
        .listen('.desk.tab_left', (e) => removeExtTab(e.tab))
        .listenForWhisper('viewport', (v) => {
            // The agent's window size changed (resize, zoom) or was re-announced: lay the mirror out at it and refit.
            const tab = cur && cur.tabs.get(v && v.t ? v.t : MAIN);
            if (!tab || !tab.replayer || !v || !(v.w > 0) || !(v.h > 0)) return;
            const f = tab.replayer.iframe;
            if (parseFloat(f.getAttribute('width')) !== v.w || parseFloat(f.getAttribute('height')) !== v.h) { f.setAttribute('width', String(v.w)); f.setAttribute('height', String(v.h)); }
            if (cur.selected === tab.key) fit();
        })
        .listenForWhisper('action_result', (r) => {
            // The agent could not do what we asked: say why in words; if its screen no longer matches ours, refresh ours.
            if (!cur || !r || r.ok) return;
            const t = r.t || MAIN;
            const words = { view_out_of_date: 'The screen had just changed - it has been refreshed, click again.', not_found: 'That is no longer on the agent\'s screen.', hidden: 'That is not visible on the agent\'s screen.', private_field: 'That field is private - ask the agent to fill it in themselves.', disabled: 'That is switched off on the agent\'s screen.', desk_ui: 'That is part of the agent\'s CoreX Desk bar.' };
            say(words[r.reason] || 'The agent\'s screen could not do that.', true);
            if (r.reason === 'view_out_of_date' || r.reason === 'not_found') askResync(t);
        })
        .listenForWhisper('control_ack', () => clearTimeout(cur && cur.askTimer))   // the agent's page is showing the question
        // Take-over (protocol 1.2)
        .listen('.desk.control_requested', (e) => setControl('requested', e.tab))
        .listen('.desk.control_granted', (e) => setControl('granted', e.tab))
        .listen('.desk.control_declined', (e) => setControl('none', e.tab, e.reason === 'no_answer' ? 'The agent did not answer in time.' : 'The agent declined.'))
        .listen('.desk.control_revoked', (e) => setControl('none', e.tab, 'The agent took back control.'))
        .listen('.desk.control_ended', (e) => setControl('none', e.tab, { idle: 'Control ended - nothing was done for a while.', tab: 'Control ended - the tab was closed.', session: 'Control ended with the session.', support: 'You handed control back.' }[e.reason] || 'Control ended.'))
        .listen('.desk.ended', (e) => ended(e.by))
        .error(() => say('Could not open the live connection.', true));

    // Subscribed: a tab may already have sent its first snapshot before we listened - ask every tab for a fresh one.
    channel.subscribed(() => cur && cur.tabs.forEach((t) => askResync(t.key)));
    cur.retry = setInterval(() => { if (cur) cur.tabs.forEach((t) => { if (!t.replayer) askResync(t.key); }); }, 3000); // until a tab's first snapshot arrives

    $('cdc-form').hidden = true;
    $('cdc-support').textContent = con.me || ''; $('cdc-support').title = con.me || '';
    openViewer();
    layoutPane();
    $('cdc-agent').textContent = session.agent_name || '';
    $('cdc-agency').textContent = session.agency_name || '';
    $('cdc-page').textContent = session.page || '—';
    cur.tick = setInterval(tickElapsed, 1000);
    cur.poll = setInterval(reconcile, 2000);   // belt and braces: events can be missed (a dropped connection, a restart) - the server's word is read every 2 s
    tickElapsed();
    renderTabs();
    showSelected();
    paintControl();
    refreshList();
}

/**
 * Every 2 s: ask the server where this session stands and fall into line with it. The websocket tells us the moment something happens, but a missed
 * event (a dropped connection, a restart, a throttled call on the agent's side) must not leave the console waiting on "Requested" for good.
 */
async function reconcile() {
    if (!cur || cur.reconciling) return;
    const id = cur.session.id;
    cur.reconciling = true;
    try {
        const r = await api('GET', con.urls.list);
        if (!r.ok || !cur || cur.session.id !== id) return;
        const row = (r.json.sessions || []).find((s) => s.id === id);
        if (!row) { ended('expired'); return; }   // no longer open on the server
        const c = row.control || { state: 'none', tab: null };
        const before = cur.control;
        if (c.state !== before.state || (c.state !== 'none' && (c.tab || null) !== (before.tab || null))) {
            const note = c.state === 'granted' ? 'The agent allowed control.' : (c.state === 'none' ? (before.state === 'requested' ? 'The request ended.' : 'Control ended.') : '');
            setControl(c.state, c.tab, note);
        }
    } finally {
        if (cur) cur.reconciling = false;
    }
}

function askResync(key) {
    if (cur) cur.channel.whisper('resync', { t: key });
}

function detach(callServer) {
    if (!cur) return;
    clearInterval(cur.tick);
    clearInterval(cur.poll);
    clearInterval(cur.retry);
    clearTimeout(cur.typeTimer);
    clearInterval(cur.focusTimer);
    if (callServer) api('POST', con.urls.sessions + '/' + cur.session.id + '/end');
    cur.tabs.forEach((t) => { if (t.ro) t.ro.disconnect(); });
    $('cdc-replay').innerHTML = '';
    $('cdc-tabs').innerHTML = '';
    cur = null;
    dropEcho();
}

function ended(by) {
    if (!cur) return;
    const who = { agent: 'The agent ended the session.', support: 'You ended the session.', logout: 'The agent signed out.', timeout: 'The connection to the agent was lost.', expired: 'The session expired.' }[by] || 'The session ended.';
    leave();
    say(who);
}

/** Back to the list page (the session is NOT ended here: End session / the other side does that). */
function leave() {
    detach(false);
    closeViewer();
    $('cdc-form').hidden = false;
    refreshList();
}

function tickElapsed() {
    if (!cur) return;
    const s = Math.max(0, Math.round((Date.now() - cur.started) / 1000));
    $('cdc-elapsed').textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}

// ── Tabs (the CoreX page + the extension's shared Chrome tabs) ───────────────────

const labelFor = (t) => (t.title && t.title.trim() ? t.title.trim() : t.host) + ' · ' + t.host;

function addExtTab(t) {
    if (!cur || !t || !t.tab || cur.tabs.has(t.tab)) return;
    cur.tabs.set(t.tab, newTab(t.tab, labelFor(t), t.host));
    renderTabs();
}

function updateExtTab(t) {
    const tab = cur && cur.tabs.get(t.tab);
    if (!tab) { addExtTab(t); return; }
    tab.label = labelFor(t);
    tab.host = t.host;
    renderTabs();
}

function removeExtTab(key) {
    const tab = cur && cur.tabs.get(key);
    if (!tab) return;
    tab.root.remove();
    cur.tabs.delete(key);
    if (cur.selected === key) { cur.selected = MAIN; showSelected(); }
    renderTabs();
    say('A shared tab was closed or stopped by the agent.');
}

function renderTabs() {
    const bar = $('cdc-tabs');
    if (!cur) return;
    bar.innerHTML = '';
    bar.hidden = cur.tabs.size < 2;
    cur.tabs.forEach((tab) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = tab.key === cur.selected ? 'corex-btn-primary' : 'corex-btn-outline';
        b.style.cssText = 'max-width:22rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
        b.textContent = tab.label;
        b.title = tab.label;
        b.addEventListener('click', () => selectTab(tab.key));
        bar.appendChild(b);
    });
}

function selectTab(key) {
    if (!cur || !cur.tabs.has(key) || cur.selected === key) return;
    cur.selected = key;
    cur.pointerOn = false;
    renderTabs();
    showSelected();
    paintControl();
    askResync(key); // a fresh picture the moment you switch
}

function showSelected() {
    if (!cur) return;
    cur.tabs.forEach((t) => { t.root.hidden = t.key !== cur.selected; });
    const tab = cur.tabs.get(cur.selected);
    $('cdc-wait').hidden = !!(tab && tab.replayer);
    if (tab && tab.replayer) fit();
}

// ── Rebuilding the agent's page ──────────────────────────────────────────────────

function onEvents(tab, events) {
    if (!cur || cur.tabs.get(tab.key) !== tab) return;
    // The Replayer schedules a live event for the moment its timestamp comes round on THIS computer's clock, so an agent whose clock is
    // a few seconds behind or ahead would be mirrored late (or in a burst). A live mirror wants "as it arrives": stamp the batch with
    // the moment it arrived here (order inside the batch is kept - equal stamps keep their sequence).
    const arrived = Date.now();
    for (const ev of events) {
        ev.timestamp = arrived;
        if (ev.type === EVENT_META && ev.data && ev.data.href && cur.selected === tab.key) {
            try {
                const u = new URL(ev.data.href);
                $('cdc-page').textContent = tab.key === MAIN ? u.pathname : u.host + u.pathname;
            } catch (e) { /* ignore */ }
        }
        if (!tab.replayer) {
            tab.events.push(ev);
            if (tab.events.some((e) => e.type === EVENT_META) && tab.events.some((e) => e.type === EVENT_FULL)) startReplayer(tab);
        } else {
            tab.replayer.addEvent(ev);
        }
    }
}

function startReplayer(tab) {
    tab.root.innerHTML = '';
    // useVirtualDom:false - the virtual DOM is a fast-forward optimisation; in live mode a mutation applied "sync" (an event stamped just
    // before the replayer started) switches it on and later mutations then wait in it for a flush that never comes.
    const replayer = new Replayer(tab.events, { root: tab.root, liveMode: true, mouseTail: false, showWarning: false, showDebug: false, skipInactive: false, useVirtualDom: false,
        // pauseAnimation:false - in live mode rrweb adds html.rrweb-paused and freezes EVERY CSS animation, and CoreX's menus, panels and cards
        // appear through entrance animations: frozen at their invisible first frame they simply never showed up (spec section 4a).
        pauseAnimation: false,
        // rrweb draws a blocked element (our own .rr-block Desk UI) as a solid currentColor box - a dark strip where the red bar is.
        // Keep its room, lose its colour.
        insertStyleRules: [
            '.rr-block { background: transparent !important; }',
            // A mirror shows the page as it IS, not the page arriving: no entrance animations or transitions. (Every full snapshot rebuilds the
            // document, which would otherwise replay each staggered fade-in and leave late items invisible for seconds.)
            '*, *::before, *::after { animation-duration: 0.001s !important; animation-delay: 0s !important; animation-iteration-count: 1 !important; transition-duration: 0s !important; transition-delay: 0s !important; }',
        ] });
    tab.replayer = replayer;
    replayer.startLive();
    hookReplayInput(tab);
    replayer.on('resize', () => { if (cur && cur.selected === tab.key) fit(); });
    replayer.on('fullsnapshot-rebuilded', () => { if (cur && cur.selected === tab.key) fit(); });
    // Whatever changes the mirror's own size (a Meta / resize event, the agent's viewport whisper), the scale follows.
    if (window.ResizeObserver) { const ro = new ResizeObserver(() => { if (cur && cur.selected === tab.key) fit(); }); ro.observe(replayer.iframe); tab.ro = ro; }
    showSelected();
    paintControl();
}

/**
 * Fit the agent's WHOLE window into the browser window: scale = min(window width / agent width, window height / agent height), letterboxed and
 * centred where the aspect differs. The iframe keeps the agent's own viewport width and height (so its media queries and its sidebar / content
 * layout are exactly the agent's) and is only scaled by transform - never scrolled.
 */
function layoutPane() {
    if ($('cdc-live').hidden) return;
    fit();
}

function fit() {
    const tab = cur && cur.tabs.get(cur.selected);
    const pane = $('cdc-pane');
    if (!tab || !tab.replayer || !pane) return;
    const wrapper = tab.replayer.wrapper;
    const iframe = tab.replayer.iframe;
    // The replayed page's own size (the agent's window) is on the iframe's width/height attributes, set from the Meta event.
    const w = parseFloat(iframe.getAttribute('width')) || wrapper.offsetWidth;
    const h = parseFloat(iframe.getAttribute('height')) || wrapper.offsetHeight;
    const aw = pane.clientWidth, ah = pane.clientHeight;
    if (!w || !h || !aw || !ah) return;
    const scale = Math.min(aw / w, ah / h, 3);
    const rep = $('cdc-replay');
    rep.style.width = Math.floor(w * scale) + 'px';
    rep.style.height = Math.floor(h * scale) + 'px';
    rep.style.left = Math.max(0, Math.floor((aw - w * scale) / 2)) + 'px';
    rep.style.top = Math.max(0, Math.floor((ah - h * scale) / 2)) + 'px';
    tab.root.style.width = rep.style.width; tab.root.style.height = rep.style.height;
    // The wrapper is a block: left alone it is only as wide as its parent (the SCALED width), and the transform then shrinks that box again - so the
    // part of the window that answered the mouse was scale x scale of it (the pointer "only reached 65% across" at a 0.65 scale). Give it the agent's
    // own window size, so what you see is exactly what you can point at.
    wrapper.style.width = w + 'px';
    wrapper.style.height = h + 'px';
    wrapper.style.transformOrigin = 'top left';
    wrapper.style.transform = 'scale(' + scale + ')';
}
window.addEventListener('resize', layoutPane);

// ── Pointer and highlight ────────────────────────────────────────────────────────

const tabParam = (tab) => (tab.key === MAIN ? {} : { tab: tab.key });

function hookReplayInput(tab) {
    const iframe = tab.replayer.iframe;
    // Pointer position -> the agent's viewport. The iframe keeps the agent's own viewport size (offsetWidth/Height, unscaled) and is only
    // scaled by a transform, so a point on screen is (clientX - the iframe's on-screen left) / the scale, in the agent's own CSS pixels - the
    // same pixels elementFromPoint() and getBoundingClientRect() use inside the mirror. (Never the document's clientWidth: a scrollbar in
    // the mirror makes that narrower than the agent's window and every click would drift towards the right-hand edge.)
    const geo = (ev) => {
        const r = iframe.getBoundingClientRect();
        const W = iframe.offsetWidth || parseFloat(iframe.getAttribute('width')) || r.width;
        const H = iframe.offsetHeight || parseFloat(iframe.getAttribute('height')) || r.height;
        const k = r.width / W;
        return { px: (ev.clientX - r.left) / k, py: (ev.clientY - r.top) / k, W, H, k };
    };
    // What goes to the agent / the pointer whisper: fractions of the agent's window.
    const ratio = (ev) => { const g = geo(ev); return { x: g.px / g.W, y: g.py / g.H }; };
    const scale = () => { const r = iframe.getBoundingClientRect(); const W = iframe.offsetWidth || r.width; return W ? r.width / W : 1; };
    const at = (ev) => {
        const d = iframe.contentDocument;
        const g = geo(ev);
        return d ? d.elementFromPoint(g.px, g.py) : null;
    };
    // Mouse events land on the wrapper (the iframe itself does not deliver them to us while replaying).
    const target = tab.replayer.wrapper;
    target.style.pointerEvents = 'auto';
    iframe.style.pointerEvents = 'none';
    target.style.cursor = 'crosshair';
    target.tabIndex = 0;   // so typing can be captured while support is in control
    const controlling = () => cur && cur.selected === tab.key && cur.control.state === 'granted' && cur.control.tab === tab.key;
    // The Replayer re-plays the agent's own focus changes inside the mirror, which would pull keyboard focus into the iframe and away from the
    // wrapper that captures support's typing. Take it straight back while in control.
    document.addEventListener('focusin', (e) => { if (e.target === iframe && controlling()) target.focus({ preventScroll: true }); });

    // The pointer is whispered at most every 40 ms - but the LAST position always goes (a trailing send): dropping it left the agent's cursor
    // one step behind wherever you stopped. It carries the window size this console believes, so an agent whose window changed can correct us.
    const sendPointer = (r) => {
        if (!cur || cur.selected !== tab.key) return;
        cur.lastPointer = Date.now();
        if (!cur.pointerOn) {
            cur.pointerOn = true;
            api('POST', con.urls.sessions + '/' + cur.session.id + '/pointer', { on: true, ...tabParam(tab) });
        }
        cur.channel.whisper('pointer', { t: tab.key, who: con.me, vw: iframe.offsetWidth, vh: iframe.offsetHeight, ...r });
    };
    let trailing = null, trailingTimer = null;
    target.addEventListener('mousemove', (ev) => {
        if (!cur || cur.selected !== tab.key) return;
        const r = ratio(ev);
        const wait = 40 - (Date.now() - cur.lastPointer);
        if (wait <= 0) { clearTimeout(trailingTimer); trailingTimer = null; sendPointer(r); return; }
        trailing = r;
        if (!trailingTimer) trailingTimer = setTimeout(() => { trailingTimer = null; if (trailing) { const t = trailing; trailing = null; sendPointer(t); } }, wait);
    });
    target.addEventListener('mouseleave', () => {
        clearTimeout(trailingTimer); trailingTimer = null; trailing = null;
        if (cur && cur.pointerOn && cur.selected === tab.key) {
            cur.pointerOn = false;
            api('POST', con.urls.sessions + '/' + cur.session.id + '/pointer', { on: false, ...tabParam(tab) });
        }
    });
    target.addEventListener('click', (ev) => {
        if (!cur || cur.selected !== tab.key) return;
        const r = ratio(ev);
        const el = at(ev);
        if (!el) return;
        target.focus({ preventScroll: true });
        // In control, a plain click is a real click on the agent's page - unless Highlight is switched on (or Shift is held): then it points.
        if (controlling() && !ev.shiftKey && !cur.hl) { controlClick(tab, el, exactSelector(el), r); return; }
        if (!cur.hl && !ev.shiftKey) return;   // viewing only, Highlight off: a click does nothing
        const selector = selectorFor(el);
        const note = $('cdc-note').value.trim();
        api('POST', con.urls.sessions + '/' + cur.session.id + '/highlight', { selector, note: note || null, ...tabParam(tab) }).then((res) => {
            // The toast names what was pointed at in words (never a selector).
            say(res.ok ? 'Highlighted: ' + visibleLabel(el) : 'Could not send the highlight.', !res.ok);
        });
    });
    target.addEventListener('dblclick', (ev) => {
        if (!controlling() || ev.shiftKey || cur.hl) return;
        const r = ratio(ev); const el = at(ev);
        if (el && !isPrivate(el)) sendAction(tab, { type: 'dblclick', s: exactSelector(el), x: r.x, y: r.y, b: boxOf(el) });
    });
    const onKey = (ev) => {
        if (!controlling() || ev.ctrlKey || ev.metaKey || ev.altKey) return;
        if (ev.key.length === 1) { ev.preventDefault(); typeChar(tab, ev.key); return; }
        if (['Enter', 'Tab', 'Escape', 'Backspace', 'Delete', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(ev.key)) {
            ev.preventDefault();
            flushTyped(tab);
            if (!cur.focusSel && ev.key !== 'Tab' && cur.privateFocus) { say('That field is private - ask the agent to fill it in themselves.', true); return; }
            sendAction(tab, { type: 'key', k: ev.key, sh: ev.shiftKey, s: cur.focusSel || '' });
        }
    };
    const onPaste = (ev) => {
        if (!controlling() || !cur.focusSel) return;
        const text = (ev.clipboardData && ev.clipboardData.getData('text')) || '';
        if (text) { ev.preventDefault(); flushTyped(tab); sendAction(tab, { type: 'type', s: cur.focusSel, v: text.slice(0, 500) }); }
    };
    target.addEventListener('keydown', onKey);
    target.addEventListener('paste', onPaste);
    // The mirror re-plays the agent's own focus changes, which can move keyboard focus INTO the iframe: catch keys there too (the iframe's
    // window is re-bound after every full snapshot, which resets it), and hand focus back to the wrapper.
    const bindIframe = () => {
        const w = iframe.contentWindow;
        if (!w) return;
        w.removeEventListener('keydown', onKey, true); w.addEventListener('keydown', onKey, true);
        w.removeEventListener('paste', onPaste, true); w.addEventListener('paste', onPaste, true);
    };
    bindIframe();
    tab.replayer.on('fullsnapshot-rebuilded', bindIframe);
    let wheel = null;
    target.addEventListener('wheel', (ev) => {
        if (!controlling()) return;
        ev.preventDefault();
        const el = at(ev);
        const sc = scale();
        wheel = wheel || { dx: 0, dy: 0, sel: scrollerFor(el), timer: setTimeout(() => { const w = wheel; wheel = null; sendAction(tab, { type: 'scroll', s: w.sel, dx: Math.round(w.dx), dy: Math.round(w.dy) }); }, 60) };
        wheel.dx += ev.deltaX / sc; wheel.dy += ev.deltaY / sc;
    }, { passive: false });
}

// ── Take-over: capture and send (phase 2, spec section 14) ───────────────────────

/** Best effort on the console side (the agent's browser is the real gate): a field the mirror shows as stars or that is a password. */
function isPrivate(el) {
    if (!el || !el.closest) return false;
    const t = String(el.type || '').toLowerCase();
    if (t === 'password' || el.closest('[data-desk-mask]')) return true;
    const v = el.value || el.getAttribute('value') || '';
    return /^(input|textarea)$/i.test(el.tagName) && v.length > 0 && /^\*+$/.test(v);
}

const isEditable = (el) => !!el && ((el.tagName === 'INPUT' && !['button', 'submit', 'reset', 'checkbox', 'radio', 'file', 'image', 'hidden', 'range', 'color', 'password'].includes(String(el.type || 'text').toLowerCase())) || el.tagName === 'TEXTAREA' || el.isContentEditable);

function controlClick(tab, el, selector, r) {
    if (isPrivate(el)) {
        cur.focusSel = null; cur.typed = null; cur.privateFocus = true;   // nothing typed afterwards may land in the PREVIOUS field
        say('That field is private - ask the agent to fill it in themselves.', true);
        return;
    }
    if (el.tagName === 'SELECT') { chooseOption(tab, el, selector); return; }
    cur.focusSel = isEditable(el) ? selector : null;
    cur.privateFocus = false;
    flushTyped(tab);
    sendAction(tab, { type: 'click', s: selector, x: r.x, y: r.y, b: boxOf(el) });
}

/** A dropdown cannot open on a static mirror: offer its options in a small native list on top of it. */
function chooseOption(tab, el, selector) {
    const iframe = tab.replayer.iframe;
    const sc = iframe.getBoundingClientRect().width / (iframe.offsetWidth || 1);
    const rect = el.getBoundingClientRect();
    const sel = document.createElement('select');
    sel.size = Math.min(8, Math.max(2, el.options.length));
    sel.style.cssText = 'position:fixed;z-index:20;min-width:10rem;left:' + (iframe.getBoundingClientRect().left + rect.left * sc) + 'px;top:' + (iframe.getBoundingClientRect().top + (rect.bottom * sc)) + 'px;';
    Array.from(el.options).forEach((o) => { const op = document.createElement('option'); op.value = o.value; op.textContent = o.textContent; sel.appendChild(op); });
    // (select.remove() is the dropdown's own remove-an-option method - the list has to be taken out through its parent.) Change closes it and the
    // blur that follows must not try again.
    // Removing a focused element fires its blur synchronously - inside the removal - so a flag, not a parentNode check, keeps it to one removal.
    let closed = false;
    const close = () => { if (closed) return; closed = true; if (sel.parentNode) sel.parentNode.removeChild(sel); };
    sel.addEventListener('change', () => { sendAction(tab, { type: 'select', s: selector, v: sel.value }); close(); });
    sel.addEventListener('blur', close);
    $('cdc-live').appendChild(sel);   // inside the viewer, so it shows in full screen too
    sel.focus();
}

/** The nearest scrollable ancestor of the element under the pointer, as a selector ('' = the page itself). */
function scrollerFor(el) {
    for (let n = el; n && n.nodeType === 1 && n.tagName !== 'BODY' && n.tagName !== 'HTML'; n = n.parentElement) {
        const cs = n.ownerDocument.defaultView.getComputedStyle(n);
        if ((cs.overflowY === 'auto' || cs.overflowY === 'scroll') && n.scrollHeight > n.clientHeight + 4) return exactSelector(n);
    }
    return '';
}

/** Keystrokes are joined into one `type` action every ~120 ms (fewer whispers; the agent's report counts them as one line). */
function typeChar(tab, ch) {
    if (!cur.focusSel) { say(cur.privateFocus ? 'That field is private - ask the agent to fill it in themselves.' : 'Click a text field in the window first.', true); return; }
    cur.typed = (cur.typed || '') + ch;
    clearTimeout(cur.typeTimer);
    cur.typeTimer = setTimeout(() => flushTyped(tab), 120);
}

function flushTyped(tab) {
    if (!cur || !cur.typed) return;
    clearTimeout(cur.typeTimer);
    const v = cur.typed; cur.typed = null;
    sendAction(tab, { type: 'type', s: cur.focusSel, v, n: v.length });
}

/** One action, browser to browser (a whisper): the text never passes through CoreX. The AGENT's browser reports what it did, for the audit. */
function sendAction(tab, a) {
    if (!cur) return;
    cur.channel.whisper('action', { t: tab.key, id: Date.now().toString(36) + Math.random().toString(36).slice(2, 5), ...a });
}

function setControl(state, tabKey, note) {
    if (!cur) return;
    clearInterval(cur.focusTimer);
    cur.control = { state, tab: state === 'none' ? null : tabKey };
    clearTimeout(cur.askTimer);
    if (state === 'requested') {
        // An agent page that was opened before CoreX Desk learned take-over cannot ask the question. It would stay silent, so say why.
        cur.askTimer = setTimeout(() => say((cur && cur.session.agent_name || 'The agent') + "'s page has not shown the question. If it was open before today's update, ask them to refresh CoreX (F5), then request again.", true), 6000);
    }
    cur.hl = state !== 'granted';   // in control a click clicks; otherwise a click points
    if (state !== 'granted') { cur.typed = null; cur.focusSel = null; cur.privateFocus = false; }
    else {
        // Belt and braces for the focus tug-of-war with the mirror: while in control, give focus back to the capture layer.
        cur.focusTimer = setInterval(() => {
            const t = cur && cur.tabs.get(cur.control.tab);
            if (t && t.replayer && cur.selected === t.key && document.activeElement === t.replayer.iframe) t.replayer.wrapper.focus({ preventScroll: true });
        }, 150);
    }
    paintControl();
    if (note) say(note);
}

/** The Request / Hand back button, the state pill (Viewing / Requested / In control) and the amber frame while in control. */
function paintControl() {
    if (!cur) return;
    const btn = $('cdc-control');
    const status = $('cdc-control-status');
    if (!btn) return;
    btn.hidden = !con.canControl;
    const c = cur.control;
    btn.textContent = c.state === 'none' ? 'Request control' : (c.state === 'requested' ? 'Withdraw request' : 'Hand back');
    btn.className = c.state === 'granted' ? 'corex-btn-outline' : (c.state === 'requested' ? 'corex-btn-outline' : 'corex-btn-primary');
    status.textContent = c.state === 'requested' ? 'Requested' : (c.state === 'granted' ? 'In control' : 'Viewing');
    status.style.background = c.state === 'granted' ? '#f59e0b' : 'var(--surface-2)';
    status.style.color = c.state === 'granted' ? '#1f2937' : 'var(--text-secondary)';
    // The amber frame goes round the whole window while in control of the tab on show.
    const framed = c.state === 'granted' && c.tab === cur.selected;
    $('cdc-live').classList.toggle('cdc-controlling', framed);
    paintHighlight();
}

/** Highlight mode: on while viewing (a click points something out), off once in control (a click is a click). The toggle overrides either way. */
function paintHighlight() {
    if (!cur) return;
    const b = $('cdc-hl');
    b.setAttribute('aria-pressed', cur.hl ? 'true' : 'false');
    b.className = cur.hl ? 'corex-btn-primary' : 'corex-btn-outline';
}

/** [data-tour] anchor when the element (or an ancestor) has one, else a CSS path that is unique on the agent's page. */
export function selectorFor(el) {
    const tour = el.closest && el.closest('[data-tour]');
    if (tour) return '[data-tour="' + tour.getAttribute('data-tour').replace(/"/g, '\\"') + '"]';
    const parts = [];
    let node = el;
    while (node && node.nodeType === 1 && node.tagName !== 'HTML') {
        if (node.id && /^[A-Za-z][\w-]*$/.test(node.id) && !/\d{4,}/.test(node.id)) {
            parts.unshift('#' + node.id);
            break;
        }
        const tag = node.tagName.toLowerCase();
        if (tag === 'body') { parts.unshift('body'); break; }
        let n = 1;
        for (let sib = node.previousElementSibling; sib; sib = sib.previousElementSibling) if (sib.tagName === node.tagName) n++;
        parts.unshift(tag + ':nth-of-type(' + n + ')');
        node = node.parentElement;
    }
    return parts.join(' > ');
}

/** The exact element's own selector - what a CLICK needs (selectorFor above deliberately stops at the nearest [data-tour] section: right for a highlight, wrong for a click on a button inside it). */
export function exactSelector(el) {
    const parts = [];
    let node = el;
    while (node && node.nodeType === 1 && node.tagName !== 'HTML') {
        if (node.id && /^[A-Za-z][\w-]*$/.test(node.id) && !/\d{4,}/.test(node.id)) {
            const d = node.ownerDocument;
            if (d.querySelectorAll('#' + node.id).length === 1) { parts.unshift('#' + node.id); break; }
        }
        const tag = node.tagName.toLowerCase();
        if (tag === 'body') { parts.unshift('body'); break; }
        let n = 1;
        for (let sib = node.previousElementSibling; sib; sib = sib.previousElementSibling) if (sib.tagName === node.tagName) n++;
        parts.unshift(tag + ':nth-of-type(' + n + ')');
        node = node.parentElement;
    }
    return parts.join(' > ');
}

/** Where the element sits in the agent's window, in the agent's own CSS pixels: the agent checks it before it clicks. */
function boxOf(el) {
    const r = el.getBoundingClientRect();
    return [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)];
}

/** What a person would call the element: its label, its text, its placeholder - never a selector. */
export function visibleLabel(el) {
    const pick = (v) => { v = (v || '').replace(/\s+/g, ' ').trim(); return v ? v.slice(0, 60) : ''; };
    const tag = (el.tagName || '').toLowerCase();
    let l = pick(el.getAttribute && el.getAttribute('aria-label')) || pick(el.getAttribute && el.getAttribute('title'));
    if (!l && el.labels && el.labels.length) l = pick(el.labels[0].textContent);
    if (!l && /^(input|textarea|select)$/.test(tag)) l = pick(el.getAttribute('placeholder')) || pick(el.getAttribute('name'));
    if (!l && !/^(input|textarea|select)$/.test(tag)) l = pick(el.innerText || el.textContent);
    if (l) return l;
    return { a: 'a link', button: 'a button', input: 'a field', select: 'a dropdown', textarea: 'a text box', img: 'a picture', svg: 'an icon' }[tag] || 'that spot';
}

// ── Live sessions list ───────────────────────────────────────────────────────────

async function refreshList() {
    const r = await api('GET', con.urls.list);
    if (!r.ok) return;
    const ul = $('cdc-list');
    ul.innerHTML = '';
    const rows = r.json.sessions || [];
    $('cdc-list-empty').hidden = rows.length > 0;
    for (const s of rows) {
        const li = document.createElement('li');
        li.className = 'cdc-row';
        li.dataset.id = s.id; li.dataset.status = s.status;
        li.style.cssText = 'display:flex;gap:.75rem;align-items:center;padding:.35rem 0;border-top:1px solid var(--border);font-size:.8rem;';
        const text = document.createElement('span');
        text.style.cssText = 'flex:1;min-width:0;';
        text.textContent = (s.agent_name || 'Agent') + (s.agency_name ? ' · ' + s.agency_name : '') + ' — ' + (s.status === 'live' ? 'live with ' + (s.support_name || 'support') : 'waiting for a support person');
        li.appendChild(text);
        if (s.status === 'live') {
            const b = document.createElement('button');
            b.type = 'button'; b.className = 'corex-btn-outline'; b.textContent = (cur && cur.session.id === s.id) ? 'Open' : 'Open'; b.dataset.open = s.id;
            li.appendChild(b);
        }
        ul.appendChild(li);
    }
}

/** Open a live session of your scope (again, after a reload; or as a second support person). The code is not needed - it was spent at the first join. */
async function openSession(id) {
    if (cur && cur.session.id === id) { say('You already have this session open.'); return; }
    say('Opening…');
    const r = await api('POST', con.urls.sessions + '/' + id + '/attach');
    if (!r.ok) { say(r.json.message || 'Could not open that session.', true); refreshList(); return; }
    say('');
    attach(r.json.session);
}
$('cdc-list').addEventListener('click', (e) => {
    const b = e.target.closest('[data-open]');
    if (b) openSession(parseInt(b.dataset.open, 10));
});

// ── Wiring ───────────────────────────────────────────────────────────────────────

$('cdc-form').addEventListener('submit', (e) => {
    e.preventDefault();
    const code = $('cdc-code').value.replace(/\D/g, '');
    if (code.length !== 6) { say('Type the 6-digit code the agent reads out.', true); return; }
    join(code);
});
$('cdc-control').addEventListener('click', async () => {
    if (!cur) return;
    const url = con.urls.sessions + '/' + cur.session.id + '/control/';
    const r = cur.control.state === 'none'
        ? await api('POST', url + 'request', { tab: cur.selected })
        : await api('POST', url + 'end');
    if (!r.ok) say(r.json.message || 'Could not do that.', true);
    else if (r.json.control) setControl(r.json.control.state, r.json.control.tab);
});
$('cdc-clear').addEventListener('click', () => {
    if (cur) api('POST', con.urls.sessions + '/' + cur.session.id + '/highlight/clear', cur.selected === MAIN ? {} : { tab: cur.selected });
});
$('cdc-hl').addEventListener('click', () => { if (cur) { cur.hl = !cur.hl; paintHighlight(); } });
$('cdc-back').addEventListener('click', () => { if (cur) { leave(); say('The session is still open - Open it again from the list.'); } });
$('cdc-end').addEventListener('click', () => {
    if (!cur) return;
    const id = cur.session.id;
    leave();
    api('POST', con.urls.sessions + '/' + id + '/end').then(() => {
        say('You ended the session.');
        refreshList();
    });
});
setInterval(refreshList, 10000);
refreshList();
