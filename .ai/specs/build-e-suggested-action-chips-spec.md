# Build E — Suggested-Action Chips per Prospecting Listing Row

**Spec file:** `.ai/specs/build-e-suggested-action-chips-spec.md`
**Status:** DRAFT — awaiting Johan approval
**Depends on:** Build A (state enricher), Build A.5 (claims), Build B (buyer tiers), Build D.1–D.3 (tracked properties)
**Series:** Final build in the pre-Wednesday market-intelligence sprint.

---

## 1. Purpose

Every prospecting row today carries 6–8 chips of **state** ("PITCHED 12 MAY", "YOU", "↩ RELEASE", "👤 1", "PITCH (STOCK)", buyer tiers). Telling the agent **what has happened**. Build E adds one further chip per row — the only one the agent actually needs to read first — telling them **what to do next**.

**One row, one recommended action.** The state chips remain as the audit trail above; the recommended-action chip replaces the current state-aware CTA cell (`💬 Pitch seller` / `💬 Pitch (stock)` / `⚠ View pitch` / etc.) with a single decision made by a ranked rules engine over real listing state.

The screen should answer two questions at a glance per row:
1. *"What's the situation here?"* — the existing state chip stack.
2. *"So what should I do?"* — the new suggested-action chip.

---

## 2. Why now

The investigation (§§11–12 of the Build E investigation report, 2026-05-14) confirmed:

- **807** listings have a strong-tier buyer match AND no pitch in the last 7 days. **668** of those have ≥3 strong matches. These are high-conversion opportunities sitting in plain sight with no visual prioritisation today — every row's CTA reads the same `Pitch seller`.
- **3** listings have been pitched in the last 7 days with no outcome recorded. The current row UI does nothing to nudge the agent to log the outcome.
- The Madeira Gardens row (id 28) — pitched 2 days ago, in agency stock — currently renders a CTA that invites a duplicate pitch. The amber state badge above is the only signal against re-pitching, and it is decorative, not interactive.

This build closes those gaps.

---

## 3. Out of scope

Explicitly **not** in Build E. These either need new schema, new endpoints, or further investigation and are deferred:

| Deferred item | Where it goes |
|---|---|
| Outbound call log endpoint + chip ("📞 Call seller today") | Build E.5 — needs `call_logs` table |
| Snooze / dismiss listing for N days | Build E.5 — needs `prospecting_listings.snoozed_until` |
| Pitch recency for non-stock listings (`matched_property_id IS NULL`) | Build E.5 — needs `seller_outreach_sends.prospecting_listing_id` direct linkage |
| Consolidation of the two release paths (`prospecting.release` vs `prospecting.claims.release-as-manager`) | Tech-debt ticket — separate audit |
| Meeting booking / calendar event from row | Build F — needs CalendarEvent integration |
| Chip rules that depend on `contact_outreach_log.occurred_at` (call events) | Build E.5 |
| Agency-tunable chip rules / thresholds via settings tab | Build E.6 (post-Wednesday) |

---

## 4. Mandatory pre-reads (every VS Code prompt for this build)

1. `CLAUDE.md`
2. `.ai/STANDARDS.md`
3. `.ai/specs/build-e-suggested-action-chips-spec.md` (this file)
4. `.ai/specs/prospecting-intelligence-spec.md` — adjacent to all Build A/B state
5. The Build E investigation report (2026-05-14) — sections 2, 4, 5, 7, 8, 12 in particular
6. `app/Services/Prospecting/ProspectingListingStateEnricher.php`
7. `app/Models/ProspectingClaim.php` — the `needsReminder()` / `needsBmFlag()` helpers that this build finally surfaces
8. `resources/views/prospecting/index.blade.php` lines 346–710 (the row block)

---

## 5. Bugs this build also fixes (in-scope, small, related)

