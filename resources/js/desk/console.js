// CoreX Desk - the SUPPORT console (spec .ai/specs/corex-desk.md section 4). Own Vite entry, loaded only by desk/console.blade.php.
//
// Join by code -> subscribe to the session's private channel -> ask the agent's tab for a fresh full snapshot (client-resync) ->
// rebuild the agent's page with rrweb's live-mode Replayer. The mouse over the replayed page is whispered to the agent as a
// labelled cursor; a click resolves the element to a selector and asks the server (audited) to have the agent outline it.
import { Replayer } from 'rrweb';
import 'rrweb/dist/style.css';
import { getEcho, dropEcho } from './echo.js';
import { Reassembler } from './frames.js';

const cfg = window.CorexDesk || {};
const con = window.CorexDeskConsole || {};
const $ = (id) => document.getElementById(id);

const EVENT_META = 4;
const EVENT_FULL = 2;

let cur = null; // { session, channel, name, replayer, events, started, tick, scale }

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

function attach(session) {
    if (cur) detach(false);
    const name = 'desk.session.' + session.id;
    const channel = getEcho().private(name);
    cur = { session, channel, name, replayer: null, events: [], started: Date.parse(session.joined_at || session.started_at), pointerOn: false, lastPointer: 0 };

    const reassembler = new Reassembler((events) => onEvents(events));
    channel
        .listenForWhisper('frame', (chunk) => reassembler.push(chunk))
        .listen('.desk.ended', (e) => ended(e.by))
        .error(() => say('Could not open the live connection.', true));

    // Subscribed: the agent's tab may already have sent its first snapshot before we listened - ask for a fresh one.
    const ask = () => channel.whisper('resync', {});
    channel.subscribed(ask);
    cur.retry = setInterval(() => { if (cur && !cur.replayer) ask(); }, 3000); // until the first snapshot arrives

    $('cdc-join').hidden = true;
    $('cdc-live').hidden = false;
    $('cdc-agent').textContent = session.agent_name || '';
    $('cdc-agency').textContent = session.agency_name || '';
    $('cdc-page').textContent = session.page || '—';
    $('cdc-wait').hidden = false;
    cur.tick = setInterval(tickElapsed, 1000);
    tickElapsed();
    refreshList();
}

function detach(callServer) {
    if (!cur) return;
    clearInterval(cur.tick);
    clearInterval(cur.retry);
    try { getEcho().leave(cur.name); } catch (e) { /* gone */ }
    if (callServer) api('POST', con.urls.sessions + '/' + cur.session.id + '/end');
    const root = $('cdc-replay');
    root.innerHTML = '';
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

// ── Rebuilding the agent's page ──────────────────────────────────────────────────

function onEvents(events) {
    if (!cur) return;
    // The Replayer schedules a live event for the moment its timestamp comes round on THIS computer's clock, so an agent whose clock is
    // a few seconds behind or ahead would be mirrored late (or in a burst). A live mirror wants "as it arrives": stamp the batch with
    // the moment it arrived here (order inside the batch is kept - equal stamps keep their sequence).
    const arrived = Date.now();
    for (const ev of events) {
        ev.timestamp = arrived;
        if (ev.type === EVENT_META && ev.data && ev.data.href) {
            try { $('cdc-page').textContent = new URL(ev.data.href).pathname; } catch (e) { /* ignore */ }
        }
        if (!cur.replayer) {
            cur.events.push(ev);
            if (cur.events.some((e) => e.type === EVENT_META) && cur.events.some((e) => e.type === EVENT_FULL)) startReplayer();
        } else {
            cur.replayer.addEvent(ev);
        }
    }
}

function startReplayer() {
    const root = $('cdc-replay');
    root.innerHTML = '';
    const replayer = new Replayer(cur.events, { root, liveMode: true, mouseTail: false, showWarning: false, showDebug: false, skipInactive: false });
    cur.replayer = replayer;
    $('cdc-wait').hidden = true;
    replayer.startLive();
    fit();
    replayer.on('resize', fit);
    hookReplayInput(replayer);
    clearInterval(cur.retry);
}

/** Scale the replayed page down to fit the console's pane (the agent's window is usually bigger). */
function fit() {
    if (!cur || !cur.replayer) return;
    const pane = $('cdc-replay');
    const wrapper = cur.replayer.wrapper;
    const w = parseFloat(wrapper.style.width) || wrapper.offsetWidth;
    const h = parseFloat(wrapper.style.height) || wrapper.offsetHeight;
    if (!w || !h) return;
    const scale = Math.min(1, pane.clientWidth / w);
    cur.scale = scale;
    wrapper.style.transformOrigin = 'top left';
    wrapper.style.transform = 'scale(' + scale + ')';
    pane.style.height = Math.round(h * scale) + 'px';
}
window.addEventListener('resize', fit);

// ── Pointer and highlight ────────────────────────────────────────────────────────

function hookReplayInput(replayer) {
    const iframe = replayer.iframe;
    const rect = () => iframe.getBoundingClientRect();
    const ratio = (ev) => {
        const r = rect();
        return { x: (ev.clientX - r.left) / r.width, y: (ev.clientY - r.top) / r.height };
    };
    // Mouse events land on the wrapper (the iframe itself does not deliver them to us while replaying).
    const target = replayer.wrapper;
    target.style.pointerEvents = 'auto';
    iframe.style.pointerEvents = 'none';
    target.style.cursor = 'crosshair';

    target.addEventListener('mousemove', (ev) => {
        if (!cur) return;
        const now = Date.now();
        if (now - cur.lastPointer < 40) return;
        cur.lastPointer = now;
        if (!cur.pointerOn) {
            cur.pointerOn = true;
            api('POST', con.urls.sessions + '/' + cur.session.id + '/pointer', { on: true });
        }
        cur.channel.whisper('pointer', ratio(ev));
    });
    target.addEventListener('mouseleave', () => {
        if (cur && cur.pointerOn) {
            cur.pointerOn = false;
            api('POST', con.urls.sessions + '/' + cur.session.id + '/pointer', { on: false });
        }
    });
    target.addEventListener('click', (ev) => {
        if (!cur) return;
        const r = ratio(ev);
        const doc = iframe.contentDocument;
        if (!doc) return;
        const el = doc.elementFromPoint(r.x * doc.documentElement.clientWidth, r.y * doc.documentElement.clientHeight);
        const selector = el ? selectorFor(el) : null;
        if (!selector) return;
        const note = $('cdc-note').value.trim();
        api('POST', con.urls.sessions + '/' + cur.session.id + '/highlight', { selector, note: note || null }).then((res) => {
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
    if (cur) api('POST', con.urls.sessions + '/' + cur.session.id + '/highlight/clear');
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
