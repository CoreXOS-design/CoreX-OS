# Build E — Suggested-Action Chips per Prospecting Listing Row

**Spec file:** `.ai/specs/build-e-suggested-action-chips-spec.md`
**Version:** v2 (supersedes v1 `8a11dfa`)
**Status:** APPROVED — ready to build
**Depends on:** Build A (state enricher), Build A.5 (claims), Build B (buyer tiers + tier settings), Build D.1–D.3 (tracked properties)

---

## 1. Purpose

Every prospecting row carries 6–8 chips of **state** today. Build E adds one chip telling the agent **what to do next**. One row, one recommended action, derived from a ranked rules engine over real listing state. State chips remain as the audit trail; the recommended-action chip **replaces** the current state-aware CTA cell (`Pitch seller` / `Pitch (stock)` / `View pitch` / `Claim`).

Two questions the screen answers per row:
1. *"What's the situation here?"* — existing state stack.
2. *"What should I do?"* — the new suggested-action chip.

---

## 2. Architectural rule — all thresholds are setup-driven

Every numeric threshold in the rules engine is **agency-configurable**, never hard-coded. Same pattern as Build B's `buyer_match_tiers`. New table `suggested_action_thresholds`, one row per agency, fed by `ProspectingConfigurationService`. Settings UI lives at `/corex/settings/prospecting` as the 6th tab.

This is non-negotiable architecture. Agencies have different rhythms — virtual high-volume vs. boutique branch — and the rules engine must flex.

---

## 3. Out of scope

| Deferred item | Where |
|---|---|
| Outbound call log endpoint + chip | Build E.5 (needs `call_logs` table) |
| Snooze / dismiss listing | Build E.5 (needs `snoozed_until` column) |
| Pitch recency for non-stock listings | Build E.5 (needs `send.prospecting_listing_id` linkage) |
| Consolidation of two release paths | Tech-debt ticket |
| Meeting booking from row | Build F (CalendarEvent integration) |
| Chip rules from `contact_outreach_log.occurred_at` | Build E.5 |

---

## 4. Mandatory pre-reads (every VS Code prompt for this build)

1. `CLAUDE.md`
2. `.ai/STANDARDS.md`
3. `.ai/specs/build-e-suggested-action-chips-spec.md` (this file)
4. `.ai/specs/prospecting-intelligence-spec.md`
5. Build E investigation report (2026-05-14) — §§2, 4, 5, 7, 8, 12
6. `app/Services/Prospecting/ProspectingListingStateEnricher.php`
7. `app/Services/Prospecting/BuyerMatchTierService.php`
8. `app/Services/Prospecting/ProspectingConfigurationService.php`
9. `app/Models/ProspectingClaim.php`
10. `resources/views/prospecting/index.blade.php` lines 346–710
11. `resources/views/settings/prospecting/_buyer-match-tiers.blade.php` (precedent for the new settings tab)

---

## 5. Bugs this build also fixes

| # | Bug | Fix |
|---|---|---|
| 5.1 | `PITCH (STOCK)` shadows recent-pitch warning | Solved by construction — ranked resolver puts recency above promotion |
| 5.2 | Two 48h countdown definitions per row | Delete inline calc at `index.blade.php:660`; consume `$listingStates['claims']['hours_left']` |
| 5.3 | `tracked_property_id` populated but invisible | Add to `$fillable`/`$casts`; add `trackedProperty()` relation; add `TP →` link chip on every row |
| 5.4 | `needsReminder` / `needsBmFlag` dead from view | Surface via enricher keys `needs_reminder` / `needs_bm_flag`; rules R1 and R4 consume them |
| 5.5 | `loadPresentations` not agency-scoped | Add agency filter |

---

## 6. Concepts

### 6.1 One row, one chip

`SuggestedActionResolver::resolve($state, $tiers, $listing, $thresholds, $authUser, $isManager)` returns a single `SuggestedAction` value object or `null`. Rules evaluated top-down; first match wins. If null, row shows a passive `—` placeholder. Quiet rows are quiet.

### 6.2 Visual hierarchy — four chip tiers

| Tier | Used for | Visual |
|---|---|---|
| **CRITICAL** | Time-critical, money on the line. R1, R2. | Solid `var(--ds-red)`, white text, no border |
| **ACTION** | Recommended next move with conversion upside. R4, R5, R6, R7. | `color-mix(in srgb, var(--ds-teal) 22%, transparent)` bg, `var(--ds-teal)` text, 1px teal border |
| **AWAIT** | Action taken, outcome owed. R3. | `color-mix(in srgb, var(--ds-amber) 18%, transparent)` bg, amber text, 1px amber border |
| **INFO** | Low-urgency informational. R8, R9. | No fill, 1px `var(--ds-slate-500)` border, `var(--ds-slate-300)` text |

