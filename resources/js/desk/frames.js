// CoreX Desk - the client-frame codec (spec .ai/specs/corex-desk.md section 7.2). The agent's rrweb events are sent browser to browser
// as "whispers": a batch of events -> JSON -> gzip (when the browser can) -> base64 -> chunks of at most CHUNK characters. The
// receiver reassembles by batch id. Nothing here touches the server and nothing is stored. The phase-3 extension reuses this as is.

export const CHUNK = 7000;

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

/** @returns {Promise<Array<{id:string,i:number,n:number,z:number,d:string}>>} the chunks to whisper, in order */
export async function encodeBatch(events, senderTag = 'a') {
    const json = new TextEncoder().encode(JSON.stringify(events));
    let z = 0;
    let bytes = json;
    if (typeof CompressionStream !== 'undefined') {
        try { bytes = await pipe(json, new CompressionStream('gzip')); z = 1; } catch (e) { bytes = json; z = 0; }
    }
    const b64 = toBase64(bytes);
    const id = senderTag + Date.now().toString(36) + (batchSeq++).toString(36);
    const n = Math.max(1, Math.ceil(b64.length / CHUNK));
    const chunks = [];
    for (let i = 0; i < n; i++) chunks.push({ id, i, n, z, d: b64.slice(i * CHUNK, (i + 1) * CHUNK) });
    return chunks;
}

/** Collects chunks; calls onBatch(events) once a batch is complete. Incomplete batches older than 30 s are dropped. */
export class Reassembler {
    constructor(onBatch) {
        this.onBatch = onBatch;
        this.parts = new Map();
    }

    async push(chunk) {
        if (!chunk || typeof chunk.id !== 'string') return;
        let entry = this.parts.get(chunk.id);
        if (!entry) {
            entry = { n: chunk.n, z: chunk.z, got: 0, d: new Array(chunk.n), at: Date.now() };
            this.parts.set(chunk.id, entry);
        }
        if (entry.d[chunk.i] === undefined) {
            entry.d[chunk.i] = chunk.d;
            entry.got++;
        }
        if (entry.got < entry.n) {
            this.sweep();
            return;
        }
        this.parts.delete(chunk.id);
        let bytes = fromBase64(entry.d.join(''));
        if (entry.z) bytes = await pipe(bytes, new DecompressionStream('gzip'));
        this.onBatch(JSON.parse(new TextDecoder().decode(bytes)));
    }

    sweep() {
        const old = Date.now() - 30000;
        for (const [id, e] of this.parts) if (e.at < old) this.parts.delete(id);
    }
}
