# AT-138 — WhatsApp capture: move to a server-side background session

**Date:** 2026-06-30 · **Type:** architecture investigation (NO build — Johan decides) · **Status:** recommendation ready
**Relates:** AT-44 (extension capture), AT-133 (@lid), AT-135 (body), AT-136 (consent), AT-132 (archive/gate), AT-137 (polish)

---

## TL;DR — recommendation

**Move the capture TRANSPORT to a server-held WhatsApp session and keep 100% of the
server-side pipeline we built.** The agent links once via QR; capture then runs 24/7
on the Hetzner box — no tab, no foreground, no idle-watching, no scroll-scraping.

- **Recommended transport:** **WAHA (self-hosted Docker) on the GOWS engine (whatsmeow/Go).**
  It exposes an HTTP + webhook API CoreX already knows how to consume, resolves **@lid
  natively** (whatsmeow's LID↔PN store — the thing we hand-built in AT-133), syncs
  history on link, and manages multi-session + reconnection for us. **Fallback:** a thin
  in-house **Baileys (Node)** microservice if we want zero licence cost and full control.
- **What changes:** ONLY the transport. A small Laravel webhook adapter maps the
  session's message payload into the **existing `messages[]` contract** and calls the
  **same `WaArchiveIngestor`**. Everything behind it (AT-132/133/135/136, tz fix,
  match-first) is reused unchanged.
- **What retires:** the whole `chrome-extension/wa-capture/` DOM-scrape / IndexedDB /
  idle-sweep / scroll-backfill machinery, plus `WaBodyBackfillService` and the
  `/wa/backfill-targets` endpoint, and the `body_status=unreadable` churn (bodies now
  arrive in cleartext).
- **Shape:** target = **fully server-side**; **migrate by running it ALONGSIDE the
  extension first** (per-agent flag) to de-risk, then retire the extension.
- **Honest caveats:** (1) a server-held linked-device session is **slightly higher ToS
  detectability** than the extension (which rides the agent's genuine browser session) —
  but our read-only/no-send discipline avoids the actual ban triggers. (2) History sync
  gives a large **initial backlog**, not a guaranteed retroactive 5 years — true 5-year
  FICA retention accrues forward from link date (see §4).

---

## The problem (why this ticket exists)

The current capture is a Chrome extension inside the agent's logged-in WhatsApp Web tab.
It can only read what the tab renders, and Chrome throttles backgrounded tabs. So capture
**structurally requires** the agent to keep WhatsApp Web open, foregrounded, and idle, and
history-backfill needs them to watch the chat scroll itself (AT-137 items 1/4, v1.4.x).
That is *the user does complicated so the system does simple* — the inversion of CoreX's
founding principle. A compliance archive cannot depend on agent babysitting. The v1.4.x
foreground-sweep tuning was treating a **wrong model** as a bug. The fix is to move the
transport off the agent's browser entirely.

---

## §1 — Server-side session options

A server process holds the WhatsApp Web (multi-device "linked device") session. The agent
scans a QR **once**; the session persists on the server and reconnects on its own.

| Option | Engine | @lid | History on link | Multi-session | We write | Cost |
|---|---|---|---|---|---|---|
| **WAHA** (devlikeapro, Apache-2.0 Docker) | GOWS (whatsmeow) / NOWEB (Baileys) / WEBJS (browser) | **native (GOWS)** | yes (engine sync) | Plus tier (unlimited) | almost nothing — consume its REST/webhook | Core free (1 session, send-only-text); **Plus ~$19/mo** for multi-session + media |
| **Baileys** (WhiskeySockets, Node) | own | native (@lid in protocol) | `messaging-history.set` on connect; `syncFullHistory` | we manage N sockets | a Node microservice + session store + webhook | free |
| **whatsmeow** (tulir, Go) | own | **native LID↔PN store** (`whatsmeow_lid_map`, `Store.LIDs.GetPNForLID()`) | sync on connect (battle-tested in mautrix bridges) | we manage N clients | a Go microservice | free |

**Recommendation: WAHA / GOWS.** Reasoning for CoreX (PHP/Laravel, no Node/Go in-house,
small box, ~4 agents scaling):
- It's the **least code we own**: WAHA is a Dockerised service; CoreX just calls its REST
  to start a session / get the QR, and receives a **webhook POST per inbound + outbound
  message** — which maps directly onto our existing ingest contract (§2).
- **GOWS = whatsmeow under the hood**, so we inherit the **mature native @lid resolution**
  (the exact `whatsmeow_lid_map` ecosystem from our AT-133 research) instead of our
  hand-rolled JS resolver.
- It owns **session storage, reconnection, and multi-session** (Plus) so we don't build a
  socket supervisor.

**Session management (WAHA):** one container, one session per agent keyed by name
(e.g. `agent-46`). Session auth state persisted to a Docker volume / DB. Reconnect is
automatic; on a hard logout WAHA emits a `session.status` event → CoreX flips the agent to
"needs re-link" and shows the QR again. ~4 agents fit comfortably on the current Hetzner box
(GOWS is the light, browserless engine).

**Baileys fallback** if we won't pay WAHA Plus or want full control: a single Node service
holding N sockets (auth state in Postgres/Redis), posting the same webhook into CoreX. More
code we own (supervisor, reconnection, history handling) but $0 and no licence ceiling.

---

## §2 — What carries over (the important part: almost everything)

**The entire server-side pipeline is reusable. Only the transport in front of it changes.**
The linchpin is that ingestion already has **one well-defined contract** — the
`messages[]` array validated in `WaIngestController::ingest`:

```
message_id, chat_id, direction, sender, timestamp, text,
has_media, media[], counterpart_phone, counterpart_lid, resolved, body_unreadable
```

A server session reaches the whole stack through a **thin adapter** that produces this exact
shape and calls `WaArchiveIngestor::ingest()` (directly, or by POSTing the same endpoint):

```
WAHA/Baileys session ──webhook──▶ WaSessionWebhookController (NEW, thin)
        maps payload → messages[] (same contract) ──▶ WaArchiveIngestor::ingest()
        ──▶ [unchanged] AT-122 match-first · AT-133 @lid guard · AT-136 consent gate ·
            AT-135 body/body_status · occurred_at tz · CommunicationLink · AT-132 archive/thread/gate
```

| Component | Status under server-side |
|---|---|
| `WaArchiveIngestor` (match-first, dedup, reconcile, consent gate wiring) | **REUSED unchanged** — it's the ingestion target |
| AT-136 consent gate (`AgentCaptureConsentService`, `isCaptureOptedIn`, body withholding, raw redaction) | **REUSED unchanged** |
| AT-132 archive + thread view + per-thread gate (`applyArchiveVisibility`, `scopeVisibleTo`) | **REUSED unchanged** |
| AT-137 sender display (`from_display`), context-aware back | **REUSED unchanged** |
| `occurred_at` timezone fix (02cc51d9 `toDate`) | **REUSED** (adapter passes a unix ts) |
| `counterpart_lid` column + `from_identifier` provenance | **REUSED** (adapter fills them from the library's native @lid/PN) |
| AT-133 @lid→phone resolution | **mostly REDUNDANT** — whatsmeow/Baileys resolve @lid→PN natively; the adapter sets `counterpart_phone`. **Keep the server-side @lid guard** in `ContactIdentifierResolver`/ingestor as defence-in-depth |
| AT-135 **body**: bodies arrive **in cleartext** from the library | **SIMPLIFIED** — no DOM scrape, no `body_status=unreadable`, no backfill sweep. `body_status` collapses to `captured` / `consent_pending` |
| **Extension** `chrome-extension/wa-capture/` (content.js DOM/IDB scrape, idbSweep, history sweep, backfillSendChat, JS @lid resolver, ping/device-token) | **RETIRED** (or kept transitionally — §5) |
| `WaBodyBackfillService` + `/wa/backfill-targets` | **RETIRED** — server sync replaces scroll-backfill |
| `communication_wa_devices` (token-per-tab) | **REPLACED** by a session model (agent ↔ WAHA session name + status) |

Net: we keep all the hard parts (identity, consent, archive, gate, matching) and delete the
fragile parts (DOM scraping, virtualization fights, idle gates, scroll-backfill). The AT-133/135/137
effort is **not wasted** — it hardened the ingestor and proved the contract the adapter targets.

---

## §3 — ToS / blocking profile

Both models are **unofficial-client territory** (neither uses Meta's paid Cloud API).

- **Extension (today):** reads the agent's **genuine, human-driven** WhatsApp Web tab. From
  Meta's side this looks the most like real usage → **lowest detectability**, but it's the
  one that violates our own UX principle.
- **Server session (Baileys/whatsmeow/WAHA):** registers as a **linked device** and talks
  the protocol directly. Meta's detection (reply-ratio, contact-graph distance, robotic
  timing, and protocol-version drift on Meta's periodic updates) can flag these. Reported
  bans in 2025–26 hit even **low-volume, reply-only** accounts in some cases.

**Realistic read:** the server move trades a little ToS safety for the correct UX + reliable
background + clean history. The **biggest ban triggers are bulk-send / robotic outbound**,
which we **do not do** — capture is **read-only and never sends**, and **outreach stays a
separate account** (the rule we already set in AT-135/136). That discipline is the main
mitigation and it carries over verbatim. Recommend: GOWS/whatsmeow (most mature, used by
production bridges), keep the session strictly read-only, monitor `session.status`, and be
ready to re-link. Risk is real but manageable and not worse in kind than what we already run.

---

## §4 — History sync (the FICA question — honest answer)

**Server-side sync is a large, clean win over scroll-scraping, but it is NOT a guaranteed
retroactive 5 years.**

- On link, the library receives WhatsApp's `messaging-history.set` (Baileys) / on-connect
  history (whatsmeow): **chats + contacts + a substantial recent message window**, in
  cleartext, automatically — no scrolling, no DOM, no agent watching. This **solves the
  deep-backfill problem we've been fighting** (AT-137 item 4) outright.
- **On-demand deep fetch beyond the initial window is unreliable in 2025–26** — Baileys'
  `fetchMessageHistory` (50/req) frequently gets **silently dropped** by WhatsApp for
  companion devices. So we can't assume we can pull *arbitrary* history on demand.
- **WhatsApp itself only serves a bounded history to a newly-linked device** (typically
  recent months, not years). So "import 5 years on link" is **not** something any of these
  libraries can guarantee — the data isn't offered.

**Conclusion for FICA:** true 5-year retention is achieved by **CoreX archiving forward from
link date** (the archive *accumulates* to 5 years), plus whatever initial backlog the sync
hands us for free. That's the same retention model as before — but now it's **automatic and
complete going forward** instead of dependent on the agent keeping a tab open. This is the
real win: **go-forward capture becomes reliable and unattended**, and we get a meaningful
historical head-start at link time.

---

## §5 — Hybrid vs full

- **(b) Hybrid** (extension live-while-open + server session for background/history): keeps
  the broken foreground dependency alive, doubles the surface to maintain, and creates
  dedup/ordering work between two transports for the same messages. **Not the destination.**
- **(a) Full server-side** (retire the extension): one transport, the correct UX, least
  long-term code. **Recommended destination.**

**But migrate via a transitional hybrid:** stand the server session up **alongside** the
extension behind a per-agent flag, prove parity on real traffic (same `WaArchiveIngestor`,
server dedups by `external_id`, so both feeding it is safe), then **flip agents over and
retire the extension**. Hybrid is the *migration tool*, not the *architecture*.

---

## §6 — Effort, infra, risk, migration

**Infra added (Hetzner):**
- WAHA Docker container (GOWS engine) + a persistent volume/DB for session auth state.
  (Baileys path instead: a small Node service + Redis/Postgres for auth state.)
- Inbound webhook route into CoreX; outbound REST calls to WAHA for QR/link/status.

**Build (CoreX side) — medium, mostly wiring (the pipeline is done):**
1. `WaSessionWebhookController` + a `WaSessionMessageAdapter` mapping WAHA/Baileys payload →
   the existing `messages[]` contract → `WaArchiveIngestor`. (small)
2. Session model replacing `communication_wa_devices`: agent ↔ session name + status +
   last_seen; "link / re-link" QR screen (agent scans once). (small–medium UI)
3. `session.status` handling → "needs re-link" state + nav surface.
4. Collapse `body_status` (drop `unreadable`); retire `WaBodyBackfillService` +
   `/wa/backfill-targets`. (cleanup)
5. Retire `chrome-extension/wa-capture/` after cutover. (deletion)

**Risk / reliability:** reconnection and multi-session are WAHA's job (lower risk than our
own supervisor); main risks are (a) ToS/ban (§3 — mitigated by read-only/no-send), (b) WAHA
Plus licence for multi-session (~$19/mo) or the Baileys-build alternative, (c) history
window is bounded (§4 — accept go-forward retention).

**Migration path (de-risked):**
1. Stand up WAHA + webhook adapter; link **one** agent (Johan) alongside the extension.
2. Compare archived rows from both transports for that agent (server dedup makes this safe).
3. Once parity holds, link the other agents; disable the extension per agent.
4. Delete the extension + backfill machinery; collapse `body_status`.

---

## Decision for Johan

1. **Transport:** WAHA/GOWS (recommended) vs in-house Baileys service vs stay-extension.
2. **Shape:** full server-side (recommended) with hybrid only as the migration step.
3. **Budget:** WAHA Plus (~$19/mo, multi-session) vs build Baileys ($0, more code we own).
4. **History expectation:** accept "large backlog on link + reliable go-forward" (true; 5-yr
   accrues forward) rather than "retroactively import 5 years" (not offered by WhatsApp).

No build proceeds until Johan picks. The entire server-side pipeline we shipped (AT-132/133/135/136/137)
is the asset this re-points at the correct "agent does nothing" model.

---

### Sources
- Baileys history sync: https://baileys.wiki/docs/socket/history-sync/ · on-demand drop issue https://github.com/WhiskeySockets/Baileys/issues/2452 · syncFullHistory behaviour https://github.com/NousResearch/hermes-agent/issues/11951
- whatsmeow LID store: https://pkg.go.dev/go.mau.fi/whatsmeow · https://deepwiki.com/tulir/whatsmeow · full history on reconnect https://github.com/tulir/whatsmeow/discussions/1033
- WAHA: https://waha.devlike.pro/ · https://github.com/devlikeapro/waha · sessions https://waha.devlike.pro/docs/how-to/sessions/
- ToS / ban risk: https://github.com/tulir/whatsmeow/issues/810 · https://github.com/WhiskeySockets/Baileys/issues/1869 · https://blog.kraya-ai.com/whatsapp-automation-ban-risk
