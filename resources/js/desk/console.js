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

let cur = null; // { session, channel, name, tabs: Map(key -> tab), selected, started, tick, retry, pointerOn, lastPointer }

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

function say(msg, bad = false) {
    const el = $('cdc-msg');
    el.textContent = msg || '';
    el.style.color = bad ? 'var(--ds-danger, #b91c1c)' : 'var(--text-muted)';
}

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
    cur = { session, channel, name, tabs: new Map(), selected: MAIN, started: Date.parse(session.joined_at || session.started_at), pointerOn: false, lastPointer: 0 };
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
        .listen('.desk.ended', (e) => ended(e.by))
        .error(() => say('Could not open the live connection.', true));

    // Subscribed: a tab may already have sent its first snapshot before we listened - ask every tab for a fresh one.
    channel.subscribed(() => cur && cur.tabs.forEach((t) => askResync(t.key)));
    cur.retry = setInterval(() => { if (cur) cur.tabs.forEach((t) => { if (!t.replayer) askResync(t.key); }); }, 3000); // until a tab's first snapshot arrives

    $('cdc-join').hidden = true;
    $('cdc-live').hidden = false;
    $('cdc-agent').textContent = session.agent_name || '';
    $('cdc-agency').textContent = session.agency_name || '';
    $('cdc-page').textContent = session.page || '—';
    cur.tick = setInterval(tickElapsed, 1000);
    tickElapsed();
    renderTabs();
    showSelected();
    refreshList();
}

function askResync(key) {
    if (cur) cur.channel.whisper('resync', { t: key });
}

function detach(callServer) {
    if (!cur) return;
    clearInterval(cur.tick);
    clearInterval(cur.retry);
    if (callServer) api('POST', con.urls.sessions + '/' + cur.session.id + '/end');
    $('cdc-replay').innerHTML = '';
    $('cdc-tabs').innerHTML = '';
    cur = null;
    dropEcho();
}

function ended(by) {
    if (!cur) return;
    const who = { agent: 'The agent ended the session.', support: 'You ended the session.', logout: 'The agent signed out.', timeout: 'The connection to the agent was lost.', expired: 'The session expired.' }[by] || 'The session ended.';
    detach(false);
    $('cdc-live').hidden = true;
    $('cdc-join').hidden = false;
    say(who);
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
        b.className = 'ds-btn' + (tab.key === cur.selected ? ' is-selected' : '');
        b.style.cssText = 'max-width:22rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' + (tab.key === cur.selected ? 'border-color:var(--brand-icon,#0ea5e9);font-weight:700;' : '');
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
    askResync(key); // a fresh picture the moment you switch
}

function showSelected() {
    if (!cur) return;
    cur.tabs.forEach((t) => { t.root.hidden = t.key !== cur.selected; });
    const tab = cur.tabs.get(cur.selected);
    $('cdc-wait').hidden = !!(tab && tab.replayer);
    $('cdc-replay').style.height = tab && tab.replayer ? $('cdc-replay').style.height : '';
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
    const replayer = new Replayer(tab.events, { root: tab.root, liveMode: true, mouseTail: false, showWarning: false, showDebug: false, skipInactive: false, useVirtualDom: false });
    tab.replayer = replayer;
    replayer.startLive();
    hookReplayInput(tab);
    replayer.on('resize', () => { if (cur && cur.selected === tab.key) fit(); });
    showSelected();
}

/** Scale the selected tab's replayed page down to fit the console's pane (the agent's window is usually bigger). */
function fit() {
    const tab = cur && cur.tabs.get(cur.selected);
    if (!tab || !tab.replayer) return;
    const pane = $('cdc-replay');
    const wrapper = tab.replayer.wrapper;
    // The replayed page's own size (the agent's window) is on the iframe's width/height attributes, set from the Meta event.
    const iframe = tab.replayer.iframe;
    const w = parseFloat(iframe.getAttribute('width')) || wrapper.offsetWidth;
    const h = parseFloat(iframe.getAttribute('height')) || wrapper.offsetHeight;
    if (!w || !h) return;
    const scale = Math.min(1, pane.clientWidth / w);
    wrapper.style.transformOrigin = 'top left';
    wrapper.style.transform = 'scale(' + scale + ')';
    pane.style.height = Math.round(h * scale) + 'px';
}
window.addEventListener('resize', fit);

