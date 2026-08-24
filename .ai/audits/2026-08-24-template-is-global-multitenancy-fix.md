# Template.is_global — multi-tenancy audit and fix (2026-08-24)

**Status:** Fixed and deployed to Staging. Live has a known 3-row exposure, deliberately
un-fixed pending Johan's go — see the runbook at the bottom of this file.

## The one-sentence version

`is_global` on `docuperfect_templates` was checked as "share with every agency on the
platform, forever" everywhere in the code, but every human who set it — including the
UI label next to the checkbox — meant "share with every branch of my own agency." Those
are completely different blast radii, and nothing in the code, the UI, or the naming
distinguished them.

## Why this matters to you, reading this cold

If you are about to add a fourth template-creation path (a new import format, a new
builder, an API endpoint, anything that ends in `Template::create([...])`), **do not set
`is_global` to anything but `false`, and always stamp `agency_id`.** Read the "The rule
going forward" section below before you write that call.

## The ambiguity, stated precisely

A boolean named `is_global` invites the question "global across *what*?" and the
codebase and its users answered it two different ways:

- **The person setting it** (an agent checking "Global (all branches)" in the template
  editor) read "global" as *agency-wide* — this document is for the whole company, not
  just my branch. That's also what Johan meant when he first described the intended
  behavior: "linked to the agency I'm working in... marked global — company docs for
  whole company to use." "Company" meant *his* agency.
- **The code** read "global" as *platform-wide* — visible and reachable to literally
  every agency CoreX ever hosts, with no agency check at all once the flag was true.

Both readings are internally consistent and neither is unreasonable in isolation. The
bug is that they coexisted, undetected, because HFC has been the only tenant on this
instance. A platform-wide flag with a platform of one tenant produces zero observable
symptoms — the failure mode was latent, not active, right up until the moment a second
agency exists.

This is the report's own author correcting course mid-investigation, worth recording
plainly: the first fix attempt defaulted new templates to `is_global=true`, on the
belief that "global" meant agency-wide. That was wrong for exactly the reason above, was
caught before it shipped, and was reverted the same session. If you find yourself about
to set `is_global=true` as a "reachability" default, this is the mistake repeating.

## What `is_global=true` actually did (the mechanism)

Three places checked it, all with the same shape — short-circuit true, no agency
comparison reached:

- `Template::assertAccessibleBy($user)` — the per-action access gate (edit, archive,
  destroy, restore, copy, wizard config): `if ($this->is_global) { return; }` before any
  agency check.
- `Template::isVisibleToAgency(?int $agencyId)` — a direct-lookup guard used by
  `webPreview()`: same shape, the `$agencyId` parameter is never consulted once
  `is_global` is true.
- `Template::scopeVisibleTo($query, $user)`, the `'all'`-data-scope branch (used for the
  templates list): `->where('is_global', true)->orWhere('agency_id', $agencyId)` — an
  `OR`, so any `is_global=true` row matches the query for *every* agency's listing
  regardless of what `agency_id` holds.

None of the three ever gate `is_global=true` on `agency_id` matching anything. A
template with `is_global=true` and `agency_id=NULL` (the shape every affected row was
actually in) is visible and fully actionable to any authenticated user on the platform,
in any agency, once one exists.

## Why `Template` was the one model with this bug

`Clause`, `Pack`, and `FieldGroup` all carry their own `is_global` column with the same
name and the same superficial purpose. None of them have this bug. The difference: all
three use the `BelongsToAgency` trait (added in the 2026-08-20 tenant-isolation pass),
which applies an automatic `agency_id` global scope to *every* query on the model,
including their own `scopeVisibleTo()`. Under that trait, `is_global=true` can only ever
widen visibility to other branches *within* the query's already-agency-scoped result —
it structurally cannot cross an agency boundary, because the query never sees rows from
another agency in the first place.

`Template` deliberately does **not** use `BelongsToAgency` — by design, per its own
class docblock, because templates can be genuinely shared across every agency
(`is_global=true` with `agency_id=NULL`), and the trait's automatic scope would hide a
shared template whose `agency_id` is NULL (`AgencyScope` treats a NULL `agency_id` row
as an orphan, not "shared"). That's a legitimate reason to opt out — but opting out of
the trait also opts out of its protection, and nothing was added in its place to
re-establish the agency boundary for the non-global case. `assertAccessibleBy()` and
`isVisibleToAgency()` were the closest thing to that replacement, and they had the exact
inverse gap: they protected the *branch-scoped* case (checked branches, correctly) but
never protected the *zero-branches* case at all (fell straight to 404, regardless of
`agency_id`) until this fix, and never gated the *`is_global=true`* case on agency at
all, before or after this fix.

**The lesson for a future model:** if you opt a model out of `BelongsToAgency` because
it needs genuine cross-agency sharing, the sharing flag on that model is now the ONLY
thing standing between "this agency" and "every agency" — audit every place that flag is
read, not just the places that read `agency_id`. This was checked across the rest of
Docuperfect on 2026-08-24 (see "Swept and found clean" below) and nothing else has opted
out of the trait, so nothing else has this specific exposure today — but the pattern is
what to watch for, not just this one column.

