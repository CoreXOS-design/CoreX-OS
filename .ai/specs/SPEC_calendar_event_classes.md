# Calendar Event Classes — Data Sources Audit & Architecture Spec
> **Status:** Phase 0 baseline. Single source of truth for Calendar Event Classes feature.
> **Conducted:** 2026-04-28 | Branch: HFC2402
> **Author:** Claude (commissioned by Johan)
> **Live URL for settings UI:** `/command-center/settings` (NOT `/corex/settings` — confirmed via investigation 2026-04-30)

---

## Methodology

1. **Schema scan** — read all 422 migration files in `database/migrations/`, identified every `date`, `datetime`, and `timestamp` column excluding framework columns (`created_at`, `updated_at`, `deleted_at`, `email_verified_at`, `remember_token`, `two_factor_*`, `last_login_at`). Also captured integer duration columns that feed computed deadlines.
2. **Model semantic review** — audited every model in `app/Models/` (including subdirectories) for `$casts` date entries, accessor methods computing expiry/deadline, `isExpired()` methods, scope methods filtering by date ranges, and hardcoded duration constants.
3. **Blade view inspection** — searched all views in `resources/views/` for user-facing date displays with labels like "expires", "due", "deadline", "renew", "valid until". Identified which are display-only vs. action-triggering.
4. **Scheduled task review** — audited `routes/console.php` (all `$schedule` entries), `app/Console/Commands/` (40+ commands), `app/Jobs/` (15 job classes), and `app/Notifications/` (7 notification classes) for recurring operational events and existing reminder infrastructure.
5. **Cross-reference** — validated findings against `CalendarEventService`, `AutoEventService`, `OversightService`, and `LeaveCalendarService` to identify what already flows to the calendar vs. what doesn't.

---

## Summary Table

| Domain | Date sources found | Currently on calendar | Recommended for calendar | Priority group |
|--------|-------------------|----------------------|--------------------------|----------------|
| Listings / Properties | 8 | 0 | 6 | A + B |
| Deals (V1 + V2) | 8 | 0 (1 task only) | 7 | A |
| FICA / Compliance | 14 | 0 | 11 | A |
| Agents / Employment | 10 | 0 | 8 | A + B |
| Payroll | 6 | 0 | 4 | A |
| Leave | 6 | 3 (leave apps + holidays + reminders) | 2 more | B |
| Rentals / Leases | 6 | 0 | 5 | A + B |
| Documents / Signatures | 5 | 0 | 4 | B |
| Recurring Ops (scheduled) | 12 | 0 | 8 | A + B |
| **TOTAL** | **75** | **3** | **55** | — |

---

## Visibility Model

All recommendations use four scopes (union model — user sees events from all scopes they qualify for):

| Scope | Who sees it | Example |
|-------|-------------|---------|
| **PERSONAL** | The assigned user only | "Your FFC expires in 30 days" |
| **BRANCH** | Branch manager + all branch members | "Branch mandate expiring" |
| **AGENCY** | Admin + designated roles (payroll, compliance officer, accountant) | "SARS EMP201 due in 7 days" |
| **SYSTEM** | Everyone in the agency | "Office closure 16 Dec" |

## Urgency Colour Model

Three auto-transitioning states, time-based. Thresholds are **agency-configurable defaults** (seeded on agency creation, editable via settings).

| Colour | Hex | Meaning |
|--------|-----|---------|
| **GREEN** | `#00d4aa` | Informational / future-distant |
| **AMBER** | `#f59e0b` | Approaching / action recommended |
| **RED** | `#ef4444` | Urgent / overdue / breach risk |

---

## Event Class Configuration Architecture

Before any calendar event sources are wired, build Phase 0 infrastructure.

Each event class is a **full configuration object** — not just thresholds. It includes activation toggle, per-colour visibility, per-colour notification routing, and daily digest opt-in.

### Table: `calendar_event_class_settings`

```
id                      bigIncrements
agency_id               foreignId → agencies (nullable for global defaults)
event_class             string(60), indexed    — e.g. 'mandate_expiry', 'ffc_expiry'
                                                  (maps to calendar_events.category)
is_active               boolean, default true  — master toggle for this event class
green_days              unsignedSmallInteger   — days before event where GREEN starts
amber_days              unsignedSmallInteger   — days before event where AMBER starts
red_days                unsignedSmallInteger   — days before event where RED starts
show_days               unsignedSmallInteger   — days before event to first show on calendar (null = always)
green_visibility        json                   — roles who see GREEN events, e.g. ["agent"]
amber_visibility        json                   — roles who see AMBER events, e.g. ["agent", "bm"]
red_visibility          json                   — roles who see RED events, e.g. ["agent", "bm", "admin"]
green_notifications     json                   — per-role notification channels at GREEN
amber_notifications     json                   — per-role notification channels at AMBER
red_notifications       json                   — per-role notification channels at RED
daily_digest_enabled    boolean, default false
daily_digest_roles      json, nullable         — roles who receive this class in daily digest
label                   string(100)            — human-readable name, e.g. "Mandate Expiry"
description             string(255), nullable  — contextual note for settings screen
timestamps
unique: [agency_id, event_class]
```

**JSON structure for notifications columns:**
```json
{
    "agent": ["in_app"],
    "bm": ["in_app", "email"],
    "admin": ["in_app"],
    "compliance_officer": ["in_app", "email"]
}
```
Empty object `{}` = calendar entry only, no push notifications.

**JSON structure for visibility columns:**
```json
["agent", "bm", "admin", "compliance_officer", "payroll", "hr"]
```

### Architectural mapping to existing `calendar_events` table

The existing `calendar_events` table (already built by Andre, 31 columns) uses:
- `event_type` (varchar 50) = pillar/domain — values: `deal`, `lease`, `compliance`, `document`, `prospecting`, `portal`, `property`, `manual`, `leave`
- `category` (varchar 80) = sub-type — this maps to our `event_class` value

**Decision:** `event_class` IS `category`. We do NOT add a new column to `calendar_events`. The `event_class` field on `calendar_event_class_settings` matches `calendar_events.category` exactly (e.g. `mandate_expiry`, `ffc_expiry`, `bond_deadline`).

### Model: `CalendarEventClassSetting`
- `BelongsToAgency` trait
- Scope: `scopeForAgency($agencyId)` — falls back to global defaults (`agency_id = null`) when no agency override
- Cast: `green_visibility`, `amber_visibility`, `red_visibility`, `green_notifications`, `amber_notifications`, `red_notifications`, `daily_digest_roles` → `array`

### Seeder: `CalendarEventClassSeeder`
- Seeds one row per `event_class` with `agency_id = NULL` (global defaults)
- On agency creation, copies global defaults into agency-specific rows
- Default values are the configurations in the **Default Event Class Configurations** section below

### Service: `CalendarThresholdResolver`
```php
class CalendarThresholdResolver
{
    /**
     * Given an agency, event class, and the event date,
     * returns the current urgency colour or null (don't show yet).
     */
    public function resolve(int $agencyId, string $eventClass, Carbon $eventDate): ?string
    {
        $config = CalendarEventClassSetting::forAgency($agencyId)
            ->where('event_class', $eventClass)
            ->first();

        if (!$config || !$config->is_active) return null;

        $daysUntil = now()->startOfDay()->diffInDays($eventDate->startOfDay(), false);

        if ($daysUntil < 0) return 'red';           // overdue always red
        if ($config->show_days && $daysUntil > $config->show_days) return null;
        if ($daysUntil <= $config->red_days) return 'red';
        if ($daysUntil <= $config->amber_days) return 'amber';
        return 'green';
    }
}
```

### Service: `CalendarVisibilityResolver`
```php
class CalendarVisibilityResolver
{
    /**
     * Given an event and a user, determines if the user sees it on their calendar.
     * Reads current colour + visibility config for that class.
     */
    public function canSee(CalendarEvent $event, User $user): bool
    {
        $config = CalendarEventClassSetting::forAgency($user->agency_id)
            ->where('event_class', $event->category)
            ->first();

        if (!$config || !$config->is_active) return false;

        $colour = $this->thresholdResolver->resolve(
            $user->agency_id, $event->category, $event->event_date
        );
        if (!$colour) return false;

        $visibleRoles = $config->{$colour . '_visibility'} ?? [];
        return $this->userMatchesAnyRole($user, $event, $visibleRoles);
    }
}
```

### Service: `CalendarNotificationDispatcher`
Thin wrapper around the existing `App\Services\Notifications\NotificationDispatcher` (108 lines, with idempotency guard + FCM push). This service resolves per-class config and delegates actual dispatch to the existing dispatcher — does NOT re-implement notification routing.

```php
class CalendarNotificationDispatcher
{
    /**
     * On colour transition, dispatch notifications per config.
     * Wraps existing NotificationDispatcher with per-class config.
     */
    public function onColourTransition(
        CalendarEvent $event,
        string $previousColour,
        string $newColour
    ): void {
        $config = CalendarEventClassSetting::forAgency($event->agency_id)
            ->where('event_class', $event->category)
            ->first();

        $notifyConfig = $config->{$newColour . '_notifications'} ?? [];

        foreach ($notifyConfig as $role => $channels) {
            $users = $this->resolveUsersForRole($role, $event);
            foreach ($users as $user) {
                $this->notificationDispatcher->send($user, $event, $channels);
            }
        }
    }
}
```

### Daily Digest Worker: `corex:calendar:send-digests` at 06:30 daily

For each user:
1. Find all event classes where `daily_digest_enabled = true` AND `daily_digest_roles` includes the user's role
2. For each matching class, find events on the user's calendar within the next `show_days`
3. Resolve current colour for each event
4. Group by colour: red items first, then amber, then green
5. Send single email: "Today's calendar digest: 3 red items, 5 amber items across your branch"
6. Mark digest as sent (prevent duplicates)

### Settings screen: `/command-center/settings` — extend existing page with new "Event Classes" section
- Replace/supersede the existing "Reminder Defaults" section (which uses an offset model, not threshold model)
- Existing `command_reminder_defaults` table (empty, 0 rows) is dropped during 0c migration
- Table of all event classes with:
  - Toggle `is_active` per class
  - Editable green/amber/red day thresholds
  - Per-colour visibility role checkboxes
  - Per-colour notification routing (role × channel matrix)
  - Daily digest toggle + role selection
- Permission: `command_center.settings` (already exists — confirmed in config/corex-permissions.php line 368)

**All colour logic, visibility, and notification routing is centralised. Future changes = one settings edit, no deploys.**

### Relationship to existing `notification_event_types` table
The existing `notification_event_types` table (26 rows seeded) is a SEPARATE system controlling user-level notification preferences (the in-app/email/push toggles per pillar in user settings). It is NOT modified by this work. The two systems are orthogonal:
- `notification_event_types` = "do I get notified at all" (user preference)
- `calendar_event_class_settings` = "what classes appear on my calendar, what colour are they at, who sees them, and when does the agency send blasts" (agency policy + role visibility)

---

## Detailed Findings Per Domain

---

### Domain: Listings / Properties

