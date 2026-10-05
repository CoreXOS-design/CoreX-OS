# Investigation — Quick Links & Quick Actions on the Contact and Property pages

> Status: **INVESTIGATION / DECISION DOCUMENT — no code changed**
> Requested by: Andre · Written: 2026-09-28 · Branch inspected: `AT-438-Multiple-Things`
> Purpose: decide *what* gets a status check, *what* gets a pop-up, and *what* stays a page.

---

## 0. The ask, restated

> "On a contact we want a button a user can click to see if they have done FICA for
> that contact — if not they need to be able to click it and a pop-up should appear
> for them to be able to do the FICA from the contact. Investigate everything that
> connects."

So there are really **two** things being asked for, and they should be decided separately:

1. **A check** — an at-a-glance answer to "is this done?" (FICA done / not done / expiring).
2. **A do-it-here** — the ability to complete that thing without leaving the contact.

Everything below is organised around those two questions.

---

## 1. Headline findings

**1. CoreX already has this pattern — but only on Properties.**
The property page carries a *Compliance Status* readiness panel: a list of gates, each with
a green tick or a red cross, a plain-language reason, and a **"Resolve" button that takes you
straight to the fix**. Missing documents upload *inline on the panel itself*, with the document
type pre-set so it can't be mistyped. Blocked actions (Ad Builder, Market Property, Live
Preview) are greyed out and open the compliance modal instead of failing silently.
Files: `resources/views/corex/properties/partials/readiness-panel.blade.php`,
`resources/views/corex/properties/_compliance-checklist.blade.php`,
`app/Services/Compliance/MarketingReadinessService.php`.

**This is the design we would be extending to Contacts, not inventing.**

**2. The contact page has the *check* for FICA already, and no *action* at all.**
The FICA tab label already carries a live badge — Complete / Expiring / Incomplete —
computed by `Contact::ficaStatus()` (`app/Models/Contact.php:434`). But the FICA tab body
only **lists** what already exists. There is no "Start FICA", no "Request FICA", no
"Capture paper FICA" — nowhere on the contact page. To start one today an agent must
leave the contact, go to Compliance → FICA → New Request, and **search for the same contact
again by name**.

**3. The property page already deep-links into that dead end.**
When a property is blocked because the seller has no FICA, the compliance checklist renders a
**"Verify FICA"** button pointing at the seller's contact page FICA tab
(`MarketingReadinessService.php:358-378`). The agent arrives at a tab that shows them the
problem and offers no way to fix it. That is the single most obvious hole in the system today.

**4. There is a permissions landmine in front of this.**
The entire `compliance/fica/*` route group is gated on `permission:access_compliance`
(`routes/web.php:2459`). The **default `agent` role does not have `access_compliance`**
(`config/corex-permissions.php:953-1050` — it has `fica.view`, which only controls *which rows*
an agent sees in the list, not whether the door opens at all).

Consequences, both of which need a decision before any build:
   - A "Start FICA" button on the contact page would 403 for a plain agent under default roles.
   - **Pre-existing, report-only:** the *existing* "View" and "PDF" buttons in the contact's
     FICA tab (`_fica-tab-body.blade.php:106,112`) point into that same gated group. Under
     default roles an agent clicking them gets a 403. Flagging, not touching — outside this task.
   - Caveat: roles are per-agency configurable in Role Manager, so HFC's live roles may
     already grant it. **Worth checking the live role config before assuming a problem.**

**5. Almost nothing in CoreX accepts "start this for contact X".**
Only five flows take a contact prefill today: the property create form, the property upload
wizard, the calendar (`prefill_contact_id`), the PDF splitter, and seller outreach. Deals,
e-sign, rental applications, viewing packs and presentations all make you start from scratch
and find the person again. This is the real cost the quick-actions idea is aimed at.

---

## 2. Everything that connects to a **Contact**

The contact page has 11 tabs: Info · Properties & Core Matches · Viewings & Feedback ·
Notes & Testimonials · Drive · Rental Applications · FICA Compliance · Consent ·
Communications · Outreach · History.

### 2a. Status signals that already exist in code (free — nothing to build)

| Signal | Where it comes from | Currently shown as |
|---|---|---|
| FICA status (complete/expiring/incomplete) | `Contact::ficaStatus()` | badge on the FICA tab |
| Communication status (5 states) | `communicationStatusMeta()` | coloured badge in the header |
| Consent per type (given/no/not recorded) | `consentStates()` | list on the Consent tab |
| Marketing opt-out | `messaging_opt_out_at`, `isOptedIn()` | "opt-out" badge on the Outreach tab |
| Buyer pre-approval valid? | `hasValidPreapproval()` | **nowhere on the page** |
| Has a client-app login? | `hasClientLogin()` | inside a collapsed panel |
| Has a wishlist / Core Match? | `hasCountableWishlist()`, `primaryMatch()` | count on a tab |
| Dead end (no contactable details) | `deadEndFlag` | warning banner |
| Structured address on file? | `hasStructuredAddress()` | **nowhere** |
| Is a company/entity? | `isEntity()`, `representatives` | Info tab |
| Document count | `documents` | count on the Drive tab + header facts |

