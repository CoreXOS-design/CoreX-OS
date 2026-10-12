# Quick Links — a status chip + one-click action on every record page

> **Status: DRAFT SPEC for Johan's review. No code written.** Requested by Johan 2026-10-12.
> Builds on `.ai/investigations/contact-property-quick-actions-2026-09-28.md` (Andre) and a four-way read-only audit of the
> whole system done 2026-10-12 (contacts / properties / deals & documents / rentals, compliance, admin, dashboards).
> Audit findings marked *(unverified)* were read from routes and model names, not from every Blade file — confirm on build.

## 1. What this is and why

An agent looking at a record (a contact, a property, a deal, a lease…) should see, at a glance, whether the next
thing that needs doing for that record **has been done**, and be able to **do it from where they are** — without leaving
the page and re-finding the same person or property somewhere else.

Johan's worked example: on a contact, a **FICA** button. If the contact has no FICA, one click sends them the FICA form; if one has
been sent, the same spot shows its status (awaiting client, under review, approved, expiring…).

One quick link = **a status chip** (what is the state?) **+ an action** (what can I do about it?), shown only to people allowed to do it.

## 2. Pillars

Reads from and writes to all four: Contact, Property, Deal, Agent. A quick link never creates a new island: every action
goes through the **existing** route/controller for that action; the chip only reads state the models already compute.

## 3. The building blocks (built once, used everywhere)

1. **`<x-quick-link>`** — one Blade component: coloured chip (state + short text) and, optionally, one action button.
   Renders **nothing** when the user lacks the permission, the feature is switched off for the agency, or the state does
   not apply. House style = `ds-badge` variants already in `resources/css/nexus.css`. (There is no generic chip today;
   only `buyer-state-chip`.)
2. **Per-record status methods** — e.g. `Contact::quickStatuses()`, `Property::quickStatuses()`, `Lease::quickStatuses()` —
   return the list of chips for that record, built from methods that **already exist** (`ficaStatus()`, `hasValidPreapproval()`,
   `ReadinessReport`, `isOverdue()`, `outstandingSignatories()` …). List pages use eager-loaded/subselect versions so a
   page of 50 rows is **not** 50 extra queries (cost contract: asserted in a test, as with System Updates).
3. **Pop-up shell** — the existing `<x-modal>` (`open-modal`/`close-modal`). Small forms open as a pop-up and, on save, the
   page refreshes the chip in place or returns to the same page. Long workflows (deal, e-sign, mandate) are **links** that
   open the existing screen **pre-filled**, never pop-ups.
4. **Pre-fill on the receiving screens.** Several screens cannot be "started for this person/property" yet because they ignore
   `?contact_id=` / `?property_id=` — see §6. Adding those is the unlock for most of the Wave 2 links.
5. **Every action keeps its existing permission.** A chip is shown only if the user holds the key that gates the target route
   (else it would render and 403). No new permission keys are needed for the chips themselves.

### Rules (all waves)
- **Multi-agency:** no HFC wording/defaults. Thresholds that are business choices (FICA expiry months, "stale" days, "expiring soon"
  days, overdue days) read the agency's setting; where none exists today the existing constant is kept and recorded in §9.
- **Scoping:** the chip's state and the action honour own/branch/agency exactly as the page does (global scopes). Direct POST by id
  to a record the user cannot see is refused at the query layer — the button is never the only guard.
- **Feature gates:** a chip for a switched-off feature (Compliance, Rentals, Auctions…) is not rendered, matching the Features page rule.
- **No hard deletes**, soft deletes only. Nothing here deletes.
- **Mobile:** chips wrap; pop-ups are full-width on phones.
- **Audit:** an action fired from a chip is logged the same way the original screen logs it (e.g. FICA request records `requested_by`).

## 4. Wave 1 — FICA from the contact (the literal ask; ships first)

### 4.1 What the agent sees (contact page header, and the FICA tab)
A **FICA chip** next to the existing badges:

| State (computed) | Chip | Button |
|---|---|---|
| No submission, no legacy e-sign FICA | red "FICA — not done" | **Send FICA form** |
| Submission `draft` (link sent, client has not filled it) | grey "FICA — awaiting client · sent 3 days ago" (amber if link expired) | **Resend** |
| `submitted` / `under_review` / `agent_approved` / `referred_to_co` | blue "FICA — under review" | View |
| `corrections_requested` | amber "FICA — corrections requested" | Resend |
| `approved`, < 11 months | green "FICA — approved 12 Oct 2026" | View PDF |
| `approved`, ≥ 11 months (existing `ficaStatus()` = expiring) | amber "FICA — expiring" | **Send new FICA form** |
| `rejected` / cancelled | red "FICA — rejected" | **Send FICA form** |
| Legacy e-sign FICA doc only | green/amber per existing `ficaStatus()` | View |