| # | Bug | Fix |
|---|---|---|
| 5.1 | `PITCH (STOCK)` chip wins over recent-pitch warning when both apply (investigation §13.1) | Solved by virtue of the new ranked resolver — recency outranks stock-promotion |
| 5.2 | Two 48h countdown definitions per row (enricher anchored to `last_updated_at`, view block anchored to `claimed_at`) (§13.2) | Delete the inline calc at `index.blade.php:660`; consume `$listingStates['claims'][...]['hours_left']` everywhere |
| 5.3 | `prospecting_listings.tracked_property_id` is 100% populated but never exposed (§13.4, §9) | Add to `$fillable` + `$casts` on `ProspectingListing` model; add `trackedProperty()` `belongsTo` relation; render a small `TP` link chip on the row that opens the Tracked Property detail page |
| 5.4 | `ProspectingClaim::needsReminder()` and `::needsBmFlag()` are dead code from the view's perspective (§13.5) | Both surfaced through new enricher keys `needs_reminder` and `needs_bm_flag`; consumed by chip rules R3 and R1 |
| 5.5 | `presentations` table not agency-scoped in `loadPresentations` (§13.3) | Add `agency_id` filter via a join on the property — defence-in-depth even though property scope effectively constrains it |

---

## 6. Concepts

### 6.1 One row, one chip

Exactly **one** suggested-action chip renders per row. Decision is made by `SuggestedActionResolver::resolve($listingState, $buyerTiers, $authUser, $isManager)` returning a single `SuggestedAction` value object or `null`.

If the resolver returns `null`, the row shows a passive "—" placeholder where the CTA was. No chip is forced; quiet rows are quiet.

### 6.2 The resolver: a ranked rules engine

Rules are evaluated **top-down**. The first rule that matches wins. Lower-ranked rules are skipped.

This is intentionally rigid. The agent should not have to read rules engine documentation to predict the chip — the highest-priority condition that applies, applies. Stable priorities make the UI predictable across refreshes.

### 6.3 Visual hierarchy — four chip tiers

| Tier | Used for | Visual |
|---|---|---|
| **CRITICAL** | Time-critical, money-on-the-line. Stale BM-flag claims, expiring claims < 1h. | Solid `var(--ds-red)` background, white text, no border |
| **ACTION** | Recommended next move with conversion upside. Pitch Now, Follow Up. | `color-mix(in srgb, var(--ds-teal) 22%, transparent)` background, `var(--ds-teal)` text, 1px `var(--ds-teal)` border |
| **AWAIT** | Action already taken, awaiting outcome. Pitch sent — log outcome. | `color-mix(in srgb, var(--ds-amber) 18%, transparent)` background, `var(--ds-amber)` text, 1px `var(--ds-amber)` border |
| **INFO** | Low-urgency informational hint. Investigate buyers, view TP. | No fill, 1px `var(--ds-slate-500)` border, `var(--ds-slate-300)` text |

No emoji. No decorative iconography. A single 12px lucide icon (line variant) at the left of the chip text — `alarm-clock` for CRITICAL, `target` for ACTION, `clock` for AWAIT, `info` for INFO. Plus Jakarta Sans 11px semibold uppercase, 4px letter-spacing, 6px vertical padding, 10px horizontal padding, 4px corner radius. Matches the existing CoreX chip language.

Per the row screenshot, the chip sits in the same horizontal cell currently occupied by `💬 Pitch seller` / `💬 Pitch (stock)` — i.e. it **replaces** the state-aware CTA block (`index.blade.php:460–510`). The state chip stack above it (`PITCHED 12 MAY`, `YOU`, `RELEASE`, `1`, etc.) is unchanged.

### 6.4 Click behaviour

Every chip is interactive. Click =  performs the action OR navigates to the page where the agent performs it. No chip is decorative.

For destructive or claim-state-mutating actions (e.g. `FLAG BM` writes `flagged_at`), the click opens a confirmation modal. For navigation chips, click is a direct anchor.

### 6.5 Tooltip / explanation

Hover (or long-press on touch) shows a tooltip in the form:

> **Why this action?**
> Strong-tier match (5 buyers, top 95%) and no pitch in the last 7 days.

The first line is the chip's name. The second is a one-sentence derivation drawn from the actual values that triggered the rule. Generated server-side by the resolver so the explanation is always correct.

### 6.6 Manager vs. agent visibility

Three rules are manager-only (rank R1, R8, R9). For non-managers, the resolver skips those rules and falls through to the next applicable. So an admin viewing the row may see a different chip than the rank-and-file agent viewing the same row — by design.

---

## 7. The chip catalogue

Listed top-to-bottom in evaluation order. **Rule index = priority.** R1 wins over R2 wins over R3 etc.