#### LP-1: `properties.expiry_date` — Mandate Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_02_25_201319_create_properties_table.php`, added `2026_03_05_100001` |
| Type | `date`, nullable |
| Model | `Property` — cast to `date` |
| Semantic | Date the sole/open/dual mandate expires |
| Currently displayed | Yes — listing index (agent, BM, admin), property show page, filing register, BM performance dashboard alert |
| User-facing labels | "Expiry", "Mandate expires", "Expiring (14d)", "Expired" |
| Currently on calendar | **NO** |
| Currently notified | Yes — `ScanPropertyNotifications` fires `property.mandate_expiring` via `PillarEventNotification` every 30 min; `OversightService.expiringMandates` feeds BM dashboard |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `mandate_expiry` |
| Visibility | **BRANCH** (listing agent + branch manager) |
| Remind who | Listing agent (PERSONAL) + branch manager (BRANCH) |
| Default thresholds | Green: >30d, Amber: 7–30d, Red: <7d or overdue |
| Default show_days | 90 |
| Default reminder_offsets | [60, 30, 14, 7, 1] |
| Risk if missed | Lose the listing to another agency; PPRA sole mandate rules breached |

#### LP-2: `properties.lease_end_date` — Property Lease Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_03_07_200001_add_rental_lease_fields_to_properties.php` |
| Type | `date`, nullable |
| Model | `Property` — cast to `date` |
| Semantic | Current tenant lease end date (for rental properties) |
| Currently displayed | Yes — property show page |
| Currently on calendar | **NO** (lease_records have their own expiry check but not properties) |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `lease_expiry` |
| Visibility | **BRANCH** |
| Remind who | Rental agent + branch manager |
| Default thresholds | Green: >60d, Amber: 30–60d, Red: <30d or overdue |
| Default show_days | 120 |
| Default reminder_offsets | [90, 60, 30, 14, 7] |
| Risk if missed | Lease lapses to month-to-month unintentionally; rental income disrupted |

#### LP-3: `properties.listed_date` — Listing Anniversary / Stale Check

| Field | Value |
|-------|-------|
| Source | Migration `2026_03_05_100001_add_extra_fields_to_properties_table.php` |
| Type | `date`, nullable |
| Semantic | Date property was formally listed |
| Currently on calendar | **NO** |
| **Should be on calendar** | **NO** — already covered by idle property detection (`FlagIdleProperties`) and `PropertyHealthCalculator`. Listing age is a metric, not a discrete event. |

#### LP-4: `property_showdays.start_date` / `end_date` — On-Show / Open House

| Field | Value |
|-------|-------|
| Source | Migration `2026_03_23_170000_create_property_showdays_table.php` |
| Type | `dateTime`, NOT NULL |
| Model | `PropertyShowday` — cast to `datetime` |
| Semantic | Scheduled show day event window |
| Currently displayed | Yes — property show page |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `property_showday` |
| Visibility | **BRANCH** |
| Remind who | Listing agent (PERSONAL) + branch members who may assist |
| Default thresholds | Green: >3d, Amber: 1–3d, Red: today or overdue |
| Default show_days | 30 |
| Default reminder_offsets | [7, 3, 1] |
| Risk if missed | Missed open house = missed buyer leads |

#### LP-5: `listing_stocks.expires_at` — Portal Listing Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_02_03_124938_create_listing_stocks_table.php` |
| Type | `timestamp`, nullable |
| Model | `ListingStock` — cast to `datetime`; accessors: `getDaysToExpiryAttribute()`, `getIsExpiredAttribute()`, `getIsExpiringSoonAttribute()` |
| Semantic | When the listing on P24/PP portal expires |
| Currently displayed | Yes — listing stock index (agent, BM, admin) with colour coding |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `portal_listing_expiry` |
| Visibility | **PERSONAL** (listing agent) |
| Remind who | Listing agent only |
| Default thresholds | Green: >14d, Amber: 3–14d, Red: <3d or expired |
| Default show_days | 30 |
| Default reminder_offsets | [14, 7, 3] |
| Risk if missed | Listing drops off portals; buyer exposure lost |

#### LP-6: `properties.pp_delay_until` — PP Exclusivity Delay

| Field | Value |
|-------|-------|
| Source | Migration `2026_03_23_100001_add_pp_syndication_columns_to_properties_table.php` |
| Type | `timestamp`, nullable |
| Semantic | Date after which PP submission is allowed (exclusivity window) |
| Currently on calendar | **NO** |
| **Should be on calendar** | **NO** — internal syndication scheduling, not user-facing event |

#### LP-7: `document_filing_register.expiry_date` — Filed Document Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_02_24_500000_create_document_filing_register_table.php` |
| Type | `date`, nullable, indexed |
| Model | `DocumentFiling` — cast to `date`; scopes: `scopeExpiringSoon($days=30)`, `scopeExpired()`; accessor: `getStatusAttribute()` returns `expired/expiring/active` |
| Semantic | Mandate document or other filed document expiry |
| Currently displayed | Yes — filing register index, BM performance, agent dashboard |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** — but duplicates mandate_expiry for mandate docs. Only calendar-worthy for non-mandate document types (e.g., compliance certificates filed per property). |
| Recommended `event_class` | `filed_document_expiry` |
| Visibility | **PERSONAL** (filing agent) |
| Default thresholds | Green: >30d, Amber: 7–30d, Red: <7d or expired |
| Default show_days | 60 |
| Default reminder_offsets | [30, 14, 7] |

#### LP-8: `properties.last_activity_at` — Idle Property Trigger

| Field | Value |
|-------|-------|
| Source | Migration `2026_03_31_300011_add_last_activity_at_to_properties_table.php` |
| Type | `dateTime`, nullable |
| Semantic | Last time any action taken on property (idle detection) |
| Currently on calendar | **NO** — but `FlagIdleProperties` creates `CommandTask` entries |
| **Should be on calendar** | **NO** — tasks are the right medium for idle alerts, not calendar events |

---

### Domain: Deals (V1 + V2)

#### D-1: `deals.deal_date` — V1 Offer/Deal Date

| Field | Value |
|-------|-------|
| Source | Migration `2026_01_15_084201_create_deals_table.php` |
| Type | `date`, NOT NULL |
| Model | `Deal` — cast to `date` |
| Semantic | Date of the offer/deal |
| Currently on calendar | **NO** |
| **Should be on calendar** | **NO** — historical anchor, not a future event. Used by `OversightService.dealsNearExpiry` for staleness. |

#### D-2: `deals.registration_date` — V1 Deeds Registration

| Field | Value |
|-------|-------|
| Source | Migration `2026_01_15_113405_add_register_fields_to_deals_table.php` |
| Type | `date`, nullable |
| Semantic | Actual deeds registration date |
| Currently on calendar | **NO** |
| **Should be on calendar** | **NO** — recorded after the fact, not a forward-looking deadline |

#### D-3: `deals_v2.offer_date` — V2 Offer Date

| Field | Value |
|-------|-------|
| Source | Migration `2026_03_30_300003_create_deals_v2_table.php` |
| Type | `date`, NOT NULL |
| Semantic | Date the OTP was signed |
| Currently on calendar | **NO** |
| **Should be on calendar** | **NO** — historical anchor. V2 pipeline steps carry the forward-looking deadlines. |

#### D-4: `deals_v2.expected_registration` — V2 Target Registration Date

| Field | Value |
|-------|-------|
| Source | Migration `2026_03_30_300003_create_deals_v2_table.php` |
| Type | `date`, nullable |
| Model | `DealV2` — cast to `date` |
| Semantic | Target/expected deeds registration date |
| Currently displayed | Yes — deal show page "Key Dates" panel |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `deal_registration_target` |
| Visibility | **BRANCH** |
| Remind who | Selling agent + listing agent + branch manager |
| Default thresholds | Green: >21d, Amber: 7–21d, Red: <7d or overdue |
| Default show_days | 90 |
| Default reminder_offsets | [30, 14, 7, 3] |
| Risk if missed | Deal transfer delays, buyer/seller frustration, possible cancellation |

#### D-5: `deal_step_instances.due_date` — V2 Pipeline Step Deadlines

| Field | Value |
|-------|-------|
| Source | Migration `2026_03_30_300006_create_deal_step_instances_table.php` |
| Type | `date`, nullable |
| Model | `DealStepInstance` — cast to `date`; methods: `daysRemaining()`, `calculateRag()`, `isOverdue()` |
| Semantic | Deadline for completing a specific deal pipeline step (bond approval, compliance cert, guarantee, etc.) |
| Currently displayed | Yes — deal show page with RAG colour coding and "OVERDUE Xd" labels |
| Currently notified | Yes — `ScanDealNotifications` fires `deal.stalled_*` via `PillarEventNotification` |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** — these are the most granular deal deadlines |
| Recommended `event_class` | `deal_step_deadline` (with step name in title/metadata) |
| Visibility | **PERSONAL** (assigned agent) + **BRANCH** (BM oversight) |
| Remind who | Agent assigned to the deal + branch manager |
| Default thresholds | Inherit from step's own `rag_green_days`/`rag_amber_days`/`rag_red_days` (typically 14/7/3) |
| Default show_days | `rag_green_days` + 7 (show when step activates) |
| Default reminder_offsets | [7, 3, 1] |
| Risk if missed | Bond deadline missed → deal collapses. Compliance cert missed → transfer blocked. Guarantee missed → contract breach. |
| Note | Each active deal may have 5-15 step instances. Calendar should show only active (not completed/skipped) steps. |

#### D-6: `deal_money_lines.paid_at` / `deal_user.paid_at` — Commission Payment

| Field | Value |
|-------|-------|
| Semantic | When commission was paid to agent |
| **Should be on calendar** | **NO** — historical record, not a deadline |

#### D-7: `deals.granted_at` — Deal Acceptance

| Field | Value |
|-------|-------|
| Semantic | When deal was granted/accepted |
| **Should be on calendar** | **NO** — historical event |

#### D-8: Computed — Offer Expiry (72-hour OTP window)

| Field | Value |
|-------|-------|
| Source | Not stored. SA standard: OTP valid for 72 hours unless specified otherwise. Could be computed from `deals_v2.offer_date + 72h` or stored on a pipeline step. |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** — but currently handled by the deal pipeline step "Acceptance" with its own `due_date`. If no step exists for this, it's a gap (see Gap Analysis). |

---

### Domain: FICA / Compliance

#### FC-1: `users.ffc_expiry_date` — FFC Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_21_000001_add_compliance_fields_to_users_table.php` |
| Type | `date`, nullable |
| Model | `User` — cast to `date` |
| Semantic | Fidelity Fund Certificate expiry (PPRA: annual, 12-month cycle) |
| Currently displayed | Yes — agent portal (colour-coded: green >60d, amber <=60d, red expired), compliance agent dashboard |
| Currently notified | Yes — `OversightService.expiringFfcs`, plus `PillarEventNotification` via `ScanContactNotifications` (unclear — may only be for contacts, not users) |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES — CRITICAL** |
| Recommended `event_class` | `ffc_expiry` |
| Visibility | **PERSONAL** (the agent) + **AGENCY** (compliance officer + admin) |
| Remind who | The agent themselves + compliance officer + branch manager |
| Default thresholds | Green: >60d, Amber: 30–60d, Red: <30d or expired |
| Default show_days | 120 |
| Default reminder_offsets | [90, 60, 30, 14, 7, 1] |
| Risk if missed | **Agent cannot legally transact.** PPRA non-compliance. All deals signed by this agent are legally compromised. |

