# MIC WhatsApp / Pitch claim flow — trace report

> **Date:** 2026-05-29.
> **Branch:** `feature/map-workspace-overhaul`.
> **Scope:** read-only. Decides whether the existing MIC pitch/claim flow
> is reusable from a map P-pin click, or whether extraction is needed
> first.

## VERDICT: **(A) REUSABLE AS-IS — and already wired.**

The map P-pin "Prospect Now →" CTA already routes to the **same**
controller endpoint (`EntryPointController::fromProspecting`) that the
MIC slide-over's `💬 Pitch` link uses. Same temp-lock service, same
collision resolver, same contact-capture Blade form, same composer
redirect, same "WhatsApp = it's yours" claim upgrade on send. No
extraction needed. The next prompt only needs to verify the wiring
behaves correctly end-to-end and tighten the edge cases listed in §6.

If the user has been seeing the MIC Opportunities tab instead of the
contact-capture form, that's the **fallback path** for record-ids the
synthetic `prospecting_listings` find-or-create can't resolve (§3.5).
The intended primary path is the entry-point form, not MIC.

---

## 1. The MIC Work-tab "Pitch / WhatsApp" trigger (file:line)

There is **no per-row** Pitch button on the MIC Work tab — the only
bookmark-icon button on the row is the simple
`market-intelligence.claim` (bookmark/flag) post, which writes a
`ProspectingClaim` row and reloads the page.

The actual **`💬 Pitch`** button lives on the **MIC slide-over header**
(the right-rail detail panel that opens when an agent clicks a Work-tab
row). The MIC Work tab opens that slide-over via
`/{listing}/details` (`market-intelligence.details`).

