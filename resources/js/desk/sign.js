// CoreX Desk - signing of take-over actions (spec .ai/specs/corex-desk.md section 14b).
//
// Every action the console whispers to the agent is signed with a per-grant key the server derives (HMAC-SHA256 of app key + session + support user +
// request id + grant time) and hands ONLY to the agent (in the Allow answer) and to the support person who holds the control. The agent's browser
// replays an action only if its signature checks out and its sequence number is higher than any it has accepted for this grant.
//
//   wire form (client-action whisper):  { t: tab key, seq: integer, p: "<the action as a JSON string>", sig: hex }
//   sig = HMAC-SHA256(key, seq + "|" + p)      key = the 64-hex-character grant key, as 32 bytes
//
// The signed string `p` is sent as it is - no re-serialising on the receiving side - so there is no canonical-JSON ambiguity to exploit. WebCrypto only
// exists in secure contexts (https); without it nothing is signed or accepted: take-over fails closed, it never falls back to unsigned actions.
// public/chrome-extension/portal-capture/desk-lib.js carries the same two functions for the extension's service worker - keep them in step.

const enc = new TextEncoder();

export const cryptoAvailable = () => typeof crypto !== 'undefined' && !!crypto.subtle;

function hexToBytes(hex) {
    if (typeof hex !== 'string' || hex.length === 0 || hex.length % 2 !== 0 || !/^[0-9a-f]+$/i.test(hex)) return null;
    const out = new Uint8Array(hex.length / 2);
    for (let i = 0; i < out.length; i++) out[i] = parseInt(hex.substr(i * 2, 2), 16);
    return out;
}

const bytesToHex = (buf) => Array.from(new Uint8Array(buf), (b) => b.toString(16).padStart(2, '0')).join('');

async function importKey(keyHex, usage) {
    const raw = hexToBytes(keyHex);
    if (!raw || raw.length < 16 || !cryptoAvailable()) return null;
    return crypto.subtle.importKey('raw', raw, { name: 'HMAC', hash: 'SHA-256' }, false, [usage]);
}

/** Sign one action batch. @returns {Promise<{seq:number,p:string,sig:string}|null>} null when WebCrypto or the key is missing (nothing is sent then) */
export async function signAction(keyHex, seq, payload) {
    const key = await importKey(keyHex, 'sign');
    if (!key) return null;
    const p = JSON.stringify(payload);
    const sig = await crypto.subtle.sign('HMAC', key, enc.encode(seq + '|' + p));

    return { seq, p, sig: bytesToHex(sig) };
}

/**
 * Check a whispered action. @returns {Promise<{ok:true,seq:number,action:object}|{ok:false,reason:string}>}
 * Accepts only: an integer seq above lastSeq, a string payload of sane size, a 64-hex signature that verifies, and a payload that parses to an object.
 */
export async function verifyAction(keyHex, msg, lastSeq) {
    if (!msg || typeof msg !== 'object') return { ok: false, reason: 'shape' };
    const { seq, p, sig } = msg;
    if (!Number.isSafeInteger(seq) || seq <= lastSeq) return { ok: false, reason: 'seq' };
    if (typeof p !== 'string' || p.length === 0 || p.length > 20000) return { ok: false, reason: 'shape' };
    const sigBytes = typeof sig === 'string' && sig.length === 64 ? hexToBytes(sig) : null;
    if (!sigBytes) return { ok: false, reason: 'shape' };
    const key = await importKey(keyHex, 'verify');
    if (!key) return { ok: false, reason: 'nocrypto' };
    const good = await crypto.subtle.verify('HMAC', key, sigBytes, enc.encode(seq + '|' + p));
    if (!good) return { ok: false, reason: 'sig' };
    let action;
    try { action = JSON.parse(p); } catch (e) { return { ok: false, reason: 'shape' }; }
    if (!action || typeof action !== 'object' || Array.isArray(action)) return { ok: false, reason: 'shape' };

    return { ok: true, seq, action };
}