#### FC-2: `users.pi_insurance_expiry` — Professional Indemnity Insurance Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_21_000001_add_compliance_fields_to_users_table.php` |
| Type | `date`, nullable |
| Model | `User` — cast to `date` |
| Semantic | PI insurance certificate expiry |
| Currently displayed | Yes — agent portal |
| Currently notified | **NO — GAP. Not in OversightService, no scan command.** |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES — CRITICAL** |
| Recommended `event_class` | `pi_insurance_expiry` |
| Visibility | **PERSONAL** + **AGENCY** (compliance officer) |
| Remind who | The agent + compliance officer |
| Default thresholds | Green: >60d, Amber: 30–60d, Red: <30d or expired |
| Default show_days | 120 |
| Default reminder_offsets | [90, 60, 30, 14, 7] |
| Risk if missed | Agent operates without PI cover. Agency liability exposure. |

#### FC-3: `users.tax_clearance_expiry` — Tax Clearance Certificate Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_21_000001_add_compliance_fields_to_users_table.php` |
| Type | `date`, nullable |
| Currently notified | **NO — GAP.** |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `tax_clearance_expiry` |
| Visibility | **PERSONAL** + **AGENCY** (compliance officer) |
| Default thresholds | Green: >60d, Amber: 30–60d, Red: <30d or expired |
| Default show_days | 120 |
| Default reminder_offsets | [90, 60, 30, 14] |
| Risk if missed | Cannot prove tax compliance. SARS issues. |

#### FC-4: `user_documents.expiry_date` — Agent Document Expiry (generic)

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_21_000002_create_user_documents_table.php` |
| Type | `date`, nullable, indexed |
| Model | `UserDocument` — scopes: `scopeExpiring($days=60)`, `scopeExpired()` |
| Semantic | Expiry date for any uploaded agent document (FFC cert, PI cert, tax clearance, etc.) |
| Currently displayed | Yes — compliance verification queue, agent portal |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** — when `agency_document_type_configs.has_expiry = true` |
| Recommended `event_class` | `agent_document_expiry` |
| Visibility | **PERSONAL** (the agent) + **AGENCY** (compliance officer) |
| Default thresholds | Green: >60d, Amber: 30–60d, Red: <30d or expired |
| Default show_days | Uses `agency_document_type_configs.renewal_days` if set, else 90 |
| Default reminder_offsets | [60, 30, 14, 7] |
| Note | Already has `renewal_days` config per document type. Calendar should honour this. |

#### FC-5: `training_completions.expires_at` — Training Certification Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_03_27_500000_create_training_tables.php` |
| Type | `date`, nullable, indexed |
| Model | `TrainingCompletion` — scopes: `scopeExpiring()` (30d), `scopeExpired()` |
| Currently displayed | Yes — training index with badges |
| Currently notified | **NO — GAP** |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `training_expiry` |
| Visibility | **PERSONAL** (the agent) + **BRANCH** (BM) |
| Default thresholds | Green: >30d, Amber: 14–30d, Red: <14d or expired |
| Default show_days | 60 |
| Default reminder_offsets | [30, 14, 7] |
| Risk if missed | Agent CPD non-compliance; PPRA audit finding |

#### FC-6: `rmcp_versions.next_review_due` — RMCP Review Deadline

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_21_100001_create_rmcp_versions_table.php` |
| Type | `date`, nullable |
| Model | `RmcpVersion` — cast to `date` |
| Currently displayed | Yes — RMCP dashboard "Next review: d M Y" |
| Currently notified | **NO — GAP** |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `rmcp_review_due` |
| Visibility | **AGENCY** (compliance officer + admin) |
| Default thresholds | Green: >60d, Amber: 30–60d, Red: <30d or overdue |
| Default show_days | 120 |
| Default reminder_offsets | [90, 60, 30, 14, 7] |
| Risk if missed | PPRA compliance breach. Agency-level regulatory finding. |

#### FC-7: `rmcp_acknowledgements.valid_until` — RMCP Acknowledgement Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_21_120001_create_rmcp_acknowledgements_table.php` |
| Type | `date`, nullable, indexed |
| Model | `RmcpAcknowledgement` — scopes: `scopeValid()`, `scopeExpiringSoon($days=30)`; method: `isValid()` |
| Currently displayed | Yes — RMCP dashboard, acknowledgement receipt |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `rmcp_ack_expiry` |
| Visibility | **PERSONAL** (the agent) + **AGENCY** (compliance officer) |
| Default thresholds | Green: >30d, Amber: 14–30d, Red: <7d or expired |
| Default show_days | 60 |
| Default reminder_offsets | [30, 14, 7] |

#### FC-8: `employee_screenings.next_due_on` — Employee Screening Due

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_21_130001_create_employee_screenings_table.php` |
| Type | `date`, nullable, indexed |
| Model | `EmployeeScreening` — scopes: `scopeOverdue()`, `scopeDueSoon($days=30)`; computed in `complete()`: +1yr (high risk), +3yr (medium), +5yr (low) |
| Currently displayed | Yes — screening dashboard, overdue page |
| Currently notified | **NO — GAP** |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `screening_due` |
| Visibility | **AGENCY** (compliance officer + HR admin) |
| Default thresholds | Green: >60d, Amber: 30–60d, Red: <30d or overdue |
| Default show_days | 90 |
| Default reminder_offsets | [60, 30, 14, 7] |

#### FC-9: `users.screening_due_on` — User-Level Screening Due

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_21_130003_add_screening_fields_to_users_table.php` |
| Type | `date`, nullable |
| Semantic | Mirror of `employee_screenings.next_due_on` on the user record |
| **Should be on calendar** | **NO** — use FC-8 (`employee_screenings.next_due_on`) as the canonical source to avoid duplication |

#### FC-10: `agency_compliance_provisions.effective_until` — Compliance Provision Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_21_150001_create_agency_compliance_provisions_table.php` |
| Type | `date`, nullable |
| Model | `AgencyComplianceProvision` — scope: `scopeActive()`; accessor: `getStatusLabelAttribute()` shows "Expiring in X days" |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `compliance_provision_expiry` |
| Visibility | **AGENCY** (compliance officer + admin) |
| Default thresholds | Green: >60d, Amber: 30–60d, Red: <30d or expired |
| Default show_days | 120 |
| Default reminder_offsets | [90, 60, 30, 14] |

#### FC-11: `user_compliance_overrides.expires_at` — Compliance Override Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_21_150003_create_user_compliance_overrides_table.php` |
| Type | `date`, nullable |
| Model | `UserComplianceOverride` — scope: `scopeActive()` |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `compliance_override_expiry` |
| Visibility | **AGENCY** (compliance officer only) |
| Default thresholds | Green: >14d, Amber: 7–14d, Red: <7d or expired |
| Default show_days | 30 |
| Default reminder_offsets | [14, 7, 3] |
| Note | When an override expires, the underlying compliance requirement becomes enforceable again |

#### FC-12: `fica_submissions.token_expires_at` — FICA Link Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_03_26_100000_create_fica_tables.php` |
| Type | `dateTime`, NOT NULL |
| Model | `FicaSubmission` — method: `isExpired()` |
| Currently on calendar | **NO** |
| **Should be on calendar** | **NO** — short-lived token (hours/days), not a calendar-scale event. Agent gets email with the link. |

#### FC-13: `fica_submissions.fica_expires_at` — FICA Renewal (24-month validity)

| Field | Value |
|-------|-------|
| Source | NEW COLUMN added in build prompt 0a (Phase 0 baseline). Migration: `add_fica_expires_at_to_fica_submissions_table` |
| Type | `date`, nullable, indexed |
| Backfill | `verified_at + 24 months` for all rows where `verified_at IS NOT NULL` |
| Write path | Set on every write to `verified_at` (controllers/services/observers) — `fica_expires_at = verified_at + 24 months` |
| Currently on calendar | **NO** |
| Currently notified | `ScanContactNotifications` fires `contact.fica_missing` — but this checks if FICA exists at all, not if it's expiring |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `fica_renewal_due` |
| Visibility | **PERSONAL** (agent managing the contact) + **AGENCY** (compliance officer) |
| Default thresholds | Green: >60d, Amber: 30–60d, Red: <30d or overdue |
| Default show_days | 120 |
| Default reminder_offsets | [90, 60, 30, 14] |
| Risk if missed | Contact's FICA lapses. Any deal involving this contact is non-compliant. |

#### FC-14: `fica_officer_appointments.appointed_on` / `ended_on`

| Field | Value |
|-------|-------|
| **Should be on calendar** | **NO** — administrative record, not a deadline |

---

### Domain: Agents / Employment

#### AE-1: `users.ffc_expiry_date` — covered in FC-1 above

#### AE-2: `users.employment_date` — Employment Anniversary

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_23_100001_add_payroll_fields_to_users_table.php` |
| Type | `date`, nullable |
| Semantic | Date of employment commencement. Anniversary = `employment_date + N years` each year. |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** (annual recurring) |
| Recommended `event_class` | `employment_anniversary` |
| Visibility | **BRANCH** |
| Remind who | Branch manager + the agent |
| Default thresholds | Green: >7d, Amber: 1–7d, Red: today |
| Default show_days | 14 |
| Default reminder_offsets | [7, 1] |
| Risk if missed | Low operational risk, but important for culture/retention |

#### AE-3: `users.date_of_birth` — Agent Birthday

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_23_100001_add_payroll_fields_to_users_table.php` |
| Type | `date`, nullable |
| Currently notified | Yes — `ScanContactNotifications` fires `contact.birthday` (but this is for contacts, not users/agents) |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** (annual recurring) |
| Recommended `event_class` | `agent_birthday` |
| Visibility | **BRANCH** |
| Default thresholds | Green: >7d, Amber: 1–7d, Red: today |
| Default show_days | 14 |
| Default reminder_offsets | [7, 1] |

#### AE-4: `users.anniversary_date` — Commission Cap Anniversary

| Field | Value |
|-------|-------|
| Source | Migration `2026_03_27_300001_add_commission_columns_to_users.php` |
| Type | `date`, nullable |
| Semantic | Commission cap period reset date |
| Currently on calendar | **NO** |
| **Should be on calendar** | **NO** — internal financial cycle, not a user-facing event |

#### AE-5: `users.ppra_last_verified_at` — PPRA Verification

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_22_090000_add_ppra_last_verified_at_to_users.php` |
| Type | `date`, nullable |
| Semantic | Last PPRA status verification (audit trigger) |
| Currently on calendar | **NO** |
| **Should be on calendar** | **NO** — trigger point for a task, not a calendar event |

#### AE-6: `agent_applications.ffc_expiry` — Applicant FFC Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_03_27_400000_create_onboarding_tables.php` |
| Type | `date`, nullable |
| **Should be on calendar** | **NO** — pre-employment onboarding data, not operational |

#### AE-7: `agent_mentors.graduated_at` — Mentee Graduation

| Field | Value |
|-------|-------|
| **Should be on calendar** | **NO** — historical event, not a deadline |

#### AE-8: `payroll_employees.employment_date` — Payroll Employment Date

