// CoreX Desk - the one-off timing panel (spec .ai/specs/corex-desk.md section 4e). Off unless config desk.timing_overlay is on; nothing here runs otherwise.
//
// What it shows, live, in the corner of the viewer:
//   · per kind of message (snapshot, mutation, input, scroll, pointer): how long from the agent's tab to this console - the agent stamps each message with the
//     SERVER's clock (it learns the offset from its heartbeat answers) and this console compares with the server's clock the same way, so the two browsers'
//     own clocks never enter into it - plus the agent's encode time, our decode time, and how long the mirror took to apply it;
//   · the size of the last full snapshot (raw and on the wire) and the ten biggest mutation batches;
//   · the relay round trip (a ping whispered to the agent and back, i.e. console -> Reverb -> agent -> Reverb -> console);
//   · server -> console: a server-stamped event sent through Reverb, with how long the server's own request took.

const MAX = 120;
const S = { on: false, deps: null, off: 0, best: Infinity, kinds: {}, snap: null, muts: [], rtt: [], srv: [], http: [] };

const pct = (a, p) => { if (!a.length) return '-'; const s = [...a].sort((x, y) => x - y); return Math.round(s[Math.min(s.length - 1, Math.floor(p * s.length))]); };
const kb = (n) => (n >= 1024 ? (n / 1024).toFixed(0) + ' KB' : n + ' B');
const push = (a, v) => { a.push(v); if (a.length > MAX) a.shift(); };

export const timing = {
    reset(on, deps) {
        S.on = !!on; S.deps = deps || null; S.off = 0; S.best = Infinity; S.kinds = {}; S.snap = null; S.muts = []; S.rtt = []; S.srv = []; S.http = [];
        const el = document.getElementById('cdc-timing');
        if (el) el.hidden = !S.on;
    },

    /** One sample of the server's clock against ours: the quickest round trip is the most trustworthy. */
    clock(t0, t1, serverMs) {
        if (!S.on || !serverMs) return;
        const rtt = t1 - t0;
        if (rtt < S.best) { S.best = rtt; S.off = serverMs - (t0 + rtt / 2); }
    },

    /** A message from the agent has arrived whole. */
    batch(tabKey, info, applyMs, events) {
        if (!S.on || !info || !info.m) return;
        const m = info.m;
        const total = (info.doneAt + S.off) - (m.ts + m.off);   // agent's server-clock stamp -> whole message here
        const k = S.kinds[m.k] || (S.kinds[m.k] = { total: [], enc: [], dec: [], apply: [], n: 0 });
        push(k.total, total); push(k.enc, m.enc); push(k.dec, info.decode); push(k.apply, applyMs); k.n++;
        if (m.k === 'snapshot') S.snap = { raw: m.raw, wire: m.wire, ms: total, tab: tabKey };
        if (m.k === 'mutation') { S.muts.push({ wire: m.wire, raw: m.raw, ev: m.ev }); S.muts.sort((a, b) => b.wire - a.wire); S.muts.length = Math.min(S.muts.length, 10); }
        S.dirty = true;
    },

    pong(p) { if (S.on && p && p.c) { push(S.rtt, Date.now() - p.c); S.dirty = true; } },

    serverEvent(e) { if (S.on && e && e.sent_ms) { push(S.srv, (Date.now() + S.off) - e.sent_ms); S.dirty = true; } },

    /** Every few seconds: a relay ping through Reverb, and a server-stamped event back through it. */
    probe(cur) {
        if (!S.on || !cur || !S.deps) return;
        try { cur.channel.whisper('tping', { id: Math.random().toString(36).slice(2, 6), c: Date.now() }); } catch (e) { /* not subscribed */ }
        const t0 = Date.now();
        S.deps.api('POST', S.deps.url(cur.session.id) + '/timing').then((r) => {
            if (r.ok) { push(S.http, Date.now() - t0); this.clock(t0, Date.now(), r.json.done_ms); }
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
        const lines = ['CoreX Desk timing (ms)  agent -> console, server-clock corrected', 'kind        n   p50   p95   enc   dec  apply'];
        ['snapshot', 'mutation', 'input', 'scroll', 'pointer', 'other'].forEach((n) => { if (S.kinds[n]) lines.push(row(n, S.kinds[n])); });
        if (S.snap) lines.push('last snapshot: ' + kb(S.snap.raw) + ' raw, ' + kb(S.snap.wire) + ' on the wire, ' + Math.round(S.snap.ms) + ' ms to arrive');
        if (S.muts.length) lines.push('10 biggest mutation batches (wire): ' + S.muts.map((m) => kb(m.wire)).join(', '));
        lines.push('relay round trip console>agent>console: ' + (S.rtt.length ? S.rtt[S.rtt.length - 1] + ' ms (p50 ' + pct(S.rtt, 0.5) + ')' : '-'));
        lines.push('server > console (Reverb):             ' + (S.srv.length ? Math.round(S.srv[S.srv.length - 1]) + ' ms (p50 ' + pct(S.srv, 0.5) + ')' : '-') + '   server request ' + (S.http.length ? S.http[S.http.length - 1] + ' ms' : '-'));
        el.textContent = lines.join('\n');
        S.dirty = false;
    },

    /** For the proofs: the numbers as data. */
    snapshot() {
        const o = {};
        for (const [n, k] of Object.entries(S.kinds)) o[n] = { n: k.n, p50: pct(k.total, 0.5), p95: pct(k.total, 0.95), enc: pct(k.enc, 0.5), dec: pct(k.dec, 0.5), apply: pct(k.apply, 0.5) };
        return { kinds: o, snap: S.snap, biggestMutations: S.muts.map((m) => m.wire), rtt: S.rtt.slice(-10), server: S.srv.slice(-10), http: S.http.slice(-10), offset: Math.round(S.off) };
    },
};

if (typeof window !== 'undefined') {
    window.__deskTiming = timing;
    setInterval(() => { if (S.on && S.dirty) timing.paint(); }, 1000);
}