`Contact::ficaStatus()` stays as is (it feeds other places). A new method returns the richer state above; it does not change the old one.

### 4.2 Flow
1. Agent clicks **Send FICA form** → pop-up: contact name (read-only), email (pre-filled; if the contact has none, an inline email field —
   the store action already saves it to the contact), **Send**.
2. On send: the existing `compliance.fica.store` logic runs (creates the `draft` submission, 14-day token, `FicaRequestMail`).
   Agent lands **back on the contact page**, chip now "awaiting client". 
3. **Resend** uses the existing resend logic (new token, `FicaResendLog`, mail) and also returns to the contact.

### 4.3 Gaps in today's code the build must close (found in the audit)
1. `FicaController@store` redirects to `compliance.fica.index`, which needs `access_compliance`. A default **agent** (holds `compliance.fica.send`
   but not `access_compliance`) would be sent to a 403 page **after** the email went out. The quick link must return to the contact.
2. `resend` / `cancel` / the PDF and `show` views live behind `access_compliance`; an agent who may *send* cannot *resend* or *view status
   detail*. Decision needed (§8-1): give `compliance.fica.send` holders resend + a read-only status view of **their own** requests.
3. **No duplicate guard:** `store` happily creates a second `draft` for the same contact. The chip prevents the normal case; the server must
   also refuse (or reuse) when a live `draft` already exists.
4. The contact must be one the agent is allowed to see (own/branch/agency) — `store` uses `Contact::findOrFail` (scoped) — add a test.
5. `FicaRequestMail` wording and the 14-day link life must be checked for HFC-specific text / made agency-neutral (§9).
6. Compliance feature off → chip not shown (routes already carry `feature:compliance`).
7. **Pre-existing, report-only (not touched):** the FICA tab's "View"/"PDF" buttons point into the `access_compliance` group, so a plain agent gets a 403.
   Resolved as a side effect if §8-1 is approved; otherwise left alone.

### 4.4 Same chip elsewhere (re-uses the one component)
Contact list row · Buyer Hub header · Buyer-pipeline cards · lease tenants · rental-application status strip · property "Seller FICA" gate
(its "Verify FICA" button opens the same pop-up for the seller instead of a dead-end tab) · FICA list rows ("Link expired → Resend", "Expires in N days → Renew").

### 4.5 Acceptance criteria (Wave 1)
- Contact with no FICA shows red chip + Send; after Send the chip shows awaiting-client **without** the agent leaving the contact page.
- Second Send for the same contact while a draft is live is impossible (UI) and refused (server).
- Plain agent (default role) completes send → resend → sees status, never hits a 403.
- Contact without email: pop-up asks for it, saves to the contact, sends.
- Agent cannot send for a contact outside their scope; agency A cannot reach agency B (direct POST tested).
- Compliance feature off → no chip, route 404.
- Chip states table above each covered by a test; list page with 50 contacts runs a bounded number of queries.
- Spec appendix in `compliance.md` updated; `CHAT_STARTER.md` entry.

## 5. Catalogue of quick links found (all waves)

Priority = agent time saved. **H** daily-use, **M** regular, **L** nice-to-have. "Prefill ✔" = the receiving screen already accepts the id.
Full row-by-row audit tables are in the 2026-10-12 session notes; this is the consolidated list.

### 5.1 Contact (detail / list / Buyer Hub / pipeline)
| Link | Chip shows | Action | Prefill | Pri |
|---|---|---|---|---|
| **FICA** (Wave 1) | state table §4 | Send / Resend / Renew | store takes `contact_id` ✔ | H |
| Pre-approval | "R1.2m to 30 Oct" / Expired / None (buyers, tenants) | pop-up set amount + expiry | saves via contact update ✔ | H |
| Core Match / wishlist | "Sale · 3 matches" / "No wishlist" | open results or wishlist form | ✔ | H |
| Book viewing | — | calendar pre-filled (contact, class=viewing) | ✔ | H |
| Viewing pack | pack status | create pack for contact | ✔ (`viewing-packs.store`) | H |
| Feedback outstanding | "2 feedback due" | open feedback modal per past viewing | ✔ | H |
| Contact-list row chips | FICA dot, comm status, buyer state, "missing details" | WhatsApp / call / email / schedule icons | ✔ | H |
| Pipeline card chips | pre-approval, FICA, last-contacted staleness | WhatsApp, viewing pack, mark warm/lost | ✔ | H |
| Send new matches (Buyer Hub) | "N new matches" | send | ✔ | H |
| Consent / can-I-contact | comm-status badge made clickable | consent grid pop-up | ✔ | M |
| Client-app login | Active / None | create / reset | ✔ | M |
| Seller pitch | "Pitch pending / last sent 3d ago" | open compose | ✔ | M |
| Buyer-state chip in header | new/warm/cold/lost | mark lost / re-engage | ✔ | M |
| Rental application / Start deal / E-sign for this contact | — | pre-filled link | **needs prefill (§6)** | M |
| Address on file, testimonial, saved list filters, bulk send | — | — | ✔ | L |

