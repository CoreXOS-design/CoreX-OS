# Spec: Ellie v2 — Tool-Using Assistant

**Status:** Approved for build (Johan, 2026-07-26) — supersedes the Phase-2 items in `.ai/specs/ellie.md`
**Author:** cc lane, 2026-07-26
**Related:** `.ai/specs/ellie.md`, `.ai/specs/ellie-navigation-atlas.md`, `.ai/specs/ellie-tour-knowledge.md`, `.ai/specs/ai-cost-ledger.md`, `.ai/specs/multi-tenancy.md`

---

## 1. Why — the evidence

Ellie answers roughly 60% of what agents ask. The other 40% is not a model-quality
problem; it is an **architecture** problem, and the failure is measurable. Replaying
the 363 real messages in `ai_messages` (64 threads) through the live retrieval code
produced the following:

### 1.1 The navigation gate discards correct answers

`NavigationAtlasService::isNavigationQuery()` is a hard-coded phrase list. If a
question does not contain one of ~22 literal phrases, `EllieController` never calls
the atlas at all — **even when the atlas would have scored a confident match**.

| Real agent question | Atlas match + score | Injected? |
|---|---|---|
| "How do i manually add a buyer in my buyer pipeline" | Buyer Pipeline — **12.0** | ❌ gate closed |
| "how do I send a FICA request" | FICA — 3.0 | ❌ gate closed |
| "how do i send a whatsapp to a seller" | Communications Triage — 3.0 | ❌ gate closed |
| "how do i change the price of a property" | Properties (My Listings) — 1.0 | ❌ gate closed |
| "When creating a document, how do i rename it" | Create a Document — 2.0 | ❌ gate closed |

13 of 25 replayed failures had a usable destination in hand and dropped it. Ellie
then replied *"I don't have documented steps for manually adding a buyer to your
buyer pipeline"* while holding a score-12 match for that exact page.

### 1.2 Tour scoring has no relevance floor, so it injects the wrong walkthrough

`TourKnowledgeService::score()` accumulates absolute points, so a long question
out-scores a short precise one and unrelated tours clear the fixed `score < 4` bar:

| Question | Tour returned | Correct? |
|---|---|---|
| "step by step how to make a viewing pack" | "Document packs" (9.0) | ❌ different feature |
| "Client want to leave me a review where does he do it" | "Reviewing & assigning a split pack" (4.0) | ❌ matched "review"/"Reviewing" |
| "How many for sale properties do I have?" | "Matching buyers to property" (6.0) | ❌ not a how-to question |

A wrong tour is worse than no tour: the system prompt instructs the model to follow
injected steps *exactly*, so a false match makes Ellie confidently describe the
wrong feature.

### 1.3 The canonical training answers are not embedded

`training_doc_chunks` — 132 rows, the 12 hand-written user-facing CoreX guides —
have **`has_embedding = 0` on every row**. `KnowledgeSearchService` therefore falls
back to `keywordMatchTrainingChunks()`, which demands 2+ literal keyword hits. The
best-written, most on-topic content in the system is the hardest to retrieve.

### 1.4 Retrieval decides before the model reads the question

Everything above is a symptom of one root cause: **retrieval is guess-up-front and
one-shot.** Keyword heuristics choose what to inject *before* the model sees the
question, the result is stuffed into a system prompt, and exactly one Anthropic call
is made with no tools. Ellie can never say "let me look that up", never retry a
search with better words, and never combine two sources.

The clearest proof: agents repeatedly asked *"whats clause 9 of the otp"* and
*"clause 2.6 of the Exclusive Authority To Sell"*. Knowledge documents **#4, #18,
#19 "Offer to Purchase"** and **#15 "Exclusive Authority To Sell"** are embedded and
ellie-enabled. The answers were in the knowledge base the whole time. One-shot
keyword retrieval could not expand "OTP" → "Offer to Purchase", so Ellie replied
*"I don't have access to your specific Offer to Purchase documents"* — five times.

### 1.5 No live data

The largest single question class in the logs — "how many listings do I have",
"How many for sale properties do I have?", "how many listings does the company
have" — is unanswerable by construction. `.ai/specs/ellie.md` lists Pillar Awareness
as Phase 2; it was never built. Ellie receives a fixed performance snapshot and
nothing else.

---

## 2. What we build

