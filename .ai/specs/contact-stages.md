# Spec — Contact Stages (Applicant → Tenant → Past tenant, and the sales equivalents)

> Status: **DRAFT — for Johan's approval (3 Nov 2026).** No code, no data, no migration until approved.
> Author: cc6 (drafted with Claude), 2 Nov 2026
> Builds on: [contact-types-and-tags.md](contact-types-and-tags.md) (AT-79 parents + sub-tags), [rental-applications.md](rental-applications.md) ("Contact status" section, AT-392), [leases.md](leases.md), [corex-domain-events-spec.md](corex-domain-events-spec.md), [agency-onboarding-setup.md](agency-onboarding-setup.md) §6.1.
> Code references are to QA1 at `ed22327a6` (Staging `14ed507b2` has the same lines unless noted).
> Part A (sections 1–2) is for Johan in plain words. Part B (sections 3–17) is for whoever builds it.

---

# PART A — FOR JOHAN

## 1. What this does, in plain words

Today a contact has a **type** (Seller, Buyer, Tenant…). That type is also the e-sign signing role, so it cannot change without breaking signing. What it cannot say is **where the person is in their journey**. Sherry's contact Leigh Arendse applied to rent, never became a tenant, and CoreX has no word for that. A tenant whose lease ended is still called a Tenant forever.

After this build:

1. The **7 fixed types stay exactly as they are** and stay equal to the e-sign signing roles (Seller, Buyer, Lessor, Lessee, Owner, Other, Tenant). Nothing about signing changes.
2. **Stages** sit under a type as labels, so the contact reads, for example, **Tenant · Applicant**, then **Tenant · Current tenant**, then **Tenant · Past tenant**. The type is for e-sign. The stage tells the agent where the person is.
3. **Stages change by themselves** when something happens. No agent changes a status by hand:

| When this happens | The contact becomes |
|---|---|
| A rental application is sent to them | **Applicant** |
| The application is declined or withdrawn (and they have no lease) | **Past applicant** |
| A lease is activated with them on it | **Current tenant** |
| A lease is renewed (the old one ends, the new one starts) | stays **Current tenant** |
| Their last lease ends or is cancelled | **Past tenant** |
| A past tenant applies again | **Applicant** again |