## What else was broken (found investigating the same code)

Two more bugs shared a root cause with `is_global` but are logically separate — noting
them here since the fix touched all three at once.

1. **List/action mismatch on the branch-scoped case.** `scopeVisibleTo()` (used by the
   templates list) treated a template with zero branches AND a matching `agency_id` as
   visible. `assertAccessibleBy()` (used by every action route) required at least one
   branch belonging to the user's agency, with no fallback — so a zero-branch template
   was *listed* and then *404'd the instant it was opened*. This is what originally
   surfaced as three "404 on delete" reports from Johan and turned out to affect every
   template created via the PDF-upload and `.docx`-import paths, since neither ever
   linked a branch. Fixed: `assertAccessibleBy()` now falls back to an `agency_id` match
   when a template has zero branches; a template WITH branches assigned still restricts
   to those branches exactly as before (branches, when present, are an explicit
   narrowing, not just a hint).

2. **The edit-screen silent-detach footgun.** The template editor's save handler, on
   `is_global` toggled off with no branches selected, called `branches()->sync([])`
   unconditionally — silently leaving the template in the exact stranded shape from bug
   #1, reachable by nobody but an owner-role user, with zero warning. This is how
   template #52 — created correctly via the CDS-Builder path — ended up stranded anyway:
   an edit, not the creation. Fixed: the save handler now refuses (HTTP 422, checked
   *before* any field is written) to leave a template with zero branches and no
   `agency_id` to fall back to. It does not block turning off Global on a template that
   still has a valid `agency_id` — that combination is safe under fix #1's fallback.

## The fix, as shipped

- `Template::assertAccessibleBy()`: zero-branches now falls back to an `agency_id`
  match (reuses the existing `isVisibleToAgency()` helper) instead of an unconditional
  404. A template with branches assigned is unaffected — still restricted to agencies
  matching one of those branches.
- All three template-creation paths — PDF upload (`TemplateController::upload()`),
  `.docx`/`.pdf` import (`DocumentTemplateGenerator::generate()`), and CDS-Builder
  (`TemplateController::cdsGenerate()`) — now stamp `agency_id` to the creator's
  effective agency and set `is_global=false`. Reachability comes entirely from the
  `agency_id` fallback above, never from the platform-wide flag.
- CDS-Builder's `cdsGenerate()` previously had `is_global => true` hardcoded
  unconditionally in the array used for BOTH create and update — meaning every routine
  content re-save of an *existing* template forced it platform-wide again, even one
  correctly scoped to a single agency. `is_global`/`agency_id` are now set only in the
  create branch; an update never touches either.