// ── Pointer and highlight ────────────────────────────────────────────────────────

const tabParam = (tab) => (tab.key === MAIN ? {} : { tab: tab.key });

function hookReplayInput(tab) {
    const iframe = tab.replayer.iframe;
    const ratio = (ev) => {
        const r = iframe.getBoundingClientRect();
        return { x: (ev.clientX - r.left) / r.width, y: (ev.clientY - r.top) / r.height };
    };
    // Mouse events land on the wrapper (the iframe itself does not deliver them to us while replaying).
    const target = tab.replayer.wrapper;
    target.style.pointerEvents = 'auto';
    iframe.style.pointerEvents = 'none';
    target.style.cursor = 'crosshair';

    target.addEventListener('mousemove', (ev) => {
        if (!cur || cur.selected !== tab.key) return;
        const now = Date.now();
        if (now - cur.lastPointer < 40) return;
        cur.lastPointer = now;
        if (!cur.pointerOn) {
            cur.pointerOn = true;
            api('POST', con.urls.sessions + '/' + cur.session.id + '/pointer', { on: true, ...tabParam(tab) });
        }
        cur.channel.whisper('pointer', { t: tab.key, ...ratio(ev) });
    });
    target.addEventListener('mouseleave', () => {
        if (cur && cur.pointerOn && cur.selected === tab.key) {
            cur.pointerOn = false;
            api('POST', con.urls.sessions + '/' + cur.session.id + '/pointer', { on: false, ...tabParam(tab) });
        }
    });
    target.addEventListener('click', (ev) => {
        if (!cur || cur.selected !== tab.key) return;
        const r = ratio(ev);
        const doc = iframe.contentDocument;
        if (!doc) return;
        const el = doc.elementFromPoint(r.x * doc.documentElement.clientWidth, r.y * doc.documentElement.clientHeight);
        const selector = el ? selectorFor(el) : null;
        if (!selector) return;
        const note = $('cdc-note').value.trim();
        api('POST', con.urls.sessions + '/' + cur.session.id + '/highlight', { selector, note: note || null, ...tabParam(tab) }).then((res) => {
            say(res.ok ? 'Highlighted: ' + selector : 'Could not send the highlight.', !res.ok);
        });
    });
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
        li.style.cssText = 'display:flex;gap:.75rem;align-items:center;padding:.5rem 0;border-top:1px solid var(--border);font-size:.8rem;';
        li.textContent = (s.agent_name || 'Agent') + (s.agency_name ? ' · ' + s.agency_name : '') + ' — ' + (s.status === 'live' ? 'live with ' + (s.support_name || 'support') : 'waiting for a support person');
        ul.appendChild(li);
    }
}

// ── Wiring ───────────────────────────────────────────────────────────────────────

$('cdc-form').addEventListener('submit', (e) => {
    e.preventDefault();
    const code = $('cdc-code').value.replace(/\D/g, '');
    if (code.length !== 6) { say('Type the 6-digit code the agent reads out.', true); return; }
    join(code);
});
$('cdc-clear').addEventListener('click', () => {
    if (cur) api('POST', con.urls.sessions + '/' + cur.session.id + '/highlight/clear', cur.selected === MAIN ? {} : { tab: cur.selected });
});
$('cdc-end').addEventListener('click', () => {
    if (!cur) return;
    const id = cur.session.id;
    detach(false);
    api('POST', con.urls.sessions + '/' + id + '/end').then(() => {
        $('cdc-live').hidden = true;
        $('cdc-join').hidden = false;
        say('You ended the session.');
        refreshList();
    });
});
setInterval(refreshList, 10000);
refreshList();