| Field | Value |
|-------|-------|
| Semantic | Duplicate of `users.employment_date` for payroll context |
| **Should be on calendar** | **NO** — use AE-2 as canonical source |

#### AE-9: `payroll_employees.termination_date` — Termination Date

| Field | Value |
|-------|-------|
| Type | `date`, nullable |
| Semantic | Last working day |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** (when set, for HR/admin planning) |
| Recommended `event_class` | `employee_termination` |
| Visibility | **AGENCY** (HR admin + payroll manager) |
| Default thresholds | Green: >14d, Amber: 7–14d, Red: <7d |
| Default show_days | 30 |
| Default reminder_offsets | [14, 7, 3, 1] |
| Risk if missed | Final payroll not processed; leave payout missed; system access not revoked |

#### AE-10: `agent_cap_periods.period_start` / `period_end` — Cap Period

| Field | Value |
|-------|-------|
| **Should be on calendar** | **NO** — financial cycle, not user-facing |

---

### Domain: Payroll

#### P-1: `payroll_runs.pay_date` — Pay Run Date

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_23_100011_create_payroll_runs_table.php` |
| Type | `date`, NOT NULL |
| Model | `PayrollRun` — cast to `date` |
| Semantic | Date employees are paid. Future event when run is in draft. |
| Currently displayed | Yes — payroll run show page |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `payroll_run` |
| Visibility | **AGENCY** (payroll manager + admin) |
| Remind who | Payroll manager (must finalise before pay date) |
| Default thresholds | Green: >7d, Amber: 3–7d, Red: <3d or overdue |
| Default show_days | 30 |
| Default reminder_offsets | [7, 3, 1] |
| Risk if missed | Staff don't get paid on time |

#### P-2: `payroll_employee_earnings.effective_from` / `effective_to` — Earning Rate Changes

| Field | Value |
|-------|-------|
| **Should be on calendar** | **NO** — configuration effective dates, not events |

#### P-3: `payroll_employee_deductions.effective_from` / `effective_to`

| Field | Value |
|-------|-------|
| **Should be on calendar** | **NO** — same as P-2 |

#### P-4: `payroll_tax_tables.tax_year_start` / `tax_year_end` — Tax Year

| Field | Value |
|-------|-------|
| Semantic | SA tax year boundaries (1 March – 28 Feb) |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** (recurring annual) — tax year end is a hard deadline |
| Recommended `event_class` | `tax_year_end` |
| Visibility | **AGENCY** (payroll + admin + accountant) |
| Default thresholds | Green: >30d, Amber: 14–30d, Red: <14d |
| Default show_days | 60 |
| Default reminder_offsets | [60, 30, 14, 7] |

#### P-5: Computed — SARS EMP201 (7th of each month)

| Field | Value |
|-------|-------|
| Source | Not stored. SA payroll obligation: EMP201 due by 7th of following month. |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** (recurring monthly) |
| Recommended `event_class` | `sars_emp201` |
| Visibility | **AGENCY** (payroll + admin) |
| Default thresholds | Green: >5d, Amber: 2–5d, Red: <2d or overdue |
| Default show_days | 14 |
| Default reminder_offsets | [7, 3, 1] |
| Risk if missed | SARS penalties and interest |

#### P-6: Computed — SARS EMP501 Reconciliation (biannual: 31 May + 31 Oct)

| Field | Value |
|-------|-------|
| Source | Not stored. |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** (recurring biannual) |
| Recommended `event_class` | `sars_emp501` |
| Visibility | **AGENCY** (payroll + admin + accountant) |
| Default thresholds | Green: >30d, Amber: 14–30d, Red: <7d or overdue |
| Default show_days | 60 |
| Default reminder_offsets | [30, 14, 7, 3] |
| Risk if missed | SARS reconciliation penalties |

---

### Domain: Leave

#### L-1: `leave_applications.start_date` / `end_date` — Leave Period

| Field | Value |
|-------|-------|
| Currently on calendar | **YES** — `LeaveCalendarService` syncs approved leave to `CalendarEvent` (type `'leave'`) |
| Status | Already integrated. No action needed. |

#### L-2: `public_holidays.holiday_date` — Public Holidays

| Field | Value |
|-------|-------|
| Currently on calendar | **YES** — seeded by `SeedPublicHolidaysCommand` |
| Status | Already integrated. |

#### L-3: Leave starting/ending reminders

| Field | Value |
|-------|-------|
| Currently notified | **YES** — `SendLeaveRemindersCommand` (daily 06:00) fires `leave.starting_soon` and `leave.ending_soon` via `PillarEventNotification` |
| Status | Already integrated. |

#### L-4: `leave_entitlements.cycle_end_date` — Leave Cycle End

| Field | Value |
|-------|-------|
| Source | Migration `2026_04_29_000002_create_leave_entitlements_table.php` |
| Type | `date`, NOT NULL, indexed |
| Semantic | End of leave entitlement cycle — when forfeit rules kick in |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** (per employee) |
| Recommended `event_class` | `leave_cycle_end` |
| Visibility | **PERSONAL** (the employee) + **BRANCH** (BM for planning) |
| Default thresholds | Green: >30d, Amber: 14–30d, Red: <14d |
| Default show_days | 60 |
| Default reminder_offsets | [30, 14, 7] |
| Risk if missed | Employee forfeits accrued leave unknowingly |

#### L-5: Computed — Mandatory Leave Periods / Office Closures

| Field | Value |
|-------|-------|
| Source | Not stored. Typically Dec-Jan shutdown, manually scheduled. |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** — when feature exists (Tier 2 leave spec) |
| Recommended `event_class` | `office_closure` |
| Visibility | **SYSTEM** |
| Note | Deferred until Tier 2 leave module adds office closure management |

#### L-6: `leave_transactions.effective_date`

| Field | Value |
|-------|-------|
| **Should be on calendar** | **NO** — ledger entries, not events |

---

### Domain: Rentals / Leases

#### R-1: `rentals.lease_start_date` / `lease_end_date`

| Field | Value |
|-------|-------|
| Source | Migration `2026_02_10_080611_create_rentals_table.php` |
| Type | `date` |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** — `lease_end_date` only (start is historical) |
| Note | Partially overlaps with LP-2 (`properties.lease_end_date`) and R-2. Use `lease_records` as canonical source where available. |

#### R-2: `lease_records.lease_start_date` / `lease_end_date` — Signed Lease Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_02_26_600007_create_lease_records_table.php` |
| Type | `date`, NOT NULL, indexed |
| Model | `LeaseRecord` — methods: `daysUntilExpiry()`, `isExpired()`, `isExpiringSoon($days=90)`; scopes: `scopeExpiringSoon()`, `scopeExpired()` |
| Currently displayed | Yes — rental signatures dashboard, active leases, expired leases page, rental dashboard stat cards |
| Currently notified | Yes — `CheckLeaseExpiry` (daily 06:00) sends `LeaseExpirationAlert` at 90/60/30/0 day thresholds |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES — canonical lease expiry source** |
| Recommended `event_class` | `lease_expiry` (same class as LP-2, deduplicated by source) |
| Visibility | **BRANCH** (rental agent + BM) |
| Remind who | Rental agent (PERSONAL) + branch manager |
| Default thresholds | Green: >60d, Amber: 30–60d, Red: <30d or expired |
| Default show_days | 120 |
| Default reminder_offsets | [90, 60, 30, 14, 7] |
| Risk if missed | Lease lapses; tenant may leave without renewal; rental income gap |

#### R-3: `rental_amount_versions.effective_from` — Rent Escalation Date

| Field | Value |
|-------|-------|
| Source | Migration `2026_02_10_080641_create_rental_amount_versions_table.php` |
| Type | `date`, NOT NULL |
| Semantic | Date new rental amount takes effect |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `rent_escalation` |
| Visibility | **BRANCH** (rental agent + BM) |
| Default thresholds | Green: >14d, Amber: 7–14d, Red: <7d |
| Default show_days | 30 |
| Default reminder_offsets | [14, 7, 3] |
| Risk if missed | Tenant billed wrong amount; revenue leakage |

#### R-4: `docuperfect_documents.lease_expiry_date` — Document-Extracted Lease Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_02_27_000001_add_lease_expiry_to_docuperfect_documents.php` |
| Type | `date`, nullable |
| **Should be on calendar** | **NO** — use R-2 as canonical source. This is a document metadata field. |

#### R-5: Computed — Rent Due Date (monthly recurring)

| Field | Value |
|-------|-------|
| Source | Not stored as a date. Rent is due on the 1st of each month (SA standard). |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** — but as a recurring system event, not per-lease |
| Recommended `event_class` | `rent_due` |
| Visibility | **BRANCH** (rental portfolio manager) |
| Default thresholds | Green: >3d, Amber: 1–3d, Red: overdue |
| Default show_days | 7 |
| Default reminder_offsets | [3, 1] |

#### R-6: `commercial_evaluation_units.lease_start` / `lease_end` — Commercial Unit Leases

| Field | Value |
|-------|-------|
| Source | Migration `2026_02_25_600004_create_commercial_evaluation_units_table.php` |
| Type | `date`, nullable |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** — `lease_end` for commercial vacancy forecasting |
| Recommended `event_class` | `commercial_lease_expiry` |
| Visibility | **BRANCH** |
| Default thresholds | Same as R-2 |

---

### Domain: Documents / Signatures

#### DS-1: `signature_requests.token_expires_at` — Signing Link Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_02_26_600003_create_signature_requests_table.php` |
| Type | `timestamp`, NOT NULL |
| Model | `SignatureRequest` — methods: `isExpired()`, `daysUntilExpiry()`, `daysSinceSent()` |
| Currently displayed | Yes — external signing page "Expires: d M Y (X days remaining)" |
| Currently notified | Yes — `SendSignatureReminders` (daily 08:00) escalating at days 2/5/7/10; `ExpireSignatureRequests` (daily 07:00) auto-expires |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `signature_expiry` |
| Visibility | **PERSONAL** (the agent who initiated the signing) |
| Default thresholds | Green: >5d, Amber: 2–5d, Red: <2d or expired |
| Default show_days | 14 |
| Default reminder_offsets | [7, 3, 1] |
| Risk if missed | Document unsigned; deal/lease blocked |

#### DS-2: `sales_document_recipients.token_expires_at` — Sales Doc Token Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_02_26_700002_create_sales_document_recipients_table.php` |
| Type | `timestamp`, nullable |
| Currently notified | Yes — `SendSalesDocumentReminders` (daily 09:00) |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** — same pattern as DS-1 |
| Recommended `event_class` | `sales_doc_expiry` |
| Visibility | **PERSONAL** (sending agent) |
| Default thresholds | Green: >5d, Amber: 2–5d, Red: <2d |
| Default show_days | 14 |
| Default reminder_offsets | [7, 3, 1] |

#### DS-3: `knowledge_documents.expiry_date` — Knowledge Base Document Expiry

| Field | Value |
|-------|-------|
| Source | Migration `2026_02_25_000001_create_knowledge_base_tables.php` |
| Type | `date`, nullable |
| Model | `KnowledgeDocument` — accessor: `getIsExpiredAttribute()` |
| **Should be on calendar** | **NO** — internal KB management, not operational deadline |

#### DS-4: Computed — Mandate Expiry from eSign Wizard

