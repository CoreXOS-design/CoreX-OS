# CoreX OS — Onboarding Flows Audit
**Date:** 2026-04-16
**Auditor:** Claude Code
**Status:** Investigation only — no code changes

---

## Agent Onboarding Flow

### Entry Point
- **UI:** `/corex/onboarding` → kanban pipeline dashboard, "New Application" button
- **Route:** `GET /corex/onboarding/create` → [OnboardingController::create()](app/Http/Controllers/Onboarding/OnboardingController.php)
- **Access:** Requires `auth` + `verified` + owner/super_admin role check (line 60)
- **No public self-service form exists.** Agents cannot apply themselves.

### Full Flow

```
1. BM/Owner opens /corex/onboarding/create
2. Fills application form: name, email, phone, ID number, FFC, PPRA, designation, motivation, referral
3. POST /corex/onboarding → store() creates AgentApplication (status: 'applied') + seeds 15 checklist items
4. Application appears in kanban pipeline at /corex/onboarding
5. BM uploads documents: ID copy, FFC cert, qualifications, PI insurance, tax clearance, proof of address, CV
   POST /corex/onboarding/{app}/upload
6. BM verifies each document → auto-ticks related checklist items
   POST /corex/onboarding/document/{doc}/verify
7. BM advances status through pipeline stages:
   applied → documents_pending → compliance_review → mentor_assignment → training → activated
   POST /corex/onboarding/{app}/status
   Each advancement checks prerequisite checklist items via canAdvanceTo()
8. BM activates agent:
   POST /corex/onboarding/{app}/activate
   → Creates User record (is_active=true, random password, role=agent)
   → Creates AgentCapPeriod (1 year, cap from CommissionSetting)
   → Creates AgentSponsorship (if referred)
   → Creates AgentMentor (if candidate/intern)
   → Auto-ticks 'user_account_created' + 'portal_access' on checklist
9. Password displayed in flash message — NOT emailed automatically
```

### Data Collected
| Field | Source | Required |
|-------|--------|----------|
| First name, Last name | Application form | Yes |
| Email | Application form | Yes |
| Phone | Application form | No |
| ID Number | Application form | No |
| FFC Number + Expiry | Application form | No |
| PPRA Status | Application form | No |
| Designation | Application form | Yes (property_practitioner / candidate / intern) |
| Motivation | Application form | No |
| Referral source + referred by | Application form | No |
| ID copy, FFC cert, qualifications, PI insurance, tax clearance, proof of address, CV | Document uploads | Required before advancement |
| Branch assignment | Activation form | No |
| Commission structure | Auto from CommissionSetting on activation | Automatic |
| Permissions | Not set during onboarding | Gap |

### Status Tracking
- **Field:** `agent_applications.status` (enum)
- **Pipeline:** applied → documents_pending → compliance_review → mentor_assignment → training → activated
- **Terminal:** rejected, withdrawn (excluded from pipeline display)
- **Advancement blocked** if prerequisite checklist items incomplete

### Checklist (15 items, all seeded on creation)
1. Identity document verified (**auto-tick** when id_copy verified)
2. Valid FFC certificate (**auto-tick** when ffc_certificate verified)
3. PPRA registration verified (manual)
4. PI insurance (**auto-tick** when pi_insurance verified)
5. Tax clearance (**auto-tick** when tax_clearance verified)
6. Proof of address (**auto-tick** when proof_of_address verified)
7. Qualifications verified (**auto-tick** when qualifications verified)
8. Employment contract signed (manual)
9. Bank details captured (manual)
10. Mentor assigned (manual)
11. FICA compliance training completed (manual)
12. CoreX system training completed (manual)
13. RMCP read and acknowledged (manual)
14. User account created (**auto-tick** on activation)
15. Portal access configured (**auto-tick** on activation)

### BM Dashboard
- **Location:** `/corex/onboarding` → [index.blade.php](resources/views/onboarding/index.blade.php)
- **Type:** Kanban board with 6 columns (one per pipeline status)
- **Features:** Card count per column, search by name/email, filter by designation, days-in-stage counter, completion percentage per application
- **Status:** BUILT AND FUNCTIONAL

