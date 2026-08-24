# 2026-08-23 — cc3 session notes: e-sign async completion, Core Matches N+1, and everything found along the way

**Author:** cc3 (lane-3), overnight session 2026-08-22 → 2026-08-23, wrapping at Johan's ~5pm return.
**Scope:** staging only. Nothing in this session touched live except where explicitly noted (presentation
fixes and MIC round 1/2, both earlier in the night, both under Johan's direct order — see git log for those
commits; this note is about the LATER work, from the e-sign async-completion task onward).

This exists because the conductor asked for it explicitly: "you've been in this code all day and you know
things about it that aren't written down anywhere... include anything that surprised you, any comment that
contradicts its code, any condition nobody could explain." Everything below is that — not a changelog
(git has that), a knowledge dump.

---

## 1. What landed on staging (verified, deployed, confirmed present)

### 1a. E-sign completion moved off the signing agent's synchronous wait

**Commit:** `60c96d595` (still an ancestor of current Staging HEAD — confirmed before writing this note).

**Problem:** `SignatureService::completeDocument()` ran PDF generation (two Puppeteer renders), contact
linking, auto-filing, and completion emails INLINE on the last signer's request. Measured on staging:
**18,125ms** blocking, real Puppeteer/Chromium invocation, not estimated.

**Fix:** `config('docuperfect.async_completion')`, default OFF. When on, the status write + audit log +
document seal stay synchronous (the legal record — Johan confirmed directly, not resting on a code search,
that a few seconds' delay on the PDF itself is fine: "The signing is legally recorded the instant it
completes... the PDF is a rendering of that record which can follow shortly after"). Everything else
dispatches as `FinalizeSignedDocumentJob` **inside the same DB transaction as the status write** —
`QUEUE_CONNECTION=database` means the job-row INSERT is genuinely atomic with the status UPDATE via that
transaction, not just "usually fine." Measured after: **42ms** agent-visible response time.

**Be precise about that number when you repeat it**: 42ms is not "we made PDF generation fast." The work
still takes ~18s — it moved to a worker, it didn't shrink. Only Johan (or whoever reads this next) should
say "430x faster" if they mean "430x faster to get an answer back," not "430x cheaper to run."

`config('docuperfect.async_completion_pdf_sync')` — separate switch, also default OFF — forces PDF
generation back to synchronous while still deferring filing/linking/email. Built because Johan wanted "we
can put it back in thirty seconds" as cheap insurance, not because we expect to need it. **This was NOT
surfaced in the Agency Onboarding Setup Wizard** (CLAUDE.md non-negotiable #10a) — deliberately: this is an
internal engineering/ops toggle about how the system processes a signing internally, not something an
agency configures about their own workflow. Same category as the MIC cache TTL flags and the
`prospecting.suburb_sort_explicit_tiebreak` flag from earlier tonight — recording the reasoning here per
10a's own instruction to make that omission a decision on the record, not an oversight.

**Proofs, all against the real served staging checkout, not a harness bypass:**
- Real supervisor-managed `corex-worker-staging` process (restarted to load the new job class) picked the
  job off the `default` queue and ran it — 18s real work while the triggering request had returned in 45ms.
- Killed the worker mid-render (`kill -9`), supervisor's `autorestart` brought it back, Laravel's 90s
  `DB_QUEUE_RETRY_AFTER` let the restart reclaim the stuck job. Final state: exactly one signed PDF, one
  filed document, one sealed version, one completion-email timestamp. No duplicates from the crash.
- Dispatched the SAME job twice on purpose, for an already-completed template. `completion_emails_sent_at`
  stayed at its original value; both re-dispatches completed in ~50-70ms (PDF-reuse check skipped
  regeneration); filed-document count and sealed-version count both stayed at 1. This is the one Johan
  said he cared most about, and it's DB-verified, not design-intent restated.
- pdf-sync mode ON: request blocked 18.1s (PDF generated synchronously), filing/email deferred and
  completed a few seconds later. Exactly the intermediate behaviour.
- Flag OFF: 18,143ms on the served checkout, matching the 18,125ms pre-change baseline within noise.
- The "finalising" UI state (see 1a-i below) verified through real HTTP — cookies, CSRF, ID verification,
  no shortcuts — caught the exact window where the page said "being finalised" before flipping to "ready."

**1a-i. Two real, pre-existing bugs found and fixed as a side effect of making the cascade idempotent for
retries** — not scaffolding for the new feature, genuinely present in the code before tonight:

1. `SigningController::downloadPage()` showed "All parties have signed... your copy is ready for download"
   the instant `status` flipped to `completed`, without checking `signed_pdf_path` actually existed. Masked
   today because generation happens inline fast enough that nobody could click through in time — but any
   Puppeteer failure or slow render would have shown a client a "ready" page that then said "actually no,
   try again later" one click later. Confusing sequence for someone who's already anxious about whether
   their legal document went through. Fixed with a `finalising` state.
2. `fileSingleDocument()` / `filePackDocuments()` (in `SignatureService.php`) both silently dropped a filed
   document from their return value when a duplicate-filing check hit (instead of returning that document's
   existing info). Harmless by accident — nothing had ever retried, so the dedup branch never actually
   fired in production. The moment retries exist, a duplicate-filing hit on retry would have silently
   degraded a completion email's attachment to a merged-PDF fallback instead of the real per-document filed
   copy. **This is exactly the class of bug that looks like the new retry logic caused it — it didn't, it
   was already there, waiting for something to finally retry.**

**1a-ii. What I'd do with two more hours here:**
- The `merged_html` embed block at `SigningController.php` (search for "party-aliased" / "backward
  compatibility" near the signature/initial embed calls in `completeWeb()`) is genuinely redundant for the
  render/seal OUTPUT once `canonical_html` is baked — every consumer (`SignaturePdfService::
  resolveRenderHtml()`, `DocumentSealService::seal()`, `SigningController::canonicalOrMerged()`) prefers
  canonical_html first, falls back to merged_html only when canonical is empty. BUT there is one narrow
  fallback branch (`CanonicalDocumentRenderer::compose()`/`resolveOrCompose()` both returning empty — "no
  composable body yet") where canonical_html could theoretically stay empty through completion, and I could
  not empirically rule this out: **staging and live both currently have ZERO `render_type='web'` signature
  templates that have ever been completed.** The entire canonical/wet-ink pipeline this codebase has clearly
  invested heavily in (see `ESIGN-WETINK.md`, `ESIGN-CANON.md`, dozens of AT-fix comments) has, as far as I
  can find, never actually completed a real web-render document in either environment. If you pick this up:
  build the synthetic fixture (see 1a-iii) with a deliberately-empty composable body and see whether
  canonical_html genuinely stays empty through to completion — that's the one experiment I ran out of time
  for, and it's the difference between "provably safe to delete" and "leave it alone."
- `SigningController::downloadWebPdf()` regenerates the PDF **live, via Puppeteer, on every single
  request** — no caching at all (confirmed by grep, not assumed). Measured directly: **~8.7-8.9 seconds per
  render, FLAT regardless of page count** (a 1-page and an 8-page synthetic document both took ~8.7s) — the
  cost is almost entirely cold Chromium process startup (`--single-process --no-zygote`, fresh launch +
  close every time), not actual rendering. This is the same family of problem as the completion-cascade fix,
  arguably worse in user-facing terms since it's paid on EVERY view/download, forever, not once at signing.
  Not fixed tonight (out of scope, flagged only) — but if someone builds a warm-Chromium-instance pool or a
  short-TTL render cache keyed on `(template_id, canonical_version)`, this is where the win is.
  **cc5 (corex-qa1-82) found and is fixing a separate, real bug in this exact method** — a dead
  empty-content check that lets a blank PDF render when `web_template_data` is empty (11/11 completed
  templates on live hit this; cc5 also traced the "stored PDF" question to a June storage-root migration —
  the files exist, just under a path the app no longer reads). I confirmed `SignaturePdfService::generate()`
  (what my new job and the legacy inline path both use for the STORED pdf) checks emptiness on the RAW
  `resolveRenderHtml()` output BEFORE any wrap — a different, correct call site — so tonight's work does
  NOT inherit that bug. Don't re-investigate that; it's confirmed and cc5 owns the fix.
- The other 11 unqueued `Mail::to()->send()` sites in `SignatureService.php` beyond `sendCompletionEmails`
  — ranked list is in section 3 below, not repeated here.

**1a-iii. Reusable test fixture, built because none existed:** `docuperfect:make-signing-fixture --complete`
(artisan command) builds a fully synthetic `render_type='web'` document/template/signature-template/
signature-request chain and optionally drives it through `completeDocument()` directly. Entirely synthetic —
zero real client data risk by construction. This is the ONLY way to exercise this code path on either
environment right now, given the zero-completed-web-templates finding above. Reuse it; don't rebuild it.

### 1b. Core Matches N+1 — `ContactMatchController::allView()` / `propertyCountsFor()`

**Commit:** `5fec5057e` (confirmed still an ancestor of current Staging HEAD).

**Baseline, measured on staging, agency 1, 474 real matches across 18 agents:**
~12.2s wall, ~1,144 queries, ~9.1s of SQL time, for ONE page load. `propertyCountsFor()` called
`MatchingService::propertiesForMatch()` once per match — each call, its own query round-trip plus two
unused eager-loads (`agent`, `branch` — `score()` never reads either, and neither did the counts code).

**Fingerprint captured first** (extended cc5's `run_one.php` pattern into a new sibling,
`mic-work-bench/run_core_matches.php` — same read-only rolled-back-transaction guarantee): every agent
group in order, every match ID in order, every match's exact total/visible/hidden counts.

**Fix:** `MatchingService::propertyCountsForMatches()` — fetches each agency's non-off-market candidate
properties ONCE, then filters/scores each match against that shared in-memory set via
`matchSurvivesFilters()`, a **hand-ported PHP mirror** of `propertiesForMatch()`'s WHERE-clause logic
(listing type + status, category, property_type, price/beds/baths/garages/floor/erf tolerance bands,
suburb ids), built specifically for the exact override shape this call site always uses
(`agent_id=null`, `include_hidden=true`). `propertiesForMatch()` itself is completely untouched — it stays
SQL-first and selective for its other, usually single-agent-scoped callers (`results()`, `printList()`,
`candidatesForProperty()`), where pushing `agent_id` into SQL is still the right thing to do.

**Proof — fingerprint byte-identical before/after**, verified twice (once against the pre-merge worktree,
once against the actual served checkout post-deploy): same 474 matches, same 18 agent groups, same order,
same total/visible/hidden count for every single match.

**Numbers after, served checkout:**

| | Before | After |
|---|---|---|
| Wall | ~12.2s | ~5.0s |
| Queries | ~1,144 | 64 |
| SQL time | ~9.1s | ~0.4s |

**What I'd do with two more hours here:** SQL is no longer the bottleneck (60x faster, 18x fewer queries).
The remaining ~4.6s wall-vs-SQL gap is now the PHP-side filter/score loop — up to ~432 candidate properties
× 474 matches worth of predicate evaluation. Pre-bucketing the candidate pool by `(listing_type,
property_type_family)` before the per-match filter pass would let most matches skip most of the 432
candidates outright instead of evaluating every predicate on every one. Didn't build it — the N+1 (the
actual thing asked for) was the priority and is done; this is a real but smaller follow-up.

**Gap flagged, not hidden:** no existing test file covers `MatchingService` or `ContactMatchController`.
Verification here rests entirely on the fingerprint diff against real staging data, not new automated test
coverage — a deliberate tradeoff under the time constraint, not an oversight. If this needs to be "actually
done" by CoreX's own testing standard, that test still needs writing.

**A process note I'm putting on the permanent record rather than letting it disappear with the terminal:**
I made the exact same mistake twice tonight — editing a file directly in `/corex-staging` (the served
checkout) instead of an isolated worktree, once during MIC round 2, once again during this Core Matches fix.
Both times I caught it before running anything and redid it properly. Two independent instances of the same
slip under time pressure is a pattern worth naming plainly rather than filing separately as two unrelated
one-offs: **the very first action after deciding to make a code change should be `git worktree add`, before
opening any editor on the target file** — not a reminder to "be careful," a literal ordering rule for the
next agent (or me) to follow mechanically.

---

## 2. Started and abandoned — none, but one thing never started

I did not start Settings (`/corex/settings`) or the `/corex` dashboard surveys — the two "bonus if there's
time" items cc5's survey also flagged and the conductor hadn't assigned elsewhere. The wrap-up instruction
arrived immediately after I said "moving on to Settings and Dashboard now" and before I'd read a single
line of either controller. **These are still fully open, not partially done** — whoever picks them up next
starts from zero, same as I would have. Nothing to revert because nothing was touched.

No other work was abandoned. The `SignatureService.php` coordination message to cc6 (proposing I keep that
file since I'd been living in it all night, cc6 takes something else) went unanswered — I never touched the
file again after sending it, so there's no conflict risk either way, but the actual division of labour on
the two email-wait fixes (`handleAmendment`'s notification, `requeueAllPartiesForInitialing`) is still
unresolved between us as of this note.

---

## 3. Follow-up backlog — the other unqueued `Mail::to()->send()` sites, ranked

Traced every one of the 13 sites to its ACTUAL caller (not assumed) earlier tonight. Not touched — report
only, cc6 has first refusal on the top two pending the SignatureService.php conversation above.

**Confirmed synchronous, inside a request a human is watching, ranked by exposure:**
1. `handleAmendment()`'s notification (`SignatureService.php`, ~line 5441 as of tonight) — fires from
   *inside* `completeWeb()` itself, before the final-party completion check, so it runs regardless of the
   async-completion flag built tonight. A recipient who just added an amendment condition is sitting on the
   signing page waiting for this send before their own request returns.
2. `requeueAllPartiesForInitialing()` (~line 5733) — loops over every prior signer, one synchronous
   `Mail::send()` per recipient, inside an agent's single "approve amendment" click
   (`AmendmentController.php:99`). Cost compounds with party count — a document with 8 prior signers is 8
   sequential SMTP round-trips before the agent's screen updates.
3. `sendSigningRequest()` → `sendSigningRequestEmail()` — dispatches the NEXT party's invite as part of the
   CURRENT signer's completion response (`handlePartyCompletion()` → `sendSigningRequest($nextRequest)`),
   plus two direct controller call sites for explicit send/resend actions.
4. `rejectAmendmentNode()`'s notification (→ `routeEditorToReacceptance()`) — approver clicks reject, waits
   on the send in the same request (`SignatureController.php:3121`).
5. `resendInvitationEmail()` / `resendCompletionEmail()` — explicit agent-clicked resend buttons; lowest
   priority since a brief wait is already the expected UX for a manual resend.

**No caller found anywhere in the app via static search — confirm before assuming safe to leave, or safe
to fix, either way:** `beginSequentialAmendmentInitialing()`, `sendReminderEmail()`,
`sendManualReminderEmail()`, `sendWetInkRejectionEmail()`, `handleWetInkUpload()` (and its
`sendWetInkUploadedNotification()`), `reactivateRequestForMark()`. Either dead code nobody's removed, or
wired through something a grep can't see (Livewire binding, reflection). I did not guess either way — this
needs someone to actually trace it, not inherit my assumption.

---

## 4. Things that surprised me / comments that contradict the code / conditions nobody explained

Collecting these in one place per the conductor's explicit ask — these are the things I now know from
reading this code closely tonight that aren't written down anywhere else.

1. **Zero completed `render_type='web'` signature templates exist on staging OR live.** Given how much
   engineering has clearly gone into the canonical/wet-ink pipeline (multiple dedicated specs, dozens of
   named AT-fix comments, a whole doctrine around hash-chained sealed versions), I expected to find at
   least a handful of real completed documents to check my work against. There are none. Every completed
   signature template on both environments is the older PDF-marker-overlay type. This isn't necessarily
   alarming (maybe the feature is genuinely not yet in real use), but it means nobody has ever seen this
   exact completion path run for real, on real data, end to end — which is worth knowing before anyone
   assumes it's battle-tested because the code reads like it should be.

2. **`SigningController.php`'s embed-block comment says "kept below for backward-compat" as if it's inert
   legacy** (party-aliased `merged_html` embed at the point where canonical ink is baked) — but the AT-373
   fix comment two paragraphs above it, in the SAME method, explains that `merged_html` is the ACTIVE
   working artifact for any document still at `canonical_version < 1` (amendments/strikes/change-initials
   are written there, not to canonical, until first bake). The two comments are both true but read like
   they contradict each other unless you trace the version-gating logic carefully — "backward compat" reads
   as "safe to eventually delete," but the code two paragraphs up shows it's currently load-bearing for an
   entire document lifecycle phase. Don't trust the comment's framing without re-deriving why from the code.

3. **`downloadWebPdf()` has literally no caching and regenerates via a fresh headless Chromium launch on
   every single call**, confirmed by grep (zero `Cache::` references in the whole PDF-generation path) and
   by direct measurement (~8.7s flat, page-count-independent). Nobody flagged this as slow in the codebase
   comments or specs I read — it was invisible until someone actually clicked "download" and measured.
   Given tonight's whole theme was "found by measuring, not by a client complaining," this is the same shape
   again, one level deeper in the same subsystem.

4. **`MatchingService::propertiesForMatch()`'s "loose" numeric comparisons treat property-side NULL as
   "never penalised, always passes"** — a property with no beds recorded matches a 4-bed-minimum wishlist.
   This is clearly deliberate (commented as "incomplete listings shouldn't be penalised," consistent
   everywhere), but it means the counts on `/corex/core-matches/all` INCLUDE properties with missing data as
   if they satisfied every unset criterion. Not a bug — just a business rule that isn't obvious from the
   screen itself, and worth knowing if anyone ever gets asked "why does this property with no bed count
   show as a match for a 4-bed search."

5. **The suburb filter and the listing_type filter are HARD gates (NULL excludes) while category and
   property_type are LOOSE gates (NULL passes)** — same method, same general shape of "does the property
   have this field," opposite NULL-handling depending on which field. This is intentional (confirmed by
   reading `score()`'s own hard-gate comments — suburb and listing_type get their own explicit "Johan's
   ruling" comments citing real incidents: a Ramsgate property matching a Southbroom-only wishlist, a
   4-bed-minimum matching a 3-bed), but it's the kind of asymmetry that looks like a bug until you find the
   comment explaining the specific incident that caused it.

6. **`Property.baths` is cast `decimal:1`; `beds`/`garages`/`size_m2`/`erf_size_m2` are not cast at all.**
   Matters if anyone else ever needs to port a numeric comparison out of SQL into PHP the way I did tonight
   for the Core Matches fix — a naive `(int)` cast on an uncast attribute is fine, but I had to specifically
   verify that truncating `baths` (e.g. 2.5 → 2) before a `>=` integer-threshold comparison can never flip
   the result, which is true ONLY because every threshold in this codebase (`beds_min`, `baths_min`,
   `garages_min`) is itself an integer and baths values only ever carry one decimal place. That's not
   written down anywhere — I derived it by proof, not by finding a comment that says so.

7. **The naming/session-identity confusion flagged twice tonight is real and reproducible, not a one-off.**
   `ListAgents` currently shows a session `corex-staging-fb` tmux-labeled `cc3`, while this session's own
   git identity is also `cc3 (lane-3)` — two independent sessions both claiming the `cc3` label at the same
   time, on the same box. A later message from the conductor also referred to "cc3" in the third person
   while addressing this session as someone else. I flagged both occurrences live rather than silently
   proceeding; recording it here too since it could matter for anyone reconstructing tonight's audit trail
   from git blame / commit authorship later and finding it doesn't cleanly map to one lane per label.

8. **Testing a queued job from an unmerged worktree against a shared staging queue produces confusing,
   misleading failures that have nothing to do with your code.** Staging already runs long-lived
   `queue:work` processes bound to `/corex-staging`, polling the same database-backed `default` queue my
   isolated worktree also pointed at. A job dispatched from the worktree gets raced by those already-running
   workers, which don't have the new Job class yet — result: `__PHP_Incomplete_Class` failures in
   `failed_jobs`, three of them, before I understood why. Not a bug in the async-completion work — a
   structural fact about testing anything queue-based on this box: **you cannot get a clean queue-worker
   proof from an isolated worktree alone; the code has to actually be merged into the served checkout and
   that checkout's worker restarted before the proof means anything.** Worth remembering before anyone
   burns twenty minutes confused by a phantom failure the way I did.

---

## 5. If I had two more hours, in priority order

1. Settings and Dashboard surveys (never started — see section 2).
2. The `merged_html`-empty-edge-case experiment on the synthetic fixture (section 1a-ii) — closes the one
   remaining "prove it, don't guess" gap from tonight's e-sign work.
3. Core Matches' remaining ~4.6s PHP-side filter/score cost (section 1b) — smaller, real, not urgent.
4. Run down the "no caller found" mail-send methods (section 3) — dead code or genuinely orphaned trigger,
   either way somebody should know which before touching them.
5. `downloadWebPdf()`'s flat ~8.7s-per-click Puppeteer cost (section 1a-ii) — bigger win than anything left
   on this list, but explicitly not started tonight and not mine to claim without checking cc5 isn't already
   on it given their adjacent fix in the same method.

Nothing here went anywhere near live. Everything above is on staging, verified present via git log ancestry
checks against the current Staging HEAD at time of writing, not assumed from memory of having pushed it
earlier in the session.
