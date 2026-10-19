# Spec: Rental Documents

**Status:** All 6 documents exist as image-based overlays — migrating to Blade web documents rendered via Puppeteer  
**Version:** Based on actual document review — Mandate V5, Disclosure V7, Marketing Permission V7, Application V8, Residential Lease V8, Commercial Lease V5

> **Audit fixes 2026-10-19 (A21):** the signing order of the lease pack is **agent -> tenant(s) -> landlord(s)** (Johan's ruling; code unchanged). HFC-specific policy and letterhead wording is now marked as an example of one agency's own settings, never a shipped default (CLAUDE.md #9).

---

## Document Inventory

| # | Document | File | Signatories |
|---|----------|------|-------------|
| 1 | Marketing Permission | `Letting_Marketing_permission__V7_` | Lessor + Agent + (Addendum A: Lessor + Lessee + Agent) |
| 2 | Letting Mandate | `Letting_Mandate__V5_` | Owner(s) + Agent |
| 3 | Mandatory Disclosure | `Letting_Mandatory_Disclosure__V7_` | Lessor + Tenant + Property Practitioner + Co-signature |
| 4 | Rental Application | `RENTAL_APPLICATION__V8_` | Applicant + Witness (×2 — Application + TPN Consent) |
| 5 | Residential Lease Agreement | `Lease_agreement_-_Popi__V8_` | Lessor(s) + Witness(es) + Lessee(s) + Witness(es) + Agent |
| 6 | Commercial Lease Agreement | `Commercial_Lease_agreement__V5_` | Lessor(s) + Witness(es) + Lessee(s) + Witness(es) + Agent + Co-signature |

---

## The Rental Flow — Document Sequence

```
LANDLORD ONBOARDING
──────────────────────────────────
Step 1:  Marketing Permission      ← Landlord signs first. Gives agency right to market.
Step 2:  Letting Mandate           ← Landlord signs. Grants mandate to let + bank details.
Step 3:  Mandatory Disclosure      ← Landlord fills property condition report. Lessor signs.
                                      (Tenant + Agent also sign — done at lease stage)

TENANT ONBOARDING
──────────────────────────────────
Step 4:  Rental Application        ← Tenant fills and signs. Pre-approval before any viewing.

LEASE CONCLUSION
──────────────────────────────────
Step 5a: Residential Lease         ← All parties sign: Lessor + Lessee + Agent + Witnesses
  OR
Step 5b: Commercial Lease          ← All parties sign: Lessor + Lessee + Agent + Co-sig + Witnesses

MANDATORY DISCLOSURE (finalised)
──────────────────────────────────
Step 6:  Mandatory Disclosure      ← Tenant and Agent sign to complete (if not done at Step 3)
         (Tenant + Agent sign)
```

**Note on Mandatory Disclosure:** The Lessor signs at Step 3 (before marketing). The Tenant and Agent countersign at Step 5/6 when the lease is concluded. This document spans both stages.

---

## Document 1: Marketing Permission

### Purpose
Irrevocable authority granted by the landlord to the agency to market and rent the property. Includes Addendum A (Service Fee breakdown).

### Linked Fields — Four Pillars Mapping

| Field in Document | Source | Pillar | Notes |
|------------------|--------|--------|-------|
| Owner/s name(s) | `contact.full_name` | Contact (Landlord) | Up to 2 lessors |
| Property Erf/Unit no | `property.erf_number` | Property | |
| Complex/Estate name | `property.complex_name` | Property | |
| Street address | `property.street_address` | Property | |
| Township | `property.township` | Property | |
| District | `property.district` | Property | |
| Lessor 1 physical address | `contact.physical_address` | Contact | |
| Lessor 1 Tel | `contact.phone` | Contact | |
| Lessor 1 Email | `contact.email` | Contact | |
| Lessor 2 physical address | `contact_2.physical_address` | Contact | Optional |
| Lessor 2 Tel | `contact_2.phone` | Contact | Optional |
| Lessor 2 Email | `contact_2.email` | Contact | Optional |
| Rental amount (R) | `deal.rental_amount` | Deal | |
| Commission % | `deal.commission_rate` | Deal | Default from Settings |
| Signed at (place) | manual entry | — | Agent fills at signing |
| Date | auto: signing date | — | |
| **Addendum A** | | | |
| Total Rental Amount | `deal.rental_amount` | Deal | |
| Agent's Service Fee (incl VAT) | calculated | — | 10% + VAT of rental |
| Let's Assist Fee | `deal.lets_assist_fee` | Deal | |
| Net Amount to Lessor | calculated | — | Rental minus fees |

### Signatories (in order)
1. **Lessor** (+ optional Lessor 2) — signs Marketing Permission body
2. **Witness** — witnesses Lessor signature
3. **Marketing Permission Agent** — agent signs
4. *(Addendum A signed after tenant is confirmed)*
5. **Lessor** — signs Addendum A
6. **Lessee** — signs Addendum A
7. **Agent** — signs Addendum A

### Agency Header
All documents show the agency's own letterhead. **Example only** - one agency's letterhead as it was captured when this spec was drafted; no value below is ever shipped as a default (CLAUDE.md #9):
- Johan and Elize Properties T/A Home Finders Coastal
- Shop 5 The Emporium, Cnr King Rd & Marine Drive, Shelly Beach
- Reg no: 2017/431318/07 | FFC: 202615038880000 | VAT: 4630287821
- Email: admin@hfcoastal.co.za | FIC AI/180629/0000019
- Elize Reichel Cell: 071 351 0291 | Johan Reichel Cell: 076 618 5578

*These values come from Settings → Company & Branches — never hardcoded.*

---

## Document 2: Letting Mandate

### Purpose
Grants the agency the mandate to offer the property to let. Includes commission rate, mandate period, and the landlord's bank account details for rental disbursements.

### Linked Fields

| Field in Document | Source | Pillar | Notes |
|------------------|--------|--------|-------|
| Owner/s name | `contact.full_name` | Contact (Landlord) | |
| Agent (HFC) | `agent.full_name` | Agent | |
| Property address (full) | `property.full_address` | Property | |
| Rental amount (R) | `deal.rental_amount` | Deal | |
| Commission % | `deal.commission_rate` | Deal | |
| Mandate end day | `deal.mandate_end_day` | Deal | |
| Mandate end month | `deal.mandate_end_month` | Deal | |
| Mandate end year | `deal.mandate_end_year` | Deal | |
| Account Holder's Name | `contact.bank_account_holder` | Contact | Landlord banking |
| Bank Name | `contact.bank_name` | Contact | |
| Account Number | `contact.bank_account_number` | Contact | |
| Branch Name and Code | `contact.bank_branch_code` | Contact | |
| Owner's Contact details | `contact.phone` | Contact | |
| Owner's Email Address | `contact.email` | Contact | |
| Signed at (place) | manual | — | |
| Date + time | auto | — | |

### Signatories (in order)
1. **Owner** (signature + print name)
2. **Owner 2** (optional — signature + print name)
3. **Agent** (signature + print name)

---

## Document 3: Mandatory Disclosure

### Purpose
Property condition report required under Property Practitioners Act 22 of 2019, Section 70 and Regulations 2022 Section 36. Lessor discloses all known defects. Legal requirement — must accompany every lease.

### Linked Fields

| Field in Document | Source | Pillar | Notes |
|------------------|--------|--------|-------|
| Property address | `property.full_address` | Property | Opening disclaimer |
| Signed at (Lessor) | manual | — | |
| Date (Lessor) | auto | — | |
| Signed at (Tenant) | manual | — | At lease conclusion |
| Date (Tenant) | auto | — | |
| Signed at (Property Practitioner) | manual | — | |
| Date (Property Practitioner) | auto | — | |

### The Disclosure Table (Yes / No / N/A checkboxes)
These are agent/landlord filled — not auto-populated from pillars. The 11 disclosure items + certificate questions must render as a proper checkbox table:

1. Aware of defects in the roof
2. Aware of defects in the electrical systems
3. Aware of defects in the plumbing system (incl. pool)
4. Aware of defects in heating and air conditioning
5. Aware of defects in septic/sanitary disposal systems
6. Aware of defects to property / basement / foundations (flooding, dampness, mould)
7. Aware of structural defects
8. Aware of boundary line disputes / encroachments / encumbrances
9. Aware that remodelling has affected structure
10. Aware that additions/improvements had required consents obtained
11. Aware that structure is earmarked as historic/heritage site
12. Registered building plans exist for whole property
13. Valid Electrical Compliance Certificate (date issued)
14. Valid Electrical Fence Certificate (date issued)
15. Valid Gas Compliance Certificate (date issued)
16. Valid Entomology Certificate (date issued)

Plus: Additional Information free-text field.

### Signatories (in order)
1. **Lessor** — signs at marketing stage (Step 3)
2. **Tenant** — signs at lease conclusion (Step 6)
3. **Property Practitioner** — signs at lease conclusion
4. **Co-signature** (optional) — second property practitioner if required

---

## Document 4: Rental Application

### Purpose
Pre-approval form completed by prospective tenant before any viewings. Whether viewings need a pre-approved application first is the AGENCY's own policy and is a per-agency setting (never hardcoded; one agency's example: "no viewings without pre-approval"). Includes TPN (Tenant Profile Network) credit bureau consent.

### Linked Fields

| Field in Document | Source | Pillar | Notes |
|------------------|--------|--------|-------|
| Address of property | `property.full_address` | Property | What they're applying for |
| Full name and Surname | `contact.full_name` | Contact (Tenant — being created) | |
| ID Number | `contact.id_number` | Contact | |
| Marital Status | `contact.marital_status` | Contact | |
| Spouse Full Name | `contact.spouse_name` | Contact | |
| Spouse ID Number | `contact.spouse_id` | Contact | |
| Citizenship | `contact.citizenship` | Contact | |
| Current Residential Address | `contact.current_address` | Contact | |
| Email Address | `contact.email` | Contact | |
| Cell / Work numbers | `contact.cell` / `contact.work_phone` | Contact | |
| Emergency contact name | `contact.emergency_contact_name` | Contact | |
| Emergency contact numbers | `contact.emergency_contact_phone` | Contact | |
| Current Landlord/Agent name | manual | — | Applicant fills |
| Current Landlord Tel | manual | — | |
| Current Rental Amount | manual | — | |
| Current rental From/To dates | manual | — | |
| Employer name | `contact.employer_name` | Contact | |
| Position | `contact.employer_position` | Contact | |
| Employer address | `contact.employer_address` | Contact | |
| Employer Tel / Monthly Salary | `contact.employer_phone` / `contact.monthly_salary` | Contact | |
| Effective Date of Occupation | `deal.occupation_date` | Deal | |
| Rental Terms Required | `deal.lease_term_months` | Deal | |
| Special Conditions | `deal.special_conditions` | Deal | |
| Number of Adults / Children | `deal.occupants_adults` / `deal.occupants_children` | Deal | |

### Signatories
**Application section:**
1. **Applicant** (signature)
2. **Witness** (signature)
3. **Date**

**TPN Consent section (separate signature block):**
1. **Applicant** (signature)
2. **Witness** (signature)
3. **Date**

*Note: Two separate signature blocks within the same document — both must be signed.*

---

## Document 5: Residential Lease Agreement

### Purpose
Full residential lease agreement with all terms and conditions. Includes POPIA consent clause (Clause 22), CPA notice, deposit terms, inspection obligations, breach/termination provisions. Addendum A (Service Fee) is part of the same document.

### Linked Fields

| Field in Document | Source | Pillar | Notes |
|------------------|--------|--------|-------|
| Lessor full name | `contact.full_name` | Contact (Landlord) | |
| Lessor address | `contact.physical_address` | Contact | |
| Lessor ID/Passport/Reg No | `contact.id_number` | Contact | |
| Lessee full name | `contact.full_name` | Contact (Tenant) | |
| Lessee address | `contact.physical_address` | Contact | |
| Lessee ID/Passport/Reg No | `contact.id_number` | Contact | |
| Erf no | `property.erf_number` | Property | |
| Street address | `property.street_address` | Property | |
| Unit no | `property.unit_number` | Property | |
| Complex name | `property.complex_name` | Property | |
| Number of adults | `deal.occupants_adults` | Deal | Clause 3.2 |
| Other persons (max) | `deal.occupants_children` | Deal | Clause 3.2 |
| Rental amount (R figure) | `deal.rental_amount` | Deal | Clause 4.1 |
| Rental amount (words) | calculated from `deal.rental_amount` | Deal | Auto-convert to words |
| Escalation % | `deal.escalation_rate` | Deal | Clause 4.2 |
| Escalation month | `deal.escalation_start_month` | Deal | |
| Lease commencement date | `deal.commencement_date` | Deal | Clause 5 |
| Cannot terminate before (day/month/year) | `deal.earliest_termination_date` | Deal | |
| Lease expiry date | `deal.expiry_date` | Deal | Clause 5.2 |
| Renewal period (months) | `deal.renewal_period_months` | Deal | |
| Electricity deposit | `deal.electricity_deposit` | Deal | Clause 6 |
| Pets allowed (description) | `deal.pets_allowed_description` | Deal | Clause 9.9 |
| Admin fee (R1200 incl VAT) | Settings → Documents | — | Fixed — from settings |
| Other conditions | `deal.other_conditions` | Deal | Clause 23 |
| Signed at (Lessor) | manual | — | |
| Date (Lessor) | auto | — | |
| Signed at (Lessee) | manual | — | |
| Date (Lessee) | auto | — | |
| Signed at (Agent) | manual | — | |
| Date (Agent) | auto | — | |
| **Addendum A — Service Fee** | | | |
| Total Rental Amount | `deal.rental_amount` | Deal | |
| Agent's Service Fee (incl VAT) | calculated | — | 10% + VAT |
| Let's Assist Fee | `deal.lets_assist_fee` | Deal | |
| Net Amount to Owner | calculated | — | |

### Signatories (in order)
**Lessor section:**
1. Lessor (signature)
2. Lessor 2 (optional)
3. Witness 1 (signature + name)
4. Witness 2 (signature + name)

**Lessee section:**
5. Lessee (signature)
6. Lessee 2 (optional)
7. Witness 1 (signature + name)
8. Witness 2 (signature + name)

**Agent section:**
9. Agent (signature + print name)
10. Agent Co-Signature Name
11. Co-Signature Name

**Addendum A:**
12. Lessor (signature)
13. Tenant (signature)
14. Agent (signature)
15. Dates for all three

---

## Document 6: Commercial Lease Agreement

### Purpose
Commercial lease variant — identical structure to residential but with key differences: premises used for business only, no CPA 24-month cap clause, electricity deposit field, different admin fee (R1500), Addendum A includes reOS payment system explanation, cancellation fee R2500 (vs R2000 residential).

### Key Differences from Residential Lease

| Item | Residential | Commercial |
|------|-------------|-----------|
| Use clause | Residential purposes | Business purposes — specify business type |
| Admin fee | R1,200 | R1,500 |
| Cancellation fee | R2,000 | R2,500 |
| Electricity deposit | Not separate | Separate field — `deal.electricity_deposit` |
| Business type field | N/A | `deal.business_type` — Clause 3.2 |
| Addendum A title | Service Fee | Commission and Payments |
| reOS payment section | Not present | Present — explains reOS payment reference |
| Limitation of liability clause | Not present | Clause 20 — full liability limitation |

### All other linked fields identical to Residential Lease.

### Signatories (same structure as residential with addition of named co-signature fields)

---

## Web Document Migration Approach

### What Changes
Every document currently exists as a background image (scanned/photographed original) with text stamped on top via `imagettftext`. This is being retired.

**New approach:** Pure HTML/Blade template → Puppeteer renders to PDF.

### Fidelity Requirements
- Layout must be visually identical to the original document
- Every font size, margin, spacing, and line position must match
- Clause numbering must be exact — no renumbering
- Legal text must be character-perfect — no autocorrection, no smart quotes
- Tables (Mandatory Disclosure checkbox grid) must render pixel-accurately

### Blade Template Structure (per document)

```
resources/views/documents/rental/
  marketing-permission.blade.php
  letting-mandate.blade.php
  mandatory-disclosure.blade.php
  rental-application.blade.php
  lease-residential.blade.php
  lease-commercial.blade.php
```

Each template:
1. Extends a `document-base` layout (agency header, page margins, font)
2. Receives a `$document` model with all linked field values pre-populated
3. Renders blank lines (`_____________`) for any field not yet populated
4. Renders signature blocks as either: blank line (unsigned) | signature image (signed) | uploaded scan (wet ink)
5. Rendered via Puppeteer — not DomPDF

### Signature Block Rendering

Per signature position, the template checks `$signature->status`:
- `unsigned` → render a blank line with role label below
- `electronic` → render the captured signature image
- `wet_ink` → render the uploaded scan image
- `pending` → render a "Awaiting signature" placeholder (for in-progress documents)

---

## Data Model Requirements

### New Fields Needed (verify against existing migrations)

**`deals` table additions:**
- `rental_amount` (decimal)
- `commission_rate` (decimal — from settings default)
- `mandate_end_date` (date)
- `commencement_date` (date)
- `expiry_date` (date)
- `earliest_termination_date` (date)
- `escalation_rate` (decimal)
- `escalation_start_month` (string)
- `renewal_period_months` (integer)
- `electricity_deposit` (decimal)
- `occupation_date` (date)
- `lease_term_months` (integer)
- `occupants_adults` (integer)
- `occupants_children` (integer)
- `pets_allowed_description` (text, nullable)
- `business_type` (string, nullable — commercial only)
- `lets_assist_fee` (decimal, nullable)
- `special_conditions` (text, nullable)
- `other_conditions` (text, nullable)

**`contacts` table additions (verify existing):**
- `bank_account_holder` (string, nullable)
- `bank_name` (string, nullable)
- `bank_account_number` (string, nullable)
- `bank_branch_code` (string, nullable)
- `marital_status` (string, nullable)
- `spouse_name` (string, nullable)
- `spouse_id` (string, nullable)
- `citizenship` (string, nullable)
- `current_address` (text, nullable)
- `emergency_contact_name` (string, nullable)
- `emergency_contact_phone` (string, nullable)
- `employer_name` (string, nullable)
- `employer_position` (string, nullable)
- `employer_address` (text, nullable)
- `employer_phone` (string, nullable)
- `monthly_salary` (decimal, nullable)

---

## Signing Sequence Per Flow

### Landlord Onboarding Pack (Steps 1–3)
Sent to landlord as a pack. Signing order:
1. Landlord signs Marketing Permission
2. Landlord signs Letting Mandate
3. Landlord signs/fills Mandatory Disclosure (Lessor section only)

All three can be sent together. Landlord signs all three before tenant search begins.

### Tenant Application (Step 4)
Sent to prospective tenant independently. Not linked to a specific lease yet.
1. Tenant completes and signs Rental Application (both signature blocks)
2. Application reviewed internally
3. If approved → Lease flow begins

### Lease Pack (Steps 5–6)
Sent as a pack once tenant is approved. **Signing order, ruled by Johan 2026-10-19 (audit fix A21): agent first, then the tenant(s), then the landlord(s)** - the code has always done this (leases.md R4, `LeaseSigningLauncher` recipient order); the earlier "landlord first" wording below this ruling was wrong and is replaced:
1. Agent signs Lease (Agent section)
2. Tenant(s) sign Lease (Lessee section)
3. Landlord(s) sign Residential/Commercial Lease (Lessor section)
4. Addendum A signed by all three, in the same order
5. Agent signs Mandatory Disclosure (Property Practitioner section)
6. Tenant signs Mandatory Disclosure (Tenant section); its Lessor section was signed earlier, at the marketing stage (Step 3)

### Sequential Signing Logic
- Agent signs first → Tenant(s) notified → sign → Landlord(s) notified → sign (Johan's ruling 2026-10-19, matches the code). The signing order is fixed by the lease-signing launcher, not chosen per lease.
- Each notification goes via DocuPerfect email with agent's signature injected
- Document is flattened after each signature before being sent to next signer

---

## Implementation Priority Order

Build in this sequence — each document is simpler than the last, building up the Blade component library:

1. **Rental Application** — single signer, no complex layout, good starter
2. **Marketing Permission** — straightforward, establishes agency header component
3. **Letting Mandate** — adds bank details section
4. **Mandatory Disclosure** — introduces the checkbox table component
5. **Residential Lease** — the longest and most complex
6. **Commercial Lease** — variation of residential, reuse components
