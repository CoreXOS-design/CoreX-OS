# Spec: Ellie External Reference Sources

**Status:** Draft — awaiting approval (Johan). Not yet built.
**Author:** cc lane, 2026-07-27
**Related:** `.ai/specs/ellie.md`, `.ai/specs/ellie-v2.md`, `.ai/specs/multi-tenancy.md`

---

## 1. Why — the business requirement

Ellie deliberately has no internet access. `.ai/specs/ellie-v2.md` §11 lists "Web
search" under "Deliberately NOT in this build" — the Python service never had a
web-search path, and that was a conscious choice to stop Ellie answering with
unrelated, unverified, or unnecessary internet content.

That choice stays. What this spec adds is a narrow, structural exception: a small,
admin-curated set of external pages — e.g. a bank's current interest-rate page —
that Ellie may draw on **only** when CoreX's own knowledge base and pillar data
don't have the answer. This is not "give Ellie web search." It is "let a human
pre-approve specific pages, and let Ellie search only the text of those pages."

Example: an agent asks Ellie "what is the prime interest rate right now?" CoreX has
no live rate feed and no KB document that would ever go stale correctly. An admin
pastes the URL of a page that always shows the current rate; Ellie can now answer
that one class of question without ever gaining general internet access.

---

## 2. Core principle: Ellie still never fetches anything live

**No tool exposed to the model can trigger a network fetch.** All fetching — adding
a source, clicking Refresh, and the daily cron — happens entirely outside the chat
request path, initiated only by an admin action or the scheduler. The model is only
ever given a *search* tool over a table of already-indexed text chunks.

This is a stronger guarantee than "the fetcher checks an allowlist": there is no
tool call path from a chat turn to a live URL fetch at all, so a maliciously
crafted question (prompt injection) cannot cause CoreX to fetch anything it wasn't
already told to fetch by an admin. Combined with the fetch-time SSRF guards in §5,
this is the mechanism that keeps "Ellie can search this URL's content" from ever
becoming "Ellie can reach arbitrary URLs."

`Ellie advises, humans decide` (`.ai/specs/ellie-v2.md` §2.1) extends here too:
Ellie surfaces what an approved page says; she never treats it as more authoritative
than it is, and always names the source.

---

## 3. Pillars

This feature does not read from or write to Property, Contact, Deal, or Agent. It
sits in the same category as the existing SA-legislation knowledge base and
training docs — global reference knowledge, not pillar data. `.ai/specs/ellie-v2.md`
§3.1 already treats "Knowledge & help" tools as a separate, non-pillar tool
category; this follows that precedent.

| Pillar | Read | Write |
|---|---|---|
| Property / Contact / Deal / Agent | never | never |

---

## 4. Scope decisions (confirmed with Johan, 2026-07-27)

| Decision | Choice | Why |
|---|---|---|
| Crawl scope | **Single page only** — exactly the URL pasted, no link-following | Smallest possible surface. A multi-page reference site is added as multiple individual sources. |
| Ownership | **Global, CoreX-team-managed only** — not per-agency | Matches the SA-legislation KB precedent (also global, not agency-configurable). Different agencies do not get different approved sources. |
| Freshness | **Scheduled daily re-fetch + manual "Refresh now"** | An interest-rate page changes; a snapshot-only model would silently go stale. |

---

## 5. Data model / migrations

### `ellie_reference_sources`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `url` | string, unique | validated http/https, no private/internal targets (§6) |
| `title` | string, nullable | admin-entered label, or the page `<title>` on first successful fetch |
| `added_by_user_id` | FK → users | |
| `is_active` | boolean, default true | disabled sources are excluded from search and from the refresh cron, but not deleted |
| `last_fetched_at` | timestamp, nullable | |
| `last_fetch_status` | enum: `pending`, `ok`, `error` | |
| `fetch_error` | text, nullable | last error message, surfaced in the admin UI |
| `content_hash` | string, nullable | sha256 of extracted text; unchanged hash skips re-embedding on refresh |
| `deleted_at` | timestamp, nullable | SoftDeletes — non-negotiable #1 |

No `agency_id` — this table is deliberately global, not tenant-scoped (see §4). This
is a conscious exception to non-negotiable #7 in the same way the existing
knowledge-base document tables already are; it is not a new pattern.

### `ellie_reference_chunks`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `source_id` | FK → ellie_reference_sources, cascade delete | |
| `chunk_index` | integer | ordering within the source |
| `chunk_text` | text | extracted, cleaned page text, chunked the same way `training_doc_chunks` is |
| `embedding` | same vector column type/dimension as existing KB chunks (384-dim, BGE) | produced via the existing self-hosted `EmbeddingService::KIND_PASSAGE` — no new embedding infra |
| `deleted_at` | timestamp, nullable | SoftDeletes |

---