---

## Agency Onboarding Flow

### Entry Point
- **UI:** `/settings/agencies/create` (admin-only)
- **Route:** `GET /settings/agencies/create` → [AgencyController::create()](app/Http/Controllers/Admin/AgencyController.php)
- **Access:** Requires authentication + `access_agencies` permission
- **No public sign-up.** Agencies are admin-provisioned only.

### Full Flow

```
1. System admin navigates to /settings/agencies/create
2. Fills agency form: name, slug, colors, trading name, address, phones, compliance numbers, logo
3. POST /settings/agencies → store() creates Agency record with defaults
4. Admin creates branch(es) for the agency via /admin/branch-assignments
5. Admin imports agents from P24 CSV via /admin/importer (Stage 1: agents)
   → Creates User records (is_active=false, no password) with agency_id set
6. Admin imports listings from P24 CSV (Stage 2: listings + images)
   → Creates Property records linked to imported agents
7. Admin creates P24 Onboarding Portal for the agency
   POST /admin/importer/review → createPortal()
   → Generates token-based public URL: /onboarding/{token}
8. Admin shares portal URL with agency principal (email/WhatsApp)
9. Agency principal opens portal → Welcome screen → Reviews imported listings
   → Confirm / Exclude / Reassign agent per listing
10. Agency clicks "Finish Review" → portal marked complete → admin notified
11. Admin sends agent invites (email with password-set link)
    POST /admin/agents/{user}/invite (or bulk via /admin/runs/{run}/invite-all)
12. Agents receive email → click link → set password → is_active flips to true
13. Agency is operational
```

### Agency Record Fields
| Field | Purpose | Required |
|-------|---------|----------|
| name | Display name | Yes |
| slug | URL-friendly key (auto-generated) | Auto |
| trading_name | Legal trading entity | No |
| tagline | Brand tagline | No |
| address, phone(s), fax, email | Contact details | No |
| reg_no, vat_no, ffc_no, fic_no | Compliance numbers | No |
| sidebar_color, icon_color, default_color, button_color | Theme colors | Auto (defaults to cyan/navy) |
| logo_path | Brand logo | No |
| email_disclaimer, popi_url | Legal | No |
| is_active | Operational flag | Auto (true) |

### Multi-Tenancy Enforcement
- **Trait:** [BelongsToAgency](app/Models/Concerns/BelongsToAgency.php) on all tenant models
- **Scope:** [AgencyScope](app/Models/Scopes/AgencyScope.php) — global query filter
- **Switcher:** [AgencySwitcherController](app/Http/Controllers/Admin/AgencySwitcherController.php) — owner-role can switch to any agency; non-owners restricted to their own
- **Tables scoped:** users, branches, properties, contacts, deals, presentations, documents (all carry `agency_id`)
- **Portal isolation:** [ResolveOnboardingPortal](app/Http/Middleware/ResolveOnboardingPortal.php) middleware validates token, checks not revoked/expired, binds portal without setting session agency_id

### P24 Onboarding Portal
- **Model:** [P24OnboardingPortal](app/Models/P24OnboardingPortal.php) — token, agency_id, expires_at, run_ids_json
- **Controller:** [OnboardingPortalController](app/Http/Controllers/Public/OnboardingPortalController.php) — welcome, review, confirm, exclude, reassign, bulk, finish
- **Views:** [resources/views/onboarding/portal/](resources/views/onboarding/portal/) — welcome, review, finish, expired
- **Job:** [ConfirmP24PropertyRowJob](app/Jobs/ConfirmP24PropertyRowJob.php) — async property creation from confirmed rows
- **Events:** [P24PortalEvent](app/Models/P24PortalEvent.php) — immutable activity log
- **Policy:** One active portal per agency (new portal supersedes old)

---

## Gaps & Half-Built Pieces

