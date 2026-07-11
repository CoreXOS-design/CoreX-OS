# CoreX Domain Events — Architectural Spec

> Status: Draft pending Johan approval — 2026-05-13
> Owner: Johan / Andre
> Pillars: All (this spec is the connective tissue between Contact, Property, Agent, Mandate, Deal, FICA, Documents)
> Decisions confirmed: full pattern adoption (not a POC), past-tense event names, queue infrastructure confirmed during pre-flight per build prompt
> Sister specs (depend on this):
> - `.ai/specs/property-cross-reference-spec.md` (next)
> - `.ai/specs/prospecting-flow-spec.md`
> - `.ai/specs/seller-outreach-communication-spec.md`
> - `.ai/specs/prospecting-intelligence-spec.md` (already drafted — will be updated to subscribe to this spec's events)
> - `.ai/specs/unified-buyer-wishlist-spec.md` (already in-flight — backfills events as part of post-migration refinement)

---

## Section 1 — Purpose & Context

**The architectural principle.** CoreX is not a set of screens that share a database. CoreX is a web of interconnected pillars (Property, Contact, Agent, Mandate, Deal, FICA, Documents) where every meaningful action sends a signal across the web. Other parts of the system listen for signals they care about and react. Most reactions are invisible to the user — the system just *knows* more than it did a moment ago.

**The user-visible benefit.** When an agent loads a property at 42 Sunset Drive:
- The prospecting tab immediately flags every alert row matching that address as "On our books." No agent in the agency wastes another cold call on it.
- The buyer-side matching engine finds the 7 buyers whose criteria match, ready to be contacted the moment a mandate is signed.
- The branch dashboard's stock pipeline ticks up by one.
- The mandate-expiry tracker schedules its first reminder.
- The audit log records the action with full data state.

One human action. Seven system reactions. The agent saved the property; the agency benefits from all seven without lifting an extra finger.

**The architectural foundation.** Laravel provides the primitives: Eloquent model observers (already used for `ContactMatchObserver`, `PropertyObserver`, `ProspectingListingObserver`), the event bus (`event()` / listeners / queued listeners), and queue infrastructure. This spec defines **the deliberate, system-wide pattern** for using these primitives consistently across CoreX so that:

1. Every important domain action emits a named event.
2. Every event has a defined payload, a defined trigger, a defined audit trail.
3. Subscribers (listeners) react in well-defined, idempotent, single-responsibility ways.
4. New features plug in by subscribing to existing events, not by inventing new query paths.
5. The propagation graph is documented — when someone says "what happens when a property is loaded?", we point to a diagram.

**Why this spec must come before feature specs that depend on cross-feature reactivity.** Without this foundation, every feature spec invents its own reactivity pattern. Five specs, five patterns, the "spider web" is actually five disconnected webs sharing a few common points. With this foundation, every feature spec references the event catalogue and subscribes by name. One pattern, one book, every chapter consistent.

**Downstream features this unblocks:**
- Property cross-reference layer (live "On our books" flagging across prospecting list, captured from `PropertyCreated` / `PropertyStatusChanged`).
- Prospecting flow + seller outreach (composition surfaces consume `PropertyCreated` + `ContactCreated` to know what data to inject).
- Stock-gap analysis (subscribes to `BuyerWishlistCreated` + `MandateExpired` + `MandateSigned` to surface "we have 7 buyers wanting 2-bed apartments in Shelly Beach, only 1 active mandate matches").
- Ellie / AI integration — every event is a structured, queryable, semantic data point.

**Explicit out of scope:**
- Replacing existing observers (`PropertyObserver`, etc.) — they continue to work; this spec defines the pattern they migrate to over time, incrementally.
- Cross-tenant events (events from agency A triggering listeners on agency B's data). Not allowed. Multi-tenancy boundary holds at the event layer.
- External webhooks (sending events to third-party systems via HTTP). Future scope — the event bus internally is the foundation; external publishing is built on top later.

---

## Section 2 — Source Material

| File / Spec | Read | Notes |
|---|---|---|
| `CLAUDE.md` | Yes | Non-negotiables #1 (PROPERTY is the spine), #7 (multi-tenancy non-negotiable), #5 (no hard deletes) shape this spec |
| `.ai/STANDARDS.md` | Yes | Existing observer-registration patterns confirmed |
| `.ai/specs/unified-buyer-wishlist-spec.md` | Yes | `ContactMatchObserver` is a reference implementation of the pattern; this spec generalises it |
| `.ai/specs/matches.md` | Yes | `MatchPropertyJob` fired from `PropertyObserver::saved()` is a reference example of an async listener |
| `.ai/specs/prospecting-intelligence-spec.md` | Yes | Will be updated to reference this spec's events for reactive count updates |
| `.ai/specs/SPEC_Portal_Scraping_Prospecting.md` | Yes | Portal scraping pipeline will emit `ProspectingListingCreated` etc. |
| Existing observer files | To be re-read | `PropertyObserver`, `ProspectingListingObserver`, `ContactMatchObserver` (Prompt 02), any others discovered during pre-flight |
| Existing event/listener files | To be re-read | Search `app/Events/` and `app/Listeners/` for any pre-existing patterns to align with |
| `config/queue.php` | To be re-read | Confirms current queue driver (sync, database, redis) |

---

## Section 3 — Decisions Locked

### E1. Event naming — past-tense facts

Domain events represent **facts that have happened**, not commands or requests. Use past-tense, PascalCase, noun + verb-past-tense:

- ✅ `PropertyCreated`, `PropertyStatusChanged`, `MandateSigned`, `BuyerWishlistCreated`, `ContactMerged`.
- ❌ `CreateProperty`, `ChangePropertyStatus`, `SignMandate`, `OnPropertyCreated`.

If a future command bus is introduced (separate concern), commands use imperative verbs: `CreateProperty`. Events and commands are different concepts and live in different namespaces.

### E2. Namespace and file organisation

```
app/Events/
    Property/
        PropertyCreated.php
        PropertyUpdated.php
        PropertyStatusChanged.php
        PropertyDeleted.php             (soft-delete; archive semantics)
    Contact/
        ContactCreated.php
        ContactUpdated.php
        ContactMerged.php
        ContactBuyerStatusChanged.php
    Mandate/
        MandateSigned.php
        MandateExpired.php
        MandateWithdrawn.php
    Wishlist/
        BuyerWishlistCreated.php
        BuyerWishlistUpdated.php
        BuyerWishlistPrimaryChanged.php
    Prospecting/
        ProspectingListingCreated.php
        ProspectingListingMatched.php
        ProspectingListingArchived.php
    Deal/
        DealCreated.php
        DealRegistered.php
    Fica/
        FicaApproved.php
        FicaRejected.php

app/Listeners/
    Prospecting/
        FlagPropertyAsOnBooks.php          (subscribes: PropertyCreated, PropertyStatusChanged)
        RecomputeBuyerMatches.php          (subscribes: ProspectingListingCreated, ProspectingListingUpdated)
    Buyer/
        NotifyBuyersOfNewMatch.php         (subscribes: PropertyCreated)
        RecomputeMatchesForWishlist.php    (subscribes: BuyerWishlistCreated, BuyerWishlistUpdated)
    StockIntelligence/
        UpdateStockGapMetrics.php          (subscribes: MandateSigned, MandateExpired, BuyerWishlistCreated)
    Audit/
        RecordDomainEvent.php              (subscribes: *)
```

### E3. Event payload shape — uniform contract

Every event class has a uniform structure:

```php
namespace App\Events\Property;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

class PropertyCreated extends AbstractDomainEvent
{
    public function __construct(
        public readonly Property $property,
        public readonly ?int $createdByUserId,
        public readonly int $agencyId,
        public readonly array $context = [],
    ) {
        parent::__construct();
    }
}
```

The abstract base class (`AbstractDomainEvent`) provides:
- `eventId` (UUID v4, generated in constructor) — for tracing across listeners.
- `occurredAt` (DateTimeImmutable) — when the event was emitted.
- `eventName` (string, derived from class name) — for audit log indexing.
- `traceId` (UUID, optional) — links related events from one user action.

**Why pass the model, not just the ID?** Listeners frequently need multiple fields from the entity. Passing the model avoids N+1 reloads in listeners. The model is in memory at emit time; pass it through.

**Multi-tenancy in payloads.** Every event payload carries `agencyId` explicitly. Listeners use this to verify scope before touching any data. If a listener finds itself reading from one agency and writing to another, it must abort and log an error.

### E4. Sync vs queued — per listener, not per event

A single event can have both sync and queued listeners. Decision per listener:

- **Sync** when the user-facing UI depends on the listener's output being complete before the response renders. Example: `FlagPropertyAsOnBooks` after `PropertyCreated` — the prospecting tab must show the new "On our books" badge the moment the agent navigates back.
- **Queued** when the work can wait seconds-to-minutes. Example: `NotifyBuyersOfNewMatch` — emails / notifications to 7 buyers; can take 30 seconds; user doesn't wait.

A listener declares its mode by implementing `ShouldQueue` (queued) or not (sync). The convention:

```php
class FlagPropertyAsOnBooks  // sync
{
    public function handle(PropertyCreated $event): void { ... }
}

class NotifyBuyersOfNewMatch implements ShouldQueue  // queued
{
    public string $queue = 'notifications';
    public int $tries = 3;
    public int $backoff = 30;
    public function handle(PropertyCreated $event): void { ... }
}
```

### E5. Idempotency — every listener safe to run twice

Every queued listener must be idempotent. Re-running it produces the same final state, not duplicate side effects.

Patterns:
- Upsert-by-unique-key rather than insert.
- Check-then-act with database constraints as the safety net.
- Use `firstOrCreate`, `updateOrCreate`, `upsert` in Eloquent.
- For notifications: use a `deduplication_key` derived from `(event_id, listener_name)` so the same listener firing twice on the same event sends one notification, not two.

Sync listeners benefit from idempotency too, but the bar is lower because they don't retry.

### E6. Audit logging — every event recorded

A universal sync listener `RecordDomainEvent` subscribes to ALL events (wildcard `*` in `EventServiceProvider`) and writes one row per event to a new `domain_event_log` table:

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | |
| `event_id` | uuid (indexed, unique) | The event's UUID — listeners trace back to this row |
| `trace_id` | uuid (indexed, nullable) | Groups related events from one user action |
| `event_name` | varchar(120) (indexed) | e.g. "App\Events\Property\PropertyCreated" |
| `agency_id` | bigint unsigned (indexed) | For multi-tenancy filtering |
| `actor_user_id` | bigint unsigned, nullable (indexed) | Auth::id() at emit time |
| `subject_type` | varchar(120), nullable | Polymorphic — the primary entity (e.g. "App\Models\Property") |
| `subject_id` | bigint unsigned, nullable | Polymorphic ID |
| `payload_snapshot` | json | The event's serialised payload (subject of the SerializesModels pattern) |
| `context` | json, nullable | The event's `context` array — anything domain-specific |
| `occurred_at` | timestamp (indexed) | |
| `created_at` | timestamp | When the log row was written (usually equal to occurred_at) |

This table is the legal-defensibility trail. Every claim made to a seller / buyer / regulator can be traced back: "On 2026-05-13 at 14:32, agent Sarah received the answer '14 active warm buyers in Margate' from the system; the underlying event PropertyCreated fired at 14:31:58 contained the following state ..."

The log table is **not** soft-deleted. Retention policy (e.g. 7 years per PPRA) handled by a separate cron / archive job. Out of scope of this spec.

### E7. Multi-tenancy at the event layer

Three layers of enforcement:

1. **Emit layer.** Every event carries `agencyId`. The emitting code must populate it (usually from the model's `agency_id` or `Auth::user()->effectiveAgencyId()`).
2. **Listener layer.** Every listener's first action is to verify the event's `agencyId` against any data it intends to touch. If mismatch, log a critical error and abort. This is defensive — AgencyScope on the model layer should already prevent cross-tenant queries — but the event layer is one more belt-and-braces.
3. **Audit layer.** Every `domain_event_log` row carries `agency_id`. Cross-agency events would be visible to super-admin forensics immediately.

A super-admin running a system-wide job (no `agencyId` in their session) emits events with `agencyId` taken from the affected entity. Never `null`. Every event has an agency.

### E8. Trace IDs — one user action, many events

When an agent loads a property, the system emits `PropertyCreated`. The `FlagPropertyAsOnBooks` listener fires and emits `ProspectingListingFlaggedAsOnBooks`. The `RecomputeBuyerMatches` listener fires for each matched buyer and emits `BuyerMatchRecorded`.

All these events share a **trace_id** so they can be reconstructed as a cascade. The trace_id is set by:
- The first event in the chain — generated when emitted from a controller / Job action.
- Subsequent events in the chain — copied from the triggering event by the listener.

Helper: `AbstractDomainEvent::deriveFrom(AbstractDomainEvent $parent): static` — used by listeners to emit child events that inherit the parent's trace_id.

This makes the "what happened when X clicked Y?" question trivially answerable: filter `domain_event_log` by `trace_id`.

### E9. The event catalogue is the API contract

The set of events defined in `app/Events/` IS the public API of CoreX's domain layer. Adding a new event = adding to the API. Removing one = breaking the API.

- Adding new events: free, encouraged. Subscribers can ignore unfamiliar events.
- Adding fields to event payloads: free if the field is nullable; otherwise treat as a breaking change.
- Removing or renaming events: explicit deprecation cycle (mark as deprecated, fire the new event alongside the old for one release, remove the old in a follow-up).

Every event class has a docblock describing **when it fires**, **who emits it**, **typical subscribers**, and **example trace context**. The docblocks are the catalogue; future Claude / Andre / external integrators read them, not search-and-pray through the codebase.

### E10. Performance budget

- Synchronous listeners on a hot path (page load, controller action) must complete in **< 50ms each** combined.
- A page load that emits one event that triggers 5 sync listeners gets a 250ms budget on that pipeline alone.
- Anything beyond budget moves to queued. The budget is enforced via instrumentation (timing metric per listener) — added in Phase 2.

The audit listener (`RecordDomainEvent`) is a sync listener but writes only one row; expected ~5ms. If it ever drifts above 20ms, it goes queued.

### E11. Phased migration of existing observers — incremental, not big-bang

CoreX has existing observers (`PropertyObserver`, `ProspectingListingObserver`, `ContactMatchObserver`). They continue to work.

**Migration path:**
- Existing observers retain their inline behaviour for now.
- Each existing observer is enhanced to **also emit a domain event** at the end of its `saved()` / `created()` / etc. methods.
- New features subscribe to the events.
- Over time, the inline observer logic migrates to dedicated listeners as the listener pattern proves out.

No big-bang refactor. The old works; the new is additive. Eventually the observers become thin shims that only emit events.

This is critical for shipping velocity. We do not stop the wishlist build to refactor every observer. We add `event(new ContactMatchSetAsPrimary(...))` to `ContactMatchObserver::saved()` once we know we have a subscriber for it.

### E12. Test pattern

Every event has a test that asserts:
- The event is emitted under the expected conditions.
- The payload contains the expected data.
- The expected listeners fire.
- The listeners are idempotent (calling twice produces same final state).

Laravel's `Event::fake()` and `Bus::fake()` make this trivial. The convention is one test class per event under `tests/Feature/Events/`.

---

## Section 4 — Schema Changes

### 4.1 New table — `domain_event_log`

Per Section E6. Indexed on `event_id` (unique), `trace_id`, `event_name`, `agency_id`, `actor_user_id`, `subject_type` + `subject_id` (composite), `occurred_at`.

Foreign keys: none (audit table — orphaning is acceptable if a parent gets deleted; we want the historical record).

### 4.2 No other schema changes

This spec defines an architectural pattern. Existing tables and models unchanged. New event classes live in `app/Events/`; new listener classes in `app/Listeners/`.

---

## Section 5 — Event Catalogue (Phase 1)

The initial catalogue. Each row: event name, when it fires, payload, who emits it, typical subscribers.

| Event | Fires when | Payload | Emitted by | Typical subscribers |
|---|---|---|---|---|
| `Property\PropertyCreated` | A Property model is created | `property`, `createdByUserId`, `agencyId`, `context` | `PropertyObserver::created()` | `FlagPropertyAsOnBooks`, `NotifyBuyersOfNewMatch`, `UpdateBranchPipelineStats`, audit |
| `Property\PropertyUpdated` | Property model is saved with dirty fields | `property`, `dirtyFields`, `originalValues`, `updatedByUserId`, `agencyId` | `PropertyObserver::updated()` | `FlagPropertyAsOnBooks` (if status changed), audit |
| `Property\PropertyStatusChanged` | Property's lifecycle status transitions (draft → active → expired → ...) | `property`, `oldStatus`, `newStatus`, `agencyId` | `PropertyObserver::updated()` when status field is dirty | `FlagPropertyAsOnBooks`, `NotifyExpiredMandateBranchManager`, audit |
| `Property\PropertyArchived` | Property soft-deleted | `property`, `archivedByUserId`, `reason`, `agencyId` | `PropertyObserver::deleted()` | `FlagPropertyAsOnBooks` (clear flag), audit |
| `Contact\ContactCreated` | Contact created | `contact`, `createdByUserId`, `agencyId` | `ContactObserver::created()` | `CheckForDuplicateContact`, `EnrichContactFromExternalSources`, audit |
| `Contact\ContactUpdated` | Contact saved with dirty fields | `contact`, `dirtyFields`, `originalValues`, `agencyId` | `ContactObserver::updated()` | audit |
| `Contact\ContactBuyerStatusChanged` | A Contact's `buyer_status` field changes (new ↔ warm ↔ cold ↔ lost) | `contact`, `oldStatus`, `newStatus`, `agencyId` | `ContactObserver::updated()` | `RecomputeProspectingIntelligence`, audit |
| `Contact\ContactMerged` | Two Contacts merged (duplicate resolution) | `winningContact`, `mergedContactId`, `agencyId` | Manual merge action via controller | `ReassignContactMatches`, `ReassignDocumentLinks`, audit |
| `Wishlist\BuyerWishlistCreated` | New ContactMatch row created | `match`, `contact`, `createdByUserId`, `agencyId` | `ContactMatchObserver::created()` | `RecomputeMatchesForWishlist`, `UpdateStockGapMetrics`, audit |
| `Wishlist\BuyerWishlistUpdated` | ContactMatch updated | `match`, `dirtyFields`, `updatedByUserId`, `agencyId` | `ContactMatchObserver::updated()` | `RecomputeMatchesForWishlist`, audit |
| `Wishlist\BuyerWishlistPrimaryChanged` | A ContactMatch's `is_primary` flips | `match`, `oldIsPrimary`, `newIsPrimary`, `agencyId` | `ContactMatchObserver::saved()` | `RecomputeProspectingIntelligence` (aggregation uses primary), audit |
| `Mandate\MandateSigned` | Mandate signed by seller | `mandate`, `property`, `agentId`, `agencyId` | Mandate controller after e-sign callback | `UpdateStockGapMetrics`, `NotifyBuyersOfNewListing`, `ScheduleMandateExpiryReminder`, audit |
| `Mandate\MandateExpired` | Scheduled job detects mandate past expiry | `mandate`, `property`, `expiredAt`, `agencyId` | Daily scheduled job | `FlagPropertyAsOnBooks` (status → expired), `NotifyBranchManager`, `AddToReProspectQueue`, audit |
| `Mandate\MandateWithdrawn` | Seller withdraws | `mandate`, `property`, `reason`, `withdrawnAt`, `agencyId` | Mandate controller | `FlagPropertyAsOnBooks` (status → withdrawn), audit |
| `Prospecting\ProspectingListingCreated` | A new row enters the prospecting pool (any source) | `listing`, `source` (captured/p24_alert/pp_alert/portal_capture), `agencyId` | `ProspectingListingObserver::created()` + email-parsing pipelines | `RecomputeBuyerMatchesForListing`, `RunPropertyCrossReference`, audit |
| `Prospecting\ProspectingListingMatched` | A `prospecting_buyer_matches` row written | `match` (the ProspectingBuyerMatch), `listing`, `contactId`, `agencyId` | `PropertyMatchScoringService::recomputeProspectingMatches()` | `UpdateProspectingTabCounts`, audit |
| `Prospecting\ProspectingListingArchived` | Row archived | `listing`, `archivedByUserId`, `agencyId` | `ProspectingListingObserver::deleted()` | audit |
| `Prospecting\ProspectingAddressCaptured` | Agent fills in the address on a previously-alert-only row | `listing`, `oldAddress` (null), `newAddress`, `enteredByUserId`, `agencyId` | Address capture controller | `RunPropertyCrossReference`, audit |
| `Deal\DealCreated` | Deal record opened (post-offer-accepted) | `deal`, `property`, `buyerContactId`, `sellerContactId`, `agencyId` | Deal controller | `UpdateBranchPipelineStats`, `NotifyConveyancer`, audit |
| `Deal\DealRegistered` | Property transfer registered at Deeds | `deal`, `registeredAt`, `agencyId` | Deal controller (manual trigger) | `CloseProperty` (status → sold), `TriggerCommissionCalc`, audit |
| `Fica\FicaApproved` | FICA submission marked approved | `submission`, `contact`, `approvedByUserId`, `agencyId` | FICA controller | `EnableDealActions`, audit |
| `Fica\FicaRejected` | FICA submission rejected with reasons | `submission`, `contact`, `reasons`, `rejectedByUserId`, `agencyId` | FICA controller | `NotifyContact`, audit |
| `AgencyCreated` | A new LIVE agency + its first Admin are created (demo agencies excluded) | `agency`, `adminUser`, `adminEmail`, `createdByUserId`, `agencyId` | `AgencyController@store()` (explicit, post-commit, `if ($adminPayload)`) | `Onboarding\CreateAgencySetupPortal` (creates guided-setup record + emails Admin), audit |

This catalogue is **not exhaustive**. As new features ship, new events are added per E9.

> **Note on `AgencyCreated`:** the domain event (`App\Events\AgencyCreated`) is distinct
> from the Eloquent `Agency::created` boot hook that already fans role provisioning to
> `RoleProvisioningService`. Different mechanism, no collision. The domain event fires
> explicitly from the controller (once, post-commit, live-create only) so the Admin's
> email is in scope for onboarding. Spec: `.ai/specs/agency-onboarding-setup.md`.

---

## Section 6 — Listener Catalogue (Phase 1)

Initial set. Each row: listener name, subscribes to, what it does, sync/queued, output.

| Listener | Subscribes to | Responsibility | Mode | Output |
|---|---|---|---|---|
| `Audit\RecordDomainEvent` | `*` (every event) | Writes one row to `domain_event_log` | Sync | `domain_event_log` row |
| `Prospecting\FlagPropertyAsOnBooks` | `PropertyCreated`, `PropertyUpdated` (when address changes), `PropertyStatusChanged`, `PropertyArchived`, `MandateExpired`, `MandateWithdrawn`, `ProspectingAddressCaptured` | Runs `PropertyCrossReferenceService` for the affected address; updates the on-books badge state on matching prospecting rows | Sync (must be visible immediately on prospecting tab) | `prospecting_listings.on_books_status` field updates (new field — see cross-ref spec) |
| `Prospecting\RunPropertyCrossReference` | `ProspectingListingCreated`, `ProspectingAddressCaptured` | The reverse direction — when a prospecting row is added, cross-reference it against the agency's properties | Sync | Same field as above |
| `Buyer\RecomputeMatchesForWishlist` | `BuyerWishlistCreated`, `BuyerWishlistUpdated` | Calls `PropertyMatchScoringService::recomputeForBuyer($contactId)` + `recomputeProspectingMatchesForBuyer($contactId)` | Queued | Rows in `property_buyer_matches`, `prospecting_buyer_matches` |
| `Buyer\NotifyBuyersOfNewMatch` | `PropertyCreated`, `MandateSigned` | For each matching buyer wishlist, queues a notification (email/in-app/push) about the new property | Queued | `notifications` table |
| `StockIntelligence\UpdateStockGapMetrics` | `BuyerWishlistCreated`, `MandateSigned`, `MandateExpired` | Recalculates aggregate stock-gap metrics for the agency's segment grid | Queued (deferred — recompute can wait seconds) | Cached metrics |
| `Prospecting\UpdateProspectingTabCounts` | `ProspectingListingMatched`, `BuyerWishlistCreated` | Invalidates the prospecting intelligence summary cache (when caching is introduced in spec Phase 2) | Sync | Cache invalidation |
| `Contact\CheckForDuplicateContact` | `ContactCreated` | Runs duplicate-detection against existing contacts; if found, surfaces a "Possible duplicate" flag (does NOT auto-merge) | Sync | Optional `contact.duplicate_flag_id` field (added per cross-ref spec) |

Each listener has its own class file with a docblock describing its trigger conditions, side effects, and any non-obvious behaviour (e.g. "this listener skips silently if the property has no captured address").

---

## Section 7 — Propagation Graph (Phase 1)

Selected user actions and their cascade:

### 7.1 Agent loads a new property at 42 Sunset Drive

```
[Agent submits property form]
    │
    ▼
PropertyController::store()
    │
    ▼
Property::create() ──► PropertyObserver::created() ──► event(new PropertyCreated)
    │
    ├──► Audit\RecordDomainEvent          (sync, ~5ms)   ──► domain_event_log row
    │
    ├──► Prospecting\FlagPropertyAsOnBooks (sync, ~20ms) ──► PropertyCrossReferenceService
    │                                                           ├── strict address match on prospecting_listings
    │                                                           ├── fuzzy match on the rest
    │                                                           └── update on_books_status field
    │
    ├──► Buyer\RecomputeMatchesForWishlist (queued)      ──► PropertyMatchScoringService
    │                                                           └── property_buyer_matches rows
    │
    ├──► Buyer\NotifyBuyersOfNewMatch     (queued)       ──► notifications table
    │
    └──► StockIntelligence\UpdateStockGapMetrics (queued) ──► aggregate cache refresh
```

User-visible: form submits, redirect to property page, badges update on prospecting tab on next page view (~within seconds).

### 7.2 Buyer creates a wishlist

```
[Agent saves wishlist for contact 47]
    │
    ▼
BuyerDetailController::saveWishlist() / ContactMatchController::store()
    │
    ▼
ContactMatch::create() ──► ContactMatchObserver::created() ──► event(new BuyerWishlistCreated)
    │
    ├──► Audit\RecordDomainEvent              (sync)    ──► domain_event_log
    │
    ├──► Buyer\RecomputeMatchesForWishlist    (queued)  ──► property_buyer_matches + prospecting_buyer_matches
    │
    └──► StockIntelligence\UpdateStockGapMetrics (queued) ──► metric refresh
```

If the new wishlist is the contact's first, observer emits `BuyerWishlistPrimaryChanged` too (since auto-promotion to primary fires).

### 7.3 Mandate signed on property 42 Sunset Drive

```
[E-sign callback indicates mandate signed]
    │
    ▼
MandateController::confirmSigning()
    │
    ▼
Mandate::update(status='signed') ──► event(new MandateSigned)
    │
    ├──► Audit\RecordDomainEvent                          (sync)
    ├──► Buyer\NotifyBuyersOfNewListing                   (queued)
    ├──► StockIntelligence\UpdateStockGapMetrics          (queued)
    └──► Mandate\ScheduleMandateExpiryReminder            (queued — schedules 14-day reminder)
```

### 7.4 Mandate expires on a property

```
[Daily cron]
    │
    ▼
Mandate::where('expires_at', '<', today())->where('status', 'signed')->each
    │
    ▼
event(new MandateExpired) per mandate
    │
    ├──► Audit\RecordDomainEvent                  (sync)
    ├──► Prospecting\FlagPropertyAsOnBooks        (sync — flips status to expired in prospecting badge)
    ├──► Mandate\NotifyBranchManager              (queued)
    └──► Prospecting\AddToReProspectQueue         (queued — surfaces it on the prospecting tab as a re-prospect opportunity)
```

The expired mandate's property is now a high-value re-prospect target. Other agents in the agency see it; agents outside the agency don't (multi-tenancy).

---

## Section 8 — Implementation Build Sequence

Each prompt: standard reads, pre-flight, changes, post-change verification, end-of-prompt verification.

| # | Prompt | Summary | Success criteria |
|---|---|---|---|
| 01 | **Schema + base class** | Create `domain_event_log` table. Create `app/Events/AbstractDomainEvent.php` base class. Create `app/Listeners/Audit/RecordDomainEvent.php` and wire it as wildcard listener. | Table created; rollback proven; firing a test event via Tinker writes a row to `domain_event_log` |
| 02 | **Property events + observer integration** | Create `Property\*` event classes. Modify `PropertyObserver` to emit them (no replacement of existing behaviour — additive). | Creating a property in Tinker fires `PropertyCreated` → audit row written; status change fires `PropertyStatusChanged`; no regression on existing flows |
| 03 | **Contact events + observer integration** | Same pattern for Contact. | Creating / updating a Contact emits the right events; audit log captures them |
| 04 | **Wishlist events + observer integration** | `Wishlist\*` events emitted from `ContactMatchObserver` (already exists from wishlist spec Prompt 02). | Wishlist created → `BuyerWishlistCreated` emitted; primary change → `BuyerWishlistPrimaryChanged` |
| 05 | **Prospecting events + observer integration** | `Prospecting\*` events. Also covers source-pipeline (P24/PP/Portal) emissions when those rows are imported. | New prospecting row emits `ProspectingListingCreated`; match writes emit `ProspectingListingMatched` |
| 06 | **Mandate + Deal + FICA events** | Less Eloquent-observer-driven; emitted from controller actions. | Mandate signing / expiry / withdrawal emit correct events; same for deals and FICA |
| 07 | **First non-audit listener — `FlagPropertyAsOnBooks`** | Plays out the cross-reference reactivity. This is also Prompt 01 of the cross-reference spec. | Loading a property updates on-books badges on matching prospecting rows in real time (sync) |
| 08 | **Trace-id support** | Add trace-id propagation. Helper methods. Test that a cascade preserves the trace. | Single user action's full cascade is filterable by trace_id in `domain_event_log` |
| 09 | **Test pattern + sample test suite** | Lay down the test convention. Build sample tests for 3 events. | All test patterns documented; sample tests pass |
| 10 | **Documentation + onboarding doc** | A markdown file under `docs/architecture/domain-events.md` for future Claude / Andre / external integrators. | Doc covers the catalogue, listener pattern, how to add a new event, how to debug a cascade |

Each later spec (cross-reference, flow, seller outreach, etc.) consumes events from this catalogue. They do not add their own observer hooks — they subscribe to events.

---

## Section 9 — Rollback Plan

Per-prompt rollback table. Schema rolled back via `migrate:rollback`. Code prompts via `git revert`. No data destruction risk in this spec — every change is additive.

If the audit listener (Prompt 01) ever has a critical bug, set the env flag `COREX_DOMAIN_EVENT_AUDIT_ENABLED=false`; the wildcard listener checks the config and exits early. Events still fire; just no log row. Bug-fix and re-enable.

---

## Section 10 — Acceptance Criteria

1. `domain_event_log` table exists with all indexes from Section 4.1.
2. Firing any registered event from Tinker writes a row to `domain_event_log` with correct `event_name`, `agency_id`, `payload_snapshot`.
3. `RecordDomainEvent` is registered as a wildcard listener and fires for every event.
4. At least 8 event classes from Section 5 exist and are emitted from the correct trigger points.
5. At least 3 listeners from Section 6 exist and are wired correctly.
6. `PropertyCreated` triggers `FlagPropertyAsOnBooks` synchronously. Loading a property in dev shows badges updated on the prospecting tab within one page render.
7. `BuyerWishlistCreated` triggers `RecomputeMatchesForWishlist` queued. Running `php artisan queue:work` once processes the job; match tables get new rows.
8. Trace IDs propagate across a cascade. Filtering `domain_event_log` by `trace_id` shows all events from one user action grouped together.
9. Multi-tenancy verified: emitting an event for `agency_id=1` and asserting only agency_id=1's listeners affect data; no cross-tenant leak.
10. Every event class has a docblock describing trigger, payload, subscribers.
11. Existing observers (`PropertyObserver`, `ContactMatchObserver`, etc.) continue working unchanged — events are emitted additively.
12. `domain_event_log` queryable to reconstruct any past event cascade for legal-defensibility purposes.

---

## Section 11 — Open Questions

1. **Queue infrastructure confirmation.** Pre-flight of Build Prompt 01 confirms current queue driver. If sync-only, queued listeners run sync as a fallback; introducing Redis + Horizon is a separate ticket.
2. **Wildcard listener performance.** `RecordDomainEvent` subscribing to `*` and writing on every event — at high event volume this could become a bottleneck. Phase 2 may move to a queued sink. Pre-flight measures current event volume baseline (zero today; we're starting fresh).
3. **Retention policy for `domain_event_log`.** PPRA typically requires 7 years for agency records. A separate archive job rotates rows older than X months to cold storage. Out of scope of this spec; flagged as a follow-up.
4. **External webhook delivery.** Eventually external systems (Property24, Private Property, Mandated Property Group's portal, future integrations) may consume CoreX events. The internal event bus is the foundation; external delivery is a separate spec.
5. **Event ordering guarantees.** Within a single PHP request, listeners fire in registration order. Across queued listeners, ordering is best-effort (queues are FIFO per queue, but listeners on different queues may race). Document the guarantee in the onboarding doc.

---

**End of spec.**
