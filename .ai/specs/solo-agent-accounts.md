# Solo Agent Accounts — Spec

> Status: **DRAFT v2 — Johan's business answers folded in (2026-11-07); awaiting approval to build.**
> Owner: QA2 lane (Andre) — drafted with Claude
> Pillars touched: **Agent** (the solo agent is the User), **Property**, **Contact**, **Deal** — a solo agent
> uses all four exactly as an agency agent does; only the agency-level machinery around them is removed.
> Sister specs (read before building): `multi-tenancy.md`, `agency-onboarding-setup.md` (§6.1 saver rule),
> `agency-onboarding-feature-switchboard.md`, `agency-billing.md` (D7 bands), `agency-suspension.md`,
> `agency-timeline-and-platform-esign.md` (the contract flow), `at456-ai-tools-scheduler-and-spend-safety.md`
> (the AI template allowance), `roles-permissions.md`, `corex-domain-events-spec.md`.
> Non-negotiables binding here: #1 no hard deletes, #2 nav same day, #5 permissions, #7 API catalogue,
> #9 domain events, #10a wizard, and the top-of-file rules: full CRUD/list completeness (rule 8) and
> **multi-agency always** (rule 9).

---

## 0. Decisions on record (Johan, 2026-11-07)

| # | Decision |
|---|----------|
| 1 | A single agent goes through the **same flow an agency does: sign-up, then sign a contract.** There is **no open self-service sign-up**. CoreX creates them in a **new section for signing up single agents** (the Solo Agents section, §4/§6), and the agent must sign the contract before they can use the system. |
| 2 | **Flat R450 a month.** |
| 3 | A single agent gets **everything** a normal agency agent gets — sales **and** rentals included — except what §5 removes (portal syndication and the team/agency machinery). |
| 4 | **Normal agencies stay exactly as they are now.** Settings / Company Settings / Role Manager are not touched for them. |
| 5 | A single agent **must enter their agency name** at sign-up; that is the name used on their documents. |
| 6 | **AI template creation: 5 a month** for a single agent. |

---

## 1. What this does and why (business requirement)

Today CoreX only works for an **agency** — an owner, branches, a team of agents, roles, company settings.
An independent agent working alone has no way in.

This feature lets a **single agent use CoreX** — their own listings, contacts, deals, calendar, documents,
e-signing, presentations, rentals — without the agency machinery they have no use for. They cannot
syndicate to the property portals. They see one simple **Settings** area instead of Company Settings /
Role Manager / Users.

Three new things:

1. **A new way to create a single agent** — a new section where CoreX signs up and creates them, followed by
   the same contract-signing step an agency goes through.
2. **A new place for the CoreX team to see every single agent** — list, search, status, suspend.
3. **A simpler product for that agent** — one Settings area, no Role Manager, no portal syndication, a
   flat R450 a month, and 5 AI template creations a month.

### Why it makes CoreX *best*, not merely *working*
It opens a second market (the independent agent) on the same engine, with no second codebase — the same
listing, contact, deal, rentals and e-sign tooling a full agency has, minus the parts that make no sense
alone. A single agent who outgrows working alone can later become a full agency without losing a record
(§11).

---

## 2. Architecture decision (locked)

**A solo agent is an `Agency` row that has exactly one user.** Not a new tenant type, not a new user type.

Why: every table, every `AgencyScope`, every query and every permission check in CoreX already assumes
"a user belongs to an agency and sees that agency's data" (`multi-tenancy.md`). Making the solo agent
their own one-person agency means **data isolation, billing, suspension, e-sign, letterhead, the AI
allowance and feature switches all work with zero new plumbing**, and the "outgrow into a full agency"
path is a flag flip rather than a data migration.

A new column marks the difference: `agencies.account_kind` = `agency` (default — every existing row) |
`solo`. (Deliberately NOT the existing `agency_type`, which means sales / rentals / both and keeps
meaning that — a solo agent still chooses sales / rentals / both.)

Everything below that says "solo" means `account_kind = 'solo'`.

---

## 3. Pillar connections