### Agent Onboarding Gaps

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| A1 | **No public application form** | Medium | Agents cannot self-apply. BM must manually create every application. No public URL for "Join our agency" recruitment. |
| A2 | **Password not emailed on activate** | High | `activate()` displays password in flash message (line 308) but does NOT dispatch `SendAgentInviteJob`. The notification class and job exist but aren't wired into the activation flow. BM must manually communicate the password. |
| A3 | **No permissions set during onboarding** | Medium | New agent is created with `role='agent'` but no role-based permissions are assigned via the permission system. Agent relies on whatever defaults `corex:sync-permissions` provides for the 'agent' role. No per-agent permission customisation during onboarding. |
| A4 | **Bank details not captured in form** | Low | Checklist item #9 "Bank details captured" exists and must be manually ticked, but there's no bank details input in the application form or anywhere in the onboarding flow. Bank details must be entered later on the agent's profile page. |
| A5 | **No training module integration** | Low | Checklist items #11 (FICA training) and #12 (CoreX training) must be manually ticked. No integration with the Training module to auto-complete when training is done. |
| A6 | **Commission structure not configurable** | Low | `AgentCapPeriod` is auto-created from `CommissionSetting.annual_cap` but the agent's individual `agent_cut_percent`, `paye_method`, `paye_value` are not set during onboarding. Defaults from the User model apply until manually changed. |
| A7 | **No sidebar navigation for onboarding** | Medium | The `/corex/onboarding` route exists and works but there is no sidebar link to it. BMs must know the URL. |
| A8 | **No email notification to BM on status change** | Low | When application status changes, no notification is sent. BM must check the dashboard manually. |

### Agency Onboarding Gaps

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| B1 | **No minimum-viable setup wizard** | High | Creating an agency is a single form (name, colors, logo). There's no guided wizard that walks through: branding → compliance details → first branch → first admin user → first agent import. Admin must know the sequence manually. |
| B2 | **No first admin user created with agency** | High | Creating an agency creates the record only — no user account is created. There's no "Agency Admin" user linked to the new agency. Someone must manually create a user with `agency_id` set and give them admin role. |
| B3 | **No default branch auto-created** | Medium | New agency has zero branches. Properties require `branch_id`. A branch must be manually created via `/admin/branch-assignments` before any property can be assigned. |
| B4 | **Compliance fields all optional** | Medium | `reg_no`, `vat_no`, `ffc_no`, `fic_no` are all nullable. An agency can be created and operate without any compliance numbers. No enforcement of minimum compliance data. |
| B5 | **No agency readiness check** | Medium | No "Agency Setup Complete" status. No dashboard showing what's configured vs what's missing. Admin can't tell if an agency is ready to operate or half-set-up. |
| B6 | **Hard delete on agencies** | Low | `AgencyController::destroy()` performs hard delete (not soft delete), violating the system-wide no-hard-delete rule. Justified by unique slug constraint but could use soft delete with slug release. |
| B7 | **No dedicated agency dashboard** | Low | After creation, no agency-specific dashboard shows setup progress. Admin must navigate between settings, branch management, importer, and user management separately. |
| B8 | **P24 portal is property-focused, not agent-focused** | Info | The onboarding portal only covers property import confirmation. Agent onboarding (FFC, PPRA, training) is a completely separate flow with no portal component. |

### Cross-Cutting Gaps

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| C1 | **Two parallel onboarding systems** | Info | Agent applications (`/corex/onboarding`) and P24 agent import (`/admin/importer`) are completely independent. An agent can be imported via P24 CSV AND have a separate application. No deduplication or linking. |
| C2 | **No onboarding analytics** | Low | No metrics: average time to activate, bottleneck stages, dropout rates, documents pending counts. The kanban shows current state but no historical trends. |
| C3 | **No webhook/automation triggers** | Low | No model observers fire when application status changes. No events dispatched. No integration points for future automation (e.g. auto-email on status change). |

---

## Recommended Next Steps

