// CoreX Desk - the loader every CoreX page carries (spec .ai/specs/corex-desk.md sections 3 and 7.5).
// Tiny on purpose: the Get help screen, the red bar, and the decision "is a session open in THIS tab?". Echo and rrweb live in
// agent.js and are only imported once a session exists. The markup and config come from layouts/partials/desk-client.blade.php.

const cfg = window.CorexDesk;
const KEY = 'corex.desk.session';
const $ = (id) => document.getElementById(id);

// The session belongs to the USER (spec section 4f), so what a tab remembers is shared by all of them: localStorage, not sessionStorage. The code is
// shown in the tab that asked for it (saved.tab); every other tab just joins.
function store(v) {
    try { if (v) localStorage.setItem(KEY, JSON.stringify(v)); else localStorage.removeItem(KEY); } catch (e) { /* private mode */ }
}
function stored() {
    try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; }
}

// This tab's key ("main" = the tab that started the session, "c<letters>" = every other CoreX tab) is worked out by the inline script in
// layouts/partials/desk-client.blade.php, which also sends the tab's join call as the page starts (so support sees a new tab as early as possible).
const tabKey = (wantMain) => window.CorexDeskTabKey(!!wantMain);

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

// ── UI ───────────────────────────────────────────────────────────────────────────

let countdown = null;

function showModal(state, extra = {}) {
    const m = $('corex-desk-modal');
    if (!m) return;
    m.hidden = false;
    m.dataset.state = state; // creating | waiting | off | error
    $('cd-code').textContent = extra.code ? extra.code.replace(/^(\d{3})(\d{3})$/, '$1 $2') : '';
    $('cd-message').textContent = extra.message || '';
    clearInterval(countdown);
    const exp = $('cd-expires');
    exp.textContent = '';
    if (state === 'waiting' && extra.expiresAt) {
        const tick = () => {
            const left = Math.max(0, Math.round((new Date(extra.expiresAt) - Date.now()) / 1000));
            exp.textContent = 'This code works for another ' + Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0') + '.';
        };
        tick();
        countdown = setInterval(tick, 1000);
    }
}

function hideModal() {
    const m = $('corex-desk-modal');
    if (m) m.hidden = true;
    clearInterval(countdown);
}

// Everyone who is in the session right now (the first support person and anyone who attached later), as the server named them.
let supporters = [];
const listText = (names) => (names.length <= 1 ? (names[0] || 'CoreX support') : names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1]);

function showBar(name) {
    const bar = $('corex-desk-bar');
    if (!bar) return;
    $('cd-bar-name').textContent = name || 'CoreX support';
    bar.hidden = false;
    document.documentElement.classList.add('corex-desk-live');
}

function hideBar() {
    const bar = $('corex-desk-bar');
    if (bar) bar.hidden = true;
    document.documentElement.classList.remove('corex-desk-live');
}

/** The red bar speaks in one voice: viewing (default), asking ("wants to take control" - Allow / Decline), in control (Take back control). */
function setBarMode(mode, name, note) {
    const bar = $('corex-desk-bar');
    if (!bar) return;
    // Viewing: everyone in the session is named. Asking / in control: the one person who asked / holds control.
    if (mode === 'view' && supporters.length) $('cd-bar-name').textContent = listText(supporters);
    else if (name) $('cd-bar-name').textContent = name;
    bar.hidden = false;
    bar.dataset.mode = mode;
    const plural = mode === 'view' && supporters.length > 1;
    $('cd-bar-what').textContent = { view: plural ? 'are viewing this window' : 'is viewing this window', ask: 'wants to take control of this window', control: 'is in control of this window' }[mode] + (note ? ' - ' + note : '');
    bar.querySelector('[data-desk-allow]').hidden = mode !== 'ask';
    bar.querySelector('[data-desk-decline]').hidden = mode !== 'ask';
    bar.querySelector('[data-desk-takeback]').hidden = mode !== 'control';
    bar.querySelector('[data-desk-end]').hidden = mode === 'ask';
    document.documentElement.classList.add('corex-desk-live');
}

// The thread on the red bar: the last three lines of it, or all of them (up to 30) after "Show all"; a reply box under it.
let threadLines = [], threadAll = false;
function paintThread(fresh) {
    const box = $('cd-thread');
    if (!box) return;
    box.hidden = threadLines.length === 0;
    const shown = threadAll ? threadLines : threadLines.slice(-3);
    const lines = $('cd-thread-lines');
    lines.innerHTML = '';
    shown.forEach((l) => {
        const d = document.createElement('div');
        d.className = 'cd-th-line' + (l.from === 'agent' ? ' cd-th-me' : '');
        const b = document.createElement('b');
        b.textContent = (l.from === 'agent' ? 'You' : (l.name || 'CoreX support')) + ': ';
        d.appendChild(b);
        d.appendChild(document.createTextNode(l.text));
        lines.appendChild(d);
    });
    const more = $('cd-thread-more');
    more.hidden = threadLines.length <= 3;
    more.textContent = threadAll ? 'Show fewer' : 'Show all ' + threadLines.length;
    const last = threadLines[threadLines.length - 1];
    $('cd-reply-in').placeholder = 'Reply to ' + ((last && last.from === 'support' && last.name) || 'CoreX support') + ' - Enter sends';
    if (fresh) { const bar = $('corex-desk-bar'); bar.classList.remove('cd-flash'); void bar.offsetWidth; bar.classList.add('cd-flash'); }
}

