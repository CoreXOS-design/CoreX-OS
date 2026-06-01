# Spec: Compliance

**Status:** Partially implemented across modules — needs centralisation

---

## SA Compliance Context

CoreX operates under South African property law. Compliance in this context means:

| Framework | Applies To |
|-----------|-----------|
| **PPRA** (Property Practitioners Regulatory Authority) | Agent licensing — FFC per agent |
| **FICA** (Financial Intelligence Centre Act) | KYC on all parties — buyer, seller, landlord, tenant |
| **POPIA** (Protection of Personal Information Act) | Data consent on every contact |
| **CPA** (Consumer Protection Act) | Disclosure obligations in mandates and offers |
| **Property Practitioners Act 22 of 2019** | Overarching legislation governing all practitioners |

---

## Current State

Compliance is currently scattered:
- FICA checklist items referenced in documents but not tracked as a status
- No central compliance dashboard
- Agent FFC status not surfaced in the system
- POPIA consent not captured at contact level

---

## Consolidation Items (Phase 1)

- [ ] FICA status flag on Contact record
- [ ] FICA status flag on Deal record
- [ ] PPRA/FFC status field on Agent (User) profile
- [ ] POPIA consent block on Contact record (captured at creation, logged with timestamp)
- [ ] FICA checklist items configurable in Settings → Compliance

---

## Pending Spec Items (Phase 2)

- Central compliance dashboard (per-deal compliance checklist with status per item)
- FICA document upload and verification workflow
- Ellie: Document Legal Review (clause checking against SA legislation)
- Automated compliance reminders (FFC renewal approaching, FICA docs expiring)
- Compliance audit trail (who verified what, when)

---

*Full spec to be completed after Phase 1 consolidation.*

---

## Go-Live Migration Mode (agency on-boarding only)

When a new agency signs up to CoreX they bring across their existing book — typically ~350 P24 listings plus a contacts spreadsheet — that is already compliant in the real world. Forcing them through the full FICA / mandate / marketing-permission gates before they can transact would block them for weeks and cost real business. Two opt-in toggles let the on-boarding admin flag these legacy records as already compliant. **Both toggles are intended for the agency go-live event only, not for ongoing imports.**

### 1. Property compliance auto-stamp (P24 CSV importer)

- **Where:** Admin → Importer → "Listings & Images" upload form (`/admin/importer`).
- **Control:** Checkbox `mark_compliant_on_confirm`, default **checked**.
- **Persistence:** Stored on `p24_import_runs.mark_compliant_on_confirm` (migration `2026_05_28_140000`) so every row confirmed from that run inherits the flag — rows are often confirmed via the public onboarding portal, possibly days later.
- **Effect at confirm time:** `ConfirmP24PropertyRowJob` writes `compliance_snapshot_at = now()` and a `compliance_snapshot_data` JSON tagged `source: 'p24_go_live_migration'` with the run id, listing number, and an explanatory note. This trips the existing short-circuit in `MarketingReadinessService::statusFor()` (line 31) so the property is treated as fully marketable.
- **Audit:** The snapshot data is preserved on the property forever; the run id links back to the source CSV upload.

### 2. Contact FICA auto-approve (contacts Excel importer)

- **Where:** Contacts → "Import Contacts from Excel" panel (`/corex/contacts`).
- **Control:** Checkbox `mark_fica_approved`, default **checked**.
- **Effect per contact:** `ContactImportController::import` inserts an approved `fica_submissions` row with `status = 'approved'`, `verified_at = now()`, `verified_by = importer`, `verification_method = ['source' => 'go_live_migration']`, and a reviewer note recording the import provenance.
- **Result:** `Contact::ficaStatus()` returns `compliant` for the contact immediately, satisfying the seller-FICA gate in `MarketingReadinessService`.

### Why this is safe

- Both flags are explicit opt-ins on the upload form — never automatic for ongoing CSV/Excel uploads after go-live.
- Every stamped record carries a `source` marker (`p24_go_live_migration` / `go_live_migration`) so compliance officers can later audit which records were grandfathered vs which passed the full workflow.
- The compliance pillar's data model is untouched — no new fake "always compliant" column, no service-layer bypass. We use the same snapshot column and the same `fica_submissions` table the regular workflow writes to.