### 5.2 Property (detail / list / MIC / CMA / auctions)
| Link | Chip shows | Action | Prefill | Pri |
|---|---|---|---|---|
| Readiness "3/5" | gates passed | opens existing compliance modal at first failing gate | ✔ | H |
| Mandate | "Sole · 12d left" / expired | Renew (existing expiry popup) / Send mandate for signing | e-sign **needs prefill** | H |
| Book viewing | upcoming count | calendar pre-filled (property, optional top buyer) | ✔ | H |
| Deal | none / in progress (step) | Start deal / open pipeline | Start deal **needs prefill** | H |
| Evaluation certificate | none / pending / signed | Create / open | **needs `?property_id=`** | H |
| Portal dots P24 / PP / Web | live / awaiting approval | open existing syndication modal on that portal | ✔ | H |
| Properties list: row chips + quick menu | "Expires 12d", "Not ready (2)", "Stale 45d" | Book viewing, Pitch seller, Share, Eval cert | ✔ (pitch, viewing, share) | H |
| MIC opportunity rows & slide-over | claimed-by, "N buyers match", "Already ours", pitch-readiness | Pitch seller, WhatsApp, fix-it links | ✔ | H |
| Seller FICA gate | blocked/ok | opens §4 pop-up | ✔ | H |
| Seller live link, presentation status, deeds, lease, marketing log, photos | status chips | existing generate/open actions | ✔ | M |
| Seller info pack | "sent on…" | send with property + seller | unknown | M |
| Lost-to-competitors report rows, imported stock rows, deeds-capture rows | state | Pitch seller / Take on | ✔ | M |
| Auctions (property tab, list, show, lots) | lot/auction chips, "N bidders need FICA" | open auction pre-filled, Open deal | ✔ | M |
| PPRA mandate register | "No signed mandate" | Send for e-sign | **needs prefill** | M |

### 5.3 Deals, documents, money
| Link | Chip shows | Action | Prefill | Pri |
|---|---|---|---|---|
| Deal register row: overdue/RAG | "Overdue 3d" | deep-link to first overdue step | ✔ | H |
| Deal register row: proforma | "PF-123 issued" / eligible | Generate / open | ✔ POST on deal | H |
| Pipeline step: send for signing / request document / email attorney | status Sent/Viewed/Signed | existing routes | e-sign **needs prefill** | H |
| Missing documents ("Mandate, FICA, OTP") | per-type chips | upload pre-typed / send for e-sign | upload ✔ | H |
| Unfiled emails | "File to suggested deal D-123" | one-click file (suggestion already computed) | ✔ | H |
| Listing stock rows | "Expiring in 9d", "Stale 21d" | Send renewal, price review, start deal | **needs prefill** | H |
| Settle, proforma email, payslip email, commission ledger links | state | existing routes | ✔ | M |
| My e-sign documents | property/contact context chips, bulk remind | existing | ✔ | M |
| Post-sign: file to deal, email signed copy | — | — | needs `deal_id` on e-sign flow | M |
| Evaluation cert lists, presentations list | "signed, not sent", "viewed 3×" | send to seller, follow up | ✔ | M |

### 5.4 Rentals, compliance, people, dashboards
| Link | Chip shows | Action | Prefill | Pri |
|---|---|---|---|---|
| Lease list/show | escalation due, expires in N days, tenant FICA | Escalate, Renew, Log fault, Start inspection | fault/WO ✔, inspection/inventory **needs prefill** | H |
| Rental applications list/show/queue | FICA outstanding, ID pending, pending authorisation | Resend FICA, Authorise, Create lease | lease ✔ | H |
| Fault reports / work orders | owner approval, overdue Nd, quotes | request approval, raise WO, nudge, complete | ✔ POST by id | H |
| Inspections / inventories | "awaiting N signatories", discrepancy | resend report, copy tenant link, resolve | ✔ POST by id | H |
| FICA list/show | link expired, expires in N days, awaiting your approval, TFS | Resend, Renew, Approve | ✔ | H |
| Document verification queue | expiry | inline Verify / Reject | ✔ | H |
| Users list | FFC expiry, never signed in | Request FFC upload, Resend invite | resend ✔ | H |
| Leave applications | balance-after, team overlap | Approve / Decline inline | ✔ | H |
| **Today page tiles** (FICA approvals, leave, overdue, my FICA) | per-row | inline Approve / Resend / Done / Snooze — one change to `<x-tile>` (optional per-row action) lights up every card | ✔ | H |
| Notifications, overdue page | by type | Approve / Resend / Done | ✔ | M |
| RMCP / policy dashboards, branches, staff take-on, payroll | coverage chips | Remind / Nudge / Resume | needs user list | L–M |
| Main dashboard KPI cards | — | click-through to pre-filtered list | ✔ | M |

