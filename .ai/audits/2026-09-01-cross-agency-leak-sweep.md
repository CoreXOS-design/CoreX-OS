# Cross-agency (tenant isolation) sweep — 2026-09-01

**Asked for by:** Johan — "full scan of the CoreX system using Demo Agency Test to see if
there are any other leaks."
**Run against:** live production (`corexos.co.za`, DB `corexos`), read-only probes, acting as
a real Demo Agency Test user (`#110 test@gmail.com`, agency 20, role `admin`).
**Reference tenant:** Home Finders Coastal (agency 1).

## Method

Isolation can only fail in four places, so all four were checked rather than reading code at
random:

1. **Structural** — every table carrying `agency_id` mapped to its Eloquent model, checking
   for the `BelongsToAgency` trait (which installs `AgencyScope`).
2. **Route-model binding** — every authenticated GET route whose controller type-hints a
   tenant model, probed with one of agency 1's record ids.
3. **Manual `{id}` routes** — the same, for routes that look a record up by hand (no binding,
   so no automatic scope). This is the class the DocuPerfect leak belonged to.
4. **Rendered pages** — every zero-parameter authenticated page loaded as the demo user and
   searched for markers that only agency 1 can legitimately produce
   (`hfcoastal.co.za`, `Home Finders Coastal`, `HFC Addendum`).

Person names are useless as markers here: Demo Agency Test's users were seeded with the same
names as HFC's (Andre Roets, Elize Reichel, Retha Kelly, Angelique Venter, Shalan Du Bois).
The email domain and agency name are the reliable discriminators.

