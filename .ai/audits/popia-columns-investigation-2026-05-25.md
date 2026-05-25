# Phase 9c POPIA Blockers — Regulatory-Number Columns Investigation

**Date:** 2026-05-25  
**Auditor:** Claude (read-only codebase scan)  
**Scope:** Regulatory-number fields on `agencies` and `branches` tables, rendering locations, legal mapping

---

## Executive Summary

**Finding:** The compliance audit's "agency PPRA number not captured as a structured field" (🔴 #3) is **technically ambiguous but practically justified**.

The issue: `agencies.ffc_no` exists and is populated (HFC: `2023116041`) but:
- It is labelled in code as "PPRA" in `corex-document.blade.php:69`
- It is actually the **Fidelity Fund Certificate number**, which is **NOT the agency's PPRA registration number**
- They are distinct legal instruments under PPA 22/2019

Current state:
- ✓ Agents have `User.ffc_number` + `User.ppra_status`
- ✓ Agency has `Agency.ffc_no` (FFC — mislabelled as PPRA)
- ✗ Agency lacks `Agency.ppra_number` (the actual PPRA registration)
- ✗ No `information_officer_user_id` column (only free-text email)
- ✗ `agencies.popi_url` exists but is empty for HFC

---

## 1. Regulatory-Number Column Inventory

### Table: `agencies`