**Read: we already compute nine "is this done?" answers and surface most of them as passive
counts buried on tabs. Nothing is a clickable check.**

### 2b. What you can already DO from the contact page

| Action | How it works today | Leaves the page? |
|---|---|---|
| Schedule an event | header button → calendar with `prefill_contact_id` | **yes — new tab** |
| Birthday reminder on/off | inline POST | no |
| Buyer Hub | header button → `command-center.buyers.show` | yes |
| Create Listing (wizard or classic) | header dropdown, only when 0 properties linked | **yes — new tab** |
| Delete contact | inline POST | no |
| Add/edit note, testimonial | inline forms on the tab | no |
| Upload a document | inline form on the Drive tab | no |
| Record / revoke consent | inline POST buttons | no |
| Create, reset, revoke client login | inline panel | no |
| Link a property, link a representative | inline search + POST | no |
| Create / edit a Core Match (wishlist) | inline form + drawer | no |
| Compose outreach pitch | link → `contacts/{contact}/outreach/compose` | yes — full page |

### 2c. What connects but has NO action from the contact

| Connected thing | Where it lives | Can you start it from the contact? |
|---|---|---|
| **FICA submission (request by email)** | `compliance.fica.create/store` | **No** |
| **FICA wet-ink (paper) capture** | `compliance.fica.wet-ink.*` | **No** |
| Deals | `deals-v2.create`, `create-wizard` | No — listed only (`$linkedDeals`) |
| E-sign / DocuPerfect document | `docuperfect.esign.create` | No |
| Rental application | `corex.rental-applications.create` | No — listed only |
| Viewing pack | `corex.viewing-packs.store` | No — built from a calendar event |
| Presentation | property-side generator | No |
| Evaluation certificate / CMA | `tools.cma.evaluation.*` (has its own contact search) | No |
| Seller information pack | `compliance.seller-info.*` | No |

---

## 3. Everything that connects to a **Property**

### 3a. Already a quick-check system (the model to copy)

The **Compliance Status** panel evaluates these gates and gives each one a Resolve button:

| Gate | What it checks | Resolve action today |
|---|---|---|
| Agency-required documents | one per document type the agency flagged required (configurable per agency, `agency_document_type_compliance`) | **inline upload, type pre-set** |
| Seller FICA | approved `fica_submissions` on a linked seller contact | link to the seller's FICA tab (dead end — see §1.3) |
| Photos | at least 4 | jump to Gallery tab |
| Listing details | required fields filled | jump to Info tab |

Plus **Go Live & Start Marketing**, which snapshots compliance and unblocks marketing.
Blocked actions (Live Preview, Ad Builder, Market Property) grey out and open the
compliance modal — an existing, working "you can't do this yet, here's why" pop-up.

### 3b. Property actions that already exist in the header strip

Syndication (modal) · Live Preview · Ad Builder · Market Property · Pitch Seller ·
Share listing · Generate Presentation · Duplicate · Change type · Mark not selling (modal) ·
Mark sold · Archive.

### 3c. Property-side gaps worth a quick action

| Connected thing | Status today |
|---|---|
| Mandate signing (e-sign) | no entry point from the property |
| Book a viewing for this property | only from the calendar side |
| Create a deal from this property | no prefill |
| Deeds / owner lookup | separate module |
| Seller report / seller live link | on the Intelligence tab, not in the action strip |

---

## 4. The FICA worked example — today vs proposed

**Today, an agent who realises a seller needs FICA:**
1. Notes the contact's name.
2. Leaves the contact page.
3. Sidebar → Compliance → FICA.
4. "New Request".
5. Types the contact's name into a type-ahead and picks them again.
6. If the contact has no email, adds one there (the form already supports this).
7. Sends. Lands back on the FICA list — not the contact.
8. Navigates back to the contact to carry on.

**Proposed:** a FICA chip in the contact header reading **"FICA — Not done"**. Click it,
a pop-up opens with the contact already filled in, two choices — *Email the client a
secure link* or *Capture a paper FICA* — and on save the chip turns green without the
page ever changing.

**Feasibility:** the email-request form is genuinely two fields — `contact_id` plus an
optional `email`. It is the cheapest possible pop-up in the system. The wet-ink form is
heavier (entity type, date received, three required file uploads, supporting docs, a
confirmation tick) but still modal-sized — and it *already* has an endpoint that pulls the
contact's existing documents into its picker (`compliance.fica.contact-documents`), so the
contact is already a first-class input to it.

---

## 5. The four patterns available, and when each is right

| Pattern | Use it when | Existing example |
|---|---|---|
| **Status chip only** | the answer is the value; acting means going somewhere real | comm-status badge in the contact header |
| **Chip → pop-up form** | the form is small, self-contained, and finishing it should return you to where you were | property syndication modal, "Mark not selling" modal |
| **Chip → inline panel** | the thing is a list you also need to read, not a one-shot form | Consent tab, Client App Access panel |
| **Chip → new tab** | the destination is a long workflow you'll live in for a while | Schedule Event, Create Listing |