Move Ellie's reasoning loop **out of the Python service and into Laravel**, and give
her tools she can call on demand.

Tools require Eloquent, `PermissionService`, and route resolution — all Laravel. The
Python service at `/opt/hf-ai` keeps `/transcribe` (Whisper, POPIA: audio never
leaves the box) and keeps `/chat` for `AiChatProxyController`. Only Ellie's brain
moves.

```
Before:  question → keyword guesses → stuff prompt → 1 Haiku call → answer
After:   question → model → [tool call → Laravel executes → result] ×N → answer
```

### 2.1 Core principle is unchanged

**Ellie advises, humans decide.** Every tool is **read-only**. No tool writes,
updates, deletes, sends, or files anything. This is non-negotiable and is enforced
structurally: the toolkit exposes no write path.

---

## 3. The toolkit

`App\Services\AI\Ellie\EllieToolkit` — definitions + dispatch. Nine tools.

### 3.1 Knowledge & help

| Tool | Input | Returns | Backed by |
|---|---|---|---|
| `search_knowledge` | `query`, `limit?` | Excerpts from KB docs, SA legislation and training guides, each with title + link | `KnowledgeSearchService` |
| `find_page` | `query` | Destination label, category, blurb, **permission-filtered URL** | `NavigationAtlasService` |
| `find_how_to` | `query` | Ordered walkthrough steps for a matching guided tour | `TourKnowledgeService` |
| `list_document_templates` | `query?` | Agency document templates + reusable clauses the user may see | `Docuperfect\Template`, `Docuperfect\Clause` (`visibleTo`) |

The model expands vocabulary itself before calling — "OTP" becomes
`search_knowledge("Offer to Purchase clause 9")` — and can re-search with different
words when the first result is thin. That single behaviour fixes §1.4.

### 3.2 Live pillar data — read-only, own-scope

| Tool | Input | Returns | Scoping |
|---|---|---|---|
| `my_listings` | `status?`, `listing_type?`, `limit?` | Count + sample: address, suburb, price, status, type | `Property::visibleTo()` |
| `my_deals` | `status?`, `limit?` | Count + sample: deal no, address, value, status, dates | `Deal::visibleTo()` |
| `my_performance` | — | Month targets, actuals, gap, pace, days left | `AgentPerformanceService` |
| `find_contact` | `name`, `limit?` | Matching people: name, type, agent, last contacted | `Contact::search()` + `AgencyScope` |
| `find_property` | `query`, `limit?` | Matching properties: address, suburb, price, status, agent | `Property::visibleTo()` + address search |

