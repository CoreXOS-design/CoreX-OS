# AT-389 — wet-ink authority/countersignature line: data contract for cc6

**Status: SPEC ONLY. No implementation here.** Written by cc2 for cc6, who is building the
in-body ruled signature lines on wet-ink/download-only documents and is deliberately leaving
the authority-line decision as an insertion point. This document is that insertion point's
contract. The full R1/R2 investigation and build plan live in the AT-389 conversation record
(cc2, 2026-08-28) — this file only extracts the piece cc6 needs.

## The three questions, answered

### 1. What boolean decides "this document needs an authority line"?

```php
$needsAuthorityLine = $agent->isCandidate();
```

- `isCandidate()` already exists: `App\Models\User::isCandidate()`, `app/Models/User.php:1028-1030`.
- Implementation: `stripos($this->designation ?? '', 'Candidate') !== false` — a case-insensitive
  substring match against `users.designation`.
- `$agent` is the SAME user the rest of the wet-ink/download-only pipeline already resolves as
  the acting agent: `$request->user()` in `ESignWizardController::prepareWetInk()` (`:7026`) and
  `ESignWizardController::prepareDownloadOnly()` (`:6847`). Do not re-derive it from anywhere
  else — if cc6's rendering context has its own `$user`/`$agent` variable at that point in the
  pipeline, it should be that same resolved model, not a fresh lookup.
- No further condition. Unlike the electronic flow (which additionally checks that an eligible
  authoriser exists before it will let the document send — `CandidatePractitionerService::
  getEligibleAuthorisers()`, throws if the pool is empty), a PRINTED line never needs that check.
  A blank ruled line stays valid on paper regardless of who is available to sign it later.

### 2. What name and title get printed under that line?

```php
$authorityName  = '';                        // always blank — see §3
$authorityTitle = 'Authorised Practitioner';  // fixed, not looked up per-document
```

- `$authorityName` is **always blank**. There is no supervisor assignment to read at print time
  — see §3 for why.
- `$authorityTitle` is a **fixed string**, not computed from any specific person's designation.
  Reuse the exact label the electronic flow already uses for this same role — `'role_label' =>
  'supervisor', 'name' => 'Authorised Practitioner'` (`ESignWizardController.php:3297-3319`) —
  so the same concept reads the same way in both flows. Do not invent new wording (e.g. do not
  print "Full Status Property Practitioner" here — that phrase does not exist anywhere in the
  system's actual data; the closest real values are `"Property Practitioner"` and `"Principal"`,
  and this line is about the ROLE being signed for, not a specific person's designation string).

### 3. What happens when the supervising practitioner cannot be determined?

**It always cannot be determined at print time — there is no exception case to branch on.**

Confirmed in the electronic flow: `SignatureTemplate::create(...)` explicitly sets
`supervisor_user_id => null` when a candidate flow starts (`ESignWizardController.php:3330-3332`).
Nobody is pre-assigned. Eligibility is a live, claimable shared queue — ANY full-status
practitioner, principal, admin, or owner in the candidate's branch (or agency-wide admin) can
claim it, first to act (`CandidatePractitionerService::getEligibleAuthorisers()`,
`app/Services/CandidatePractitionerService.php:172-220`). For a printed page nobody has clicked
anything, so there is no claim event to read.

So: when `$needsAuthorityLine` is true, **always** render the third ruled line with a blank
name and the title `"Authorised Practitioner"` under it — a human fills in and signs it by hand.
This is not a fallback for a lookup failure; it is the only behaviour that exists for print.
There is no code path where a specific supervisor's name should ever appear pre-printed on a
wet-ink document.

## What to do with these three values

- `$needsAuthorityLine === false`: render exactly what cc6's component already renders today —
  two lines, no third line, nothing else changes.
- `$needsAuthorityLine === true`: render the existing two lines plus a third ruled line, labelled
  with `$authorityTitle`, with `$authorityName` (blank) beneath the rule — the same blank-name
  rendering behaviour the end-of-document signature block already has (an empty name still
  produces a valid blank ruled line with its role label, per
  `resources/views/docuperfect/web-templates/components/signature-block.blade.php:147`, whose
  `@if($memberCount === 1 && $members[0]['name'])` guard already degrades gracefully to a bare
  line when the name is empty — the same pattern is safe to copy for the in-body lines).

## Explicitly out of scope of this contract

- How cc6 sources `$agent` in their own rendering context — that's cc6's implementation, not
  named or touched here.
- The end-of-document "Thus done and signed by…" block (`signature-block.blade.php`) — that's
  cc2's own R1/R2 work, tracked separately, not this file.
- `external/sign.blade.php`, `a4-page-styles.blade.php`, `TemplateController.php` — cc2 has not
  touched and will not touch these per Conductor's coordination note.