| Field | Value |
|-------|-------|
| Source | `docuperfect/esign/wizard.blade.php` — "Mandate Expiry Date" field computed from `mandate_start + N months` |
| **Should be on calendar** | **NO** — this feeds `properties.expiry_date` (LP-1) on document completion. Use LP-1 as canonical. |

#### DS-5: `tv_messages.starts_at` / `ends_at` — TV Display Schedule

| Field | Value |
|-------|-------|
| Source | Migration `2026_02_10_034752_create_tv_messages_table.php` |
| Type | `timestamp`, nullable |
| **Should be on calendar** | **NO** — automated display scheduling, not user-facing event |

---

### Domain: Recurring Scheduled Operations

These are recurring system events that represent operational rhythms. They don't have a stored date — they recur on a cadence.

#### RS-1: Monthly Payroll Run

| Field | Value |
|-------|-------|
| Source | Payroll module. `payroll_runs.pay_date` when a run is created. |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** — covered by P-1 above |

#### RS-2: SARS EMP201 (7th of each month)

| Field | Value |
|-------|-------|
| Covered by P-5 above |

#### RS-3: SARS EMP501 (biannual: 31 May + 31 Oct)

| Field | Value |
|-------|-------|
| Covered by P-6 above |

#### RS-4: UIF Declaration (monthly)

| Field | Value |
|-------|-------|
| Source | Not stored. Due same time as EMP201 submission. |
| **Should be on calendar** | **YES** (recurring monthly) |
| Recommended `event_class` | `uif_declaration` |
| Visibility | **AGENCY** (payroll + admin) |
| Default thresholds | Green: >5d, Amber: 2–5d, Red: <2d |
| Default show_days | 14 |
| Default reminder_offsets | [7, 3, 1] |

#### RS-5: SDL Submission (monthly)

| Field | Value |
|-------|-------|
| Source | Not stored. |
| **Should be on calendar** | **YES** (recurring monthly) |
| Recommended `event_class` | `sdl_submission` |
| Visibility | **AGENCY** (payroll + admin) |
| Same thresholds as RS-4 |

#### RS-6: IRP5 Issue Deadline (after tax year end)

| Field | Value |
|-------|-------|
| Source | Not stored. Due ~60 days after tax year end (28 Feb). |
| **Should be on calendar** | **YES** (recurring annual) |
| Recommended `event_class` | `irp5_deadline` |
| Visibility | **AGENCY** |
| Default thresholds | Green: >30d, Amber: 14–30d, Red: <14d |
| Default show_days | 60 |
| Default reminder_offsets | [30, 14, 7, 3] |

#### RS-7: Target Carry-Forward (1st of each month)

| Field | Value |
|-------|-------|
| Source | `targets:carry-forward` — runs monthly on 1st at 00:05 |
| **Should be on calendar** | **NO** — automated background process, no human action needed |

#### RS-8: Leave Accrual / Cycle Rollover (daily)

| Field | Value |
|-------|-------|
| **Should be on calendar** | **NO** — background process |

#### RS-9: PPRA Trust Audit Report Submission

| Field | Value |
|-------|-------|
| Source | Not stored. PPRA requires annual trust audit report. |
| **Should be on calendar** | **YES** (recurring annual) |
| Recommended `event_class` | `ppra_trust_audit` |
| Visibility | **AGENCY** (admin + principal) |
| Default thresholds | Green: >60d, Amber: 30–60d, Red: <30d |
| Default show_days | 120 |
| Default reminder_offsets | [90, 60, 30, 14, 7] |
| Risk if missed | PPRA regulatory action against agency |

#### RS-10: Annual Increase Review

| Field | Value |
|-------|-------|
| Source | Not stored. Typically reviewed annually around employment anniversary or fiscal year. |
| **Should be on calendar** | **YES** — but tied to `employment_date` anniversary (AE-2) or a company-wide review date |
| Recommended `event_class` | `salary_review` |
| Visibility | **AGENCY** (HR admin) |
| Default thresholds | Green: >30d, Amber: 14–30d, Red: <14d |
| Default show_days | 60 |

#### RS-11: Contacts Birthday (recurring annual)

| Field | Value |
|-------|-------|
| Source | `contacts.birthday` — already scanned by `ScanContactNotifications` |
| Currently on calendar | **NO** |
| **Should be on calendar** | **YES** |
| Recommended `event_class` | `contact_birthday` |
| Visibility | **PERSONAL** (agent who created/manages the contact) |
| Default thresholds | Green: >7d, Amber: 1–7d, Red: today |
| Default show_days | 14 |
| Default reminder_offsets | [7, 1] |

#### RS-12: Property24 / PP Portal Sync (every 15 min)

| Field | Value |
|-------|-------|
| **Should be on calendar** | **NO** — automated background sync |

---

## Gap Analysis — Dates That SHOULD Exist But Don't

### Gap 1: No explicit "offer expiry" column on deals

**Problem:** SA OTPs typically have a 72-hour acceptance window, but there's no `offer_expires_at` column on `deals` or `deals_v2`. The V2 pipeline handles this via a step instance due date, but V1 deals have no expiry tracking at all.

**Impact:** V1 deals (still the live system) have no formal offer expiry warning.

**Recommendation:** Add `offer_expires_at` column to `deals_v2` (V2 is the future). For V1, accept the gap as V1 is being superseded. Priority: Medium.

### Gap 2: No "finance bond deadline" column

**Problem:** Bond approval is typically required within 21 days of OTP acceptance. In V2 this is tracked as a pipeline step (`due_date` on the bond step instance), which is correct. In V1, no tracking exists.

**Impact:** V2 handles this via D-5. V1 gap is acceptable given V2 transition.

**Recommendation:** No action for V1. Ensure V2 bond step always has a default `days_offset` of 21.

### Gap 3: No stored FICA expiry date — RESOLVED in build prompt 0a

**Problem:** FICA verification validity (~24 months) was not stored as an explicit date. `fica_submissions.verified_at` exists but `fica_expires_at` did not.

**Resolution:** Build prompt 0a (Phase 0 baseline) adds `fica_expires_at` column to `fica_submissions` with backfill from `verified_at + 24 months`, and updates all write paths to set this column whenever `verified_at` is written.

### Gap 4: No `pi_insurance_expiry` or `tax_clearance_expiry` oversight signals

**Problem:** `users.pi_insurance_expiry` and `users.tax_clearance_expiry` exist as columns, displayed on the agent portal, but `OversightService` only monitors `ffc_expiry_date`. No scan command, no notification, no calendar event for these two.

**Impact:** PI and tax clearance can expire silently with no system warning.

**Recommendation:** Add to `OversightService` signal list and wire to calendar. Priority: High (Group A).

### Gap 5: No training expiry notifications

**Problem:** `training_completions.expires_at` has scopes (`scopeExpiring()`, `scopeExpired()`) but no scheduled command scans for expiring training. No notification is sent.

**Impact:** Training certifications expire silently.

**Recommendation:** Add `notifications:scan-training` command or extend `ScanContactNotifications` to cover user training. Priority: Medium (Group A).

### Gap 6: No RMCP review due notifications

**Problem:** `rmcp_versions.next_review_due` is displayed on the RMCP dashboard but no scheduled command monitors it or sends reminders.

**Impact:** RMCP review deadline can be missed.

**Recommendation:** Add to oversight/scan infrastructure. Priority: Medium.

### Gap 7: No "deal target close date" on V1 deals

**Problem:** V1 `deals` table has no `target_close_date` or `expected_registration` equivalent. Only V2 has `expected_registration`.

**Recommendation:** V1 is being superseded. Accept the gap.

### Gap 8: No intern/PDE exam tracking

**Problem:** The prompt mentions intern logbook submission deadlines, PDE 4/PDE 5 exam due dates, CPD points cycle. None of these exist in the database.

**Recommendation:** Future module. Note for roadmap. Not addressable by current schema.

### Gap 9: No "compliance certificate" deadlines on deals

**Problem:** Electrical, gas, beetle compliance certificates required for property transfers have no explicit date columns. In V2, these would be pipeline step due dates. In V1, no tracking.

**Recommendation:** Ensure V2 pipeline templates include steps for each compliance certificate type with appropriate `days_offset`.

### Gap 10: No deposit refund deadline tracking

**Problem:** After tenant vacates, deposit refund has a statutory deadline. No column tracks this.

**Recommendation:** Add `deposit_refund_deadline` to `lease_records` or `rentals`. Compute from `lease_end_date + statutory days`. Priority: Low (Tier 2).

### Gap 11: CMA validity / presentation expiry

**Problem:** Presentations have no `expires_at` or `valid_until` date. CMAs are typically valid 30–60 days.

**Recommendation:** Add `valid_until` to `presentations` table. Priority: Low (Group C).

---

## Default Event Class Configurations

All values below are **seeder defaults** — agency-configurable via `/command-center/settings`.

Notification channel abbreviations: `ia` = in_app, `em` = email.

---

### GROUP A — Critical Operational (18 event classes)

---

#### #1 `mandate_expiry`
```
Source:         properties.expiry_date
Priority:       A
Label:          "Mandate Expiry"

Thresholds:     green=30, amber=14, red=7
show_days:      90

Visibility:
  green:        [agent]
  amber:        [agent, bm]
  red:          [agent, bm, admin]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em], bm: [ia, em], admin: [ia]}

Daily digest:   yes, roles: [bm, admin]
Notes:          Listing risk — escalates fast in final week. Lose the listing
                to another agency if not renewed.
```

#### #2 `lease_expiry`
```
Source:         lease_records.lease_end_date (canonical)
                Also: properties.lease_end_date, rentals.lease_end_date (do NOT
                duplicate — use lease_records where available, fall back to
                properties.lease_end_date for properties without a signed lease)
Priority:       A
Label:          "Lease Expiry"

Thresholds:     green=60, amber=30, red=14
show_days:      120

Visibility:
  green:        [agent]
  amber:        [agent, bm]
  red:          [agent, bm, admin]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em], bm: [ia, em]}

Daily digest:   yes, roles: [bm]
Notes:          Lease lapses to month-to-month unintentionally. Rental income
                at risk. Already has CheckLeaseExpiry notifications at
                90/60/30/0d — calendar adds visibility, digest adds BM oversight.
```

#### #3 `ffc_expiry`
```
Source:         users.ffc_expiry_date
Priority:       A — CRITICAL
Label:          "FFC Expiry"

Thresholds:     green=60, amber=30, red=14
show_days:      120

Visibility:
  green:        [agent]
  amber:        [agent, bm, compliance_officer]
  red:          [agent, bm, compliance_officer, admin]

Notifications:
  green:        {agent: [ia]}
  amber:        {agent: [ia, em], compliance_officer: [ia]}
  red:          {agent: [ia, em], bm: [ia, em], compliance_officer: [ia, em], admin: [ia]}

Daily digest:   yes, roles: [compliance_officer, admin]
Notes:          Agent CANNOT legally transact without valid FFC. PPRA
                non-compliance. All deals signed by expired agent are legally
                compromised. Widest notification net of any event class.
```