- `saveFields()` (the editor's save handler): the zero-branches/no-agency_id refusal
  described above, checked before any write.
- Both template-editor screens: the `is_global` checkbox/toggle is removed from the UI
  entirely. There is currently no way for any user, at any permission level, to set
  `is_global=true` through the product. The column stays in the schema — see "What's
  deliberately NOT done" below.

## Swept and found clean

Every other Docuperfect model was checked for the same bypass shape (a flag that skips
an agency comparison entirely) and for missing per-action access gates:

- `Clause`, `Pack`, `FieldGroup` — each has its own `is_global` column, each uses
  `BelongsToAgency`, none has this bug (see mechanism explanation above).
- No other Docuperfect model uses `withoutGlobalScopes()` outside an already-audited
  owner-role/audit context.
- No Docuperfect controller action does a raw `Model::findOrFail($id)` on a
  tenant-scoped model without either `BelongsToAgency`'s automatic scope or an
  equivalent explicit gate. `Template` was the one model that needed (and, prior to
  2026-08-20, lacked) a bespoke gate; that gap is what `assertAccessibleBy()` already
  closed for routing — this pass confirms nothing else needs the same treatment.

## What's deliberately NOT done

- **`is_global` is not removed from the schema.** It may still have a legitimate future
  use — a CoreX-supplied, genuinely-shared-across-every-agency template (a standard
  FICA form, for instance) is a real concept the platform may eventually want. What's
  removed is any way to set it from the current UI, because right now the *only* thing
  it can do is expose one customer's legal documents to another, and no legitimate use
  case needs it in the next nine days. If that future concept gets built, it needs its
  own deliberate, admin-only (likely owner-role-only) UI, not a checkbox on every
  agency's template editor.
- **No backfill/migration for historical rows was run as part of this commit.** The
  3-row live exposure (see runbook) is fixed by a targeted UPDATE, not a migration,
  because it's a one-time data correction, not a schema change — the schema's default
  (`is_global` boolean, default `false`) was always correct; only three specific rows
  drifted from it.
- **Live is not touched.** Deliberately held per Johan's explicit decision: HFC is
  currently the only tenant, so the 3-row exposure has zero live impact today — nothing
  is exposed to anyone. It becomes load-bearing only when a second agency is onboarded,
  which has not happened yet. See the runbook below.

## Verification (Staging)

`tests/Feature/Docuperfect/SigningView/CrossAgencyTemplateAccessTest.php` and
`tests/Feature/Docuperfect/TemplateCreationDefaultsTest.php` — 22 tests, 53 assertions,
all passing as of this commit. The two tests that matter most:

- `test_agency_wide_template_is_fully_unreachable_to_a_foreign_agency` — an agency-wide
  (zero-branch) template is invisible in a foreign agency's list, and 404s on open,
  archive, and copy. All four checked in one test.
- `test_agency_wide_template_is_fully_reachable_to_a_different_branch_of_its_own_agency`
  — the same shape of template is visible and fully actionable (list, open, archive,
  copy) to a user on a *different branch* of its own agency — proving the fix doesn't
  over-restrict. Agency-wide is the point; branch-level lockdown would be its own bug.

---

## LIVE RUNBOOK — ready to execute on Johan's word

**Do not run any of this until Johan says go.** Held deliberately per his call: zero
urgency while HFC is the only tenant; this matters starting with agency #2, which
doesn't exist yet.

### 1. Deploy the code fix

```bash
cd /corex
git fetch origin
git merge --ff-only origin/main   # ONLY after this fix has been merged Staging -> main
```

(As of this writing the fix is on `origin/Staging` only. It needs to go through the
normal Staging → main promotion — Johan's explicit QA1/Staging/live gate — before this
step applies. Do not skip that gate to expedite the data fix; the data fix and the code
deploy are independent and the data fix does not require the code fix to be live first,
since neither `is_global=true` nor a stranded template is created by simply leaving old
rows as they are.)

### 2. The 3-row data fix

Exact statement (or equivalent Eloquent, matching what was run on Staging):

```sql
UPDATE docuperfect_templates
SET agency_id = 1, is_global = 0
WHERE id IN (54, 67, 68);
```

**Expected before-state** (verify this matches before running — if it doesn't, STOP and
re-investigate, do not run the fix blind):

| ID | agency_id | is_global | deleted |
|---|---|---|---|
| 54 | 1 | 1 | soft-deleted |
| 67 | NULL | 1 | soft-deleted (confirmed live, 2026-08-24 — re-confirm if time has passed) |
| 68 | NULL | 1 | **not** soft-deleted — live, active (confirmed live, 2026-08-24 — re-confirm) |

**Expected after-state:** all three rows `agency_id = 1, is_global = 0`. No other row in
`docuperfect_templates` changes — confirm via `SELECT COUNT(*) FROM
docuperfect_templates` before and after; the count must be identical (this was run as
individual per-ID model saves on Staging, not a bulk query, specifically so the blast
radius is provable — do the same on live, not a raw bulk UPDATE, so an off-by-one ID
typo can't silently touch more rows).

### 3. Verification to run immediately after

```php
// via `sudo -u www-data HOME=/tmp php artisan tinker <script>` on /corex
use App\Models\Docuperfect\Template;
use App\Models\User;

foreach ([54, 67, 68] as $id) {
    $t = Template::withoutGlobalScopes()->withTrashed()->find($id);
    echo "#{$id}: agency_id={$t->agency_id} is_global={$t->is_global}\n";
}

// Reachable by a real HFC (non-owner-role) user via the agency_id fallback:
$hfcUser = /* a real, non-owner-role, agency_id=1 user */;
foreach ([54, 67, 68] as $id) {
    Template::withoutGlobalScopes()->withTrashed()->find($id)->assertAccessibleBy($hfcUser);
    // must NOT throw
}

// Blocked for a foreign agency -- use a FRESH User instance, never a clone of an
// already-used one (effectiveAgencyId() memoizes per-instance; a clone carries the
// stale memo and gives a false "still reachable" reading -- this bit us once during
// the Staging verification, corrected before reporting it).
$foreign = new User();
$foreign->id = 999999999; $foreign->agency_id = 999999999; $foreign->role = 'admin';
foreach ([67, 68] as $id) {
    try {
        Template::withoutGlobalScopes()->withTrashed()->find($id)->assertAccessibleBy($foreign);
        echo "#{$id}: WRONG -- still reachable by a foreign agency\n";
    } catch (\Throwable $e) {
        echo "#{$id}: correct -- blocked\n";
    }
}
```

### 4. Rollback

If anything looks wrong after the fix (a template that should still be reachable isn't,
or the row count changed):

```sql
UPDATE docuperfect_templates SET agency_id = 1,    is_global = 1 WHERE id = 54;
UPDATE docuperfect_templates SET agency_id = NULL, is_global = 1 WHERE id = 67;
UPDATE docuperfect_templates SET agency_id = NULL, is_global = 1 WHERE id = 68;
```

This restores the exact pre-fix state (verified above) — not a generic "set is_global
back to true," the specific per-row `agency_id` each one had before. Confirm the
restored state matches the "expected before-state" table above.