| Rank | Chip text | Tier | Manager only | Condition | Click action | Tooltip pattern |
|---|---|---|---|---|---|---|
| **R1** | `FLAG TO BM` | CRITICAL | Yes | `claim.status='listing'` AND `claim.last_updated_at < now-14d` AND `claim.flagged_at IS NULL` | POST `/prospecting/claims/{claim_id}/flag` → opens reason modal, sets `flagged_at=now()` | "Claim has been in *listing* status for N days with no movement. Flag to branch manager." |
| **R2** | `CLAIM EXPIRES SOON` | CRITICAL | No (claim owner only) | `claim.user_id = auth().id` AND `claim.is_active` AND `claim.feedback_at IS NULL` AND `hours_left < 6` | Opens `openFeedbackModal(listing.id, claim.status)` | "Your claim auto-releases in Nh Mmin if you don't log feedback." |
| **R3** | `LOG OUTCOME` | AWAIT | No (pitch sender only) | `pitch.sent_at < now-2d` AND `pitch.sent_at > now-30d` AND `pitch.outcome IN ('sent', null)` AND `pitch.agent_user_id = auth().id` | Anchor to `seller-outreach.composer.timeline` with `?send_id=` and `?focus=outcome` | "You pitched N days ago and haven't logged a response yet. Log the outcome." |
| **R4** | `FOLLOW UP CLAIM` | ACTION | No (claim owner only) | `claim.user_id = auth().id` AND `claim.status IN ('contacted','meeting_set')` AND `claim.last_updated_at < now-7d` | Opens `openFeedbackModal(listing.id, claim.status)` | "Your claim has been in *contacted* for N days with no update. Time to follow up." |
| **R5** | `PITCH NOW · HIGH` | ACTION | No | No active pitch in last 7d for matched property AND no active claim AND `buyerTiers.strong >= 3` AND listing `is_active=true` AND `matched_property_id IS NULL` (not yet in stock) | Anchor to `seller-outreach.entry.from-prospecting` for listing_id | "N strong-tier buyers (top match M%) and no recent outreach. High-conversion pitch opportunity." |
| **R6** | `PITCH NOW` | ACTION | No | No active pitch in last 7d AND no active claim AND `buyerTiers.strong >= 1` AND listing `is_active=true` AND `matched_property_id IS NULL` | Anchor to `seller-outreach.entry.from-prospecting` for listing_id | "N strong-tier buyers and no recent outreach. Worth a pitch." |
| **R7** | `RE-PITCH STOCK` | ACTION | No | `matched_property_id IS NOT NULL` AND no pitch in last 30d AND `buyerTiers.strong >= 1` AND no active claim by anyone else | Anchor to `seller-outreach.entry.from-property` for matched_property_id | "Already in agency stock. New strong-tier buyers since last outreach — re-engage seller." |
| **R8** | `RESOLVE COLLEAGUE CLAIM` | INFO | Yes (manager only) | `claim.is_active` AND `claim.user_id != auth().id` AND `claim.last_updated_at < now-21d` | Opens release-as-manager modal | "Colleague {name} has held this claim for N days with no update. Consider releasing." |
| **R9** | `INVESTIGATE` | INFO | No | No active pitch, no active claim, `buyerTiers.strong = 0` AND `buyerTiers.mid >= 5` AND listing `is_active=true` | Opens buyer-matches side panel via `openBuyerPanel(listing.id)` | "No strong matches but N mid-tier buyers. Worth a look — maybe price or specs adjustment." |
| **R10** | `VIEW TP` | INFO | No | `tracked_property_id IS NOT NULL` AND none of R1–R9 fired (i.e. nothing better to suggest) | Anchor to `corex.tracked-properties.show` for tracked_property_id | "Tracked Property record exists with N source contributions. View intelligence." |
| — | (no chip — placeholder "—") | — | — | Nothing above matched. | None | "No suggested action right now." |

### 7.1 Why this ranking?

Top to bottom: **money in danger → time-critical → outcome owed → opportunity ranked by conversion likelihood → low-priority hints**.

Specifically:
- **R1 above R2** because a stale listing-status claim represents an in-progress mandate that may be slipping; that's worse than a 1h-expiry claim that simply re-pools.
- **R3 above R4** because logging an outcome unlocks downstream reporting and freezes pitched listings out of the re-pitch pool; following up on a stale claim only nudges the agent. Both are owner-only.
- **R5 above R6** because the high-value pool (668 rows for HFC) is the demo headline.
- **R7 (re-pitch stock) below R5/R6** because un-mandated opportunity outranks already-mandated re-engagement — agents win mandates first, manage them second.
- **R9 (investigate)** is INFO not ACTION because it's exploratory, not actionable.
- **R10 (View TP)** is the gentle catch-all that surfaces the D.1–D.3 pillar instead of leaving the row chipless.