Infrastructure already in place for pop-ups: the `<x-modal>` Blade component
(`open-modal` / `close-modal` window events) and, new on this branch, the global
`window.CoreXDocViewer` document pop-up. Nothing new is needed to *build* a pop-up.

**One hard constraint:** CLAUDE.md Non-negotiable #7 forbids hidden JSON endpoints. Any
pop-up that loads or saves data must post to an existing named route or a new
`/api/v1/*` named route that appears in the Admin → API catalog.

---

## 6. Recommended shortlist — what to check, what to pop up

### Tier 1 — build these (highest pain, lowest cost, no new concepts)

| # | Check | Pattern | Why |
|---|---|---|---|
| 1 | **FICA** on a contact | chip → **pop-up** (request by email / capture paper) | the literal ask; closes the property page's dead-end deep link; the request form is two fields |
| 2 | **Consent / can I contact them** | chip → **pop-up** (the consent grid, already built) | every send in CoreX is gated on this; today it's a tab click away and invisible from the header |
| 3 | **Contact details complete** (phone, email, ID number, address) | chip → **pop-up** to fill the gaps | FICA, e-sign, deals and mandates all fail downstream on missing ID/address; failing early is cheaper |
| 4 | **Seller FICA** on a property | make the existing "Verify FICA" button open the *same* FICA pop-up on the seller | one fix makes the property gate actually resolvable in place |

### Tier 2 — strong candidates, decide after Tier 1 lands

| # | Check / action | Pattern | Why |
|---|---|---|---|
| 5 | **Buyer pre-approval** (amount + expiry) | chip → pop-up | already computed (`hasValidPreapproval()`), shown nowhere; it's the question every agent asks a buyer |
| 6 | **Client app access** | chip → existing panel, promoted out of the collapse | already fully built, just buried |
| 7 | **Book a viewing** for this contact | pop-up instead of a new calendar tab | today it throws the agent into a different page entirely |
| 8 | **Documents on file** (what's missing) | chip → inline list, upload in place | mirror the property compliance checklist at contact level |

### Tier 3 — quick links only (no pop-up; these are real workflows)

Start a deal · start an e-sign document · start a rental application · build a viewing pack ·
generate a presentation. These should become **"start this, for this person"** links that
carry a contact prefill — the work is in the *receiving* screens accepting `contact_id`,
not in the contact page.

### Deliberately NOT recommended

- **Outreach compose as a pop-up.** It's a full composer with templates and previews; it
  deserves its page.
- **Deal creation as a pop-up.** A deal has too many required decisions to be a modal.
- **A "do everything" mega-menu.** The property page shows the failure mode: twelve action
  buttons in a strip is already at the edge of readable.

---

## 7. What has to be decided before anything is built

These are business calls, not engineering ones:

1. **Who may start a FICA?** Right now the whole FICA module is closed to a default-role
   agent. Either agents get the door key, or the button only appears for compliance staff,
   or we introduce a narrow "request FICA" grant. This changes who the feature is *for*.
2. **What counts as "done" for a contact?** FICA alone, or a set — FICA + consent +
   ID number + address? If it's a set, it should be **agency-configurable**, exactly like
   the property's required document types already are. That is the difference between a
   one-off button and a system.
3. **Where do the chips live?** In the contact header (always visible, competes with the
   existing badges and six fact cells), or as a strip directly under it (like the property
   readiness panel). Recommendation: a strip, matching the property page.
4. **Do checks block anything?** On properties, a failed gate genuinely blocks marketing.
   On contacts — should missing FICA block sending a mandate for signature, or just warn?
5. **Same treatment on Properties?** The property page already has the panel. The question
   is whether its Resolve buttons become pop-ups too, rather than jumps to other tabs.

---

## 8. Files an implementation would touch (reference)

**Contact**
- `resources/views/corex/contacts/show.blade.php` — tab bar, tab panes, Alpine root
- `resources/views/corex/contacts/_header.blade.php`, `_header-actions.blade.php`, `_header-badges.blade.php`
- `resources/views/corex/contacts/_fica-tab-body.blade.php`, `_consent-tab-body.blade.php`
- `app/Http/Controllers/CoreX/ContactController.php` — `show()`
- `app/Models/Contact.php` — `ficaStatus()`, `consentStates()`, `hasValidPreapproval()`

**FICA**
- `routes/web.php:2459-2497` — the `compliance.fica.*` group
- `app/Http/Controllers/Compliance/FicaController.php` — `create/store`, `createWetInk/storeWetInk`
- `resources/views/compliance/fica/create.blade.php` (139 lines), `create-wet-ink.blade.php` (242 lines)

**Property (the pattern being copied)**
- `resources/views/corex/properties/partials/readiness-panel.blade.php`
- `resources/views/corex/properties/_compliance-checklist.blade.php`
- `app/Services/Compliance/MarketingReadinessService.php`, `ReadinessReport.php`
- `app/Services/Compliance/AgencyComplianceDocTypeService.php` — the per-agency configurable model

**Shared**
- `resources/views/components/modal.blade.php` — the modal component
- `config/corex-permissions.php` — role defaults (`agent` block from line 953)

---

## 9. Scope confirmation

Investigation only. No file in the repository was modified. This document is the only
file created.