4. **Agencies can still add as many sub-tags as they like** (Cash seller, Investor, Landlord's friend…). Every sub-tag is linked to one of the 7 types in **Settings → Contacts → Contact Types**, and that link is what e-sign uses. The stages are the same kind of sub-tag, but CoreX owns them: an agency can rename a stage ("Resident" instead of "Tenant") and change its colour, but cannot delete it or move it to another type.
5. **The Contacts screen shows the stage** next to the type, and the "All Types" filter lists the types **and** the sub-tags under them, so you can filter to "Past tenants" or "Applicants". The same filter drives the export.
6. **Approval no longer makes someone a Tenant.** The lease does. Approved but no lease yet means "Applicant (approved)".
7. A **one-off command** can put existing contacts into the right stage from their real applications and leases. It shows what it would do first, keeps a record, and can be undone.
8. **Sales:** the same idea is proposed for buyers (Lead → Buyer → Past buyer) and sellers (Prospect → Seller → Past seller). These are a proposal only. They are built switched off until you rule (question 5).

What agents will see on screen: pills on the Contacts list, a stage pill in the contact header, a History line ("Stage changed from Applicant to Current tenant — lease 12 activated"), a new "All Types" dropdown, and a short settings block. Nothing else on the screens moves.

## 2. Your rulings this spec is built on

- "The signing role is used for esign. The sub tag is used for whatever agencies want to name it."
- "We can essentially have the types, and as many as any agency want, and in the background we link them under settings to — applicant links as tenant for esign."
- Rental stages: Applicant → Tenant → Past tenant (trigger: Sherry's "was an applicant that never became a tenant so maybe you can have a status as Applicant or Past").
- "More statuses for both sales and rentals."
- "Having tenant as esign is what's shown on the search screen and what you can filter by under type."
- Stages are changed by events (application sent, lease activated, lease ended), not by the agent.

**Questions that need your ruling are in section 17, each with my recommended answer.**

---

# PART B — BUILD SPEC

## 3. Pillars, scope, what is out of scope

- **Contact** (primary): stages are labels on the contact.
- **Deal / Lease / Rental application**: the sources of the events.
- **Property**: unchanged (landlord and seller links stay as they are).
- **Out of scope:** changing the 7 parents; changing e-sign role resolution; rewriting the Buyers report; the mobile app build (a mobile addendum is written separately); live deployment.

## 4. Concepts

| Term | Meaning |
|---|---|
| **Parent type** | One of the 7 fixed, global rows (`contact_types`). Seller, Buyer, Lessor and Lessee carry an `esign_role`; Tenant is a name-only parent with role `lessee`; Owner and Other carry none. Unchanged. |
| **Sub-tag** | Agency-owned label (`contact_tags`) under exactly one parent. Agencies add as many as they want. Assigning a sub-tag also assigns its parent (`Contact::syncTypeAssignments`, `app/Models/Contact.php:258-266`). |
| **Stage** | A sub-tag with `is_system = 1` and a fixed `system_key`. Seeded for every agency. Name and colour editable, key, parent and existence fixed. A contact holds **at most one stage per family**. |
| **Family** | A set of stages under one parent: `rental` (under Tenant), `buyer` (under Buyer), `seller` (under Seller). |
| **Mirror** | `contacts.contact_type_id`, the single "main type" kept in step with the multi-type list. Many old readers still use it (section 10). |

### 4.1 Stage list and fixed keys

| Family | Parent | Key | Default label | Order | On by default |
|---|---|---|---|---|---|
| rental | Tenant | `rental.applicant` | Applicant | 1 | yes |
| rental | Tenant | `rental.tenant` | Current tenant | 2 | yes |
| rental | Tenant | `rental.past_tenant` | Past tenant | 3 | yes |
| rental | Tenant | `rental.past_applicant` | Past applicant | 4 | yes |
| buyer (PROPOSAL) | Buyer | `buyer.lead` | Lead | 1 | no |
| buyer (PROPOSAL) | Buyer | `buyer.buyer` | Buyer | 2 | no |
| buyer (PROPOSAL) | Buyer | `buyer.past_buyer` | Past buyer | 3 | no |
| seller (PROPOSAL) | Seller | `seller.prospect` | Prospect | 1 | no |
| seller (PROPOSAL) | Seller | `seller.seller` | Seller | 2 | no |
| seller (PROPOSAL) | Seller | `seller.past_seller` | Past seller | 3 | no |

Code addresses stages only by key, never by name, so an agency renaming "Current tenant" to "Resident" breaks nothing.

## 5. The stage engine

### 5.1 Design: recompute from facts, not step by step

One service, `ContactStageService::recompute(Contact, family)`, works out the stage from what is true now. It is idempotent and the same pattern as `RecomputeRentalApplicationStatus` (`app/Listeners/Contact/RecomputeRentalApplicationStatus.php`). Running it twice changes nothing.

**Rental rules, highest first:**

| Rank | Fact | Stage |
|---|---|---|
| 1 | The contact is on a lease with status Active (any of its tenants, including co-tenants) | Current tenant |
| 2 | The contact has a rental application that is sent, in progress, returned, under assessment, reopened, or approved with no lease yet | Applicant |
| 3 | The contact was on a lease that has ended or been cancelled, and has no active lease and no open application | Past tenant |
| 4 | Every application of the contact is declined or withdrawn, and there was never a lease | Past applicant |
| 5 | None of the above | no stage (the parent alone, as today) |

Co-applicants and co-tenants always move together (`applicantContactIds()`, AT-517).

### 5.2 Writing the stage (finding 2 — never the whole-set save)

A new method `Contact::swapStageTag(family, ?stageKey)`:

1. Ensures the **parent** is on the contact (add only, never remove).
2. Detaches only that **family's** stage tags and attaches the new one. Every other parent and every other sub-tag stays as is.
3. Re-derives the mirror by the same rule as `syncTypeAssignments` (`Contact.php:269-283`).
4. Fires `ContactTagged` for the tag newly attached (`app/Events/Contact/ContactTagged.php`, logged by `LogContactEvent`, `AppServiceProvider.php:888`).
5. Writes one line to `contact_audit_log` ("Stage changed from X to Y — lease 12 activated").
6. Never throws into the caller. A failure is logged and the lease or application action still succeeds (BUILD_STANDARD §3, same as `RecomputeRentalApplicationStatus.php:57-63`).

The existing whole-set save (`ContactController.php:1563`, `:2303`) is not used by the engine.

### 5.3 Event → stage table (rental, to be built)

| Event | Where it fires today | Engine reaction |
|---|---|---|
| Application **sent** | `RentalApplicationController.php:1584` sets `status='sent'`. **No event exists yet — NEW `RentalApplicationSent`** | recompute → Applicant |
| Application submitted / returned / reopened / reopen-cancelled / approved | existing events (`app/Events/RentalApplication/*`), already wired to `RecomputeRentalApplicationStatus` (`AppServiceProvider.php:941`) | recompute (stays Applicant; approved no longer adds Tenant, see 5.4) |
| Application **declined / withdrawn** | existing `RentalApplicationDeclined` / `…Withdrawn` | recompute → Past applicant, only if no lease and no other open application (setting) |
| Lease **activated** | `LeaseActivationService.php:94`. **No event — NEW `LeaseActivated`** | recompute all tenants on the lease → Current tenant |
| Old lease **expired by a renewal** | `LeaseActivationService.php:84`, the same transaction as the new lease activating | No visible change: recompute sees the new active lease, so there is no "Past tenant" flicker |
| Lease **cancelled / archived as ended** | `LeaseArchiveService.php:60-76` (`endsTenancy`). **No event — NEW `LeaseEnded`** | recompute all tenants → Past tenant (unless another active lease) |
| Lease **expired** by an agent action | `LeaseActivationService.php:84`, `LeaseRenewalService.php` | same as above |
| Renewal **draft** cancelled | `LeaseRenewalService.php:159` (a draft, not the tenancy) | none |
| Notice accepted / landlord not renewing | `LeaseRenewalService.php:281`, `LeaseNoticeService.php:206` | none by default (setting `stage_past_tenant_on_notice`, default off, question 3) |
| Lease **end date passes** with nobody acting | `CheckLeaseExpiry.php:24-34` (AT-439: the lease deliberately stays Active) | none. The stage stays Current tenant until the agent records the outcome. The existing alert is the nudge. |
| Tenant added to / removed from a lease | lease tenant maintenance | recompute that contact |
| Imports that create an active lease (take-on, Reos) | `RentalTakeOnConfirmService.php:254-262`, `ReosTenancyConfirmService.php:441` | call the same engine after the lease is saved |

New events follow `corex-domain-events-spec.md` (named events in the catalogue, listeners registered explicitly in `AppServiceProvider` because discovery is off, AT-261). Catalogue entries are added in the same commit.

### 5.4 What happens to `tag_contact_as_tenant_on_approval`

The setting stays in `rental_application_qualifying_settings` (`RentalApplicationQualifyingSetting.php:364, 394, 479`) and its stored values are **not touched**. Behaviour:

- **Rental stages ON for the agency (default):** `AddTenantTypeOnRentalApproval` (`AddTenantTypeOnRentalApproval.php:81-109`, registered `AppServiceProvider.php:953`) **stops adding Tenant on approval**. The lease activation adds the Tenant parent and the Current tenant stage. The Settings page and wizard show the toggle greyed with the line "Superseded: with stages on, a lease makes someone a Tenant, not approval."
- **Rental stages OFF:** the listener behaves exactly as today.
- Contacts already tagged Tenant by an old approval keep the parent (add-only rule). The re-stage command (section 11) gives them a stage from their real lease.

### 5.5 Manual change

An agent with `contacts.change_stage` can set or clear a stage by hand from the contact page (family dropdown only). The change is logged with who and when. The **next event recomputes from facts and may overwrite it** (question 8). Manual changes never touch the parent types.

## 6. Screens

| Screen | What changes | What it shows |
|---|---|---|
| **Contacts list** (`resources/views/corex/contacts/index.blade.php:793-800`, filter `:251-256`) | Row pills: parent pill(s) as today, then the contact's **sub-tag pills**, with the stage pill tinted by its colour. Loaded already (`ContactController.php:272` eager-loads `tags`), never drawn today. | e.g. `Tenant` `Past tenant`; hover shows "since 21 Oct". A stage whose label equals its parent's label is shown once. |
| **"All Types" filter** (`ContactController.php:226-246, 299-303`; Rentals lens `:300-302`) | Grouped dropdown: each **parent** with its **sub-tags** (including stages) under it. Parent value `type=<id>`, sub-tag value `tag=<id>`. | "Tenant (all)", "– Applicant", "– Current tenant", "– Past tenant", "– Past applicant"; same for the other parents. Hidden stages (family off) are not listed. |
| **Contact page header** (`_header-badges.blade.php:14`) | Adds sub-tag pills after the parent pills; stage pill with "since" tooltip. | |
| **Contact page type picker** (`_type_picker.blade.php`) | Stage tags appear as one locked line: "Stage: Past tenant (automatic)" with a "Change" action for `contacts.change_stage`. Not in the free sub-tag list. | |
| **Contact History tab** | New lines from the audit log. | "Stage changed from Applicant to Current tenant — lease 12 activated" |
| **Settings → Contacts → Contact Types** (`settings.blade.php:2097-2231`; `ContactTagController`) | Each sub-tag row shows its **linked parent and signing role** ("Applicant → Tenant · signs as Lessee"). Stages show a lock, the key hint, rename + colour only, no delete, no move. Custom sub-tags: full add/edit/move/delete as today. Add: name box + parent picker, several names at once. **Fix the stale text** "The four signing roles are fixed" (it is seven). New search box and count on the sub-tag list. | |
| **Settings → Contacts → Contact stages (new block)** | Family switches, event switches, labels (section 8). | |
| **Setup Wizard, Contacts step** (`config/agency-onboarding-copy.php:1897`) | Same switches and labels as the settings block, with `explain` / `affects` text (section 8). | |
| **Contacts export** (`ContactExportController.php:189-203`) | Same filter code as the list, so `tag=` and the pivot-based type filter work in the export. Adds a "Stage" column after "Type" (`:100`). | |
| **Rental application page, Lease pages** | No change. | |

**Navigation:** no new page, so no new menu entry. The Settings entry (`?tab=feature&fsec=contacts`) and the Contacts list already exist.

## 7. List-screen standard (BUILD_STANDARD §1b–1d)

**Contacts list**

- **Search fields:** unchanged (first name, last name, ID number, every phone and email — `Contact::scopeSearch`, `Contact.php:679-708`). **Added:** sub-tag / stage name, so typing "past tenant" finds them.
- **Sort:** unchanged. Contacts: Name (last, first) ascending by default (`ContactController.php:134`). Rentals lens: Name, Date added, Last updated (`:118-132`). **No new sort column.**
- **Filters:** existing (type, agent, outreach state) plus **sub-tag/stage** through the grouped "All Types". Combined with the others by AND. The existing "type" filter is **fixed to read the multi-type list**, not the single main type (today a Seller+Tenant contact is missed by "Tenant", `ContactController.php:244`).
- **Pagination:** unchanged (`contacts_per_page`, `ContactController.php:273`).
- **Empty states:** a filter on a stage with no results says, for example: "No contacts are Past tenants yet. A contact moves here automatically when their last lease ends." Genuinely no contacts keeps today's message.

**Settings sub-tag list:** search by name; default sort = system stages first (by order), then `sort_order`, then name; no pagination needed until a parent holds more than 200 (Owner holds 127 on QA1) — then page at 50.

**Scoping (OWN / BRANCH / AGENCY), enforced at the query layer**

- The Contacts list and export keep `ContactScope`; the new `tag=` clause is added inside that same scoped query. A hand-edited `tag=` can only narrow, never widen.
- The dropdown options come from `ContactTag` (global `BelongsToAgency` scope), so another agency's sub-tags are never offered. A `tag=` id from another agency matches nothing.
- No counts next to dropdown options (a count would leak what the user's scope hides).
- The engine writes with the contact's own `agency_id` (events carry the application or lease and its agency); the re-stage command loops **agencies explicitly**, never relying on a missing auth user.
- Contact pages and History keep `ContactScope`/policy checks. Direct-URL by ID stays blocked as today.
- Settings routes keep `permission:access_settings` (`routes/web.php:7017, 7039`).

**CRUD (Non-negotiable 8):** custom sub-tags: Create/Read/Update/Archive/Restore as today (archive = soft delete). System stages: Create = seeded; Read; Update (name, colour); Archive = not allowed (switch the family off instead); Restore n/a.

## 8. Settings (every rule is a setting, with a default)

Storage: `PerformanceSetting` keys, agency-scoped (same mechanism as `contacts_per_page` on the wizard's Contacts step; confirm per-agency scoping at build, otherwise a small `contact_stage_settings` table). No migration needed for settings if confirmed.

| Key | What it is | Default |
|---|---|---|
| `stages_rental_enabled` | Use rental stages for this agency | **On** |
| `stages_buyer_enabled` | Use Lead / Buyer / Past buyer | **Off** (until ruled) |
| `stages_seller_enabled` | Use Prospect / Seller / Past seller | **Off** (until ruled) |
| `stage_past_applicant_on_decline` | Declined or withdrawn → Past applicant | **On** |
| `stage_past_tenant_on_notice` | Also move to Past tenant when notice is accepted, before the lease actually ends | **Off** |
| Stage labels and colours | One name and colour per stage (editable on the sub-tag row) | defaults in section 4.1 |
| `tag_contact_as_tenant_on_approval` | Existing; see 5.4 | unchanged (On) |

**Setup Wizard (Non-negotiable 10a):** the Contacts step gets the five switches. Draft copy:

- *Use rental stages* — explain: "CoreX can label each rental contact as Applicant, Current tenant or Past tenant by itself, from their application and lease." affects: "What the Contacts screen shows next to Tenant, and what the Tenant filter can narrow to. Signing is not affected."
- *Past applicant when declined* — explain: "When an application is declined or withdrawn and the person never had a lease, CoreX labels them Past applicant." affects: "Whether declined applicants stay labelled Applicant or move to Past applicant."

Saver rule (spec §6.1): guard every boolean write with `$request->has()` so a wizard step that does not render a switch never wipes it.

**Role Manager:** new action key `contacts.change_stage` ("Change a contact's stage by hand"), section `contacts`, `config/corex-permissions.php` next to `contacts.edit` (`:623`). Default grant: every role that has `contacts.edit`. Managing sub-tags and stage labels stays on `access_settings`.

## 9. Data model — MIGRATION NEEDED (nothing runs until Johan says go)

1. `contact_tags`: add `system_key` varchar(40) null, `is_system` boolean default 0, `stage_family` varchar(16) null, `stage_order` tinyint null. Unique index `(agency_id, system_key)` (nulls allowed). Existing rows untouched.
2. **Seeding:** the migration creates the rental stage rows for every existing agency, under the Tenant parent. The buyer and seller stage rows are seeded too, but their family switch is off. A helper `ContactStageTags::ensureFor($agencyId)` is also called lazily (Settings page load, engine first use, new agency) so a new agency never lacks them.
3. Journal for the re-stage command (section 11): `contact_stage_runs` (id, agency_id, mode, started_at, finished_at, counts json) and `contact_stage_run_items` (run_id, contact_id, before json, after json, applied_at). Created in the same migration.
4. Nothing else: no change to `contact_types`, `contact_contact_type`, `contact_tag`, or `contacts`.
5. After the migration: `php artisan schema:dump`, strip the `DEFINER` clauses, commit the snapshot in the same commit (Non-negotiable 12a).

## 10. Impact map — everything that reads contact type today

"Mirror" = the single main type. "Pivot" = the multi-type list. Status: **CHANGE** (this build edits it), **FIX** (small adjacent fix, recommended), **NONE** (read-only impact, no edit), **CHECK** (verify at build).

| # | Area | Reads | File:line | Effect of stages (automatic sub-tags, new stage) | Status |
|---|---|---|---|---|---|
| 1 | Contacts list filter | Mirror; buyer flag, seller/landlord property links by role | `ContactController.php:226-246`, select `index.blade.php:251-256`, options `:299-303` | Tenant filter misses Seller+Tenant contacts today; stages not selectable | **CHANGE** (pivot + `tag=`, grouped options) |
| 2 | Contacts list rows, contact header | Pivot (header falls back to mirror) | `index.blade.php:793-800`, `_header-badges.blade.php:14` | Sub-tags never drawn | **CHANGE** (pills) |
| 3 | Contacts export | Mirror name; same filter as the list | `ContactExportController.php:100, 189-203` | Same blind spots | **CHANGE** (shared filter, Stage column) |
| 4 | Type picker + save | Pivot; full replace | `ContactController.php:1495-1563`, `:2293-2303`, `_type_picker.blade.php:18,45`, `Contact.php:252-283` | A full-replace save would wipe stages or other types | **CHANGE** (stage line locked in picker; engine never uses full replace; keep the "never strip what the picker did not offer" guard at `:1521-1535`) |
| 5 | Rentals → Contacts view | Pivot role Lessor/Lessee + landlord link | `Contact.php:208-235`, `ContactController.php:272, 300-302` | Past tenants stay in the view (they still hold the Tenant parent). Contacts typed only on the mirror (imports) are missed | **CHECK** (fixed by rows 12–14) |
| 6 | Application approval | Looks up "Tenant" **by name**, adds it | `AddTenantTypeOnRentalApproval.php:81-109`, `AppServiceProvider.php:953` | Contradicts "lease decides" | **CHANGE** (section 5.4) |
| 7 | Application create | Sets no type | `RentalApplicationController.php:854-870` | Where "sent" will fire the engine | **NONE** (event added at `:1584`) |
| 8 | Landlord link / owner→seller | Adds Lessor; promotes Owner to Seller, keeps owner sub-tags | `ContactPropertyLinker.php:105-121`, `PromoteOwnerToSellerOnPropertyLink.php:39-73` | Add-only, safe. Seller family (proposal) would later hook the same link | **NONE** |
| 9 | E-sign signer resolution | 1) property link role, 2) pivot esign_role, 3) mirror | `ESignWizardController.php:5528-5569` | Applicant/Past tenant hold the Tenant parent (role lessee), so resolution is unchanged | **NONE** (regression test) |
| 10 | E-sign contact search + signer payload | Mirror OR pivot; buyer flag; seller by property link; payload sends the **mirror's** role | `ESignWizardController.php:1460-1514`, `:1579`, `wizard.blade.php:4107` | If the mirror moves (e.g. Tenant added to a Seller) the wizard role can change | **CHECK** (engine keeps the mirror rule; test) |
| 11 | Party labels / new contact from role | Parent by role, then by **name** | `PartyRoleLabel.php:43-73`, `ESignWizardController.php:833-870` | Duplicates/renames of parents would break it; stages are sub-tags so unaffected | **NONE** |
| 12 | CSV contact import | Type and tag **by name**; mirror only; fires `ContactTagged` | `ContactImportController.php:156-158, 287, 316, 333, 585` | No pivot row, so no list badge and not in the Rentals view | **CHANGE** (shared assigner) |
| 13 | Take-on / Reos import | "Tenant"/"Landlord" **by name**; mirror only | `RentalTakeOnConfirmService.php:254-262`, `ReosTenancyConfirmService.php:441` | Same; imported active leases need a stage | **CHANGE** (shared assigner + engine call) |
| 14 | Portal leads P24 / PP / website / PP webhook | Lessee→Tenant→Buyer→**Lead** by name; mirror only | `P24LeadService.php:344-349`, `PpLeadService.php:376-381`, `WebsiteLeadService.php:174-179`, `PpWebhookController.php:187-221` | The old "Lead" type has no parent or sub-tag | **CHANGE** (shared assigner; Lead home, question 6) |
| 15 | Buyers report | Mirror's type **name** by LIKE (Buyer / Lead / Tenant) | `BuyersReportService.php:196, 284, 527, 576`, `BuyersReportDrilldownService.php:93` | Sub-tags invisible. If the mirror moves to a lower-sort parent the contact changes bucket | **CHECK** (test on mirror stability; report moving to the pivot is a separate ticket, question 12) |
| 16 | Buyers list, Core Matches (web) | Buyer **flag**, not type | `Contact.php:1903` | None | **NONE** |
| 17 | Seller outreach / MIC pitch | Property link roles (owner, seller, landlord), not type | `SellerOutreach/TransactionStateService.php:41, 190` | None | **NONE** |
| 18 | FICA | Contact and FICA submissions; no type read | `FicaQuickState.php` | None | **NONE** |
| 19 | Rental applications, leases (other) | Contact on application or lease; no type read | `RecomputeRentalApplicationStatus.php`, `Lease::tenants()` | They become the **sources** of events | **NONE** |
| 20 | Mobile app | Picker lists **all** active types; sends the type name; role from the mirror | `MobileContactController.php:110, 295, 317`, `MobilePropertyController.php:1198, 1233, 1264, 1272-1275`, `MobileCoreMatchController.php:48, 98` | New additive fields (`stage`, `sub_tags`); the type list stays; creates go through the shared assigner. Old app builds ignore the new fields | **CHANGE** (additive; mobile addendum `*-MOBILE-PROMPT.md` per repo convention) |
| 21 | Desktop quick-add pickers | Lists **all** active types, including old composites | `properties/show.blade.php:9962, 10270`, `PropertyContactController.php:75, 195, 512`, `ContactPropertyController.php:49-52`, `PropertyController.php:1668` | Same list problem; role from the mirror | **FIX** (use `ContactType::parents()`; recommended, tiny) |
| 22 | Calendar, Ellie | Display of the type name only | `CalendarEventService.php:706`, `CalendarController.php:3246`, `EllieToolkit.php:636-646` | None | **NONE** |
| 23 | Notifications / alert recipients | No type read found | – | None | **NONE** |
| 24 | Client app / portal access | No type read found | – | None | **NONE** |
| 25 | Agency Tracker, deals, commission | No type read found | – | None | **NONE** |
| 26 | Setup Wizard | No contact-type step today | `agency-onboarding-copy.php:1897` (Contacts step) | New switches | **CHANGE** (section 8) |
| 27 | Domain events | "Contact tagged" is logged only | `AppServiceProvider.php:888` | The engine fires it; nothing else reacts | **NONE** |
| 28 | Clean-up commands | Collapse old composite types into parents + sub-tags | `NormaliseContactTypes.php`, `SyncNamedFields.php` | Not opened in detail | **CHECK** (read both before build) |

**The two rules that keep the above safe:**

1. **Every writer goes through one `ContactTypeAssigner`** (parent by role/key, optional sub-tag/stage) that writes the pivot **and** the mirror. Rows 12, 13, 14, 20 and the quick-add use it. After this, "mirror set, pivot missing" cannot be created again. The re-stage command also reports and repairs existing mismatches (the QA1 Tenant counts, 81 on the mirror and 76 in the pivot, show they exist).
2. **The mirror stays "the lowest-sort parent"** (`Contact.php:269-283`). Adding the Tenant parent to a Buyer or Seller may move their mirror; the build must test the result against rows 10 and 15 before anything ships.

## 11. One-off command: `contacts:restage`

`php artisan contacts:restage --agency=<id|all> --family=rental [--dry-run] [--apply] [--undo=<run id>]`

- **Default is a dry run.** Nothing is written without `--apply`.
- Works agency by agency, explicitly (never relies on a logged-in user).
- For each contact with an application or lease in the family, runs the same `recompute()` as the engine and records `before` and `after` in `contact_stage_run_items`.
- The report lists: counts per stage; contacts changed; **"manual Tenant, no application or lease"** (Leigh Arendse type cases) — **left untouched and listed for the agents to review**; contacts with a mirror but no pivot row (repaired only with `--repair-pivot`).
- **Undo:** `--undo=<run>` restores `before` for each item, but only where the contact still equals `after` (anything changed since is skipped and reported).
- Idempotent; chunked; runs on QA1 first. Staging and live need your explicit order (CLAUDE.md rule 5).
- Dry-run output is shown to you before any `--apply`.

## 12. Tests (one file per concern; run through `scripts/lane-test.sh`, one file at a time)

| File | What it proves |
|---|---|
| `ContactStageRecomputeTest` | The 5-rank table: sent → Applicant; declined only → Past applicant; active lease → Current tenant; renewal keeps Current tenant (no Past flicker); last lease cancelled → Past tenant; past tenant re-applies → Applicant; co-tenants move together; idempotent on a second run |
| `ContactStageSwapSafetyTest` | A Seller + Tenant contact keeps Seller after every swap; other sub-tags survive; mirror and pivot agree after the swap; `ContactTagged` fired once |
| `ContactStageEventsTest` | The new `RentalApplicationSent`, `LeaseActivated`, `LeaseEnded` fire from the real services; a listener exception does **not** break the lease action; approval no longer adds Tenant with stages on, and still does with stages off |
| `ContactListStageFilterTest` | Grouped options; `type=` reads the pivot (Seller+Tenant found under Tenant); `tag=` filters; combines with agent/search; hand-edited `tag=` cannot widen scope; another agency's tag id returns nothing; no counts in options; empty-state text |
| `ContactListStagePillsTest` | Row and header render the stage pill; same-label collapse; blade render gate and browser smoke at 1366×768 (BUILD_STANDARD §0a) |
| `ContactSearchStageTest` | Search finds a contact by stage name |
| `ContactTypeSettingsTest` | Stages: rename/colour only, no delete, no move, key unchanged; custom sub-tags full CRUD and parent link; stale "four roles" text gone; search box on the list |
| `ContactStageSettingsTest` | Defaults; wizard saver does **not** wipe a switch the step did not render (spec §6.1) |
| `ContactTypeAssignerTest` | CSV import, take-on, Reos, P24/PP/website lead, mobile create all write pivot **and** mirror |
| `ESignStageRegressionTest` | An Applicant and a Past tenant resolve to role lessee; a Seller+Tenant keeps the right role per template; signer payload role |
| `ContactsRestageCommandTest` | Dry run writes nothing; apply matches the recompute table; undo restores; undo skips contacts changed since; manual-Tenant contacts are listed and untouched |
| `ContactStageScopingTest` | Own/branch/agency on the list, export and contact page; agency isolation of seeded stages |
| `BuyersReportMirrorStabilityTest` | Adding the Tenant parent does not move a Buyer between report buckets |

## 13. Build phases (one concern per prompt; none starts before approval)

1. **Data + assigner:** migration (columns, seeding, journal tables, schema dump), `ContactStageTags::ensureFor`, `ContactTypeAssigner`, `Contact::swapStageTag`. *(migration needs Johan's go)*
2. **Engine + events (rental):** the three new events, `ContactStageService`, listeners, section 5.4 behaviour.
3. **Contacts screens:** pills, grouped filter, pivot-based type filter, export column, header, History line, contact-page picker line.
4. **Settings + wizard + permission:** the Contact Types block, stage settings, wizard copy, `contacts.change_stage`.
5. **Writers on the assigner:** imports, take-ons, portal leads, quick-add pickers, Lead home.
6. **Mobile addendum** (written for the mobile lane) and the additive API fields.
7. **Re-stage command** and its dry-run review with Johan on QA1.
8. **Sales families** (only after question 5 is ruled).

## 14. Files to create / modify

**Create:** `app/Services/Contacts/ContactStageService.php`, `ContactStageTags.php`, `ContactTypeAssigner.php`; `app/Events/RentalApplication/RentalApplicationSent.php`; `app/Events/Rentals/LeaseActivated.php`, `LeaseEnded.php`; `app/Listeners/Contact/RecomputeContactStage.php`; `app/Console/Commands/ContactsRestage.php`; the migration; the tests in section 12; `.ai/specs/contact-stages-MOBILE-PROMPT.md`.
**Modify:** `app/Models/Contact.php` (`swapStageTag`), `ContactTag.php`, `ContactController.php`, `ContactExportController.php`, `ContactTagController.php`, `SettingsController.php`, `AddTenantTypeOnRentalApproval.php`, `RentalApplicationController.php` (fire the event at `:1584`), `LeaseActivationService.php`, `LeaseArchiveService.php`, `LeaseRenewalService.php`, `AppServiceProvider.php` (explicit listener registrations), the import/lead services in section 10, `contacts/index.blade.php`, `_header-badges.blade.php`, `_type_picker.blade.php`, `settings.blade.php`, `config/agency-onboarding-copy.php`, `config/corex-permissions.php`, `.ai/specs/corex-domain-events-spec.md` (catalogue), `.ai/specs/rental-applications.md` (Contact status section), `.ai/specs/contact-types-and-tags.md` (cross-reference), `.ai/CHAT_STARTER.md`.

## 15. Acceptance criteria

1. Send an application to a new contact: they read **Tenant · Applicant** on the list, the header and the filter, within the same request.
2. Decline it: **Past applicant**. Re-open or re-apply: **Applicant**.
3. Activate a lease with them: **Current tenant**, and the application tab still reads correctly.
4. Renew the lease: still **Current tenant**, no Past tenant at any moment.
5. Cancel the lease: **Past tenant**, and they are still found in the Rentals view and under "Tenant (all)".
6. A Seller who becomes a Tenant keeps Seller, and is found under both filters.
7. Filtering on any parent or any sub-tag works on the list **and** the export, and never shows another agency's tags or contacts outside the user's scope.
8. An agency renames "Current tenant" to "Resident": the pill, filter and wizard use the new name and the engine keeps working.
9. E-sign: an Applicant and a Past tenant are offered and resolved as Lessee exactly as a Tenant is today.
10. Turning rental stages off restores today's behaviour (including approval adding Tenant).
11. The re-stage dry run on QA1 lists every change and the manual-Tenant cases, applies cleanly, and undoes cleanly.
12. Nothing in the Buyers report, Core Matches, seller outreach, FICA or calendar changes (section 12 regression tests).

## 16. Multi-agency check (Non-negotiable 9)

- No agency id, no HFC wording in code. Stage labels are per-agency data. Defaults are neutral English.
- Seeding runs for every agency and lazily for new ones. Mega Real Estate (agency 23) has no sub-tags today; it gets the four rental stages like everyone else.
- Settings are per agency; the existing approval setting keeps its per-agency value.

## 17. Open questions for Johan (each with my recommendation)

1. **Name of the middle rental stage.** "Tenant" would read "Tenant · Tenant". *Recommend* **"Current tenant"** (agencies can rename it).
2. **Approved but no lease yet:** Applicant or Tenant? *Recommend* **Applicant** ("approved" is already shown on the application). The lease makes a Tenant.
3. **A tenant gives notice, or the landlord is not renewing:** Past tenant now, or when the lease actually ends? *Recommend* **when the lease actually ends** (they still live there). A setting is built to switch it earlier (default off).
4. **Past applicant** (declined or withdrawn, never a tenant): keep it as its own stage? *Recommend* **yes** — it is exactly Sherry's "applicant that never became a tenant".
5. **Sales stages (Lead → Buyer → Past buyer; Prospect → Seller → Past seller).** Triggers I would use: Lead when an enquiry or capture creates the contact; Buyer when they become an active buyer (buyer flag or pipeline); Past buyer when they bought through us or are marked lost; Prospect when linked as an owner without a mandate; Seller when a mandate is live; Past seller when sold or the mandate ends. *Recommend* **build rental first; seed the sales stages switched off; I confirm each trigger against the event catalogue and bring you the final table before turning them on.**
6. **Where the old "Lead" type goes.** *Recommend* it becomes the **Lead stage under Buyer** for sale enquiries. A rental enquirer gets no stage until an application is sent. The old "Lead" row is hidden, not deleted, after the re-stage run.
7. **"Lessee" and "Tenant" both exist as parents** with the same signing role. *Recommend* keep both as they are; the engine only uses Tenant; no merge in this build.
8. **Manual change vs automatic:** may an agent change a stage by hand, and does the next event overwrite it? *Recommend* **yes to the manual change (logged), and yes, the next real event overwrites it.** A manual override that blocks the system defeats the point.
9. **People who are Tenant by hand with no application or lease (Leigh-type):** *Recommend* **leave them untouched** and give the agents a list to review (add-only rule), not auto-removed.
10. **A lease whose end date has passed but nobody closed it:** *Recommend* **stays Current tenant** until the agent records the outcome, consistent with your AT-439 rule; the expiry alert is the nudge.
11. **Co-applicants and co-tenants:** *Recommend* they always move together (same as AT-517).
12. **Buyers report** still reads the main type's name. *Recommend* leave it in this build (tested for stability) and move it to the multi-type list as a separate ticket.
13. **Migration:** one migration (columns, stage seeding, journal tables, schema dump). *Recommend* approve it as a single step on QA1 first; Staging and live only on your explicit order.
14. **Desktop quick-add pickers** list old composite types. *Recommend* include the small fix (row 21) in this build.