| Pillar | Relationship |
|--------|--------------|
| **Agent** (`User`) | **Creates + reads.** Creation makes the User (the single agent) and its Agency. FFC number + expiry captured at creation and tracked by the existing FFC tracking. |
| **Property** | Unchanged for the solo agent (own stock only, AgencyScope). **Writes none to portals** — syndication is locked off (§5). |
| **Contact** | Unchanged — own contacts only. |
| **Deal** | Unchanged — own deals; commission splits are not offered (nobody to split with). |

Reactivity (NN #9): emit named events, catalogued in `corex-domain-events-spec.md` as part of this build —
`SoloAgentCreated`, `SoloAgentActivated` (contract signed), `SoloAgentSuspended`, `SoloAgentArchived`.
Activation is a **listener on the existing `Platform\SubscriptionAgreementCompleted`** event (the fully-signed
contract), not an inline call; CoreX-team notifications are listeners on the new events.

---

## 4. Creating a single agent — the new sign-up section

There is **no public sign-up page** and the disabled `register` route stays off. Single agents are created
by the CoreX team (owner-only), in the new **Solo Agents** section (§6) → **Sign up a single agent**.

### 4.1 The form (all fields required unless marked)
**Agency name** (required — this is the name on their documents, letterhead and e-sign; Johan decision 5),
full name, email, mobile, **FFC number + expiry**, what they do (sales / rentals / both — default *both*,
decision 3), trading/display name (optional — defaults to the agency name).

### 4.2 The flow (mirrors the agency flow)
1. CoreX completes the form. `SoloAgentProvisioner` creates, in one transaction: the Agency
   (`account_kind=solo`, feature profile applied, AI allowance set to 5, billing set to the flat fee) + the
   User (inactive login) + the sign-up record. Status **Awaiting contract**.
2. CoreX sends the **single-agent subscription agreement** to the agent through **Platform E-Sign** (the
   same contract flow agencies use — `agency-timeline-and-platform-esign.md`), linked to the new agency.
   The agreement states the flat R450 a month.
3. The agent signs. `Platform\SubscriptionAgreementCompleted` fires → listener sets status **Active**, sends
   the existing account-setup email (`AccountSetupController`) so the agent chooses their own password, and
   emits `SoloAgentActivated`.
4. First login lands in the trimmed setup wizard (§7.3).

**Until the contract is signed the account cannot be used** — the user cannot sign in; an
awaiting-contract agent who somehow reaches the app sees only a "waiting for your signed agreement" page.

`SoloAgentProvisioner` **reuses the agency/user creation logic `Admin\AgencyController@store` already uses**
(extract the shared part; never copy it — onboarding spec §3.1 applies equally).

> **Needs an owner decision outside this build:** the single-agent subscription agreement itself (its
> wording) is a Platform E-Sign template that Johan/Andre author in the existing template creator. The
> build wires the flow; it does not write contract text.

---

## 5. What a solo agent can and cannot do

Enforced **server-side and at the route** (a hidden link is not a lock — rule 8 of the project file):

| Area | Solo agent |
|------|-----------|
| Listings, contacts, deals, calendar, tasks, documents, e-sign, presentations, CMA, matches, **rentals**, everything else a normal agent has | **Yes** — via a built-in feature profile (below) |
| **Portal syndication (P24, Private Property)** | **No.** `p24_enabled`/`pp_enabled` forced off; the syndication services refuse the call for a solo agency (not just hide the button); the switches cannot be turned on by the agent |
| Public agency website, Auctions, Payroll, Leave, multi-branch, Assistants, Feature rollout, agency-level compliance-officer / RMCP appointments | **No** (platform-locked off) |
| Role Manager, Users (user management), Features page | **No** — routes return 403/redirect for a solo agency even by direct URL |
| Commission splits / revenue share | **No** (no team to split with) |
| Adding a second login | **No** — a solo agency is exactly one seat |

The lists live in **one config file** (`config/solo-agent.php`): `locked_features` and `denied_permissions`.
**Built in step 2 (design amended while building — three changes from the first draft):**

1. **Locked features are applied inside `AgencyFeatureService`**, before its dependency cascade, whatever the
   agency's own switches say. One decision drives the sidebar, every feature-gated route, the Features page and
   the permissions a feature owns. (No separate `not.solo` middleware — it would be a second source of truth.)
2. **Denied permissions are refused inside `PermissionService::userHasPermission`**, not by editing role rows.
   The deploy-time permission sync re-adds missing grants to every agency's roles, so a removed grant would
   come back; a refusal at the check cannot.
3. **The solo user holds the agency's own `admin` role, not a new "Solo Agent" role.** The project's
   agency-admin rule and many role checks assume an agency's person-in-charge is `admin`; a new role name risks
   breaking them. With the denied list above, an `admin` in a solo agency has everything an agency admin has
   *except* the team machinery.
4. **Portal block is at the two clients every call passes through** — `Property24ApiClient::request()` and
   `PrivatePropertySoapClient::call()` — via `ExternalPublishingGuard`. It refuses reads as well as writes
   (a client without credentials of its own would fall back to the platform's), and unlike the install-wide
   switch it holds on the real live server.

**Provisioner rule:** `account_kind = 'solo'` must be set **in the same insert that creates the agency**
(`forceFill` before `save()`), not afterwards — creating an agency already makes the feature gate work out and
remember its answers, and a later flip would leave that agency's answers stale for the rest of the request.

**Deferred, to confirm before locking:** `commission-management` (unverified what it owns) and
`company-settings` (merged into Settings in step 5, not removed).

---

## 5a. Price and AI allowance

### Price — flat R450 a month
- One config value, `config('solo-agent.monthly_fee_zar') = 450`, used for every solo agency. It does **not**
  follow the agency price bands (`agency-billing.md` D7) — band 1 happens to be R450 today, but a solo agent's
  price must not move if the band list is ever edited. It does not rise with branches (a solo agency has none)
  or seats (it has exactly one).
- Wired into the existing pricing engine (`SubscriptionPricingService`) as a solo line so the agent's
  Billing page and the owner's Agency Billing page both show **"Single agent — R450/month"** and the
  existing custom-amount / discount controls keep working for the owner.
- The signed agreement and the billing page must agree. (The existing `ApplyAgreedQuotedFee` listener only
  acts on *quoted* agreements; a solo agreement is not one, so billing is untouched by it.)
- VAT presentation follows whatever `agency-billing.md` D7/D4 already says; this spec adds no new VAT rule.

### AI template creation — 5 a month
- The mechanism **already exists**: `agencies.ai_template_monthly_limit`, counted per calendar month, counting
  only successful generations, refused with the reset date at the limit (AT-456). Solo provisioning simply
  sets it to `config('solo-agent.ai_template_monthly_limit') = 5` (a normal agency's default is 20).
- It is the same shared allowance the AI Template Builder already uses (property templates **and** company
  ads draw from the one pool, as today) — one request counts once however many property types are ticked.
- The agent sees "n of 5 left this month". Only the CoreX owner can change a given agent's number (the
  existing owner-only control on the AI usage agency page) — the agent cannot raise it.
- This is the AI *template builder* allowance only. Other AI features (Ellie, photo analysis, ad copy) are
  governed by the platform's existing AI spend safety and are not capped by this spec.

---

## 6. The Solo Agents section (CoreX team)

**Place:** System Developer menu → **Solo Agents** (owner-only), route `admin.solo-agents.index`. Solo
agencies are **excluded from the normal Admin → Agency Management list by default** (they would drown it),
with a "Type" filter there to include them.

**List columns:** agency name · agent name · email · mobile · FFC no. + expiry · what they do · created ·
contract status · status · last login · listings count · deals count · AI templates used (n of 5).

| Required list element | Spec |
|---|---|
| **Search** | agency name, agent name, email, mobile, FFC number |
| **Sort** | every column above; **default = created date, newest first** |
| **Filter** | status (awaiting contract / active / suspended / archived), created date range, FFC expiring within N days, sales/rentals/both |
| **Pagination** | 25 per page, user-selectable |
| **Empty state** | "No single agents yet." + the **Sign up a single agent** button |

**Detail view:** the sign-up details, FFC, contract state (with a link to the Platform E-Sign document and
a Resend button), timeline of status changes (who/when), usage counts, price line, AI allowance.
**Actions (full CRUD):** Create (§4) · Read · Edit details · **Resend contract / setup email** ·
**Suspend / Unsuspend** (reuses `agency-suspension.md` — a suspended solo agent sees the existing suspended
message) · **Archive** (soft delete only — NN #1) · **Restore**. No hard delete anywhere.

**Scoping:** owner-only at the query layer; no agency user can reach it by URL. Data inside each solo
agent's account remains isolated by `AgencyScope` — the CoreX team sees *counts and sign-up facts*, not the
agent's contacts or deals (those need the existing agency-access authorization flow,
`agency-access-authorization-spec.md`).

---

## 7. What the solo agent sees

### 7.1 Navigation
Sidebar **Settings** group for a solo agent contains exactly one entry: **Settings**. No Company Settings
entry, no Role Manager, no Users, no Features, no Feature rollout. All other sidebar groups follow the
feature profile in §5.

### 7.2 Settings = Company Settings merged in — for solo agents ONLY
For a solo agent, `/corex/settings` is the single settings area and **absorbs the Company Settings sections
that still make sense for one person**: agency name and trading details, address, letterhead/branding,
signature, document defaults, contact details. Team-only sections (branches, commission splits, role/user
management, syndication, payroll, leave) do not appear.

- `admin.company-settings` for a solo agent **redirects** to `/corex/settings` (anchored to the merged
  section) so bookmarks never dead-end.
- Built once as a shared section list: the settings page decides per account kind which sections to show,
  and reads/writes through the **existing** savers — no parallel settings system (onboarding spec §3.1).
- **Normal agencies see no change whatsoever** (decision 4). A regression test pins this (§14).

### 7.3 Setup wizard (NN #10a)
A trimmed **solo track** of the existing setup wizard: agency name and business details → what you do →
letterhead + signature → notification preferences → done. Agency-only steps are skipped, exactly like the
switchboard skips features switched off. Any new setting this build adds is surfaced in
`config/agency-onboarding-copy.php` with `explain` + `affects` in the same change, or recorded in the
spec's §5.1 "Deliberately NOT in the wizard" list (onboarding spec §6.1: guard every boolean write with
`$request->has()`).

---

## 8. Permissions

New keys (added to `config/corex-permissions.php`, sidebar-gated, route-middleware'd, controller-checked):

| Key | Who | What |
|-----|-----|------|
| `solo_agents.view` | CoreX owner | See the Solo Agents section + detail |
| `solo_agents.create` | CoreX owner | Sign up a single agent |
| `solo_agents.manage` | CoreX owner | Edit, resend contract, suspend/unsuspend |
| `solo_agents.archive` | CoreX owner | Archive + restore |

Granted to the System Owner only (no agency role can hold them). The solo agent's own built-in role: §5.

---

## 9. Data model (migrations)

1. `agencies`: add `account_kind` string(10) default `'agency'`, indexed. Backfill: all existing =
   `agency` (the migration must not change any existing agency's behaviour).
2. New `solo_agent_signups` (one per solo agency): `id, agency_id (unique), user_id,
   status (awaiting_contract|active), ffc_number, ffc_expires_on, agreement_document_id nullable,
   contract_sent_at, contract_signed_at, created_by, deleted_at, timestamps`. (Suspension and archive reuse
   the agency's own `suspended_at` / soft delete — no duplicate state.)
3. New `solo_agent_status_events` (timeline): `signup_id, from_status, to_status, actor_user_id, reason,
   created_at`.
4. Run `php artisan schema:dump`, **strip DEFINER clauses**, commit the snapshot with the migrations
   (NN #12a).

`Agency` gets `isSolo()`; `account_kind` is **not** mass-assignable by any agency-facing form (set only by
`SoloAgentProvisioner` and the owner-only convert action later) — same pattern as `suspended_at`.

---

## 10. API (NN #7)

Versioned, named, auto-listed in Admin → API: `GET /api/v1/solo-agents` (owner; feeds the list). **No public
sign-up endpoint** (there is no public sign-up). No hidden JSON endpoints.

---

## 11. Deliberately NOT in this build

- **Converting a solo agent into a full agency** (adding a team). Designed for — a flag flip plus switching
  the feature profile — but the screen and rules are a separate spec.
- A solo agent inviting an assistant or a second user (locked off).
- **Public self-service sign-up** (decision 1) and online card payment at sign-up. Billing shows the R450
  via the existing machinery only.
- Any change to Settings / Company Settings / Role Manager for normal agencies (decision 4).
- Writing the contract wording (§4.2 note).
- Capping any AI feature other than the template builder (§5a).

---

## 12. Multi-agency check (project rule 9)

Every solo agent is **their own agency**: their own name, letterhead, signature, documents and defaults.
Nothing HFC-specific, no agency-1 default, no shared record. Defaults seeded for a solo agency are the
neutral platform ones. "What does this look like for the second agency?" — for the 500th: identical, and
fully isolated. The R450 and the 5 are config values, not constants buried in code.

---

## 13. Remaining small confirmations (not blockers — build proceeds on these defaults)

- The 5-a-month AI limit covers the AI Template Builder (property templates + company ads share one pool,
  as today). Say if you meant only one of the two.
- R450 is the full price shown to the agent, in line with the VAT treatment agencies already have.

---

## 14. Acceptance criteria

1. "Sign up a single agent" creates one agency (`account_kind=solo`, agency name as entered) + one user +
   one sign-up record in a single transaction; a failure leaves nothing behind. Agency name is required.
2. A newly created solo agent cannot sign in until the agreement is fully signed; on
   `SubscriptionAgreementCompleted` they become Active and receive the account-setup email.
3. There is no public sign-up route or endpoint; `register` is still disabled.
4. A solo agent **cannot** syndicate: P24/PP switches are off and unchangeable; calling the syndication
   service for a solo agency is refused and logged; the portal buttons are absent.
5. Direct URLs for Role Manager, Users, Features, Feature rollout, and Company Settings return 403 or
   redirect (Company Settings → merged Settings) for a solo agent. No second user can be added.
6. The solo sidebar's Settings group shows one **Settings** entry; the merged page shows the
   single-person sections and none of the team sections; each section saves via its existing saver.
7. Solo agent A cannot see solo agent B's, or HFC's, listings/contacts/deals — tested by direct ID.
8. Billing shows **R450/month** for a solo agency on both the agent's and the owner's page, and it stays
   R450 when the band price list is changed in a test.
9. A solo agent's **6th successful AI template creation in a calendar month is refused** with the reset date
   and **no AI call**; failed generations do not count; the agent sees "n of 5 left"; only the owner can
   change the number.
10. Solo Agents section: search on each named field, sort on every column (default newest first), every
    filter, pagination, empty state; owner-only (an agency admin gets 403).
11. Suspend/unsuspend/archive/restore/resend all work and are logged to the status timeline; archive is soft.
12. **Existing agencies (incl. HFC) behave exactly as before:** `account_kind` backfilled `agency`; a
    regression test proves their sidebar, Company Settings, Role Manager, AI limit (20) and syndication are
    unchanged.
13. Domain events fire and are in the catalogue; the API route appears in Admin → API.
14. The wizard's solo track works and every new setting is in `agency-onboarding-copy.php`.
15. Tests run through `scripts/lane-test.sh` — single most relevant files only (NN #13).

---

## 15. Files (expected — confirm at build against `CODEBASE_MAP.md`)

**Create:** migration(s) + schema snapshot; `app/Models/SoloAgentSignup.php`;
`app/Services/Solo/SoloAgentProvisioner.php`; `app/Http/Controllers/Admin/SoloAgentController.php`;
`config/solo-agent.php`; views (Solo Agents list + create + detail,
awaiting-contract page); `app/Events/Solo*`; listeners (activate on `SubscriptionAgreementCompleted`);
tests under `tests/Feature/SoloAgent/`.

**Modify:** `app/Models/Agency.php` (`isSolo()`, non-fillable `account_kind`); `routes/web.php` +
`routes/api.php`; `resources/views/layouts/corex-sidebar.blade.php` (solo Settings group; System Developer →
Solo Agents); `config/corex-permissions.php`; the CoreX settings page + `admin.company-settings` redirect;
`Admin\AgencyController` (extract shared creation; Type filter); `SubscriptionPricingService` (solo line);
the P24/PP syndication entry points (solo guard); `config/agency-onboarding-copy.php` + wizard step-skip for
solo; `corex-domain-events-spec.md`; `.ai/CHAT_STARTER.md`.

---

## 16. Build order (one concern per prompt)

1. Migrations + `account_kind` + `isSolo()` + backfill regression test (nothing visible changes).
2. **DONE (2026-11-07)** — The locks: feature lock in `AgencyFeatureService`, permission refusal in
   `PermissionService`, portal block at the P24 and PP clients, `config/solo-agent.php`, tests
   (`tests/Feature/SoloAgent/SoloLocksTest.php`, `tests/Feature/Syndication/SoloAgentPortalBlockTest.php`).
2b. **DONE (2026-11-07)** — `SoloAgentProvisioner` (`app/Services/SoloAgent/`) sets `account_kind` in the
   creating insert; the shared agency + first branch + first admin creation was extracted from
   `Admin\AgencyController@store` into `AgencyProvisioningService` (the controller now calls it, behaviour
   unchanged). The one-seat rule lives in the `User` model (`creating`, and `updating` when `agency_id`
   changes) and throws `SoloSeatException`, so no path can seat a second person. Provisioning creates the
   login **inactive and un-invited** (`is_active=false`, `invited_at=null`); step 4's activation sets it
   active and sends the password-setup email. Provisioning also fires `AgencyCreated` (rental defaults etc.)
   and seeds the same per-agency defaults a normal agency gets. Sign-up record, fee and AI allowance follow
   in steps 3-4. Tests: `tests/Feature/SoloAgent/SoloProvisionerTest.php`.
3. **DONE (2026-11-07)** — Price + AI allowance. `SubscriptionPricingService::quoteFor` swaps in one
   "Single agent" line from `config('solo-agent.monthly_fee_zar')` for a solo agency (never the band list);
   it is billed only while the account has its one active login, so an account awaiting its agreement owes
   nothing. `BillingQuote::isSolo` drives the wording on the agent's Billing page; the owner's custom-amount
   and discount controls still apply on top. The provisioner sets `agencies.ai_template_monthly_limit` to
   `config('solo-agent.ai_template_monthly_limit')` (5) inside the creation transaction; the one shared pool
   (property templates and company ads) is unchanged. Tests:
   `tests/Feature/SoloAgent/SoloBillingAndAiAllowanceTest.php`.
4. **DONE (2026-11-07)** — Solo Agents section + agreement + activation. As built (amends §4.2/§6/§9):
   - **Tables:** `solo_agent_signups` (adds `suspended_before_archive`, so Restore never reopens an account that was
     suspended before it was archived) and `solo_agent_status_events` (adds an `event` column: created, contract_sent,
     contract_signed, setup_email_sent, edited, suspended, unsuspended, archived, restored). Platform-owned like
     `agency_timelines` - no agency scoping, owner-only access.
   - **Agreement = the generic Platform E-Sign engine**, new template kind `solo_agent_agreement` (a wording or PDF
     template the owner authors; the build writes no contract text). The first signer role is the agent; any
     further role is signed by the CoreX user who sends it. Wording can use `{{ agent_name }}`, `{{ agent_ffc_number }}`
     and `{{ monthly_fee }}` - the last comes from the same setting billing uses, so the contract and the bill cannot
     disagree. (The existing web-document Subscription Agreement is band-priced and stays for normal agencies.)
   - **Activation:** `EsignService::complete()` emits `Platform\EsignDocumentCompleted`; `ActivateSoloAgentOnAgreementSigned`
     opens the account only for kind `solo_agent_agreement` AND only for the very document this sign-up sent. Login on,
     password-setup email out, `SoloAgentActivated`. Idempotent; never opens an archived account.
   - **Awaiting-contract gate = the login itself.** The login is off until signing; the first-sign-in claim
     (`AgencyAdminFirstLoginService`) refuses an unsigned or archived solo agent so a password reset cannot skip the
     contract, and the sign-in screen says "waiting for your signed agreement" instead of the generic message.
     (No separate "waiting" page: an unsigned agent can never be signed in.)
   - **Archive** = soft delete of the sign-up, agency suspended, login shut (raw update - the last-admin rule protects
     live agencies, not closed ones), billing stops (no active seat), an unsigned agreement voided. **Restore** reverses
     it (login back on only if the agreement was signed; stays suspended if it was suspended before).
   - **Edit details** covers agency name, trading name, agent name, mobile, FFC; not the login email or what they do.
   - **List** has the §6 columns, search on the five named fields, sort on all 14 columns (default newest first), the
     status/what-they-do/FFC-expiry/date filters, 25/50/100 per page and the empty state. Last login comes from
     `login_histories`; AI used from the same count the allowance uses (`AiTemplateBuilderService::usedQuery`, now public).
   - **Permissions** `solo_agents.view/create/manage/archive` are owner-only: excluded from admin's default, listed in
     `solo-agent.php` denied permissions, every route behind `owner_only` + the key, every action re-checks.
   - **Agency Management** hides single agents by default; `?type=solo|all` shows them.
   - **Events** (catalogue updated): `SoloAgentCreated/Activated/Suspended/Unsuspended/Archived/Restored` and `EsignDocumentCompleted`.
   - Tests: `tests/Feature/SoloAgent/SoloAgentSectionTest.php` (29).
5. **DONE (2026-11-07)** — Merged Settings + sidebar + Company Settings redirect. As built (amends §7):
   - **One body, two homes.** The Company Settings page was split: `admin/company-settings/_page.blade.php` is the body
     (identical content and savers), `index.blade.php` is now a thin wrapper, and `CompanySettingsController::pageData()`
     supplies the data to both. A single agent's Settings page (`/corex/settings`, rail item **Business details &
     branding**) embeds that same body; there is no second settings system and every form posts to the same saver.
   - **What a single agent keeps from Company Settings:** Company (identity, contact, address, registration, VAT set-up,
     catalogue and proforma blocks), Branding (logo, colours, letterhead, email signature, document defaults), CoreX Desk,
     Performance. **Left out:** Branches, Other Agency Stock, the Compliance board list, the public Website tab.
   - **Rail:** Company Settings, Other Agency Stock, Commission & Revenue Share, Leave Visibility, Team Inbox and the
     Management cards (Users / Role Manager) are not offered; a bookmark to one lands on the business details.
   - **Sidebar:** the Settings group holds one **Settings** entry (the team entries were already refused by
     step 2's permission lock); the Company Settings entry is hidden for a single-agent account.
   - **Redirect:** `/admin/company-settings` (and every save-redirect that lands there) sends a single agent to
     `/corex/settings?s=agency`, keeping any save message. The CoreX owner is never redirected - support sees the full
     page for any agency, single agents included. Normal agencies: no change (pinned by tests).
   - Tests: `tests/Feature/SoloAgent/SoloSettingsMergeTest.php` (12).
6. **DONE (2026-11-07)** - Wizard solo track + API + docs + multi-agency check. As built (amends §7.3/§10/§12):
   - **Setup guide:** `config('solo-agent.wizard_steps')` = welcome, identity (business details AND what they do), branding
     (logo + colours), notifications, access (finish). `AgencyOnboardingSetup::activeSteps()` drops every other step for a
     single-agent account exactly like a gated-off feature step (a team step opened by URL is stepped past, not a 404).
     A step may carry a `solo` wording block in `agency-onboarding-copy.php` (welcome, identity, notifications use it); the
     controls and savers are always the same ones. NN #10a: **this build adds no agency setting** - the flat fee and AI allowance
     are CoreX-owner config (`config/solo-agent.php`, owner-only AI control), not something an agency configures, so there is
     nothing new to surface in the wizard (recorded here as the §5.1 decision).
   - **What they do:** `AgencyTypeController::update` (the one saver) now also accepts a single agent changing their OWN
     account's type; the direct route stays behind `agency_features.manage`, so it is reachable only through the guide, and
     the locked features stay off whatever they choose.
   - **API:** `GET /api/v1/solo-agents` (name `api.v1.solo-agents.index`, owner-only, listed in Admin -> API): same query as
     the screen (search, sort, filters, paging), sign-up facts and counts only. No other verb exists under it; no public
     sign-up route exists and `register` stays off.
   - **Multi-agency check (rule 9):** two single agents never see each other's listings/contacts (query layer, tested); a
     test pins that the solo code carries no hardcoded agency id or house name; every default (fee, allowance, lists, wizard
     track, wording) is neutral config.
   - Tests: `tests/Feature/SoloAgent/SoloWizardApiAndIsolationTest.php` (13).
