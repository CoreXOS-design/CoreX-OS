// CoreX Desk - the client-frame codec (spec .ai/specs/corex-desk.md section 7.2). The agent's rrweb events are sent browser to browser
// as "whispers": a batch of events -> JSON -> gzip (when the browser can) -> base64 -> chunks of at most CHUNK characters. The
// receiver reassembles by batch id. Nothing here touches the server and nothing is stored. The phase-3 extension reuses this as is.

// (Reverb accepts client messages up to config/reverb.php max_message_size - 1 MB - so a snapshot goes in a few big whispers instead of two dozen small
// ones; still small enough that a pointer move never waits long behind one.)
export const CHUNK = 32000;
// Below this a batch is sent as it is: gzip costs a header and a CompressionStream round trip, and saves nothing on a pointer move or a keystroke.
const GZIP_MIN = 300;

let batchSeq = 0;

function toBase64(bytes) {
    let bin = '';
    const step = 0x8000;
    for (let i = 0; i < bytes.length; i += step) {
        bin += String.fromCharCode.apply(null, bytes.subarray(i, i + step));
    }
    return btoa(bin);
}

function fromBase64(b64) {
    const bin = atob(b64);
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return bytes;
}

async function pipe(bytes, stream) {
    const out = new Blob([bytes]).stream().pipeThrough(stream);
    return new Uint8Array(await new Response(out).arrayBuffer());
}

/**
 * @param {object} [opts] t: the tab the batch is from (the receiver files it under that tab); meta: timing-overlay stamp, carried on chunk 0 only
 * @returns {Promise<Array<{id:string,i:number,n:number,z:number,d:string,t?:string,m?:object}>>} the chunks to whisper, in order
 */
export async function encodeBatch(events, senderTag = 'a', opts = {}) {
    const t0 = Date.now();
    const json = new TextEncoder().encode(JSON.stringify(events));
    let z = 0;
    let bytes = json;
    if (json.length >= GZIP_MIN && typeof CompressionStream !== 'undefined') {
        try { bytes = await pipe(json, new CompressionStream('gzip')); z = 1; } catch (e) { bytes = json; z = 0; }
    }
    const b64 = toBase64(bytes);
    const id = senderTag + Date.now().toString(36) + (batchSeq++).toString(36);
    const n = Math.max(1, Math.ceil(b64.length / CHUNK));
    const chunks = [];
    for (let i = 0; i < n; i++) {
        const c = { id, i, n, z, d: b64.slice(i * CHUNK, (i + 1) * CHUNK) };
        if (opts.t) c.t = opts.t;
        if (i === 0 && opts.meta) c.m = { ...opts.meta, raw: json.length, wire: b64.length, enc: Date.now() - t0 };
        chunks.push(c);
    }
    return chunks;
}

/** The most chunks one batch may claim (a snapshot is a few dozen at most): a larger count in a chunk is hostile or broken, not a big page. */
export const MAX_CHUNKS = 256;

/** Collects chunks; calls onBatch(events, info) once a batch is complete (info: the timing stamp if the sender added one, the wire size, decode time). Incomplete batches older than 30 s are dropped. */
export class Reassembler {
    constructor(onBatch) {
        this.onBatch = onBatch;
        this.parts = new Map();
        this.lastAt = 0;   // when a chunk last arrived (the console does not ask again for a picture that is still coming in)
        this.timer = null; // sweeps stale partial batches while any exist (not only when the next chunk happens to arrive)
    }

    /** Stop the sweep and forget what is half-received (the tab or the session is gone). */
    dispose() {
        clearInterval(this.timer);
        this.timer = null;
        this.parts.clear();
    }

    /** Is part of a batch on its way in? */
    get busy() { return this.parts.size > 0 && Date.now() - this.lastAt < 6000; }

    async push(chunk) {
        if (!chunk || typeof chunk.id !== 'string' || chunk.id.length > 64 || typeof chunk.d !== 'string') return;
        // A whisper comes from the browser, not the server: bound what it can make us allocate. n is a whole number from 1 to MAX_CHUNKS and i is one of
        // its slots - anything else is dropped, never turned into an array of that size.
        if (!Number.isInteger(chunk.n) || chunk.n < 1 || chunk.n > MAX_CHUNKS || !Number.isInteger(chunk.i) || chunk.i < 0 || chunk.i >= chunk.n) return;
        let entry = this.parts.get(chunk.id);
        if (!entry) {
            entry = { n: chunk.n, z: chunk.z, got: 0, d: new Array(chunk.n), at: Date.now(), m: null, firstAt: Date.now() };
            this.parts.set(chunk.id, entry);
            if (!this.timer) this.timer = setInterval(() => { this.sweep(); if (!this.parts.size) { clearInterval(this.timer); this.timer = null; } }, 10000);
        }
        if (chunk.n !== entry.n) return;   // (a later chunk of the same batch that disagrees about the count)
        if (entry.d[chunk.i] === undefined) {
            entry.d[chunk.i] = chunk.d;
            entry.got++;
            if (chunk.m) entry.m = chunk.m;
        }
        this.lastAt = Date.now();
        if (entry.got < entry.n) {
            this.sweep();
            return;
        }
        this.parts.delete(chunk.id);
        const d0 = Date.now();
        let bytes = fromBase64(entry.d.join(''));
        if (entry.z) bytes = await pipe(bytes, new DecompressionStream('gzip'));
        const events = JSON.parse(new TextDecoder().decode(bytes));
        this.onBatch(events, { m: entry.m, wire: entry.d.reduce((a, x) => a + x.length, 0), raw: bytes.length, decode: Date.now() - d0, firstAt: entry.firstAt, doneAt: Date.now() });
    }

    sweep() {
        const old = Date.now() - 30000;
        for (const [id, e] of this.parts) if (e.at < old) this.parts.delete(id);
    }
}
