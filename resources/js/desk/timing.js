// CoreX Desk - the one-off timing panel (spec .ai/specs/corex-desk.md section 4e). Off unless config desk.timing_overlay is on; nothing here runs otherwise.
//
// What it shows, live, in the corner of the viewer:
//   - per kind of message (snapshot, mutation, input, scroll, pointer): how long from the agent's tab to this console - the agent stamps each message with its
//     own clock and this console learns the difference between the two clocks from the ping / pong round trips below (a round trip of a few milliseconds
//     through Reverb, so the clocks agree to a few ms) - plus the agent's encode time, our decode time, and how long the mirror took to apply it;
//   - the size of the last full snapshot (raw and on the wire) and the ten biggest mutation batches;
//   - the relay round trip (a ping whispered to the agent and back, i.e. console -> Reverb -> agent -> Reverb -> console);
//   - server -> console: an event the server sends through Reverb, timed from the moment the request that causes it was sent, next to how long the
//     server's own answer to that request took.

const MAX = 120;
const S = { on: false, deps: null, off: {}, best: {}, kinds: {}, snap: null, muts: [], rtt: [], srv: [], http: [], probeAt: 0 };

const pct = (a, p) => { if (!a.length) return '-'; const s = [...a].sort((x, y) => x - y); return Math.round(s[Math.min(s.length - 1, Math.floor(p * s.length))]); };
const kb = (n) => (n >= 1024 ? (n / 1024).toFixed(0) + ' KB' : n + ' B');
const push = (a, v) => { a.push(v); if (a.length > MAX) a.shift(); };

export const timing = {
    reset(on, deps) {
        S.on = !!on; S.deps = deps || null; S.off = {}; S.best = {}; S.kinds = {}; S.snap = null; S.muts = []; S.rtt = []; S.srv = []; S.http = [];
        // The diagnostics hook and the 1 s repaint exist only while the overlay is switched on (config desk.timing_overlay) - nothing global otherwise.
        if (S.on) install(); else uninstall();
        const el = document.getElementById('cdc-timing');
        if (el) el.hidden = !S.on;
    },

    /** A message from the agent has arrived whole. */
    batch(tabKey, info, applyMs, events) {
        if (!S.on || !info || !info.m) return;
        const m = info.m;
        if (S.off[tabKey] === undefined) return;   // (no ping answered yet from that tab, so no way to compare the two clocks)
        const total = info.doneAt - (m.ts - S.off[tabKey]);   // the agent's stamp, in this computer's time -> the whole message here
        const k = S.kinds[m.k] || (S.kinds[m.k] = { total: [], enc: [], dec: [], apply: [], n: 0 });
        push(k.total, total); push(k.enc, m.enc); push(k.dec, info.decode); push(k.apply, applyMs); k.n++;
        if (m.k === 'snapshot') S.snap = { raw: m.raw, wire: m.wire, ms: total, tab: tabKey };
        if (m.k === 'mutation') { S.muts.push({ wire: m.wire, raw: m.raw, ev: m.ev }); S.muts.sort((a, b) => b.wire - a.wire); S.muts.length = Math.min(S.muts.length, 10); }
        S.dirty = true;
    },

    /** A ping came back: the round trip, and (from the round trip with the fewest milliseconds) the difference between the agent tab's clock and ours. */
    pong(p) {
        if (!S.on || !p || !p.c) return;
        const rtt = Date.now() - p.c;
        push(S.rtt, rtt);
        const k = p.t || 'main';
        if (p.a && (S.best[k] === undefined || rtt < S.best[k])) { S.best[k] = rtt; S.off[k] = p.a - (p.c + rtt / 2); }
        S.dirty = true;
    },

    serverEvent(e) { if (S.on && e && S.probeAt) { push(S.srv, Date.now() - S.probeAt); S.dirty = true; } },

    /** Every few seconds: a relay ping through Reverb, and a server-stamped event back through it. */
    probe(cur) {
        if (!S.on || !cur || !S.deps) return;
        try { cur.channel.whisper('tping', { id: Math.random().toString(36).slice(2, 6), c: Date.now() }); } catch (e) { /* not subscribed */ }
        const t0 = Date.now();
        S.probeAt = t0;
        S.deps.api('POST', S.deps.url(cur.session.id) + '/timing').then((r) => {
            if (r.ok) push(S.http, Date.now() - t0);
            S.dirty = true;
        });
        this.paint();
    },

    paint() {
        const el = document.getElementById('cdc-timing');
        if (!S.on || !el) return;
        el.hidden = false;
        const row = (name, k) => name.padEnd(9) + String(k.n).padStart(4) + '  ' + String(pct(k.total, 0.5)).padStart(5) + String(pct(k.total, 0.95)).padStart(6) +
            String(pct(k.enc, 0.5)).padStart(6) + String(pct(k.dec, 0.5)).padStart(6) + String(pct(k.apply, 0.5)).padStart(7);
        const lines = ['CoreX Desk timing (ms)  agent tab -> console, clocks compared by ping', 'kind        n   p50   p95   enc   dec  apply'];
        ['snapshot', 'mutation', 'input', 'scroll', 'pointer', 'other'].forEach((n) => { if (S.kinds[n]) lines.push(row(n, S.kinds[n])); });
        if (S.snap) lines.push('last snapshot: ' + kb(S.snap.raw) + ' raw, ' + kb(S.snap.wire) + ' on the wire, ' + Math.round(S.snap.ms) + ' ms to arrive');
        if (S.muts.length) lines.push('10 biggest mutation batches (wire): ' + S.muts.map((m) => kb(m.wire)).join(', '));
        lines.push('relay round trip console>agent>console: ' + (S.rtt.length ? S.rtt[S.rtt.length - 1] + ' ms (p50 ' + pct(S.rtt, 0.5) + ')' : '-'));
        lines.push('server event -> console: ' + (S.srv.length ? Math.round(S.srv[S.srv.length - 1]) + ' ms (p50 ' + pct(S.srv, 0.5) + ')' : '-') + ' after the request; server answered in ' + (S.http.length ? S.http[S.http.length - 1] + ' ms' : '-'));
        el.textContent = lines.join('\n');
        S.dirty = false;
    },

    /** For the proofs: the numbers as data. */
    snapshot() {
        const o = {};
        for (const [n, k] of Object.entries(S.kinds)) o[n] = { n: k.n, p50: pct(k.total, 0.5), p95: pct(k.total, 0.95), enc: pct(k.enc, 0.5), dec: pct(k.dec, 0.5), apply: pct(k.apply, 0.5) };
        return { kinds: o, snap: S.snap, biggestMutations: S.muts.map((m) => m.wire), rtt: S.rtt.slice(-10), serverEventAfterRequest: S.srv.slice(-10), serverAnswer: S.http.slice(-10), clockDifference: Object.fromEntries(Object.entries(S.off).map(([k, v]) => [k, Math.round(v)])) };
    },
};

let repaint = null;
function install() {
    if (typeof window === 'undefined') return;
    window.__deskTiming = timing;
    if (!repaint) repaint = setInterval(() => { if (S.on && S.dirty) timing.paint(); }, 1000);
}
function uninstall() {
    if (repaint) { clearInterval(repaint); repaint = null; }
    if (typeof window !== 'undefined' && window.__deskTiming === timing) delete window.__deskTiming;
}