No emoji. Lucide line icons (12px) at chip left — `alarm-clock` (CRITICAL), `target` (ACTION), `clock` (AWAIT), `info` (INFO). Plus Jakarta Sans 11px semibold uppercase, 4px letter-spacing, 6px×10px padding, 4px corner radius.

### 6.3 Click behaviour

Every chip is interactive. Click = performs action OR navigates to action page. No decorative chips.

Destructive / state-mutating clicks (e.g. R1 `FLAG TO BM` writes `flagged_at`) open a confirmation modal with required reason. Navigation chips are direct anchors. Feedback-modal chips reuse the existing Alpine `openFeedbackModal()`.

### 6.4 Tooltip

Hover (long-press on touch) shows server-generated explanation:

> **Why this action?**
> Strong-tier match (5 buyers, top 95%) and no pitch in the last 7 days.

Generated by the resolver from the actual values that triggered the rule. Always correct.

### 6.5 Viewer-aware

Rules R1 and R8 are manager-only (`prospecting.manage` permission). R2, R3, R4 are owner-only (`auth()->id() === claim.user_id` or `pitch.agent_user_id`). The same row will render different chips for different viewers — by design. Tooltip explains why.

---

## 7. The chip catalogue

Listed in evaluation order. Rule index = priority. Every threshold reads from `$thresholds` (the agency's row in `suggested_action_thresholds`).

| Rank | Chip | Tier | Visibility | Condition | Click | Tooltip pattern |
|---|---|---|---|---|---|---|
| **R1** | `FLAG TO BM` | CRITICAL | Manager | `claim.status='listing'` AND `claim.last_updated_at < now - $thresholds->stale_listing_days` AND `claim.flagged_at IS NULL` | POST `/prospecting/claims/{claim_id}/flag` → reason modal | "Claim in *listing* status for N days with no movement. Flag branch manager." |
| **R2** | `CLAIM EXPIRES SOON` | CRITICAL | Claim owner | `claim.user_id = auth().id` AND `claim.is_active` AND `claim.feedback_at IS NULL` AND `hours_left < $thresholds->expiry_warning_hours` | `openFeedbackModal(listing.id, claim.status)` | "Your claim auto-releases in Nh Mmin without feedback." |
| **R3** | `LOG OUTCOME` | AWAIT | Pitch sender | `pitch.sent_at < now - $thresholds->outcome_overdue_days` AND `pitch.sent_at > now - $thresholds->outcome_stale_days` AND `pitch.outcome IN ('sent', null)` AND `pitch.agent_user_id = auth().id` | Anchor to `seller-outreach.composer.timeline` with `?send_id=&focus=outcome` | "You pitched N days ago — log the response." |
| **R4** | `FOLLOW UP CLAIM` | ACTION | Claim owner | `claim.user_id = auth().id` AND `claim.status IN ('contacted','meeting_set')` AND `claim.last_updated_at < now - $thresholds->follow_up_days` | `openFeedbackModal(listing.id, claim.status)` | "Your claim in *contacted* for N days. Time to follow up." |
| **R5** | `PITCH NOW · HIGH` | ACTION | All | No pitch in last `$thresholds->pitch_recency_days` AND no active claim AND `buyerTiers.strong >= $thresholds->high_value_strong_min` AND `listing.is_active` AND `matched_property_id IS NULL` | Anchor to `seller-outreach.entry.from-prospecting` | "N strong-tier buyers (top M%). High-conversion opportunity." |
| **R6** | `PITCH NOW` | ACTION | All | Same as R5 but `buyerTiers.strong >= 1` (and below R5's threshold) | Anchor to `seller-outreach.entry.from-prospecting` | "N strong-tier buyers. Worth a pitch." |
| **R7** | `RE-PITCH STOCK` | ACTION | All | `matched_property_id IS NOT NULL` AND no pitch in last `$thresholds->stock_repitch_days` AND `buyerTiers.strong >= 1` AND no active claim by another | Anchor to `seller-outreach.entry.from-property` | "Already in agency stock. New strong-tier buyers since last outreach." |
| **R8** | `RESOLVE COLLEAGUE CLAIM` | INFO | Manager | `claim.is_active` AND `claim.user_id != auth().id` AND `claim.last_updated_at < now - $thresholds->colleague_claim_stale_days` | Release-as-manager modal | "{name} held this claim N days without update. Consider releasing." |
| **R9** | `INVESTIGATE` | INFO | All | No active pitch, no active claim, `buyerTiers.strong = 0` AND `buyerTiers.mid >= $thresholds->investigate_mid_min` AND `listing.is_active` | `openBuyerPanel(listing.id)` | "No strong matches but N mid-tier buyers. Worth a look." |
| **—** | `—` (placeholder, no action) | — | All | Nothing above matched | None | "No suggested action right now." |

### 7.1 Ranking rationale

Money in danger (R1) → time-critical (R2) → outcome owed (R3) → stale-but-actioned (R4) → opportunity ranked by conversion (R5, R6) → re-engagement (R7) → manager hygiene (R8) → exploration (R9) → silence.

R1 over R2: mandate-in-progress slipping is worse than a claim auto-releasing.
R5 over R6: high-value pool is the demo headline.
R7 below R5/R6: un-mandated opportunity wins over already-mandated re-engagement.
No R10 catch-all: the `TP →` chip in the state stack (Bug 5.3 fix) already makes the TP page one click from every row.

### 7.2 Default threshold values (seeded for HFC, agency-tunable)

| Field | Default | Used by |
|---|---|---|
| `stale_listing_days` | 14 | R1 |
| `expiry_warning_hours` | 6 | R2 |
| `outcome_overdue_days` | 2 | R3 |
| `outcome_stale_days` | 30 | R3 |
| `follow_up_days` | 7 | R4 |
| `pitch_recency_days` | 7 | R5, R6 |
| `high_value_strong_min` | 3 | R5 |
| `stock_repitch_days` | 30 | R7 |
| `colleague_claim_stale_days` | 21 | R8 |
| `investigate_mid_min` | 5 | R9 |

### 7.3 Estimated HFC distribution at defaults

| Chip | Approx. count |
|---|---|
| R1 `FLAG TO BM` | 0 |
| R2 `CLAIM EXPIRES SOON` | 0 |
| R3 `LOG OUTCOME` | 3 |
| R4 `FOLLOW UP CLAIM` | 0 |
| R5 `PITCH NOW · HIGH` | ~665 |
| R6 `PITCH NOW` | ~140 |
| R7 `RE-PITCH STOCK` | ≤ 3 |
| R8 `RESOLVE COLLEAGUE CLAIM` (manager) | 0 |
| R9 `INVESTIGATE` | ~30–80 |
| `—` (silent) | balance |

Synthetic test data required for R1, R2, R4 demo cases — E.3 prompt covers.

---

## 8. Implementation

### 8.1 New table

Migration: `create_suggested_action_thresholds_table`

```php
Schema::create('suggested_action_thresholds', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('agency_id')->unique();
    $t->unsignedSmallInteger('stale_listing_days')->default(14);
    $t->unsignedSmallInteger('expiry_warning_hours')->default(6);
    $t->unsignedSmallInteger('outcome_overdue_days')->default(2);
    $t->unsignedSmallInteger('outcome_stale_days')->default(30);
    $t->unsignedSmallInteger('follow_up_days')->default(7);
    $t->unsignedSmallInteger('pitch_recency_days')->default(7);
    $t->unsignedSmallInteger('high_value_strong_min')->default(3);
    $t->unsignedSmallInteger('stock_repitch_days')->default(30);
    $t->unsignedSmallInteger('colleague_claim_stale_days')->default(21);
    $t->unsignedSmallInteger('investigate_mid_min')->default(5);
    $t->timestamps();
    $t->softDeletes();
    $t->foreign('agency_id')->references('id')->on('agencies');
});
```

Seeder: one row per existing agency at migration time, with defaults.

### 8.2 Model

`App\Models\SuggestedActionThresholds` — `BelongsToAgency`, soft-deletes, all fields fillable. Singleton-per-agency. Provides `getOrCreateForAgency(int $agencyId): self` static.

### 8.3 Service

New: `App\Services\Prospecting\SuggestedActionResolver`

```php
final class SuggestedActionResolver
{
    public function resolve(
        array $state,                          // per-listing slice from enricher
        array $tiers,                          // per-listing tier counts
        ProspectingListing $listing,
        SuggestedActionThresholds $thresholds,
        ?User $viewer,
        bool $isManager,
    ): ?SuggestedAction;
}
```

New DTO: `App\Services\Prospecting\SuggestedAction` (readonly: rank, label, tier, icon, tooltipHtml, clickType, href, modalKey, alpineCall).

**Zero new DB queries.** Resolver consumes already-loaded state. < 0.5ms per row.

### 8.4 Configuration service extension

`ProspectingConfigurationService` gains:
- `getSuggestedActionThresholds(int $agencyId): SuggestedActionThresholds` (cached singleton)
- `updateSuggestedActionThresholds(int $agencyId, array $values): SuggestedActionThresholds` (invalidates cache, fires `SuggestedActionThresholdsUpdated` domain event)

### 8.5 Enricher additions

`ProspectingListingStateEnricher::loadClaims()` adds:
```php
'needs_reminder' => bool,   // replicated from ProspectingClaim::needsReminder() against raw row
'needs_bm_flag'  => bool,   // replicated from ProspectingClaim::needsBmFlag() against raw row
```

`loadPresentations()` adds `agency_id` filter via property join.

### 8.6 Controller wiring

`ProspectingController::index` — after the existing `enrich()` call:

```php
$thresholds = app(ProspectingConfigurationService::class)
    ->getSuggestedActionThresholds($agencyId);
$resolver = app(SuggestedActionResolver::class);
$viewer = $request->user();
$suggestedActions = [];
foreach ($listings->items() as $listing) {
    $suggestedActions[$listing->id] = $resolver->resolve(
        $this->buildStateSlice($listingStates, $listing),
        $buyerTiers[$listing->id] ?? $this->emptyTiers(),
        $listing,
        $thresholds,
        $viewer,
        $isProspectingManager,
    );
}
```

Pass `$suggestedActions` via `compact()`.

### 8.7 New endpoint: flag-to-BM

`POST /prospecting/claims/{claim}/flag` → `ProspectingController@flagToManager`:
- Requires `prospecting.manage`
- `reason` (string, min 5)
- Sets `flagged_at=now()`, prepends timestamped notes entry, fires `ProspectingClaimFlagged` domain event
- Returns to index with success flash

### 8.8 Model patches

`App\Models\ProspectingListing`:
- Add `tracked_property_id` to `$fillable` and `$casts['integer']`
- Add `trackedProperty(): BelongsTo`

### 8.9 View changes

`resources/views/prospecting/index.blade.php`:
- **Replace** lines 460–510 (state-aware CTA block) with `@include('prospecting._suggested-action-chip', [...])`
- **Delete** inline 48h calc at line 660; consume `$listingStates['claims'][$listing->id]['hours_left']`
- **Add** `TP →` link chip in state stack when `$listing->tracked_property_id` set (alongside `IN STOCK`, not in place of)
- **Add** flag-to-BM modal at bottom (matches release modal pattern)

New partial: `resources/views/prospecting/_suggested-action-chip.blade.php`.

### 8.10 Settings tab — 6th tab under `/corex/settings/prospecting`

New tab: "Suggested Actions".

Form: 10 numeric inputs, grouped by rule (R1–R9), with inline help text explaining what each threshold controls and the resulting chip. Defaults pre-populated.

Live preview panel (right column): "With your current settings, your agency would see today:" followed by chip-by-chip count breakdown (recomputed on input blur via AJAX to a new `prospecting.suggested-actions.preview` endpoint).

Save button → `POST /corex/settings/prospecting/suggested-actions` → `ProspectingSettingsController@updateSuggestedActions`. Cache invalidates. Index page reflects new thresholds on next render.

---

## 9. Files touched

| File | Change |
|---|---|
| `.ai/specs/build-e-suggested-action-chips-spec.md` | THIS file (v2) |
| `database/migrations/...create_suggested_action_thresholds_table.php` | NEW |
| `database/seeders/SuggestedActionThresholdsSeeder.php` | NEW |
| `app/Models/SuggestedActionThresholds.php` | NEW |
| `app/Services/Prospecting/SuggestedActionResolver.php` | NEW |
| `app/Services/Prospecting/SuggestedAction.php` | NEW DTO |
| `app/Services/Prospecting/ProspectingConfigurationService.php` | +2 methods |
| `app/Services/Prospecting/ProspectingListingStateEnricher.php` | Extend `loadClaims`, agency-scope `loadPresentations` |
| `app/Models/ProspectingListing.php` | Fillable/casts/relation for `tracked_property_id` |
| `app/Http/Controllers/ProspectingController.php` | Resolver wiring; `flagToManager` method |
| `app/Http/Controllers/CoreX/ProspectingSettingsController.php` | `updateSuggestedActions`, `previewSuggestedActions` |
| `app/Domain/Prospecting/Events/ProspectingClaimFlagged.php` | NEW domain event |
| `app/Domain/Prospecting/Events/SuggestedActionThresholdsUpdated.php` | NEW domain event |
| `routes/web.php` | `prospecting.claims.flag`, `prospecting.suggested-actions.update`, `prospecting.suggested-actions.preview` |
| `resources/views/prospecting/index.blade.php` | Replace CTA block; delete inline 48h calc; add TP chip; add flag modal |
| `resources/views/prospecting/_suggested-action-chip.blade.php` | NEW partial |
| `resources/views/settings/prospecting/_suggested-actions.blade.php` | NEW tab content |
| `resources/views/settings/prospecting/index.blade.php` | Register 6th tab |

---

## 10. Performance

| Metric | Budget |
|---|---|
| Additional DB queries vs. pre-Build-E | 1 (cached thresholds singleton lookup) |
| Additional service-layer PHP per row | < 0.5ms |
| Additional render time for 50 rows | < 25ms |
| Total prospecting page render delta | < 50ms |
| Settings preview endpoint response | < 200ms |

---

## 11. Verification matrix

Build prompt's final report must include each:

| # | Verification |
|---|---|
| 11.1 | Madeira Gardens row resolves to R3 `LOG OUTCOME` |
| 11.2 | Bug 5.1 fixed: in-stock + recent pitch → R3, not "Pitch (stock)" |
| 11.3 | Bug 5.2 fixed: grep view for `diffInHours` returns zero hits |
| 11.4 | Bug 5.3 fixed: every row has TP link chip; click opens TP detail |
| 11.5 | Bug 5.4 fixed: `needs_reminder` / `needs_bm_flag` in enricher output for synthetic claim |
| 11.6 | Bug 5.5 fixed: `loadPresentations` filters by agency |
| 11.7 | R5 fires on 5 sampled rows from the high-value pool |
| 11.8 | R6 fires on 5 sampled rows from the strong-1-or-2 pool |
| 11.9 | R3 fires on each of the 3 currently-pitched rows |
| 11.10 | R1, R2, R4, R8 fire against synthetic test claims (E.3 seeder) |
| 11.11 | Manager vs. agent: load page as manager and basic agent — R1/R8 only render for manager |
| 11.12 | Tooltip non-empty and accurate on 10 sampled chips |
| 11.13 | Flag-to-BM end-to-end: modal → submit → `flagged_at` set → domain event recorded |
| 11.14 | **Threshold reactivity:** change `high_value_strong_min` from 3 → 5 in settings, reload index, assert R5/R6 distribution shifts accordingly |
| 11.15 | Settings preview endpoint returns updated counts within 200ms |
| 11.16 | Idempotency: 5 page reloads, Madeira chip identical each time |
| 11.17 | Performance: prospecting page render < pre-Build-E + 50ms |
| 11.18 | `php -l` on every changed PHP file |
| 11.19 | `php artisan view:clear` |
| 11.20 | `scripts/dev-check.ps1` passes with 0 new failures |

Final line of each build report MUST be:
```
BUILD E.{n} COMPLETE — XX/YY VERIFICATIONS PASSED.
```

---

## 12. Demo script for Wednesday

1. Open `/prospecting` as Johan (manager). Top rows show `PITCH NOW · HIGH` chips. *"668 actionable opportunities at a glance."*
2. Click `PITCH NOW · HIGH` → composer opens, pre-filled. *"One click from intel to action."*
3. Filter "My Claims" → synthetic R2 `CLAIM EXPIRES SOON` (red). Click → feedback modal. *"System nudges you before the listing escapes."*
4. Madeira row → R3 `LOG OUTCOME` (amber). Click → timeline opens at outcome field. *"You pitched, system asks you to close the loop."*
5. Synthetic R1 row → red `FLAG TO BM`. Click → reason modal. *"Manager flag with audit trail."*
6. Navigate to `/corex/settings/prospecting` → Suggested Actions tab. Change `high_value_strong_min` from 3 → 5. Live preview drops the R5 count. Save. Return to prospecting. Distribution shifts. *"Every agency tunes the rules to their own rhythm."*

---

## 13. Build sequence

After this v2 spec lands on `HFC2402`:

1. **E.1** — Migration + model + seeder + resolver + DTO + enricher extensions + adjacent bug fixes + configuration service extension. Backend only. Tinker-verify all 9 rules fire against synthetic state at default thresholds. (Single prompt.)
2. **E.2** — View partial + index.blade integration + flag-to-BM modal & endpoint + TP-link chip + 48h-calc deletion. (Single prompt.)
3. **E.3** — Synthetic test data seeder for R1, R2, R4 demo cases. (Single prompt.)
4. **E.4** — Settings tab UI + preview endpoint + cache invalidation wiring + Verification 11.14. (Single prompt.)

Four prompts. Each ends with `php -l`, `view:clear`, `dev-check.ps1`, Tinker verification, build report.

---

## 14. Promotion path

Per CoreX branch rules: HFC2402 → Staging (once E.1–E.4 batch passes) → main → live. Spec rides with the code.