**Scoping rule (Johan, 2026-07-26): strictly the user's own scope.** Every pillar
tool routes through the model's existing `scopeVisibleTo()`, which reads
`PermissionService::getDataScope()`. An agent sees their own records, a branch
manager their branch, an admin the agency. `AgencyScope` (non-negotiable #7) sits
underneath all of it. Ellie can never widen a user's view by one row.

`Contact` has no `scopeVisibleTo()` — contacts are agency-wide in CoreX today, and
`find_contact` deliberately matches that existing product behaviour rather than
inventing a stricter rule Ellie alone enforces. Isolation still comes from
`BelongsToAgency`. If contact-level scoping is added later, `find_contact` adopts it
automatically by switching to the new scope.

### 3.3 Tool result contract

Every tool returns a compact JSON string. Every handler is individually wrapped: a
failing tool returns a plain-language `error` the model can read and route around —
it never throws into the chat request (BUILD_STANDARD §4). A tool with no results
returns an explicit `"no results"` marker, never an empty string, so the model can
tell "nothing found" from "tool broke".

---

## 4. The agent loop

`App\Services\AI\Ellie\EllieAgentService`

1. Build the system prompt: persona, SA context, the user's identity/role, the page
   they are on, and the live-data disclosure rules.
2. Send the conversation (last 10 turns) + tool definitions to Anthropic.
3. While the model returns `tool_use` blocks: execute each via the toolkit, append
   `tool_result` blocks, call again.
4. Stop at `end_turn`, or at **6 iterations** (hard cap — prevents a runaway loop
   from burning budget; the partial answer is returned rather than an error).
5. Record every API round-trip to the AI cost ledger via `AiUsageRecorder` under a
   new `SOURCE_ELLIE_CHAT`.

**Model:** `services.anthropic.models.quality` (Sonnet). Tool selection and
multi-source synthesis is reasoning work; Haiku is the current model and is a
material part of why answers are shallow. Ellie is a low-volume, high-value surface
— a handful of calls per agent per day — and it lands in the existing per-agency
budget cap either way.

**Failure posture:** if Anthropic is unreachable or the key is missing, Ellie returns
a plain-language message telling the user what to do next. Never a stack trace, never
a raw 500 (BUILD_STANDARD §4).

---

## 5. Retrieval repairs (independent of the loop)

These are defects in existing code and are fixed regardless of the agent loop, since
the tools call the same services:

1. **`NavigationAtlasService`** — `isNavigationQuery()` stops being a gate. It
   becomes a *confidence boost*. The atlas always runs; a **relevance floor**
   (min score 2.0, or 1.0 when the phrasing is explicitly navigational) decides
   whether a match is offered. Fixes §1.1.
2. **`TourKnowledgeService`** — scores are **normalised by matched-token coverage**
   instead of accumulated absolutely, so a 3-word hit on a 12-word question can no
   longer beat a precise match. Adds a coverage floor and a stop-word-aware
   false-friend guard. Fixes §1.2.
3. **`KnowledgeSearchService`** — filter *before* `take()` (the current order takes
   the top N and may then discard all of them), and raise the candidate pool so the
   limit applies to surviving results. Fixes silent recall loss.
4. **Training-doc embeddings** — `ellie:embed-training` backfills the 132 chunks.
   Fixes §1.3.

---

## 6. Page context

The widget posts the current path and page title with every message. Ellie is told
where the user is standing, so "how do I rename it" resolves against the page in
front of them. Cheap, and it removes a whole class of ambiguity.

---

## 7. Permissions

No new permission keys. Ellie is already gated by `permission:access_ellie` on every
route. Tools inherit the caller's existing scopes — Ellie is a lens on what the user
can already see, never a bypass.

---

## 8. Pillars

| Pillar | Read | Write |
|---|---|---|
| Property | `my_listings`, `find_property` | never |
| Contact | `find_contact` | never |
| Deal | `my_deals` | never |
| Agent (User) | `my_performance` | never |

Ellie is a **read-only lens across all four pillars** — the first surface in CoreX
that spans them all in one conversation.

---

## 9. Acceptance criteria

1. Every question in §1.1 returns the correct page **with a working link**.
2. "step by step how to make a viewing pack" no longer returns the Document Packs
   walkthrough; a wrong-feature tour is not injected.
3. "whats clause 9 of the otp" retrieves the Offer to Purchase knowledge document.
4. "how many listings do I have" returns the user's real count, own-scoped.
5. An agent asking about another agent's listings gets only their own scope back.
6. All 132 training chunks report `has_embedding = 1`.
7. Anthropic unreachable → plain-language message, no 500.
8. Tool throwing → the model recovers and answers, no 500.
9. Every Ellie exchange appears in `ai_usage_events` under `ellie_chat`.

## 10. Files

**New**
- `app/Services/AI/Ellie/EllieToolkit.php`
- `app/Services/AI/Ellie/EllieAgentService.php`
- `app/Console/Commands/EmbedTrainingChunks.php`
- `tests/Feature/AI/EllieToolkitTest.php`
- `tests/Feature/AI/EllieRetrievalRepairTest.php`

**Modified**
- `app/Http/Controllers/EllieController.php` (one-shot call → agent loop)
- `app/Services/AI/NavigationAtlasService.php` (gate → floor)
- `app/Services/AI/TourKnowledgeService.php` (normalised scoring)
- `app/Services/AI/KnowledgeSearchService.php` (filter before take)
- `app/Models/AI/AiUsageEvent.php` (`SOURCE_ELLIE_CHAT`)
- `resources/views/layouts/partials/ellie-widget.blade.php` (page context)
- `.ai/specs/ellie.md` (Phase 2 → superseded)

## 11. Deliberately NOT in this build

- **Write actions.** Ellie advises, humans decide. Permanent.
- **Agency-wide aggregates for agents.** Johan chose strict own-scope, 2026-07-26.
- **Web search.** `.ai/specs/ellie.md` claims it is live; the Python service has no
  such path and never did. Noted here so the claim is corrected rather than
  inherited.
- **New settings.** This build adds no agency-configurable setting, so
  non-negotiable #10a does not apply.