### 7.2 Estimated chip distribution for HFC today

From investigation §11. Real numbers, agency_id=1:

| Chip | Approx. count |
|---|---|
| `FLAG TO BM` (R1) | 0 |
| `CLAIM EXPIRES SOON` (R2) | 0 |
| `LOG OUTCOME` (R3) | 3 |
| `FOLLOW UP CLAIM` (R4) | 0 |
| `PITCH NOW · HIGH` (R5) | ~665 (subtract the 3 R3-eligible) |
| `PITCH NOW` (R6) | ~140 |
| `RE-PITCH STOCK` (R7) | ≤ 3 (only 3 in stock total) |
| `RESOLVE COLLEAGUE CLAIM` (R8, manager view) | 0 |
| `INVESTIGATE` (R9) | small remainder, est. 30-80 |
| `VIEW TP` (R10) | catch-all for whatever's left |
| no chip / "—" | 0 (every row has a TP id, so R10 absorbs the tail) |

Synthetic test data needs creating for R1, R2, R4 to demo on Wednesday — see §11 Verification.

---

## 8. Implementation

### 8.1 Service: `SuggestedActionResolver`

New file: `app/Services/Prospecting/SuggestedActionResolver.php`

```php
final class SuggestedActionResolver
{
    public function __construct(private AuthManager $auth) {}

    /**
     * @param  array  $state    Per-listing slice from ProspectingListingStateEnricher
     * @param  array  $tiers    Per-listing buyer tier counts from BuyerMatchTierService
     * @param  object $listing  The ProspectingListing model (read-only access to columns)
     * @param  bool   $isManager Whether the viewer has prospecting.manage
     */
    public function resolve(array $state, array $tiers, object $listing, bool $isManager): ?SuggestedAction
    {
        // R1 → R10 in order. First match wins. Each returns SuggestedAction or null.
    }
}
```

`SuggestedAction` is a small DTO:

```php
final class SuggestedAction
{
    public function __construct(
        public readonly string $rank,         // 'R1'..'R10'
        public readonly string $label,        // 'PITCH NOW · HIGH'
        public readonly string $tier,         // 'critical'|'action'|'await'|'info'
        public readonly string $icon,         // lucide name
        public readonly string $tooltipHtml,
        public readonly string $clickType,    // 'anchor'|'modal'|'alpine'
        public readonly ?string $href,        // present if clickType='anchor'
        public readonly ?string $modalKey,    // present if clickType='modal'
        public readonly ?string $alpineCall,  // present if clickType='alpine'
    ) {}
}
```

**No new DB queries.** The resolver consumes:
- `$state` — already loaded by `ProspectingListingStateEnricher`
- `$tiers` — already loaded by `BuyerMatchTierService`
- `$listing` — already in scope inside the foreach
- `$isManager` — already in scope (`$isProspectingManager` in `index.blade.php`)

Performance budget: **zero additional queries**, < 0.5ms per row in PHP.

### 8.2 Enricher additions

`ProspectingListingStateEnricher::loadClaims()` must additionally expose:

```php
'needs_reminder' => bool,   // from ProspectingClaim::needsReminder()
'needs_bm_flag'  => bool,   // from ProspectingClaim::needsBmFlag()
```

Computed inline from the claim row (avoid re-fetching as model — work off the raw stdClass and replicate the boolean expression). This keeps the enricher's existing single-query pattern.

### 8.3 Controller wiring

`ProspectingController::index` — after the existing `enrich()` call (line 376):

```php
$resolver = app(SuggestedActionResolver::class);
$suggestedActions = [];
foreach ($listings->items() as $listing) {
    $state = [
        'pitch'        => $listingStates['pitches'][$listing->id]         ?? null,
        'claim'        => $listingStates['claims'][$listing->id]          ?? null,
        'presentation' => $listingStates['presentations'][$listing->id]   ?? null,
        'contacts'     => $listingStates['contact_counts'][$listing->id]  ?? 0,
        'temp_lock'    => $listingStates['temp_locks'][$listing->id]      ?? null,
        'promoted'     => $listing->matched_property_id
                          && isset($listingStates['promotions'][$listing->matched_property_id]),
    ];
    $suggestedActions[$listing->id] = $resolver->resolve(
        $state,
        $buyerTiers[$listing->id] ?? ['strong'=>0,'mid'=>0,'weak'=>0,'total'=>0,'top_score'=>null],
        $listing,
        $isProspectingManager
    );
}
```