#### #4 `pi_insurance_expiry`
```
Source:         users.pi_insurance_expiry
Priority:       A — CRITICAL (currently has NO alerts at all)
Label:          "PI Insurance Expiry"

Thresholds:     green=60, amber=30, red=14
show_days:      120

Visibility:
  green:        [agent]
  amber:        [agent, compliance_officer]
  red:          [agent, compliance_officer, admin]

Notifications:
  green:        {}
  amber:        {agent: [ia], compliance_officer: [ia]}
  red:          {agent: [ia, em], compliance_officer: [ia, em], admin: [ia]}

Daily digest:   yes, roles: [compliance_officer]
Notes:          Agent operates without PI cover. Agency liability exposure.
                Currently has ZERO oversight — this is a gap.
```

#### #5 `tax_clearance_expiry`
```
Source:         users.tax_clearance_expiry
Priority:       A (currently has NO alerts at all)
Label:          "Tax Clearance Expiry"

Thresholds:     green=60, amber=30, red=14
show_days:      120

Visibility:
  green:        [agent]
  amber:        [agent, compliance_officer]
  red:          [agent, compliance_officer, admin]

Notifications:
  green:        {}
  amber:        {agent: [ia], compliance_officer: [ia]}
  red:          {agent: [ia, em], compliance_officer: [ia, em]}

Daily digest:   yes, roles: [compliance_officer]
Notes:          Cannot prove tax compliance. SARS issues. Currently ZERO
                oversight — gap.
```

#### #6 `deal_step_deadline`
```
Source:         deal_step_instances.due_date (only active, not completed/skipped)
Priority:       A
Label:          "Deal Pipeline Step Due"

Thresholds:     green=14, amber=7, red=3
                (Override: inherit from step's own rag_green_days/rag_amber_days/
                rag_red_days when available)
show_days:      rag_green_days + 7 (show when step activates)

Visibility:
  green:        [agent]
  amber:        [agent, bm]
  red:          [agent, bm, admin]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em], bm: [ia]}

Daily digest:   yes, roles: [bm]
Notes:          Bond deadline missed → deal collapses. Compliance cert missed →
                transfer blocked. Each active deal may have 5–15 step instances.
                Calendar shows only active steps.
```

#### #7 `deal_registration_target`
```
Source:         deals_v2.expected_registration
Priority:       A
Label:          "Target Registration Date"

Thresholds:     green=21, amber=10, red=5
show_days:      90

Visibility:
  green:        [agent]
  amber:        [agent, bm]
  red:          [agent, bm, admin]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em], bm: [ia, em]}

Daily digest:   yes, roles: [bm]
Notes:          Deal transfer delays, buyer/seller frustration, possible
                cancellation.
```

#### #8 `fica_renewal_due`
```
Source:         fica_submissions.fica_expires_at (added in build prompt 0a)
Priority:       A
Label:          "FICA Renewal Due"

Thresholds:     green=60, amber=30, red=14
show_days:      120

Visibility:
  green:        [agent]
  amber:        [agent, compliance_officer]
  red:          [agent, compliance_officer, admin]

Notifications:
  green:        {}
  amber:        {agent: [ia], compliance_officer: [ia]}
  red:          {agent: [ia, em], compliance_officer: [ia, em]}

Daily digest:   yes, roles: [compliance_officer]
Notes:          Contact's FICA lapses. Any deal involving this contact is
                non-compliant. Column added to fica_submissions in build
                prompt 0a, backfilled from verified_at + 24 months.
```

#### #9 `payroll_run`
```
Source:         payroll_runs.pay_date (when status = draft/processing)
Priority:       A
Label:          "Payroll Run"

Thresholds:     green=7, amber=3, red=1
show_days:      30

Visibility:
  green:        [payroll]
  amber:        [payroll, admin]
  red:          [payroll, admin]

Notifications:
  green:        {}
  amber:        {payroll: [ia]}
  red:          {payroll: [ia, em], admin: [ia, em]}

Daily digest:   yes, roles: [payroll, admin]
Notes:          Staff don't get paid on time. Payroll manager must finalise
                before pay date. Auto-purge from calendar once run is finalised.
```

#### #10 `sars_emp201`
```
Source:         Computed: 7th of each month (recurring)
Priority:       A
Label:          "SARS EMP201 Due"

Thresholds:     green=5, amber=3, red=1
show_days:      14

Visibility:
  green:        [payroll]
  amber:        [payroll, admin]
  red:          [payroll, admin]

Notifications:
  green:        {}
  amber:        {payroll: [ia]}
  red:          {payroll: [ia, em], admin: [ia]}

Daily digest:   yes, roles: [payroll, admin]
Notes:          Monthly SARS obligation. Penalties and interest if missed.
```

#### #11 `sars_emp501`
```
Source:         Computed: 31 May + 31 Oct (biannual recurring)
Priority:       A
Label:          "SARS EMP501 Reconciliation"

Thresholds:     green=30, amber=14, red=7
show_days:      60

Visibility:
  green:        [payroll]
  amber:        [payroll, admin, accountant]
  red:          [payroll, admin, accountant]

Notifications:
  green:        {}
  amber:        {payroll: [ia], admin: [ia]}
  red:          {payroll: [ia, em], admin: [ia, em], accountant: [ia, em]}

Daily digest:   yes, roles: [payroll, admin, accountant]
Notes:          Biannual SARS reconciliation. More complex than EMP201, hence
                wider window.
```

#### #12 `rmcp_review_due`
```
Source:         rmcp_versions.next_review_due
Priority:       A (currently has NO alerts — gap)
Label:          "RMCP Review Due"

Thresholds:     green=60, amber=30, red=14
show_days:      120

Visibility:
  green:        [compliance_officer]
  amber:        [compliance_officer, admin]
  red:          [compliance_officer, admin]

Notifications:
  green:        {}
  amber:        {compliance_officer: [ia]}
  red:          {compliance_officer: [ia, em], admin: [ia, em]}

Daily digest:   yes, roles: [compliance_officer, admin]
Notes:          PPRA compliance breach if missed. Agency-level regulatory
                finding.
```

#### #13 `screening_due`
```
Source:         employee_screenings.next_due_on
Priority:       A (currently has NO alerts — gap)
Label:          "Employee Screening Due"

Thresholds:     green=60, amber=30, red=14
show_days:      90

Visibility:
  green:        [compliance_officer]
  amber:        [compliance_officer, hr]
  red:          [compliance_officer, hr, admin]

Notifications:
  green:        {}
  amber:        {compliance_officer: [ia]}
  red:          {compliance_officer: [ia, em], hr: [ia, em]}

Daily digest:   yes, roles: [compliance_officer, hr]
Notes:          Periodic background screening renewal. Frequency depends on
                risk tier (high=1yr, medium=3yr, low=5yr).
```

#### #14 `ppra_trust_audit`
```
Source:         Computed: annual (agency-specific date)
Priority:       A
Label:          "PPRA Trust Audit Report"

Thresholds:     green=60, amber=30, red=14
show_days:      120

Visibility:
  green:        [admin]
  amber:        [admin]
  red:          [admin]

Notifications:
  green:        {}
  amber:        {admin: [ia]}
  red:          {admin: [ia, em]}

Daily digest:   yes, roles: [admin]
Notes:          PPRA requires annual trust account audit report. Regulatory
                action against agency if missed.
```

#### #15 `training_expiry`
```
Source:         training_completions.expires_at
Priority:       A (currently has NO notifications — gap)
Label:          "Training Certification Expiry"

Thresholds:     green=30, amber=14, red=7
show_days:      60

Visibility:
  green:        [agent]
  amber:        [agent, bm]
  red:          [agent, bm, compliance_officer]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em], bm: [ia]}

Daily digest:   yes, roles: [bm, compliance_officer]
Notes:          Agent CPD non-compliance. PPRA audit finding.
```

#### #16 `compliance_provision_expiry`
```
Source:         agency_compliance_provisions.effective_until
Priority:       A
Label:          "Compliance Provision Expiry"

Thresholds:     green=60, amber=30, red=14
show_days:      120

Visibility:
  green:        [compliance_officer]
  amber:        [compliance_officer, admin]
  red:          [compliance_officer, admin]

Notifications:
  green:        {}
  amber:        {compliance_officer: [ia]}
  red:          {compliance_officer: [ia, em], admin: [ia, em]}

Daily digest:   yes, roles: [compliance_officer, admin]
Notes:          Agency-level regulatory provision. Gap in compliance posture
                when expired.
```

#### #17 `compliance_override_expiry`
```
Source:         user_compliance_overrides.expires_at
Priority:       A
Label:          "Compliance Override Expiry"

Thresholds:     green=14, amber=7, red=3
show_days:      30

Visibility:
  green:        [compliance_officer]
  amber:        [compliance_officer]
  red:          [compliance_officer, admin]

Notifications:
  green:        {}
  amber:        {compliance_officer: [ia]}
  red:          {compliance_officer: [ia, em]}

Daily digest:   no
Notes:          When override expires, underlying compliance requirement
                re-activates. Narrow window — overrides are temporary by
                nature.
```

#### #18 `agent_document_expiry`
```
Source:         user_documents.expiry_date (where has_expiry = true per
                agency_document_type_configs)
Priority:       A
Label:          "Agent Document Expiry"

Thresholds:     green=60, amber=30, red=14
show_days:      Per agency_document_type_configs.renewal_days if set, else 90

Visibility:
  green:        [agent]
  amber:        [agent, compliance_officer]
  red:          [agent, compliance_officer, admin]

Notifications:
  green:        {}
  amber:        {agent: [ia], compliance_officer: [ia]}
  red:          {agent: [ia, em], compliance_officer: [ia, em]}

Daily digest:   yes, roles: [compliance_officer]
Notes:          Generic document renewal. Already has renewal_days config per
                document type — calendar should honour existing settings.
```

---

### GROUP B — Important Workflow (13 event classes)

---

#### #19 `property_showday`
```
Source:         property_showdays.start_date
Priority:       B
Label:          "Show Day / Open House"

Thresholds:     green=3, amber=1, red=0 (today)
show_days:      30

Visibility:
  green:        [agent]
  amber:        [agent, bm]
  red:          [agent, bm]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia]}

Daily digest:   no
Notes:          Tight window — tactical event. Missed open house = missed
                buyer leads. Calendar entry is the primary reminder mechanism.
```

#### #20 `signature_expiry`
```
Source:         signature_requests.token_expires_at (active requests only)
Priority:       B
Label:          "Signature Request Expiry"

Thresholds:     green=5, amber=2, red=1
show_days:      14

Visibility:
  green:        [agent]
  amber:        [agent]
  red:          [agent, bm]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em]}

Daily digest:   no
Notes:          Already has escalating reminder emails (day 2/5/7/10 via
                SendSignatureReminders). Calendar adds visibility. Team alert
                already fires at day 7 via SignatureTeamAlert.
```

#### #21 `sales_doc_expiry`
```
Source:         sales_document_recipients.token_expires_at
Priority:       B
Label:          "Sales Document Expiry"

Thresholds:     green=5, amber=2, red=1
show_days:      14

Visibility:
  green:        [agent]
  amber:        [agent]
  red:          [agent, bm]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em]}

Daily digest:   no
Notes:          Same pattern as signature_expiry. Already has email reminders
                via SendSalesDocumentReminders.
```