Every probe used a control (the demo user's OWN equivalent record) to prove the harness was
authenticated and the result meaningful — a bare 404 otherwise proves nothing.

## Coverage

| Check | Scope | Result |
|---|---|---|
| Tables carrying `agency_id` | 334 | 286 models enforce `BelongsToAgency` |
| Models with `agency_id` but no scope | 24 | 13 hold foreign rows → each triaged below |
| Model-bound GET detail routes | 131 | **0** returned another agency's record |
| Authenticated pages rendered | 309 (of 391; rest non-200) | 17 flagged → triaged below |
| Manual `{id}` GET routes | 99 probe hits | 2 confirmed real (rest false positives) |

## FINDINGS

### 1. DocuPerfect templates — CONFIRMED, fixed on `QA2`, STILL LIVE ON PRODUCTION

Two of HFC's own templates (`#74 Sales Mandatory Disclosure`, `#75 HFC Addendum B`) carry
`is_global = 1`, and `is_global` was read as "the whole platform" with nothing checking who
owns the row. Every other agency saw them.

Surfaces confirmed affected — **two more than Johan reported**:

- `/docuperfect/create`
- `/docuperfect/templates`
- `/docuperfect/esign/create`
- `/docuperfect/web-packs/create`

Fixed by commit `004639f78` on `QA2` (`Template::applySharedWith()` — `is_global` widens
across BRANCHES, never across AGENCIES; only `agency_id IS NULL` crosses a tenant boundary).
Not on production.

### 2. `/admin/ai-usage` — one agency sees every other agency's AI spend. NOT FIXED.

`AiUsageController::index()` is a **platform-wide** dashboard: global month-to-date spend,
global daily burn, and a "Top consumers (agencies)" table built from an unfiltered
`DB::table('ai_usage_events')`.

It is gated only by `permission:mic.view_ai_costs`. An agency `admin` holds that permission
by default, so a customer's admin sees:

> Top consumers (agencies) — **Home Finders Coastal · R 7.02 · 178 generations**

Confirmed live as the demo admin. The drill-down (`/admin/ai-usage/agencies/1`) **correctly
403s** — so isolation was considered for the detail page and missed on the index.

This is precisely the mistake `CLAUDE.md` already records for `/corex/admin/billing`, which is
`owner_only` **with no permission key on purpose** — "a key is grantable, and one mis-click
shows an agency admin every competitor's rate." `/admin/ai-usage` repeats it.

**Fix:** put all three `/admin/ai-usage*` routes behind `owner_only`, matching the billing
precedent. The permission key alone is not a tenant boundary.

### 3. E-sign wizard reads templates with no access guard. NOT FIXED.

`TemplateController` calls `Template::assertAccessibleBy()` **14 times**.
`ESignWizardController` calls it **0 times**, while looking templates up by raw id **8 times**
(lines 40, 139, 275, 335, 1318, 1343, 1370, 2043).

Confirmed live as the demo user against HFC's template #3 (`SB 2026 O.A.T.S - VL - Com`):

| URL | Result |
|---|---|
| `/docuperfect/esign/api/template/3/pages` | **200** — returns HFC's page count, page-image URLs and full field layout as JSON |
| `/docuperfect/esign/test-render/3` | **200** — renders HFC's template name + field overlay |
| `/docuperfect/templates/3/page/0` | 404 (guarded) |
| `/docuperfect/templates/3/edit` | 404 (guarded) |
| `/docuperfect/templates/3/web-preview` | 404 (guarded) |

**Scope of the exposure is structure, not content** — the page images themselves stay blocked,
so the document body does not leak. What leaks is another agency's template name and its
complete field/signature layout.

**Fix:** `assertAccessibleBy($request->user())` after every `Template::find*()` in
`ESignWizardController`, exactly as `TemplateController` already does.

## VERIFIED CLEAN (checked, not leaking)

- **Document packs** — `Pack` uses `BelongsToAgency`; `AgencyScope` covers it in a real
  authenticated request. An unauthenticated CLI probe makes packs *look* like they leak
  because `AgencyScope::applyInner()` returns early with no `Auth::user()`. Measurement
  artefact, not a defect. (`Pack::scopeVisibleTo()`'s `if ($scope === 'all') return $query;`
  does lean entirely on that global scope, so any future caller adding
  `withoutGlobalScopes()` there would leak — worth knowing, not currently exploitable.)
- **Roles / permissions** — `RoleManagerController`'s delete+insert is correctly scoped with
  `->when($agencyId, …where('agency_id',…), …whereNull('agency_id'))`. No cross-agency write.
- **AI narrative cache** — every cache key embeds `agency:{id}` or a user id
  (`weekly_brief:agency:N:…`, `suburb_deep_dive:agency:N:…`, `tiles:user:N:…`). No collision.
- **Agent activity feed** — `OutreachActivityFeedService::feed()` filters `agency_id`
  explicitly.
- **Pillars** — property, contact, deal, document, presentation, tracked property, rental,
  viewing pack, worksheet, payroll, proforma: direct cross-agency access all blocked.

## NOT A LEAK, BUT A REAL PRODUCT DEFECT — CoreX is not white-labelled

**100 Blade files hardcode `Home Finders Coastal` / `hfcoastal.co.za`:**

| Area | Files |
|---|---|
| `docuperfect/web-templates/imported` | 39 |
| `emails/signatures` | 11 |
| `docuperfect/web-templates/cds` | 9 |
| `docuperfect/web-templates` | 7 |
| `sales-documents` (seller-facing) | 5 |
| `emails/sales` | 4 |
| tv, presentations, fica, compliance/fica, errors, … | 25 |

These render for ANY agency. Confirmed as the demo user:

- `/docuperfect/web-preview/lease-agreement-popi-v8` → *"The Agent means **Home Finders
  Coastal**, a duly authorised property practitioner registered with the PPRA."*
- `/docuperfect/web-preview/rental-application-v8` → *"submit … to **letting@hfcoastal.co.za**"*
- `/corex/settings` → *"Emails route to **johan@hfcoastal.co.za** with a [DEMO] subject prefix."*

A second agency's lease agreement would name HFC as the agent and send their tenants to HFC's
mailbox. No customer data crosses a boundary, so this is not a tenant leak — but for a
multi-agency product it is arguably more damaging than one, and it is invisible to every
isolation check because it is text in a view, not a row in a table.

Deliberately not fixed here: 100 files, and the right shape (agency letterhead/merge fields
vs literal strings) is a product decision, not a lane's call.

## False positives worth recording

`e.g. info@hfcoastal.co.za` appears as **placeholder text** on `/admin/branch-assignments` and
`/corex/admin/company-settings`; `/tools/cma` and `/tools/commission` carry a
`* Home Finders Coastal Portal` **JS comment header**. Neither is data.

## Incident caused during this sweep — disclosed

At 15:23 the worktree used for the QA2 fix was set up by copying `/corex/storage`, which is
15 GB, into `/tmp` (7.7 GB tmpfs). `/tmp` filled and **MySQL could not write its temporary
files** — `SQLSTATE[HY000]: General error: 3 Error writing file '/tmp/MLfd=…' (errno 28)`.
Space was freed at 15:24 and `/tmp` has been healthy since (7.6 GB free).

Consequence: the P24 image-download jobs that were mid-flight burned retry attempts on the
failure. The last 67 properties are now at attempts 4 of 5 and short by 1–3 images each
(e.g. property #19712 at 23/25) — some of that retry budget was spent on this, not on P24.
They will need a re-dispatch once they exhaust. **Storage must never be copied into the
worktree; symlink `vendor` and `.env` and create an empty `storage/framework` skeleton.**