### 5.5 Deliberately NOT quick links
Outreach composer, deal creation form, e-sign wizard and mandate signing stay full screens (they are real workflows) — the quick link is a
**pre-filled link into them**. No "do-everything" mega menu (property header already holds ~12 actions). Offers: no module exists.
Admin config pages: nothing to add.

## 6. Pre-fill enablers (receiving-screen work; unlocks most Wave 2 rows)

| Screen | Needs to accept | Note |
|---|---|---|
| `compliance.fica.create` | `?contact_id=` | only if a full-page route is kept; the pop-up posts to `store` directly |
| `deals-dr2.create` / wizard | `?property_id=` `?contact_id=` | high value |
| `docuperfect.esign.create` | `?property_id=` `?contact_id=` `?template_id=` (+ optional `deal_id` on the flow) | highest value — most "send for signing" links depend on it; e-sign has no deal link today |
| `tools.cma` evaluation form | `?property_id=` | feed routes exist |
| `corex.rental-applications.create` | `?contact_id=` `?property_id=` | **audits disagree** whether `property_id` is already handled (one says yes at line ~986, one says `old()` only) — verify |
| `corex.rental-inspections.create`, `rental-inventories.create` | `?property_id=` (+lease) | |
| `compliance.seller-info` | property/seller | unverified |
Pre-fill is **read-only input convenience**: the receiving screen still applies its own permission, scoping and validation.

## 7. Phasing
- **Wave 0 (foundation):** `<x-quick-link>`, `quickStatuses()` pattern + query-count test, `<x-tile>` per-row action slot.
- **Wave 1:** FICA on the contact + same chip on contact list, Buyer Hub, property seller gate (§4).
- **Wave 2:** prefill enablers (§6), then the High rows of §5.1–5.3.
- **Wave 3:** rentals, compliance queues, Today-page tiles, people/admin.
- **Wave 4:** Medium/Low rows. Each wave is its own commit set, tested one file at a time, and goes to Andre on QA2 before Staging.

## 8. Decisions needed from Johan (business, not technical)
1. May anyone who can *send* a FICA form (agents, branch managers, admins) also **resend** it and **see the status of the requests they sent**, without being given the whole Compliance module? (Recommended: yes — otherwise an agent can send but is blind afterwards.)
2. Should a missing/expired FICA **block** anything from the contact page (e.g. sending a mandate for signature), or only show the chip? (Recommended: chip only for now.)
3. Wave order: confirm FICA first, then "book viewing / pre-approval / readiness" (Wave 2 H rows), then rentals.
4. FICA link life (14 days) and "expiring" threshold (11 months) are fixed today — make them agency settings (then they must also appear in the Setup Wizard per rule 10a), or keep as is?

## 9. Multi-agency / wizard notes
- FICA email text and any chip wording must be neutral (no HFC name) — check `FicaRequestMail` and its view before Wave 1 ships.
- If decision 8-4 is "agency setting", rule 10a applies: the setting must appear in `config/agency-onboarding-copy.php` in the same change.
- Chips introduce **no new setting** otherwise, so nothing else for the wizard.

## 10. Permissions
No new keys for chips. Each chip/button is gated by the key of the route it calls (`compliance.fica.send`, `command_center.calendar.create`,
`viewing_packs.create`, `outreach.compose`, `deals_v2.create`, `access_docuperfect`, `access_calculators`, `leases.create`, …).
Only if 8-1 is approved: extend `compliance.fica.send` to cover resend + own-request status read (route-gate change, no new key).
Default-role check done: `agent` and `branch_manager` already hold `compliance.fica.send`; `access_compliance` is not held by `agent`.

## 11. Files (Wave 0 + 1 only)
**Create:** `resources/views/components/quick-link.blade.php`; `Contact::quickStatuses()` (+ FICA state method) in `app/Models/Contact.php`;
`resources/views/corex/contacts/_fica-quick-link.blade.php` (chip + pop-up); tests `tests/Feature/QuickLinks/ContactFicaQuickLinkTest.php`.
**Modify:** `resources/views/corex/contacts/_header-badges.blade.php` (or `_header-actions`), `_fica-tab-body.blade.php`;
`app/Http/Controllers/Compliance/FicaController.php` (`store`/`resend`: return-to-contact, duplicate guard, scope); `routes/web.php`
(move resend/status behind `compliance.fica.send` only if 8-1 approved); contact list partial for row chip; `.ai/specs/compliance.md`; `.ai/CHAT_STARTER.md`.