#### #22 `portal_listing_expiry`
```
Source:         listing_stocks.expires_at
Priority:       B
Label:          "Portal Listing Expiry"

Thresholds:     green=14, amber=5, red=2
show_days:      30

Visibility:
  green:        [agent]
  amber:        [agent]
  red:          [agent, bm]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em]}

Daily digest:   no
Notes:          Listing drops off P24/PP portals. Buyer exposure lost. Agent
                needs to renew/refresh before expiry.
```

#### #23 `rent_escalation`
```
Source:         rental_amount_versions.effective_from
Priority:       B
Label:          "Rent Escalation Effective"

Thresholds:     green=14, amber=7, red=3
show_days:      30

Visibility:
  green:        [agent]
  amber:        [agent, bm]
  red:          [agent, bm]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em], bm: [ia]}

Daily digest:   no
Notes:          Tenant billed wrong amount if escalation not applied on time.
                Revenue leakage risk.
```

#### #24 `rent_due`
```
Source:         Computed: 1st of each month (recurring)
Priority:       B
Label:          "Rent Due Date"

Thresholds:     green=3, amber=1, red=0 (overdue)
show_days:      7

Visibility:
  green:        [agent]
  amber:        [agent]
  red:          [agent, bm]

Notifications:
  green:        {}
  amber:        {}
  red:          {agent: [ia], bm: [ia]}

Daily digest:   no
Notes:          Monthly recurring. Short lead-in. Post-due auto-purges after
                tenant pays (or escalates to arrears process).
```

#### #25 `commercial_lease_expiry`
```
Source:         commercial_evaluation_units.lease_end
Priority:       B
Label:          "Commercial Lease Expiry"

Thresholds:     green=60, amber=30, red=14
show_days:      120

Visibility:
  green:        [agent]
  amber:        [agent, bm]
  red:          [agent, bm, admin]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em], bm: [ia]}

Daily digest:   yes, roles: [bm]
Notes:          Commercial vacancy forecasting. Higher revenue impact than
                residential.
```

#### #26 `leave_cycle_end`
```
Source:         leave_entitlements.cycle_end_date
Priority:       B
Label:          "Leave Cycle End"

Thresholds:     green=30, amber=14, red=7
show_days:      60

Visibility:
  green:        [agent]
  amber:        [agent, bm]
  red:          [agent, bm]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em]}

Daily digest:   no
Notes:          Employee forfeits accrued leave unknowingly if they don't
                use it before cycle end.
```

#### #27 `employee_termination`
```
Source:         payroll_employees.termination_date
Priority:       B
Label:          "Employee Last Day"

Thresholds:     green=14, amber=7, red=3
show_days:      30

Visibility:
  green:        [hr]
  amber:        [hr, payroll, admin]
  red:          [hr, payroll, admin, bm]

Notifications:
  green:        {}
  amber:        {hr: [ia], payroll: [ia]}
  red:          {hr: [ia, em], payroll: [ia, em], admin: [ia]}

Daily digest:   yes, roles: [hr, payroll]
Notes:          Final payroll, leave payout, system access revocation,
                equipment return all keyed off this date.
```

#### #28 `tax_year_end`
```
Source:         Computed: 28 Feb annually
Priority:       B
Label:          "Tax Year End"

Thresholds:     green=30, amber=14, red=7
show_days:      60

Visibility:
  green:        [payroll]
  amber:        [payroll, admin, accountant]
  red:          [payroll, admin, accountant]

Notifications:
  green:        {}
  amber:        {payroll: [ia], admin: [ia]}
  red:          {payroll: [ia, em], admin: [ia, em], accountant: [ia, em]}

Daily digest:   yes, roles: [payroll, admin, accountant]
Notes:          28 Feb is a hard deadline. Tax year end triggers IRP5 issuance,
                EMP501, annual reconciliation.
```

#### #29 `uif_declaration`
```
Source:         Computed: 7th of each month (recurring, same as EMP201)
Priority:       B
Label:          "UIF Declaration Due"

Thresholds:     green=5, amber=3, red=1
show_days:      14

Visibility:
  green:        [payroll]
  amber:        [payroll, admin]
  red:          [payroll, admin]

Notifications:
  green:        {}
  amber:        {payroll: [ia]}
  red:          {payroll: [ia, em]}

Daily digest:   yes, roles: [payroll]
Notes:          Monthly UIF declaration to Department of Employment and Labour.
```

#### #30 `sdl_submission`
```
Source:         Computed: 7th of each month (recurring)
Priority:       B
Label:          "SDL Submission Due"

Thresholds:     green=5, amber=3, red=1
show_days:      14

Visibility:
  green:        [payroll]
  amber:        [payroll, admin]
  red:          [payroll, admin]

Notifications:
  green:        {}
  amber:        {payroll: [ia]}
  red:          {payroll: [ia, em]}

Daily digest:   yes, roles: [payroll]
Notes:          Skills Development Levy submission. Same cadence as UIF.
```

#### #31 `irp5_deadline`
```
Source:         Computed: ~60 days after tax year end (annual)
Priority:       B
Label:          "IRP5 Issue Deadline"

Thresholds:     green=30, amber=14, red=7
show_days:      60

Visibility:
  green:        [payroll]
  amber:        [payroll, admin]
  red:          [payroll, admin, accountant]

Notifications:
  green:        {}
  amber:        {payroll: [ia]}
  red:          {payroll: [ia, em], admin: [ia, em]}

Daily digest:   yes, roles: [payroll, admin]
Notes:          IRP5 certificates must be issued to employees after tax year
                end. SARS requirement.
```

---

### GROUP C — Nice to Have (7 event classes)

---

#### #32 `employment_anniversary`
```
Source:         Computed: users.employment_date + N years (annual recurring)
Priority:       C
Label:          "Employment Anniversary"

Thresholds:     green=7, amber=3, red=0 (today)
show_days:      14

Visibility:
  green:        [agent, bm]
  amber:        [agent, bm]
  red:          [agent, bm]

Notifications:
  green:        {}
  amber:        {bm: [ia]}
  red:          {}

Daily digest:   no
Notes:          Culture/retention milestone. Low operational risk but
                meaningful for team morale.
```

#### #33 `agent_birthday`
```
Source:         Computed: users.date_of_birth (annual recurring)
Priority:       C
Label:          "Agent Birthday"

Thresholds:     green=7, amber=3, red=0 (today)
show_days:      14

Visibility:
  green:        [bm]
  amber:        [bm]
  red:          [bm]

Notifications:
  green:        {}
  amber:        {bm: [ia]}
  red:          {}

Daily digest:   no
Notes:          Branch manager sees upcoming birthdays for team. Agent sees
                their own. No email blast.
```

#### #34 `contact_birthday`
```
Source:         contacts.birthday (annual recurring)
Priority:       C
Label:          "Contact Birthday"

Thresholds:     green=7, amber=3, red=0 (today)
show_days:      14

Visibility:
  green:        [agent]
  amber:        [agent]
  red:          [agent]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {}

Daily digest:   no
Notes:          Personal relationship building. Agent who manages/created
                the contact sees it. Already scanned by
                ScanContactNotifications.
```

#### #35 `rmcp_ack_expiry`
```
Source:         rmcp_acknowledgements.valid_until
Priority:       C
Label:          "RMCP Acknowledgement Expiry"

Thresholds:     green=30, amber=14, red=7
show_days:      60

Visibility:
  green:        [agent]
  amber:        [agent, compliance_officer]
  red:          [agent, compliance_officer, admin]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em], compliance_officer: [ia]}

Daily digest:   yes, roles: [compliance_officer]
Notes:          Agent must re-acknowledge RMCP when their acknowledgement
                expires. Compliance officer tracks completion.
```

#### #36 `salary_review`
```
Source:         Computed: annual (company-wide or per employment_date anniversary)
Priority:       C
Label:          "Annual Salary Review"

Thresholds:     green=30, amber=14, red=7
show_days:      60

Visibility:
  green:        [hr]
  amber:        [hr, admin]
  red:          [hr, admin]

Notifications:
  green:        {}
  amber:        {hr: [ia]}
  red:          {hr: [ia, em], admin: [ia]}

Daily digest:   no
Notes:          Internal HR planning. Low urgency but important for
                retention and budgeting.
```

#### #37 `filed_document_expiry`
```
Source:         document_filing_register.expiry_date (non-mandate documents only;
                mandate docs use #1 mandate_expiry to avoid duplication)
Priority:       C
Label:          "Filed Document Expiry"

Thresholds:     green=30, amber=14, red=7
show_days:      60

Visibility:
  green:        [agent]
  amber:        [agent]
  red:          [agent, bm]

Notifications:
  green:        {}
  amber:        {agent: [ia]}
  red:          {agent: [ia, em]}

Daily digest:   no
Notes:          Generic document expiry in filing register. Filtered to exclude
                mandate documents (covered by mandate_expiry).
```

#### #38 `office_closure`
```
Source:         Future: Tier 2 leave module (not yet built)
Priority:       C
Label:          "Office Closure"

Thresholds:     green=14, amber=7, red=3
show_days:      30

Visibility:
  green:        [agent, bm, admin, payroll, compliance_officer, hr]
  amber:        [agent, bm, admin, payroll, compliance_officer, hr]
  red:          [agent, bm, admin, payroll, compliance_officer, hr]

Notifications:
  green:        {}
  amber:        {}
  red:          {}

Daily digest:   no
Notes:          SYSTEM-level visibility — everyone sees it. No notifications
                needed (informational). Deferred until Tier 2 leave adds
                office closure management.
```

---

## Priority Groups

### GROUP A — Critical Operational (Build First)

These prevent **business loss, regulatory breach, or financial penalty** if missed.