const ui = {
    thread(lines, fresh) { threadLines = lines.slice(); paintThread(!!fresh); },
    controlAsk(name, note) { setBarMode('ask', name, note); },
    controlOn(name) { setBarMode('control', name); },
    controlOff() { setBarMode('view'); },
    joined(name, names) {
        supporters = Array.isArray(names) && names.length ? names.slice() : (name ? [name] : []);
        hideModal();
        showBar(supporters.length ? listText(supporters) : name);
        // The code was spent at the join: it is not kept in this browser any longer.
        const saved = stored();
        if (saved && saved.code) store({ id: saved.id, tab: saved.tab });
        if (supporters.length > 1) setBarMode('view');
    },
    /** Another support person joined the live session: the bar names them all and flashes, so the agent is never watched without being told. */
    supporters(names, who) {
        supporters = names.slice();
        const bar = $('corex-desk-bar');
        if (!bar) return;
        const mode = bar.dataset.mode || 'view';
        if (mode === 'view') setBarMode('view');
        bar.title = (who ? who + ' joined the session. ' : '') + 'In this session: ' + listText(supporters);
        bar.classList.remove('cd-flash'); void bar.offsetWidth; bar.classList.add('cd-flash');
    },
    ended(by) {
        store(null);
        threadLines = []; threadAll = false;
        const th = $('cd-thread'); if (th) th.hidden = true;
        hideBar();
        const p = $('corex-desk-pointer');
        if (p) p.hidden = true;
        if (by === 'timeout' || by === 'expired') {
            showModal('error', { message: by === 'expired' ? 'Nobody joined in time, so the session was closed. Press Get help to start again.' : 'The connection was lost, so the session was closed.' });
        } else {
            hideModal();
        }
    },
    error(message) { showModal('error', { message }); },
};

// ── Flow ─────────────────────────────────────────────────────────────────────────

async function startAgent(session, wantMain) {
    const key = await tabKey(!!wantMain);
    const agent = await import('./agent.js');
    agent.start({ cfg, session, api, ui, tabKey: key, earlyJoin: window.CorexDeskEarlyJoin || null });
    return agent;
}

async function openHelp() {
    if (!cfg.enabled) {
        showModal('off', { message: cfg.phone ? 'Phone CoreX support on ' + cfg.phone : 'Phone CoreX support.' });
        return;
    }
    const saved = stored();
    if (cfg.current && cfg.current.status === 'live') {
        showModal('error', { message: 'CoreX support is already connected to all of your CoreX windows. Use the red bar to end the session.' });
        return;
    }
    if (saved && saved.code && saved.expires_at && new Date(saved.expires_at) > new Date()) {
        showModal('waiting', { code: saved.code, expiresAt: saved.expires_at });
        return;
    }
    showModal('creating', { message: 'Getting your code…' });
    const r = await api('POST', cfg.urls.sessions);
    if (r.status === 409) {
        showModal('off', { message: r.json.phone ? 'Phone CoreX support on ' + r.json.phone : 'Phone CoreX support.' });
        return;
    }
    if (!r.ok) {
        showModal('error', { message: 'Could not start CoreX Desk. Please try again.' });
        return;
    }
    const s = r.json.session;
    const expiresAt = s.code_expires_at;
    const mine = await tabKey(true);
    store({ id: s.id, code: r.json.code, expires_at: expiresAt, tab: mine });
    showModal('waiting', { code: r.json.code, expiresAt });
    startAgent(s, true);
}

async function cancel() {
    const saved = stored();
    if (saved) await api('POST', cfg.urls.sessions + '/' + saved.id + '/end');
    store(null);
    hideModal();
    try { (await import('./agent.js')).endSession(); } catch (e) { /* nothing open */ }
}

async function control(fn, arg) {
    try { return await (await import('./agent.js'))[fn](arg); } catch (e) { /* nothing open */ }
}

function sendReply() {
    const input = $('cd-reply-in');
    if (!input || !input.value.trim()) return;
    control('sendMessage', input.value).then((sent) => { if (sent !== false) input.value = ''; });
}

async function endFromBar() {
    try { await (await import('./agent.js')).endSession(); } catch (e) { /* ignore */ }
}

function boot() {
    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-desk-open]')) { e.preventDefault(); openHelp(); }
        else if (e.target.closest('[data-desk-close]')) { hideModal(); }
        else if (e.target.closest('[data-desk-cancel]')) { cancel(); }
        else if (e.target.closest('[data-desk-end]')) { endFromBar(); }
        else if (e.target.closest('[data-desk-allow]')) { control('controlRespond', true); }
        else if (e.target.closest('[data-desk-decline]')) { control('controlRespond', false); }
        else if (e.target.closest('[data-desk-takeback]')) { control('controlRevoke'); }
        else if (e.target.closest('[data-desk-reply]')) { sendReply(); }
        else if (e.target.closest('#cd-thread-more')) { threadAll = !threadAll; paintThread(false); }
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target && e.target.id === 'cd-reply-in') { e.preventDefault(); sendReply(); } });

    // A session the server has open for this user (spec section 4f): EVERY CoreX tab joins it - the one that asked for the code, a page change in it,
    // and any tab opened afterwards (a button that opens a new tab). The code is shown once, in the tab that asked.
    const saved = stored();
    const cur = cfg.current;
    if (cur) {
        if (cur.status === 'waiting' && saved && saved.id === cur.id && saved.code) {
            tabKey(true).then((k) => { if (saved.tab === k) showModal('waiting', { code: saved.code, expiresAt: saved.expires_at }); });
        }
        startAgent(cur, !!(saved && saved.id === cur.id && saved.tab === 'main'));
    } else if (saved) {
        store(null); // the server no longer has it open
    }
}

if (cfg && cfg.canRequest) boot();