## 6. The fetch pipeline (admin-triggered or cron-triggered only, never chat-triggered)

Runs on: (a) admin adds a source, (b) admin clicks "Refresh now," (c) the daily
`ellie:refresh-reference-sources` cron, for every `is_active` source.

Guards, in order:
1. **Scheme check** — `http`/`https` only.
2. **DNS resolution + IP-range check** — reject if the resolved address falls in a
   private, loopback, link-local, or reserved range (RFC1918, `127.0.0.0/8`,
   `169.254.0.0/16`, `::1`, `fc00::/7`, and friends). Checked both when the URL is
   first added (fail fast, don't create a source that can never fetch) and again
   **immediately before every fetch**, including on refresh — a hostname that
   resolved safely at add-time can be re-pointed later (DNS rebinding), so add-time
   validation alone is not sufficient.
3. **Redirect validation** — every redirect hop is re-validated against the same
   scheme + IP-range check before being followed. A redirect to a blocked target
   fails the fetch rather than silently following it.
4. **Content-Type check** — only `text/html` (or `text/plain`) is accepted; anything
   else fails the fetch.
5. **Size + timeout caps** — response capped at ~5MB, request timeout in the
   low-single-digit seconds; both fail closed (marks `last_fetch_status = error`,
   keeps whatever chunks existed from the last successful fetch rather than wiping
   them on a transient failure).
6. **Text extraction** — strip HTML to plain text (script/style/nav/footer
   stripped), chunk using the same chunking approach as `training_doc_chunks`.
7. **Hash check** — if the extracted text's hash matches `content_hash`, stop here
   (no re-embedding, `last_fetched_at` still updates). Otherwise replace this
   source's chunks and re-embed via `EmbeddingService::KIND_PASSAGE`.

A failed fetch (any guard above) sets `last_fetch_status = error` and
`fetch_error`, logs at WARNING, and — critically — **does not remove existing
indexed chunks**. A page that's temporarily down does not make Ellie forget what it
last successfully read; it just stops refreshing until the next successful fetch.

---

## 7. The Ellie tool

`search_reference_sites` — added to `App\Services\AI\Ellie\EllieToolkit` alongside
the existing "Knowledge & help" tools (§3.1 of `ellie-v2.md`).

| Tool | Input | Returns | Backed by |
|---|---|---|---|
| `search_reference_sites` | `query`, `limit?` | Excerpts from `ellie_reference_chunks` (active sources only), each with the source title + URL | new `EllieReferenceSourceSearchService`, same hybrid cosine + structural scoring as `KnowledgeSearchService` |

**System prompt update:** try `search_knowledge` and the live pillar tools first.
Only if those return nothing useful, try `search_reference_sites`. If that is also
empty, say plainly that no answer was found — never fall back to the model's own
general knowledge, and never imply CoreX has a live data feed it doesn't have. Any
answer sourced from `search_reference_sites` names the source URL in the reply, so
it is visibly distinguishable from CoreX's own knowledge base — matching the
existing citation pattern `search_knowledge` already uses for KB docs.

Tool result contract matches `.ai/specs/ellie-v2.md` §3.3: wrapped, never throws
into the chat request, explicit `"no results"` marker rather than an empty string.

---

## 8. Admin UI

**Route:** `/admin/ellie/reference-sources` (new page, not nested under agency
settings — this is global/CoreX-team config, consistent with §4).

**Nav entry:** added to the Admin sidebar the same day this ships, under the
existing AI/Ellie admin grouping (non-negotiable #2).

**Page contents:**
- Add-source form: URL input (client + server validation against §6's guards
  before the source is even created — no point creating a source that will only
  ever error), optional title override.
- Table of existing sources: title, URL, status pill (`pending` / `ok` / `error`,
  with `fetch_error` shown on hover/expand for `error`), last-fetched timestamp,
  chunk count, active/inactive toggle.
- Per-row **"Refresh now"** action — runs the fetch pipeline synchronously (or
  queued with a status poll, implementation detail) and updates the row.
- Delete = soft delete (non-negotiable #1) — removes it from search and from the
  refresh cron; recoverable by an admin with DB access, same as every other
  CoreX delete.

### User flow
1. Admin (with `ai.manage_reference_sources`) opens Admin → Ellie → Reference
   Sources.
2. Pastes a URL (e.g. a bank's prime-rate page), optionally names it, saves.
3. CoreX runs the fetch pipeline immediately: validates, fetches, extracts,
   chunks, embeds. Row shows `pending` → `ok` (or `error` with the reason).
4. From then on, `ellie:refresh-reference-sources` re-fetches it daily; the admin
   can also force a refresh any time.
5. An agent asks Ellie a question the KB can't answer. Ellie calls
   `search_reference_sites`, finds a matching chunk, answers, and cites the source
   URL.
6. If the admin disables or deletes the source, it stops being searchable
   immediately (excluded by `is_active` / `deleted_at`), independent of the next
   cron run.

---

## 9. Permissions

New permission key: `ai.manage_reference_sources` — added to
`config/corex-permissions.php` (non-negotiable #5). Gated to super-admin only,
matching the "global, CoreX-team-managed" decision in §4 — this is not something
individual agency admins/principals can touch, unlike most agency settings.
Route middleware + controller check enforce it; the sidebar entry is hidden without
it.

No changes to Ellie's existing `permission:access_ellie` gate — every agent who can
already talk to Ellie automatically benefits from whatever reference sources are
approved; there is no separate per-agent toggle for this (matches how the KB and SA
legislation content already work — Ellie's existing knowledge sources aren't
individually switchable per agent either).

---

## 10. Deliberately NOT in this build

- **Not in the Agency Setup Wizard** (non-negotiable #10a). This is not an
  agency-configurable setting — it's global CoreX-team-managed reference data, the
  same category as the SA-legislation knowledge base, which also never appears in
  onboarding. Recorded here per the non-negotiable's requirement to make the
  omission a decision on the record.
- **No same-domain crawling.** Each page is added individually; there is no
  "index this whole site" mode. If a reference site's answer lives across several
  pages, each gets pasted in as its own source.
- **No live/on-demand fetch tool for the model.** Structural, not a phase-2 item —
  see §2. This is the property that makes the feature safe to ship at all; it does
  not get relaxed later without a fresh security review.
- **No per-agency reference sources.** Confirmed with Johan 2026-07-27 (§4). If a
  future need for agency-specific sources shows up, that's a new spec, not an
  extension of this one, because it changes the permission model in §9.
- **General web search.** Still not built, still not planned. This spec adds one
  narrow, admin-gated exception; it is not a step toward open web access.

---

## 11. Acceptance criteria

1. Adding a URL that resolves to a private/loopback/reserved IP is rejected at
   submission time with a clear error — no source row is created.
2. A source pointing at a normal public HTML page is fetched, chunked, embedded,
   and searchable by Ellie within one request cycle of being added.
3. Ellie asked a question answerable only by CoreX's own KB never calls
   `search_reference_sites` unnecessarily — the tool is reached only when
   `search_knowledge` and the pillar tools come back empty (matches the "internal
   plumbing" answer posture in `.ai/specs/ellie-v2.md` §4.2 — no narrating which
   tool missed).
4. Ellie asked a question answerable only by an approved reference source answers
   correctly and names the source URL in the reply.
5. Ellie asked a question not answerable by the KB or any approved source says so
   plainly — she never falls back to general model knowledge.
6. Disabling a source removes it from `search_reference_sites` results immediately,
   without waiting for the next cron run.
7. A source whose page goes temporarily unreachable keeps its last-known-good
   chunks searchable; `last_fetch_status` shows `error` but nothing already indexed
   is deleted.
8. A redirect from an approved URL to a private/internal target fails the fetch
   rather than being followed.
9. A non-super-admin cannot reach `/admin/ellie/reference-sources` (route
   middleware) and does not see the sidebar entry.
10. `ellie:refresh-reference-sources` re-embeds only sources whose extracted-text
    hash actually changed since the last fetch.

---

## 12. Files to create or modify

**New**
- `database/migrations/xxxx_create_ellie_reference_sources_table.php`
- `database/migrations/xxxx_create_ellie_reference_chunks_table.php`
- `app/Models/AI/EllieReferenceSource.php`
- `app/Models/AI/EllieReferenceChunk.php`
- `app/Services/AI/EllieReferenceSourceFetchService.php` — the guarded fetch
  pipeline (§6)
- `app/Services/AI/EllieReferenceSourceSearchService.php` — search over
  `ellie_reference_chunks`, backing the new tool
- `app/Console/Commands/RefreshEllieReferenceSources.php`
  (`ellie:refresh-reference-sources`)
- `app/Http/Controllers/Admin/EllieReferenceSourceController.php`
- `resources/views/admin/ellie/reference-sources/index.blade.php`
- `tests/Feature/AI/EllieReferenceSourceFetchServiceTest.php` (SSRF guard cases are
  the important coverage here — private IPs, redirect-to-private, oversized
  response, wrong content-type)
- `tests/Feature/AI/EllieReferenceSourceSearchToolTest.php`

**Modified**
- `app/Services/AI/Ellie/EllieToolkit.php` — add `search_reference_sites`
- `app/Services/AI/Ellie/EllieAgentService.php` — system prompt: try KB/pillars
  first, reference sources second, citation requirement
- `config/corex-permissions.php` — `ai.manage_reference_sources`
- `routes/web.php` (or `routes/admin.php`) — new admin route, `->name()`d
- Admin sidebar partial — new nav entry
- `routes/console.php` — schedule `ellie:refresh-reference-sources` daily
