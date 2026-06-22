# Atlas — Rentals / Leases

> **Status: DONE** · Last verified: 2026-06-22
> Pillars: should be Property × Contact × Deal — but **largely an island** (see §9). Companion spec:
> `.ai/specs/rental-documents-spec.md`. Roadmap: `.ai/ROADMAP.md`.

---

## 1. WHAT IT DOES

Manages residential/commercial rental mandates and lease documents. **There are TWO separate, only-loosely-
coupled subsystems** under the "Rentals" banner — they share no table or model:

| | **System A — Rental Management** (commission) | **System B — Rental Division** (eSign lease lifecycle) |
|---|---|---|
| Routes | `rentals.*` | `rental.*` |
| Controller | `RentalsController` | `Rental\RentalDivisionController` |
| Model | `App\Models\Rental` (`rentals`) | `Docuperfect\LeaseRecord` (`lease_records`) |
| Purpose | commission tracking on rental mandates (rent/commission versions, agent splits, worksheet) | document-driven lease lifecycle from the eSign engine (active/expired/renew/terminate) |

They converge only inside `RentalCalendarSource` (§6). A third property store, `rental_properties`, exists
separately from the real `properties` pillar.

---

## 2. ENTRY POINTS

### Routes (`routes/web.php`)
- **System A** (`permission:view_rentals`): `rentals.index` `:1002`, `.create` `:1005`, `.edit` `:1008`,
  `.store` `:1011`, `.update` `:1014`; permissions `rentals.permissions*` `:1029-1033`. Controller
  `RentalsController` (`store` `:309-370`).
- **System B** (`prefix('rental')`, `:3038-3066`): `rental.dashboard` `:3039`, `rental.signatures` `:3040`
  (+assign-metadata/set-expiry `:3041-3042`), `rental.active-leases` `:3043`, `rental.expired-leases`
  `:3044`, `rental.settings` `:3045` (+properties/document-types/reminders `:3050-3066`). Controller
  `Rental\RentalDivisionController:13`.
- **Lease lifecycle** (under DocuPerfect prefix, NOT rental): `docuperfect.leases.index` `:3014`,
  `.renew`/`.terminate`/`.history` `:3017-3019` → `Docuperfect\LeaseController:13`.

Views: System A `resources/views/rentals/{index,edit,permissions}.blade.php`; System B
`resources/views/rental/{dashboard,signatures,active-leases,expired-leases,settings}.blade.php`. Nav:
System A under Worksheet/Sales (`corex-sidebar.blade.php:689`); System B its own "Rentals" panel `:923-939`
(`rental.settings` has **no sidebar link** — reachable only from dashboard). Permissions in
`config/corex-permissions.php` (`access_rental_signatures` `:52`, `view_rentals` `:64`, `manage_rentals` `:65`).

---

## 3. THE FLOW — lease lifecycle (System B)

- **Creation:** auto-created from a **completed signature** — `SignatureService.php:1738-1739` calls
  `createLeaseRecord()` (`:2561-2603`) when `isLeaseDocument($template)` (keyword match lease/rental/tenancy
  /rent, `:2547-2550`); extracts parties from `parties_json`, defaults `lease_end_date` to +1 year `:2585`,
  `status=active` `:2586`; writes `lease_record_created` audit `:2589`. System A leases are manual via
  `RentalsController@store:309-370` (creates `Rental` + first `RentalAmountVersion` + agent sync).
- **Active/Expired:** `RentalDivisionController@activeLeases:135` (status in active/expiring_soon),
  `@expiredLeases:151`. Status cron `CheckLeaseExpiry` (`signatures:check-lease-expiry`): active→expired
  `:29-40`, active→expiring_soon within 90d `:57-72`, tiered alerts 90/60/30/0 `:93-101`, **in-app only,
  no email** `:130`.
- **Renew/Terminate:** `LeaseController@renewLease:25-95` (replicates doc, old→renewed, new start=old end,
  end=+1yr, links previous/renewed, audit `lease_renewed`); `@terminateLease:100-130` (status `terminated`,
  audit only — **termination_date validated but NOT persisted** `:107-109`).
- **Rent escalation:** System A only, via `RentalAmountVersion.effective_from` rows; **no first-class
  escalation field** on either model. **Deposit:** not on either model — lives only as
  `properties.deposit_amount` (migration `2026_03_07_200001:17`).

---

## 4. DATA IT READS / WRITES

| Model / table | Key columns | SoftDeletes / audit |
|---------------|-------------|---------------------|
| `Rental` (`rentals`) | address-string only: `lease_address`, start/end, `is_month_to_month`, `is_active`, `branch_id`, `created_by_user_id` — **no property/contact/agency/rent FK** | SoftDeletes `:13`; **no audit** |
| `RentalAmountVersion` | rent_incl/excl, commission_incl/excl, effective_from | — |
| `LeaseRecord` (`lease_records`) | `property_id` (nullable), `tenant_name/email`, `landlord_name/email` (strings, **not Contact FKs**), `rental_amount`, start/end, `status`, `previous_lease_id`/`renewed_lease_id` | SoftDeletes `:12`; audited (System B, when template present) |
| `RentalProperty` (`rental_properties`) | own address fields, `landlord_*`, `monthly_rental` — **standalone, no FK to `properties`, no agency_id** | SoftDeletes |
| `properties` rental columns | `rental_amount`, `deposit_amount`, `commission_percent`, lease dates (migration `2026_03_07_200001`) | a **third** place rental data lives — not read by either Rental model |
| `WetInkInspection` (`wet_ink_inspections`) | `signature_request_id`, `checklist_json`, `result` — this is **eSign wet-ink verification, NOT a property inspection** | SoftDeletes |