Pass `$suggestedActions` to the view via the existing `compact()`.

### 8.4 View changes

`resources/views/prospecting/index.blade.php`:

**Replace** the state-aware CTA block at lines 460–510 with a single `@include('prospecting._suggested-action-chip', ['suggested' => $suggestedActions[$listing->id] ?? null, 'listing' => $listing])`.

The state chip stack above (`PITCHED`, `YOU`, `RELEASE`, `1`) stays unchanged. The buyer tier badge, IN STOCK badge, presentations badge, temp-lock badge — all unchanged.

**New partial:** `resources/views/prospecting/_suggested-action-chip.blade.php`. Renders the chip with the four tier styles defined in §6.3. Includes the `<x-tooltip>` wrapper with the resolver-generated explanation.

**Bug 5.2 fix:** at `index.blade.php:660` delete the inline `48 - $claim->claimed_at->diffInHours(now())` calc and consume `$listingStates['claims'][$listing->id]['hours_left']`. Single source of truth.

**Bug 5.3 fix:** small `TP →` link chip in the state stack when `$listing->tracked_property_id` is set, anchored to `corex.tracked-properties.show`. Visible alongside `IN STOCK` (not in place of). Renders for **all** rows since 983/983 are linked.

### 8.5 Model changes

`app/Models/ProspectingListing.php`:

```php
protected $fillable = [/* existing */, 'tracked_property_id'];
protected $casts = [/* existing */, 'tracked_property_id' => 'integer'];

public function trackedProperty(): BelongsTo
{
    return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
}
```

### 8.6 New endpoint: flag-to-BM

`POST /prospecting/claims/{claim_id}/flag` → `ProspectingController@flagToManager`:
- Requires `prospecting.manage` permission
- Requires `reason` (text, min 5 chars)
- Sets `flagged_at = now()`, appends timestamped entry to claim notes (matching the existing notes-log format)
- Records domain event `ProspectingClaimFlagged`
- Returns to prospecting index with success flash

### 8.7 No new permissions

R1, R8 reuse `prospecting.manage`. R2, R3, R4 are owner-only and checked inside the resolver via `auth()->id() === $claim['user_id']` (or `pitch.agent_user_id`).

---

## 9. Files touched

| File | Change |
|---|---|
| `.ai/specs/build-e-suggested-action-chips-spec.md` | NEW (this file) |
| `app/Services/Prospecting/SuggestedActionResolver.php` | NEW |
| `app/Services/Prospecting/SuggestedAction.php` | NEW DTO |
| `app/Services/Prospecting/ProspectingListingStateEnricher.php` | Extend `loadClaims()` with `needs_reminder`/`needs_bm_flag`; add agency_id filter to `loadPresentations()` |
| `app/Models/ProspectingListing.php` | Add `tracked_property_id` to `$fillable`/`$casts`; add `trackedProperty()` relation |
| `app/Http/Controllers/ProspectingController.php` | Build `$suggestedActions[]`; pass to view; new `flagToManager()` method + route |
| `app/Domain/Prospecting/Events/ProspectingClaimFlagged.php` | NEW domain event |
| `routes/web.php` | New route `prospecting.claims.flag` |
| `resources/views/prospecting/index.blade.php` | Replace CTA block; delete inline 48h calc (Bug 5.2); add TP-link chip (Bug 5.3); new flag-modal at bottom |
| `resources/views/prospecting/_suggested-action-chip.blade.php` | NEW partial |

No migration. No new tables. No schema change.

---

## 10. Performance

| Metric | Budget |
|---|---|
| Additional DB queries vs. pre-Build-E | 0 |
| Additional service-layer PHP per row | < 0.5ms |
| Additional render time for 50 rows | < 25ms |
| Total prospecting page render delta | < 50ms |

Spot-check via Laravel Debugbar before commit.

---

## 11. Verification matrix

For each rule R1–R10, the build prompt's final report must include:

| # | Verification | Method |
|---|---|---|
| 11.1 | Resolver returns correct chip for the Madeira Gardens row (§12 of investigation) — expected R3 `LOG OUTCOME` | Tinker: instantiate resolver, pass real state, assert `rank === 'R3'` |
| 11.2 | Bug 5.1 fixed: in-stock + recent pitch → resolver returns R3, not the old "Pitch (stock)" | Same row, before/after screenshot comparison |
| 11.3 | Bug 5.2 fixed: only one `hours_left` value renders per row | Grep view for `diffInHours` — must return zero hits |
| 11.4 | Bug 5.3 fixed: every row has a TP link chip; click opens the TP detail | Spot-check 5 random rows |
| 11.5 | Bug 5.4 fixed: `needs_reminder` and `needs_bm_flag` appear in enricher output for at least one synthetic test claim | Tinker output |
| 11.6 | Bug 5.5 fixed: `loadPresentations` filters by agency | Read code; grep for `agency_id` in the method |
| 11.7 | R5 fires on a real HFC row with ≥3 strong matches and no recent pitch | Sample 5 random rows from the 665-strong pool, assert R5 |
| 11.8 | R6 fires on a real HFC row with strong=1 or 2 and no recent pitch | Sample 5 rows |
| 11.9 | R3 fires on each of the 3 currently-pitched rows | Enumerate |
| 11.10 | R1, R2, R4, R8 demo cases — create synthetic test claims via Tinker to verify each rule fires; teardown after | Tinker script |
| 11.11 | R10 catch-all: pick a row where no other rule could match; assert R10 | Tinker |
| 11.12 | Manager view vs. agent view: load page as `johan@hfcoastal.co.za` (manager) and a basic agent user; assert R1/R8 only render for the manager | Two sessions |
| 11.13 | Tooltip text is non-empty and accurate for every chip — sample 10 rows | Visual |
| 11.14 | Flag-to-BM modal end-to-end: open, submit reason, claim `flagged_at` set, domain event recorded | Tinker + DB check |
| 11.15 | Performance: total prospecting page render < pre-Build-E + 50ms | Debugbar |
| 11.16 | `php -l` on every changed PHP file | Required |
| 11.17 | `php artisan view:clear` | Required |
| 11.18 | `scripts/dev-check.ps1` passes with 0 new failures | Required |
| 11.19 | Idempotency: reload prospecting page 5 times, chip rendered for Madeira row is identical each time | Visual |

Final line of the build report MUST be exactly:

```
BUILD E COMPLETE — XX/19 VERIFICATIONS PASSED.
```

---

## 12. Demo script for Wednesday

1. Open `/prospecting` as Johan (manager).
2. Top of the table: rows show `PITCH NOW · HIGH` chips on rows with 3+ strong matches. Headline: 668 actionable opportunities at a glance.
3. Click `PITCH NOW · HIGH` on a sample row → composer opens, pre-filled. *"One click from intel to action."*
4. Filter to "My Claims" → demo a synthetic R2 `CLAIM EXPIRES SOON` (red chip). Click → feedback modal opens. *"System nudges you before the listing escapes."*
5. Filter to Madeira row → R3 `LOG OUTCOME` chip (amber). Click → timeline opens at the outcome field. *"You pitched, system knows you pitched, system asks you to close the loop."*
6. Switch to a synthetic R1 row (stale listing-status claim) → CRITICAL red `FLAG TO BM`. Click → reason modal. *"Manager flag with audit trail."*
7. Show R10 `VIEW TP` on a quiet row → opens Tracked Property detail with full source chain.

**The story:** *"Every row tells the agent exactly what to do. No reading, no guessing, no missed opportunities."*

---

## 13. Build sequence

After this spec is approved by Johan:

1. **E.1** — Resolver + DTO + enricher extensions + adjacent bug fixes (5.2, 5.3, 5.4, 5.5). Backend only. No UI yet. Tinker-verify all 10 rules fire on synthetic state. (Single prompt.)
2. **E.2** — View partial + index.blade integration + flag-to-BM modal & endpoint. (Single prompt.)
3. **E.3** — Synthetic test data seeder for Wednesday demo (R1, R2, R4 cases). (Single prompt.)

Three prompts. Each ends with `php -l`, `view:clear`, `dev-check.ps1`, Tinker verification, build report. Standard sequence.

---

## 14. Approval needed before any code

Johan reviews this spec and approves / amends. On approval the spec is committed to `.ai/specs/build-e-suggested-action-chips-spec.md` and Build E.1 prompt is written referencing it.

Per HARD RULE #3: investigate → report → **approve** → fix. We are at "approve".