| Column | Type | Current Value (HFC) | Purpose | Framework |
|--------|------|---------------------|---------|-----------|
| `ffc_no` | varchar(255) | `2023116041` | Fidelity Fund Certificate (mislabelled as PPRA) | PPA 22/2019 |
| `fic_no` | varchar(255) | `AI/180629/0000019` | FIC registration number | FIC Act 38/2001 |
| `reg_no` | varchar(255) | `2017/431318/07` | Company registration (CIPC) | Companies Act |
| `vat_no` | varchar(255) | `4630287821` | VAT number (SARS) | VAT Act |
| `paye_registration_no` | varchar(20) | NULL | PAYE registration | SARS |
| `uif_employer_no` | varchar(20) | NULL | UIF employer number | UIF Act |
| `sdl_registration_no` | varchar(20) | NULL | SDL registration | SDL Act |
| `whistleblow_compliance_officer_email` | varchar(255) | NULL | Free-text whistleblower contact (NOT a User FK) | PPA Whistleblower |
| `popi_url` | varchar(500) | NULL | Privacy policy URL | POPIA s18 |
| **MISSING** | — | — | PPRA registration number (agency's own ID) | PPA 22/2019 |
| **MISSING** | — | — | Information Officer User FK | POPIA s55 |

### Table: `branches` (HFC branches 1-3)

All have: `ffc_no` = NULL, `fic_no` = `AI/180629/0000019`, `reg_no` = `2017/431318/07`, `vat_no` = `463087821`

---

## 2. Rendering Locations of Regulatory Numbers

### `ffc_no` Rendering

| File | Line | Context | Issue |
|------|------|---------|-------|
| `corex-document.blade.php` | 69 | PDF header | **MISLABELLED as "PPRA: {{$d('ffc_no')}}"** |
| `company-header.blade.php` | 75 | CDS template footer | **Correctly "FFC: {{$d('ffc_no')}}"** |
| `rcr/export-pdf.blade.php` | 45 | RCR export | **Ambiguous "PPRA / FFC reference"** |
| Admin forms | 104,117,224,542,574 | Settings UI | Input fields |

### `fic_no` Rendering

| File | Line | Context |
|------|------|---------|
| `company-header.blade.php` | 77 | CDS footer — "FIC {{$d('fic_no')}}" |
| `corex-document.blade.php` | 72 | PDF header — "FIC: {{$d('fic_no')}}" |
| `rcr/export-pdf.blade.php` | 46 | RCR export |
| Admin forms | 111,124,231,549,580,181 | Settings UI |

### `popi_url` Rendering

| File | Line | Context |
|------|------|---------|
| `agent-footer.blade.php` | 66–68 | **Email footer** (conditional) |
| `template-116.blade.php` | 114–115 | **Exclusive Authority to Sell** (conditional) |
| Admin forms | 182, 304 | Settings input |

**Status:** `popi_url` is NULL for HFC (will only render if populated).

---

## 3. Legal Framework Mapping

### Property Practitioners Act 22 of 2019

| Term | What It Is | Issued To | Current Field | Gap |
|------|-----------|-----------|----------------|-----|
| Fidelity Fund Certificate (FFC) | Annual practitioner licence | Individual agents | `User.ffc_number` ✓, `Agency.ffc_no` ✓ | No |
| PPRA Registration Number | Agency's permanent business ID | Agency (business entity) | **MISSING** | **YES** |
| Practitioner Status | Compliance status | Agent | `User.ppra_status` ✓ | No |

**Critical distinction:** FFC and PPRA registration are separate legal instruments. `Agency.ffc_no` is mislabelled.

### Financial Intelligence Centre Act 38/2001

- `Agency.fic_no` = `AI/180629/0000019` ✓ Correctly captured

### POPIA 4/2013

| Requirement | Status |
|-------------|--------|
| Privacy policy URL (s18) | `popi_url` exists, empty for HFC |
| Information Officer (s55) | **MISSING** — only free-text email, no User FK |

---

## 4. Compliance Audit Quote (Verbatim)

**From:** `.ai/audits/compliance-audit-2026-06.md` — 🔴 #3

> **Status:** Partial (agency has `ffc_no` but no `ppra_number` / `ppra_registration_number`)
> **Evidence:** Property Practitioners Act regulations require the agency's PPRA registration number on every consumer-facing marketing document. Agent footer displays agent's individual FFC but no agency PPRA reference. The mandate templates would need agency PPRA visible — currently relies on agency name/address text only.
> **Risk:** Each piece of marketing without the agency PPRA reference is a separate contravention. Cumulative risk on launch day = volume × penalty.
> **Fix:** Add `ppra_number` + `ppra_registered_at` to agencies + render in document footers + agent email signature.

---

## 5. Answers to Phase 9c Investigation Questions

### Q1: Does `ffc_no` semantically equal the PPRA registration number?

**Answer: NO.**

- FFC = annual practitioner licence (individual)
- PPRA registration = agency's permanent business identifier
- Distinct legal instruments per PPA 22/2019
- Code mislabels FFC as "PPRA" in `corex-document.blade.php:69`

**Action:** Create `Agency.ppra_number` (new column, separate from FFC). Ask Johan if HFC's `ffc_no` (2023116041) is actually the PPRA registration — if yes, rename/relabel; if no, populate with true PPRA number.

### Q2: If Yes — is Phase 9c fix a UI label change + ensure populated?

**Not applicable.** Answer is NO; new column needed.

### Q3: If No — what's the gap? New `ppra_number` column? Where would it render?

**Answer: YES, new column needed.**

**Render locations:**
1. Email signature footer (`agent-footer.blade.php`)
2. PDF document headers (`corex-document.blade.php`)
3. CDS template footers (`template-116.blade.php`, etc.)
4. RCR export PDF (`rcr/export-pdf.blade.php`)
5. Admin settings UI

### Q4: Is there an `information_officer_user_id` column?

**Answer: NO.**

**Current:** Only `whistleblow_compliance_officer_email` (free-text, not User FK)  
**Needed:** `Agency.information_officer_user_id` (bigint unsigned FK to User)

### Q5: Is `popi_url` populated for HFC? What's its value?

**Answer: NO, NULL (empty).**

**Where used:**
- Email signature footer (conditional)
- Exclusive Authority to Sell template (conditional)

**Action:** Create privacy policy page, populate `popi_url` with the link.

---

## 6. HFC Current Values

**Agency (id=1):**
- ffc_no: `2023116041`
- fic_no: `AI/180629/0000019`
- reg_no: `2017/431318/07`
- vat_no: `4630287821`
- popi_url: `NULL`
- whistleblow_compliance_officer_email: `NULL`

**Branches (all 3):**
- ffc_no: all NULL
- fic_no: all `AI/180629/0000019`
- reg_no: all `2017/431318/07`
- vat_no: all `463087821`

---

**End of investigation. Read-only scan completed.**