1. **FFC expiry** (#3) — agent cannot legally transact
2. **PI insurance expiry** (#4) — agency liability exposure (**currently has NO alerts**)
3. **Tax clearance expiry** (#5) — SARS compliance (**currently has NO alerts**)
4. **FICA renewal** (#8) — deal compliance breach (column added in 0a)
5. **Mandate expiry** (#1) — lose listing to competitor
6. **Lease expiry** (#2) — rental income disruption
7. **Deal step deadlines** (#6) — bond/transfer/compliance deadlines
8. **Deal registration target** (#7) — deal completion tracking
9. **Payroll run date** (#9) — staff don't get paid
10. **SARS EMP201** (#10) — monthly penalty risk
11. **SARS EMP501** (#11) — biannual penalty risk
12. **RMCP review** (#12) — PPRA regulatory action
13. **Employee screening** (#13) — compliance audit finding
14. **PPRA trust audit** (#14) — agency-level regulatory risk
15. **Training expiry** (#15) — CPD non-compliance
16. **Compliance provision expiry** (#16) — regulatory gap
17. **Compliance override expiry** (#17) — requirement re-activation
18. **Agent document expiry** (#18) — generic document renewal

### GROUP B — Important Workflow (Build Second)

These improve **daily operational efficiency**.

19. Show days (#19)
20. Signature expiry (#20)
21. Sales doc expiry (#21)
22. Portal listing expiry (#22)
23. Rent escalation (#23)
24. Rent due (#24)
25. Commercial lease expiry (#25)
26. Leave cycle end (#26)
27. Employee termination (#27)
28. Tax year end (#28)
29–31. UIF/SDL/IRP5 (#29–31)

### GROUP C — Nice to Have (Build Third)

Visibility-only, less time-critical.

32–38. Anniversaries, birthdays, RMCP ack, salary review, filed docs, office closures

---

## Architecture Recommendation: How to Wire Sources to Calendar

### Recommended: Option 2 — CalendarSubject Interface + Per-Domain Source Services

**Neither pure per-model observers (too much boilerplate) nor a single nightly cron (too stale). A hybrid approach.**

#### Design

```
CalendarSubjectContract (interface)
├── getCalendarEvents(): Collection<PendingCalendarEvent>
│   Returns date, title, event_class, visibility, source_type, source_id, metadata
│
CalendarSourceService (abstract)
├── PropertyCalendarSource    → mandate_expiry, lease_expiry, showdays, portal_expiry
├── DealCalendarSource        → deal_step_deadline, deal_registration_target
├── ComplianceCalendarSource  → ffc_expiry, pi_expiry, tax_expiry, fica_renewal, rmcp, screening, training
├── PayrollCalendarSource     → payroll_run, sars_emp201, emp501, uif, sdl, irp5, tax_year_end
├── RentalCalendarSource      → lease_expiry (lease_records), rent_escalation, rent_due, commercial_lease
├── DocumentCalendarSource    → signature_expiry, sales_doc_expiry, filed_doc_expiry
├── PeopleCalendarSource      → agent_birthday, contact_birthday, employment_anniversary, termination, leave_cycle
├── RecurringCalendarSource   → ppra_trust_audit, salary_review, office_closure
```

#### How it works

1. **On model create/update** — each domain Source Service listens to Eloquent model events (`created`, `updated`) via Laravel observers. When a calendar-relevant date changes, it calls `CalendarEventService::createFromSource()` (existing method on Andre's service) which upserts the corresponding `CalendarEvent`.

2. **CalendarEventService::createFromSource()** — already exists. Accepts source model + payload. Finds existing event by `(source_type, source_id, category)`. If the date changed, updates it. If the record was soft-deleted, removes the event. This is idempotent.

3. **CalendarThresholdResolver** — called on every calendar render (not on event creation). Returns the current colour based on agency thresholds. This means colour transitions happen automatically as time passes — no cron needed for colour updates.

4. **Nightly reconciliation job** — `corex:calendar:reconcile` runs at 03:00. Scans all Source Services, compares their output against existing `calendar_events`, and fixes any drift (events that were missed by observers, source dates that changed via raw queries, etc.). This is the safety net, not the primary mechanism.

5. **Recurring events** — `RecurringCalendarSource` generates computed events (SARS deadlines, birthdays, anniversaries) for the next 12 months. The nightly reconciliation refreshes these.

#### Why this scales to 50+ sources

- Adding a new source = one new method in the relevant Source Service + one row in the threshold seeder
- No per-model observer classes needed (the Source Service IS the observer)
- Colour logic lives in one place (ThresholdResolver)
- Threshold changes apply immediately (settings, not code)
- Reconciliation job catches edge cases without being the primary mechanism
- Each Source Service can be tested independently

#### Files to create (when building)

```
Phase 0 — Infrastructure:
  database/migrations/XXXX_add_fica_expires_at_to_fica_submissions_table.php
  database/migrations/XXXX_drop_command_reminder_defaults_table.php
  database/migrations/XXXX_create_calendar_event_class_settings_table.php
  app/Models/CommandCenter/CalendarEventClassSetting.php
  database/seeders/CalendarEventClassSeeder.php
  app/Services/CommandCenter/Calendar/CalendarThresholdResolver.php
  app/Services/CommandCenter/Calendar/CalendarVisibilityResolver.php
  app/Services/CommandCenter/Calendar/CalendarNotificationDispatcher.php
  app/Console/Commands/CommandCenter/SendCalendarDigests.php  (daily 06:30)
  app/Console/Commands/CommandCenter/ReconcileCalendarEvents.php (nightly 03:00)
  resources/views/command-center/settings/index.blade.php (UPDATE: replace Reminder Defaults section)
  + Add BelongsToAgency + BelongsToBranch traits to CalendarEvent model (multi-tenancy fix)

Phase 1 — Source Services:
  app/Contracts/CalendarSourceContract.php
  app/Services/CommandCenter/Calendar/Sources/PropertyCalendarSource.php
  app/Services/CommandCenter/Calendar/Sources/DealCalendarSource.php
  app/Services/CommandCenter/Calendar/Sources/ComplianceCalendarSource.php
  app/Services/CommandCenter/Calendar/Sources/PayrollCalendarSource.php
  app/Services/CommandCenter/Calendar/Sources/RentalCalendarSource.php
  app/Services/CommandCenter/Calendar/Sources/DocumentCalendarSource.php
  app/Services/CommandCenter/Calendar/Sources/PeopleCalendarSource.php
  app/Services/CommandCenter/Calendar/Sources/RecurringCalendarSource.php

Phase 2 — Daily Digest:
  app/Mail/CalendarDailyDigest.php
  resources/views/emails/calendar-daily-digest.blade.php
```

---

## Unexpected Findings

1. **PI insurance and tax clearance have NO oversight signals at all.** `OversightService` monitors FFC expiry but completely skips `pi_insurance_expiry` and `tax_clearance_expiry`. These can expire silently today.

2. **Lease expiry data exists in FOUR places:** `rentals.lease_end_date`, `lease_records.lease_end_date`, `properties.lease_end_date`, and `docuperfect_documents.lease_expiry_date`. Only `lease_records` has a scheduled checker (`CheckLeaseExpiry`). The calendar should use `lease_records` as canonical and ignore the others to avoid duplicate events.

3. **Hardcoded durations scattered across codebase:** ProspectingClaim expiry (48h), signature token expiry (14d), RMCP acknowledgement validity (1 year), screening renewal (1/3/5 years by risk) — all hardcoded in model/service methods rather than configurable settings.

4. **Existing `command_reminder_defaults` table is empty (0 rows) and uses an offset model** that does not match the threshold model in this spec. The Phase 0 work drops this table and replaces it with `calendar_event_class_settings`. The existing settings page section "Reminder Defaults" is replaced with "Event Classes".

5. **`ScanContactNotifications` fires `contact.birthday`** but there's no equivalent for agent/user birthdays. Agent birthdays (`users.date_of_birth`) are only used for payroll calculations, never surfaced as a calendar/social event.

6. **No calendar events are created by ANY scheduled command.** Despite the infrastructure existing (`CalendarEventService::createFromSource`), the only automated calendar entries come from `LeaveCalendarService` (leave approvals) and `BackfillCalendarEvents` (one-time). The calendar is currently almost entirely manual.

7. **`CalendarEvent` model does NOT use `BelongsToAgency` or `BelongsToBranch` traits** despite having both columns. This is a multi-tenancy safety bug that must be fixed in Phase 0 before adding 30+ source services that all create events. Build prompt 0b addresses this.

---

## Report Statistics

- **Total date/datetime columns audited:** 150+ across 60+ tables
- **Calendar-relevant date sources identified:** 75
- **Currently flowing to calendar:** 3 (leave applications, public holidays, leave reminders)
- **Recommended for calendar integration:** 55 (38 distinct `event_class` types)
- **Full event class configurations defined:** 38 (with per-colour visibility, notification routing, daily digest)
- **Critical gaps (no alerts at all):** PI insurance expiry, tax clearance expiry, training expiry, RMCP review, FICA renewal
- **Priority Group A (critical):** 18 event classes
- **Priority Group B (workflow):** 13 event classes
- **Priority Group C (nice-to-have):** 7 event classes
- **Schema gaps identified:** 11 (documented in Gap Analysis)
- **Event classes with daily digest enabled:** 22 of 38
- **Recommended architecture:** CalendarSubject + Per-Domain Source Services + CalendarThresholdResolver + CalendarVisibilityResolver + CalendarNotificationDispatcher + Daily Digest Worker
- **New infrastructure tables:** 1 (`calendar_event_class_settings`)
- **Tables dropped:** 1 (`command_reminder_defaults` — empty, deprecated)
- **New services:** 3 (ThresholdResolver, VisibilityResolver, NotificationDispatcher)
- **New commands:** 2 (SendCalendarDigests, ReconcileCalendarEvents)
- **Files to create:** ~25 across Phase 0 + Phase 1 + Phase 2

---

## Build Sequence

### Phase 0 — Infrastructure (6 prompts)
- **0a** — Add `fica_expires_at` to `fica_submissions` + backfill from `verified_at + 24 months` + update all write paths
- **0b** — Add `BelongsToAgency` + `BelongsToBranch` traits to `CalendarEvent` model (multi-tenancy fix)
- **0c** — Drop `command_reminder_defaults`, create `calendar_event_class_settings` table + model + 38-row seeder
- **0d** — Three services: `CalendarThresholdResolver`, `CalendarVisibilityResolver`, `CalendarNotificationDispatcher`
- **0e** — Settings UI: replace "Reminder Defaults" section in `/command-center/settings` with "Event Classes" section
- **0f** — `corex:calendar:send-digests` (daily 06:30) + Mailable + email template + `corex:calendar:reconcile` (nightly 03:00)

### Phase 1 — Source Services (8 prompts, priority order so Group A classes light up first)
- **1a** — ComplianceCalendarSource (FFC, PI, tax clearance, FICA renewal, RMCP, screening, training, agent docs, compliance provisions/overrides)
- **1b** — DealCalendarSource (step deadlines, registration target)
- **1c** — PropertyCalendarSource (mandate, property lease, showday, portal expiry, filed docs)
- **1d** — RentalCalendarSource (lease records, rent escalation, rent due, commercial lease)
- **1e** — PayrollCalendarSource (payroll runs, EMP201, EMP501, UIF, SDL, IRP5, tax year end)
- **1f** — DocumentCalendarSource (signature expiry, sales doc expiry)
- **1g** — PeopleCalendarSource (birthdays, anniversaries, termination, leave cycle end)
- **1h** — RecurringCalendarSource (PPRA trust audit, salary review, office closure)

---

## Pre-build Architectural Decisions (locked 2026-04-30)

1. `event_class` IS `category` on existing `calendar_events` table. No schema change to that table.
2. `command_reminder_defaults` (empty, deprecated offset model) is dropped during Phase 0c.
3. Settings UI lives at `/command-center/settings` (existing page). The audit's reference to `/corex/settings?tab=feature&fsec=...` is corrected — that route does not exist.
4. `ProcessReminders` (every 15 min) keeps running on its existing UserDashboardSetting window model — separate concern from class-level threshold transitions. No collision.
5. `CalendarNotificationDispatcher` reuses the existing `App\Services\Notifications\NotificationDispatcher` (108 lines). Does NOT re-implement notification routing.
6. `notification_event_types` table (26 rows, user-level prefs) is NOT modified. Two systems are orthogonal: user prefs vs agency policy.
7. `fica_expires_at` column added to `fica_submissions` in build prompt 0a (resolves Gap #3).
8. `CalendarEvent` model gets `BelongsToAgency` + `BelongsToBranch` traits in build prompt 0b (multi-tenancy safety fix).
