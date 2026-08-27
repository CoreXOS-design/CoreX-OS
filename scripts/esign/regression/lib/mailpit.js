// E-sign regression harness — Mailpit lookup for recipient signing links.
//
// 2026-08-27 — Johan's spec: "rec 1 matches from agent, rec 2 matches from
// rec 1, etc." This is what makes that checkable at all. A signing email
// goes out over real SMTP into Mailpit (the QA/staging mail sink) the same
// way it does for a real recipient; the only harness-side step is reading
// the token link back out of it, since nothing else can act as that
// recipient (they have no CoreX session — the token IS their identity).

const MAILPIT_BASE = 'http://localhost:8025';

// Finds the MOST RECENT "Please sign" email to `email` and returns its
// token signing link (https://.../sign/{token}). Disposable harness
// fixtures reuse the same addresses across runs, so this trusts recency,
// not uniqueness — call it immediately after the action that should have
// sent the email, before anything else could send another one to the same
// address (this harness's shapes don't run concurrently against the same
// fixture email within one process, and fixtures are namespaced to this
// harness only).
async function findSigningLink(email, { maxWaitMs = 20000, pollMs = 1500 } = {}) {
    const deadline = Date.now() + maxWaitMs;
    let lastSeenId = null;
    while (Date.now() < deadline) {
        const resp = await fetch(`${MAILPIT_BASE}/api/v1/search?query=${encodeURIComponent('to:' + email)}`);
        if (resp.ok) {
            const data = await resp.json();
            const msg = (data.messages || [])[0]; // Mailpit returns newest-first
            if (msg) {
                lastSeenId = msg.ID;
                const full = await (await fetch(`${MAILPIT_BASE}/api/v1/message/${msg.ID}`)).json();
                const html = full.HTML || full.Text || '';
                const linkMatch = html.match(/https?:\/\/[^\s"'<>]+\/sign\/[A-Za-z0-9_-]+/);
                if (linkMatch) {
                    return { link: linkMatch[0], messageId: msg.ID, subject: full.Subject, created: msg.Created };
                }
            }
        }
        await new Promise(r => setTimeout(r, pollMs));
    }
    throw new Error(`findSigningLink: no signing link found for ${email} within ${maxWaitMs}ms (last message seen: ${lastSeenId || 'none'})`);
}

module.exports = { findSigningLink };