[resources/views/corex/market-intelligence/_slideover-header.blade.php:99-110](resources/views/corex/market-intelligence/_slideover-header.blade.php#L99-L110)

```blade
@if($canPitch)
    @if($h['in_stock'])
        <a href="{{ route('seller-outreach.entry.from-property', $h['matched_property_id']) }}"
           style="{{ $actionPrimary }}">
            💬 Pitch
        </a>
    @else
        <a href="{{ route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listing->id]) }}"
           style="{{ $actionPrimary }}">
            💬 Pitch
        </a>
    @endif
@endif
```

Two routes, switched on whether the listing is already promoted to HFC
stock (`in_stock` = `matched_property_id IS NOT NULL`). For an
unclaimed prospecting listing the relevant one is
**`seller-outreach.entry.from-prospecting`**.

The Work-tab list row itself ([_listing-row.blade.php:244-258](resources/views/corex/market-intelligence/_listing-row.blade.php#L244-L258))
has only a bookmark icon that POSTs to `market-intelligence.claim` —
that's the simple "I'm taking this row off the unclaimed list" action.
It is NOT the WhatsApp/Pitch flow; it just creates a `ProspectingClaim`
row directly via
[MarketIntelligenceController::claim](app/Http/Controllers/CoreX/MarketIntelligenceController.php#L2193-L2222).
Ignore this button for the map wiring; it's unrelated.

## 2. The "modal" — actually a full-page entry-point view

There is no modal. The `💬 Pitch` button is an `<a href>` that
navigates to the **entry-point page** at:

  `GET /prospecting/{prospectingListingId}/outreach/compose`

[routes/web.php:1714-1721](routes/web.php#L1714-L1721)

The page rendered is a standalone Blade view that can be reached from
any surface:

  [resources/views/seller-outreach/entry/prospecting-create-contact.blade.php](resources/views/seller-outreach/entry/prospecting-create-contact.blade.php)

It already handles two source types via the same template:

- `$listing` (a prospecting_listings row) — used by both MIC and map P-pin.
- `$trackedProperty` (a tracked_properties row) — used by map T-pin.

The view extends `layouts.corex`. It is shared across surfaces and
not coupled to MIC tab state. Includable elsewhere = no, but
**linkable** from any surface = yes, which is what matters.

## 3. The submit endpoint — route + controller + method (file:line) + request shape

Two endpoints — one for the GET form render, one for the POST submit.

**GET (enter the flow):**

  `seller-outreach.entry.from-prospecting`
  → [`EntryPointController::fromProspecting`](app/Http/Controllers/SellerOutreach/EntryPointController.php#L70-L112)

  Middleware: `permission:outreach.compose`.

  Side effects on entry (before the agent fills in anything):
  1. **Collision check** — `MapProspectStatusService::resolve()` to
     decide held / own_draft / other_draft / previously_sold /
     previously_held / available. Held / drafts ABORT here with a
     redirect; "previously_*" warns and proceeds; "available" proceeds
     clean. ([L85-88](app/Http/Controllers/SellerOutreach/EntryPointController.php#L85-L88))
  2. **Temp lock** — `ProspectingClaimService::createTempLock()` reserves
     the listing for 30 minutes (agency-configurable). Throws
     `PitchLockConflictException` if another agent already holds the
     lock; that's caught and surfaced as a flash redirect to the Work
     tab. ([L93-107](app/Http/Controllers/SellerOutreach/EntryPointController.php#L93-L107))
  3. Renders the contact-capture form.

**POST (submit the captured contact):**

  `seller-outreach.entry.store-from-prospecting`
  → [`EntryPointController::storeFromProspecting`](app/Http/Controllers/SellerOutreach/EntryPointController.php#L114-L287)

  Request shape (validated at [L124-131](app/Http/Controllers/SellerOutreach/EntryPointController.php#L124-L131)):

  ```php
  $validated = $request->validate([
      'first_name' => 'required|string|max:100',
      'last_name'  => 'nullable|string|max:100',
      'phone'      => 'nullable|string|max:30',
      'email'      => 'nullable|email|max:255',
      'id_number'  => ['nullable', 'string', 'max:20', new \App\Rules\SouthAfricanIdNumber()],
  ]);
  // Plus: at least one of phone or email is required (custom check at L135-139).
  ```

  No address / GPS / portal-ref in the request — those are pulled from
  the path-bound `$listing` row.

## 4. What the submit does, in order — every call site quoted

[`EntryPointController::storeFromProspecting` body, L144-273](app/Http/Controllers/SellerOutreach/EntryPointController.php#L144-L273).
All inside a `DB::transaction`.

1. **Find existing Contact** (dedupe by normalised phone, then lowercased email).
   [`findExistingContact`](app/Http/Controllers/SellerOutreach/EntryPointController.php#L421-L453).

2. **Create Contact** (or reuse the dedupe match).
   [L157-174](app/Http/Controllers/SellerOutreach/EntryPointController.php#L157-L174).
   `agency_id`, `branch_id` (from `$request->user()->branch_id`),
   `first_name`, `last_name`, `phone`, `email`, optional `id_number` +
   POPIA-audit fields. If the existing contact lacked an ID and the
   agent supplied one, the existing row is capture-filled (never
   overwritten) at [L179-185](app/Http/Controllers/SellerOutreach/EntryPointController.php#L179-L185).

3. **Promote the listing to a Property**.
   [`promoteListingToProperty`](app/Http/Controllers/SellerOutreach/EntryPointController.php#L466-L541).
   Idempotent: matches an existing Property by
   `external_id = 'prospecting:{listing.id}'` OR exact address OR
   normalised address (+ optional suburb). Reuses if found, otherwise
   creates a `Property` row with `status='draft'`, `listing_type='sale'`,
   `agent_id` resolved through `resolvePromotionAgentId` (the actor,
   unless they're a super_admin/system role — falls back to the agency's
   first admin/branch_manager/agent).

4. **Match-or-create TrackedProperty** via the canonical service
   (`TrackedPropertyMatchOrCreateService::matchOrCreate`) — CLAUDE.md
   non-negotiable #10. [L197-238](app/Http/Controllers/SellerOutreach/EntryPointController.php#L197-L238).
   Sets `promoted_to_property_id`, `promoted_at`, `promoted_by_user_id`,
   `status=STATUS_PROMOTED` on the TP. Failure here is logged but does
   NOT roll back the transaction — the user-visible operation (Contact +
   Property + pivot) still succeeds.

5. **Link Contact ↔ Property** via `contact_property` pivot with
   `role='seller'`. Idempotent
   `updateOrInsert([contact_id, property_id], [role='seller', timestamps])`.
   [L249-259](app/Http/Controllers/SellerOutreach/EntryPointController.php#L249-L259).

6. **Mark the prospecting_listing as matched** to the Property and
   linked to the TP. Idempotent: `WHERE matched_property_id IS NULL`.
   [L263-271](app/Http/Controllers/SellerOutreach/EntryPointController.php#L263-L271).

7. **Redirect to composer** at `seller-outreach.composer.show` with
   `contact={id}&property_id={id}`. Flash status notes whether the
   contact was newly created or deduped.
   [L279-286](app/Http/Controllers/SellerOutreach/EntryPointController.php#L279-L286).

**The "claim" upgrade** (`consumeLockAsPermanentClaim`) does NOT happen
here. It runs later in
[`ComposerController::submit`](app/Http/Controllers/SellerOutreach/ComposerController.php)
when the agent actually sends the pitch. That's the "WhatsApp = it's
yours" step — the temp lock created in §3 step 2 is upgraded to a
permanent `ProspectingClaim` row (48-hour expiry from `isExpired()`
on the model). Side effects on send include the pitch outbound (WhatsApp
deeplink / email) and the claim record being written.

**Activity log events:** the entry-point fires `MapProspectLaunched`
with `source='mic_entry_point'` via `Event::dispatch` at
[`fireMicProspectLaunched`](app/Http/Controllers/SellerOutreach/EntryPointController.php#L698-L714)
when entering the form from the "available" / "previously_*" paths.

## 5. Post-submit response — redirect (not JSON)

`return redirect()->route('seller-outreach.composer.show', [...])` —
plain server-side redirect with a flash status. Browser-driven
navigation. The composer renders the WhatsApp/email composer UI for the
captured contact.

## 6. What the P-pin click currently does — file:line

The P-pin (active_listings layer) CTA is built in
[resources/views/corex/map/index.blade.php — actionsForRecord, case 'active_listings'](resources/views/corex/map/index.blade.php#L961-L1000).
The action shape (quoted from the file):

```javascript
case 'active_listings': {
    const ps = (card && card.prospect_status) ? card.prospect_status : { status: 'available' };
    const prospectAction = {
        key:       'prospect_launched',
        label:     'Prospect Now →',
        iconLabel: 'Prospect this property',
        iconSvg:   ICON_FIND,
        style:     'primary',
        destUrl:   MIC_OPPORTUNITIES_URL,      // fallback if the server doesn't send a redirect_url
        newTab:    true,
        awaitServerRedirect: true,             // critical: server picks the real target
        logPayload: {
            ...baseLog,
            action:              'prospect_launched',
            record_id:           String(recId),
            tracked_property_id: record.tracked_property_id ?? null,
            address:             record.title || null,
            latitude:            record.lat ?? null,
            longitude:           record.lng ?? null,
            suburb:              record.suburb || null,
        },
    };
    // ... (collision-override variants for held / drafts) ...
}
```

When clicked, the handler at [index.blade.php:1882-1910](resources/views/corex/map/index.blade.php#L1882-L1910)
fires:

```javascript
if (act.awaitServerRedirect) {
    e.preventDefault();
    const resp = await fetch(MAP_ACTIVITY_URL, { /* POST logPayload */ });
    if (resp.ok) {
        const body = await resp.json();
        const url = body.redirect_url || act.destUrl;
        if (url) {
            if (act.newTab) window.open(url, '_blank', 'noopener');
            else window.location.href = url;
            return;
        }
    }
    // fall through to act.destUrl fallback (= MIC opportunities)
}
```

The server side (the activity-log endpoint) is
[`MapActivityController::prospectLaunched`](app/Http/Controllers/Map/MapActivityController.php#L318-L375):

1. `TrackedPropertyMatchOrCreateService::matchOrCreate(...)` →
   finds/creates the TP from the click payload's
   `{address, latitude, longitude, suburb}`. ([L341-354](app/Http/Controllers/Map/MapActivityController.php#L341-L354)).
2. `resolveProspectingListingId(...)` →
   ([L386-439](app/Http/Controllers/Map/MapActivityController.php#L386-L439))
   - If `record_id` is purely numeric → looks up a native
     `prospecting_listings` row by id.
   - If `record_id` matches `^(mrcr|pal|deal):\d+$` → looks up or
     **synthesises** a `prospecting_listings` row keyed by
     `(agency_id, portal_source='p24', portal_ref=record_id)`. This is
     the bridge that makes MRCR / PAL / deal-derived map records flow
     through the same prospecting-listing pipeline MIC uses.
3. Sets the response body:
   ```php
   $extras['redirect_url'] = $plId
       ? route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $plId])
       : route('market-intelligence.opportunities.show', $tp);
   ```

So the **primary** behaviour today is: click P-pin → POST activity log
→ server creates the TP + the synthetic prospecting_listing → server
returns `redirect_url = seller-outreach.entry.from-prospecting/{id}` →
client opens that URL in a new tab → identical entry-point flow as the
MIC slide-over uses.

The **fallback** (lands on MIC opportunities.show) only fires when
the prospecting_listing synthesis fails — i.e. `record_id` is neither
numeric nor matches `^(mrcr|pal|deal):\d+$`, or the INSERT raised.
That's likely what the user has been seeing for some P-pins (loads MIC
instead of the pitch entry-point page).

## 7. Verdict & wiring summary

**(A) REUSABLE AS-IS.**

The map P-pin click → entry-point form → composer flow is already
wired through to the same endpoint as MIC's `💬 Pitch` link. Both
surfaces share:

| Concern | Shared component |
|---|---|
| Collision detection | `MapProspectStatusService::resolve()` |
| Temp lock (30 min) | `ProspectingClaimService::createTempLock()` |
| Contact dedupe / create | `EntryPointController::findExistingContact()` + `Contact::create()` |
| Property promote (find-or-create) | `EntryPointController::promoteListingToProperty()` |
| TP linkage | `TrackedPropertyMatchOrCreateService::matchOrCreate()` |
| Contact ↔ Property pivot | `contact_property` (role='seller') |
| Composer | `seller-outreach.composer.show` |
| Permanent claim upgrade (the "WhatsApp = it's yours" step) | `ProspectingClaimService::consumeLockAsPermanentClaim()` on `ComposerController::submit` |
| 48-hour expiry | `ProspectingClaim::isExpired()` |

**Endpoint the map P-pin click currently posts to:**
`POST /corex/map/activity/log` with payload:

```json
{
  "action": "prospect_launched",
  "category": "active_listings",
  "record_id": "mrcr:123" | "pal:456" | "<numeric prospecting_listings.id>",
  "location_key": "sha256:...",
  "source": "single_detail" | "composite_row",
  "tracked_property_id": <int|null>,
  "address":  "<string|null>",
  "latitude":  <float|null>,
  "longitude": <float|null>,
  "suburb":  "<string|null>"
}
```

The server returns
`{ logged: true, event_id, tracked_property_id, prospecting_listing_id, redirect_url }`
and the client navigates to `redirect_url` (which is
`seller-outreach.entry.from-prospecting/{prospectingListingId}` — the
same Blade form MIC uses).

**Nothing needs extraction.** The MIC pitch flow IS already the map
pitch flow — same controller, same temp lock, same composer, same
claim upgrade on send.

## 8. Edge cases to verify in the wiring prompt (NOT extractions)

These are **verification items**, not extraction work. The pattern is
sound; only confirm runtime behaviour.

1. **Permission gate.** The entry-point routes are gated by
   `permission:outreach.compose` ([routes/web.php:1708](routes/web.php#L1708)).
   The map route group is gated by `permission:access_properties`
   ([routes/web.php:2014](routes/web.php#L2014)). Confirm any agent who
   can use the map (`access_properties`) also holds
   `outreach.compose`, else the P-pin click 403s on the redirect. The
   activity-log endpoint itself only enforces "user has an agency
   context"; it doesn't pre-check `outreach.compose` before returning
   `redirect_url`.

2. **`record_id` shape.** The P-pin's `recId` is whatever the bounds
   endpoint stamped on the pin — for the `active_listings` layer that's
   `mrcr:{id}` from `market_report_comp_rows` or `pal:{id}` from
   `presentation_active_listings`. Both match
   `^(mrcr|pal|deal):\d+$` so the synthetic prospecting_listings
   insert at [MapActivityController.php:415-430](app/Http/Controllers/Map/MapActivityController.php#L415-L430)
   should succeed. The post-`feature/map-workspace-overhaul` re-point
   to `prospecting_listings` for the P layer may have changed this
   shape — confirm the bounds endpoint still emits `mrcr:` / `pal:` or
   now emits numeric ids of the underlying `prospecting_listings`
   row. If numeric, the Case 1 branch (look up by id, no synthetic
   create) takes over and the flow stays valid; if some legacy P pins
   still emit MRCR/PAL refs, both branches must work.

3. **Anonymous `newTab` behaviour.** `awaitServerRedirect` opens
   `window.open(url, '_blank', 'noopener')`. Popup blockers triggered
   by a fetch-then-open sequence (not a direct user gesture) will
   silently swallow the navigation; the agent then sees nothing happen
   on click. Worth a manual click-test in a fresh browser session — if
   it fails, switch to a same-tab navigation for the P-pin or fire the
   fetch beforehand and synchronously open on response in the click
   handler's user-gesture window.

4. **Fallback on synthesis failure.** When
   `resolveProspectingListingId` returns null (record_id outside the
   accepted patterns, or the INSERT raised), the response's
   `redirect_url` points at `market-intelligence.opportunities.show` —
   that's what the user reported seeing ("loads MIC"). Decide whether
   the right behaviour is to (a) keep this as the failure fallback or
   (b) hard-error so the agent knows the flow didn't start. Current
   behaviour is (a).

5. **Map P-pin currently opens in a new tab; MIC `💬 Pitch` is a
   same-tab link.** Two different UX choices for the same flow. If
   parity matters, align them (probably same-tab for both — the
   composer is the next surface either way).

No code changes implied by this report — it is a trace artefact only.