**SoftDeletes** on all rental tables (compliant). **Audit:** System B via `SignatureAuditLog`
(`lease_record_created`/`lease_renewed`/`lease_terminated`); System A has **no audit logging**.

---

## 5. AFFECTS DOWNSTREAM / AFFECTED BY UPSTREAM

**Affects:** the calendar (lease_expiry/rent_due/rent_escalation events — §6); the worksheet
(`RentalWorksheetInclusionService`, System A commission). **Affected by:** the eSign engine (System B leases
are born from signature completion — see `esign-docuperfect.md`); `properties.deposit_amount`/lease columns
(written for documents/CMA but not consumed by the Rental models).

---

## 6. AGENCY SETTINGS / CONFIG

`rental.settings` (`RentalDivisionController@settings:167`): **Properties** (`rental_properties` island),
**Document Types** (`RentalDocumentType`, `is_lease` flag), **Reminder rules** (`RentalReminderSetting` +
controller + route exist and are **built**, but the settings tile is hardcoded **"Coming soon"** disabled
`rental/settings.blade.php:42-54` — built backend not surfaced). **Not present:** escalation defaults,
deposit rules, lease-template config (all roadmap).

---

## 7. ROADMAP / BUILT-vs-BACKLOG

**BUILT:** System A commission tracking; System B lease lifecycle (auto-create, active/expired, renew/
terminate/history, expiry cron + in-app alerts); Rental Division dashboard/signatures/settings; the 6
rental documents (image-overlay form) + web-preview routes; soft-deletes + partial audit; calendar
integration. **BACKLOG** (`.ai/ROADMAP.md`): Property inspection reports (entry/exit, `:39`), Tenant
Pre-Approval AI (`:36`), Rental Pipeline view (`:38`), Deferred Signing (`:40`); Blade/Puppeteer document
rewrite (in progress per spec); first-class escalation/deposit, Contact-pillar linkage, agency_id
multi-tenancy — not built; reminder settings UI surfaced from the hub.

---

## 8. (covered in §4 — data) 

## 9. KNOWN FRAGILITIES

1. **Two disconnected islands, neither linked to the Property or Contact pillar.** `rentals` has only
   `branch_id` + address string (no property/contact/agency FK, migration `2026_02_10_080611:12-30`);
   `lease_records` stores tenant/landlord as strings not Contact FKs (`LeaseRecord.php:20-23`);
   `rental_properties` is standalone with no FK to `properties` (`2026_02_26_950001:13-31`). **Violates
   CLAUDE.md #4 (pillars are the spine) and #7 (no `agency_id` on rentals/lease_records/rental_properties)**
   — tenant isolation is join-derived, not a global `AgencyScope`.
2. **`property_id` ID-space collision.** `lease_records.property_id` is joined to the **real `properties`**
   in the calendar (`RentalCalendarSource.php:51`), but `assignMetadata` sets it from `rental_properties.id`
   (`RentalDivisionController:104-107`). The same column can point at two different tables depending on
   write path — calendar events may resolve to the wrong (or no) property/agency.
3. **Lease expiry depends on an unguaranteed cron** (`CheckLeaseExpiry`). Views filter purely on persisted
   `status` (`:140,156`); if the schedule isn't registered, active/expired counts drift and a stale `active`
   record never self-corrects.
4. **Termination date not persisted** (`LeaseController:107-109`) — survives only in audit metadata;
   "when did this lease end" is unreliable for terminated leases.
5. **Lease auto-creation is fuzzy-keyword-driven** (`isLeaseDocument` `:2547-2550`) — false positives create
   spurious `LeaseRecord`s; field extraction defaults the term to +1 year regardless of the real lease.
6. **Calendar tenancy reconstructed by LEFT JOIN** (`RentalCalendarSource.php`): a null `property_id` on a
   lease_record yields `agency_id = NULL` (`:57-60`) → lease-expiry event can leak across or vanish from
   agency-scoped calendars. `rent_due`/`rent_escalation` come only from System A `rentals`, so eSign-only
   leases (System B) get **no rent-due events**.

---

## Key file:line index
- `app/Models/Rental.php`, `app/Models/Docuperfect/LeaseRecord.php`, `app/Models/Rental/RentalProperty.php`.
- `app/Http/Controllers/Rental/RentalDivisionController.php:135-170`, `app/Http/Controllers/Docuperfect/LeaseController.php:25-149`.
- `app/Services/Docuperfect/SignatureService.php:2534-2603` (lease auto-create); `app/Console/Commands/CheckLeaseExpiry.php`.
- `app/Services/CommandCenter/Calendar/Sources/RentalCalendarSource.php` (cross-ref `calendar-command-center.md`).
- Spec `.ai/specs/rental-documents-spec.md`; `.ai/ROADMAP.md:36-40`.