### Priority 1 (Before first real agency onboarding)
1. **Wire SendAgentInviteJob into activate()** — 5 min fix. Dispatch the job after user creation so the password-reset email is sent automatically.
2. **Add sidebar link for /corex/onboarding** — 2 min fix. Add under Franchise Admin or a new "Onboarding" section.
3. **Create default branch on agency creation** — Add `Branch::create(['name' => 'Head Office', 'agency_id' => $agency->id])` in `AgencyController::store()`.

### Priority 2 (Quality of life)
4. **Agency setup wizard** — Multi-step: Create agency → Set compliance → Create first branch → Import agents → Create portal.
5. **Public agent application form** — A tokenised or public URL where agents can submit their own application. Pre-fill agency context.
6. **Onboarding status notifications** — Dispatch events on status change; send email to BM and applicant.

### Priority 3 (Polish)
7. **Merge P24 import and application flows** — If an agent was imported via P24, auto-link to an application record so compliance tracking is unified.
8. **Training module integration** — Auto-tick training checklist items when LMS marks courses complete.
9. **Agency readiness dashboard** — Show: compliance ✓/✗, branch ✓/✗, admin user ✓/✗, first agent ✓/✗, logo ✓/✗.

---

## File Reference Index

### Controllers
- [OnboardingController.php](app/Http/Controllers/Onboarding/OnboardingController.php) — Agent application CRUD + activation
- [AgencyController.php](app/Http/Controllers/Admin/AgencyController.php) — Agency CRUD
- [AgencySwitcherController.php](app/Http/Controllers/Admin/AgencySwitcherController.php) — Multi-agency switching
- [OnboardingPortalController.php](app/Http/Controllers/Public/OnboardingPortalController.php) — P24 public portal
- [ImporterController.php](app/Http/Controllers/Admin/ImporterController.php) — P24 CSV import + invite sending

### Models
- [AgentApplication.php](app/Models/AgentApplication.php) — Application record with statuses + checklist
- [Agency.php](app/Models/Agency.php) — Agency tenant record
- [P24OnboardingPortal.php](app/Models/P24OnboardingPortal.php) — Token-based portal
- [P24PortalEvent.php](app/Models/P24PortalEvent.php) — Portal activity log
- [OnboardingChecklist.php](app/Models/OnboardingChecklist.php) — Per-application checklist items
- [ApplicationDocument.php](app/Models/ApplicationDocument.php) — Uploaded compliance documents

### Migrations
- [2026_03_27_400000_create_onboarding_tables.php](database/migrations/2026_03_27_400000_create_onboarding_tables.php) — agent_applications, checklist, documents, sponsorships, mentors, cap_periods
- [2026_04_15_000001_create_p24_onboarding_portals_table.php](database/migrations/2026_04_15_000001_create_p24_onboarding_portals_table.php) — P24 portal

### Views
- [onboarding/index.blade.php](resources/views/onboarding/index.blade.php) — Kanban pipeline dashboard
- [onboarding/create.blade.php](resources/views/onboarding/create.blade.php) — Application form
- [onboarding/show.blade.php](resources/views/onboarding/show.blade.php) — Application detail
- [onboarding/portal/welcome.blade.php](resources/views/onboarding/portal/welcome.blade.php) — Portal welcome
- [onboarding/portal/review.blade.php](resources/views/onboarding/portal/review.blade.php) — Portal listing review
- [onboarding/portal/finish.blade.php](resources/views/onboarding/portal/finish.blade.php) — Portal completion
- [admin/agencies/create-edit.blade.php](resources/views/admin/agencies/create-edit.blade.php) — Agency form

### Routes
- Agent onboarding: `routes/web.php` lines 754-763 (under `/corex/onboarding`)
- Agency management: `routes/web.php` (under `/settings/agencies`)
- P24 portal: `routes/web.php` (under `/onboarding/{token}`)
- Agency switcher: `routes/web.php` (under `/dashboard/agency/switch`)

### Specs
- [.ai/specs/importer.md](.ai/specs/importer.md) — P24 import system
- [.ai/specs/importer-onboarding-portal.md](.ai/specs/importer-onboarding-portal.md) — Public portal
- [.ai/specs/multi-tenancy.md](.ai/specs/multi-tenancy.md) — Agency scoping
