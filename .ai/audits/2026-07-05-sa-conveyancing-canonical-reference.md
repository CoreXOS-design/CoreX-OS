# SA Conveyancing / Property Transfer — Canonical Reference

**Date:** 2026-07-05
**Purpose:** Authoritative reference for the South African property transfer (conveyancing) process, with sensible default day-offsets and parallel/sequential flags. Built for auditing CoreX seeded deal-pipeline templates against real-world conveyancing. READ-ONLY research synthesis.
**Region focus:** KZN South Coast (coastal) — Home Finders Coastal.

Sources: STBB, Snymans, ooba home loans, Barter McKellar, Ngoetjana Attorneys, Cape Coastal Homes (URLs in §4).

---

## 1. Canonical ordered process — step list with relative day-offsets

**Day-offset convention:** each step's offset is measured **relative to its immediate predecessor's completion** (not absolute from deal date), unless the "Parallel with" column says otherwise. Anchor totals: **bond grant ~21–30 days from OTP**; **full registration ~8–12 weeks (2–3 months) total** from OTP.

| # | Step | Owner / actor | Offset from predecessor | Seq / Parallel | Notes |
|---|------|---------------|-------------------------|----------------|-------|
| 1 | **OTP accepted** (Offer to Purchase signed by both parties) | Agent / buyer + seller | Day 0 (anchor) | Sequential (gate) | Legally binding suspensive contract. Deposit & bond clauses live here. |
| 2 | **Deposit paid** into attorney/agent trust | Buyer | ~1–3 days after OTP | Parallel with bond application | Per OTP terms; held in trust. Not always present (cash/100% bond). |
| 3 | **Bond application** submitted | Buyer / bond originator (e.g. ooba) | ~1–3 days after OTP | Sequential (before grant) | Triggered by OTP suspensive condition. |
| 4 | **Bond grant / approval** (final grant, not pre-approval) | Bank | ~14–30 days after application (**~21–30 days from OTP**) | Sequential (gate) | Satisfies the bond suspensive condition → deal becomes unconditional. ooba: home-loan approval "5–10 working days"; Barter McKellar: "1–4 weeks (or longer)". |
| 5 | **Attorneys instructed** — transfer attorney (conveyancer, appointed by seller), bond attorney (appointed by buyer's bank), cancellation attorney (appointed by seller's existing bank) | Seller nominates transfer attorney; banks appoint bond & cancellation attorneys | ~2–5 days after bond grant | Sequential trigger, then the three run in parallel | Three distinct attorney roles. Transfer attorney coordinates all three. |
| 6 | **FICA documents** to attorneys (buyer + seller) | Buyer + seller | ~3–7 days after instruction | Parallel (with 7–11) | Certified ID, proof of address, marriage/divorce, tax number. "Missing paperwork is one of the most common causes of delay." |
| 7 | **Bond cancellation figures** requested from seller's existing bondholder | Cancellation attorney | ~2–5 days after instruction | Parallel (with 6, 8–11) | Bank issues cancellation figures + requires ~90 days' notice (early-settlement penalty if <90 days' notice). |
| 8 | **Guarantees issued** (bank/buyer's attorney guarantees to transfer + cancellation attorneys) | Bond attorney → transfer & cancellation attorneys | ~7–14 days after bond grant | Parallel (with 6–7, 9–11) | Payment guarantees delivered once bond docs signed; underpins the money leg. |
| 9 | **Compliance certificates** obtained (see §3) — electrical COC (mandatory), gas (if gas installed), electric-fence COC (if fence), **beetle/entomologist (coastal — standard on the KZN South Coast)**, plumbing (only where municipality requires, e.g. Cape Town) | Seller (legal obligation) | ~7–21 days after instruction | Parallel (with 6–8, 10–11) | Seller's legal obligation. Certificates named/required in the OTP. Run concurrently with clearances. |
| 10 | **Rates clearance certificate** from municipality (+ **levy clearance** from body corporate for sectional title, + **HOA consent to transfer** where an estate/HOA applies) | Transfer attorney requests; seller funds arrears + advance | ~7–21 days after instruction | Parallel (with 6–9, 11) | Municipality requires rates paid several months in advance; confirms no arrears (typically prior 2 years). Levy/HOA clearance for ST/estate properties. |
| 11 | **Transfer duty paid / SARS receipt** (or VAT if seller is a VAT vendor) | Buyer funds; transfer attorney submits via SARS e-filing | ~7–14 days after signing (step 12) | Parallel-ish (with 9–10), gates lodgement | SARS transfer-duty receipt is a lodgement prerequisite. No duty below the threshold, but a SARS exemption receipt is still required. |
| 12 | **Documents signed** by both parties at transfer attorney (Power of Attorney, transfer/bond/cancellation docs) + all costs paid | Buyer + seller | ~2–5 days after guarantees + FICA in hand | Sequential (converges the parallel legs) | Convergence point: signing can only complete once bond signed, FICA in, and figures known. |
| 13 | **Lodgement at Deeds Office** (transfer + new bond + cancellation lodged together, linked) | Transfer, bond & cancellation attorneys lodge in batch | ~3–10 days after signing — **only once ALL conditions met** (COCs, clearances, guarantees, SARS receipt) | **Strictly sequential (hard gate)** | Cannot lodge until every parallel leg (9,10,11 + guarantees) is complete. The three deeds must lodge simultaneously or they reject. |
| 14 | **Registration** (Deeds Office examines & registers) | Deeds Office | **7–10 working days** after lodgement | Strictly sequential | Examination "7–10 working days" (STBB, Snymans, Ngoetjana). On registration, ownership passes and bonds register/cancel simultaneously. |
| 15 | **Finances / payout & commission** | Transfer attorney (trust account) | ~1–3 days after registration | Sequential | Purchase price released to seller, existing bond settled, agent commission paid, keys handed over. |

**Total elapsed (no complications):** ~**8–12 weeks** from OTP to registration (2–3 months). Steps 6–11 overlapping is what keeps the total near 8 weeks; any single stalled parallel leg pushes toward 12+ weeks.

### Sequential vs parallel — summary

- **Strictly sequential gates:** OTP (1) → bond application (3) → bond grant (4) → attorneys instructed (5) → … → signing (12) → **lodgement (13)** → **registration (14)** → payout (15). Lodgement is the hard convergence gate: it happens **only after every condition is satisfied**. Registration happens **only after lodgement**.
- **Run in parallel in reality (the "preparation" cluster, all after attorneys instructed):** FICA (6), bond cancellation figures (7), guarantees (8), compliance certificates (9), rates/levy/HOA clearance (10), and transfer-duty/SARS processing (11) are all pursued **concurrently**. They must all be complete before lodgement (13). Deposit (2) runs in parallel with the bond application (3).

---

## 2. Per-source summaries (with quoted timeframes)

### ooba home loans — "The complete Property Transfer process guide in South Africa (2026)"
8 ordered steps: (1) Signing the OTP → (2) Home loan approval → (3) Appointment of transfer attorney → (4) Preparation & signing of transfer documents → (5) Payment of transfer fees → (6) Payment of transfer duty to SARS → (7) Lodgement at Deeds Office → (8) Registration of the deed.
Quoted timeframes:
- "Home loan approval typically takes 5-10 working days after application"
- "The complete process typically takes 2-3 months from signing the Offer to Purchase to registration"
- "8-12 weeks from signing the OTP" (preparation to lodgement)
- Deeds Office examination "8-10 working days"
Delays cited: outstanding compliance certificates, municipal clearance delays, FICA issues, bond approval complications, linked transfers.
URL: https://www.ooba.co.za/resources/property-transfer-process/

### STBB (Smith Tabata Buchanan Boyes) — Quick Guide to Registering a Transfer
Steps: Sale Agreement (OTP) → Conveyancer appointment (coordinates bond & cancellation attorneys, prepares transfer docs) → Document preparation + clearance certificates → Deeds Office registration → final registration & return of title deed/bond.
Quoted timeframes:
- Average transaction completed in "six to eight weeks"; full process "approximately 8 to 12 weeks (or longer) from the date the Offer to Purchase is signed", assuming no delays.
- Sale agreement finalised "within a few days to a week".
- Document preparation + clearance certificates "approximately 1 to 3 weeks (or longer)".
- Deeds Office checks each document "7–10 working days".
- Up to three months for the Deeds Office to deliver original Title Deed and Mortgage Bond back to the conveyancer.
URLs: https://stbb.co.za/wp-content/uploads/2019/11/STBB_Brochure_Quick-guide-to-registering-a-transfer.pdf ; https://www.stbb.co.za/wp-content/uploads/2014/09/STBB_Bond-and-Transfer-Procedure-Summary.pdf

### Snymans Inc — Transfer process / role of the transfer attorney
Transfer attorney responsibilities (ordered): receive instruction → administrative handling → draft transfer documents & docs for signature → pay costs involved → communicate with all role players → monitor & report → lodgement & registration of title deeds → reconciliation of all accounts. Seller signs a Power of Attorney authorising the conveyancer to act at the Deeds Office (Deeds Registries Act 47 of 1937 requires a qualified conveyancer to lodge). "Once all documents are signed, payments made, and clearance certificates issued, the attorney lodges the transfer documents at the Deeds Office along with the bond registration and cancellation documents."
Quoted timeframes:
- Deeds Office checks each document "7–10 working days".
- "The typical house transfer process in South Africa takes 8–12 weeks from signing the offer to registration."
URLs: https://www.snymans.com/advice/the-role-of-a-transfer-attorney-in-south-africa ; https://www.snymans.com/advice/documents-required-for-transfer-of-property

### Barter McKellar — "What to Expect During a Property Transfer: Timelines and Costs"
7 ordered steps with timeframes:
1. Sale Agreement (OTP) — "usually finalized within a few days to a week"
2. Bond Approval — "can take anywhere from approx. 1 to 4 weeks (or longer)"
3. Appointing a Conveyancer — "usually happens within a week"
4. Transfer Process Initiation — "can take approx. 1 to 3 weeks (or longer)"
5. Obtaining Clearance Certificates — "can take approx. 1 to 2 weeks (or longer)"
6. Lodgement at the Deeds Office — "typically takes approx. 1 to 3 weeks (or longer)"
7. Transfer of Ownership — "completed within approx. 7 to 14 days after the lodgement"
Overall: "anywhere from approx. 8 to 12 weeks (or longer)".
URL: https://www.bartermckellar.law/conveyancing-explained/what-to-expect-during-a-property-transfer-timelines-and-costs-in-south-africa

### Ngoetjana Attorneys — Step-by-Step Guide for Buyers and Sellers
8 steps: (1) Signing the OTP → (2) Appointing the conveyancing attorney (buyer selects & pays transferring attorney) → (3) Gathering documents / FICA → (4) Bond registration & cancellation (bank appoints bond attorney; cancellation attorney cancels seller's existing bond on registration day; "Runs alongside property transfer to save time") → (5) Rates clearance + levy certificates (municipality; sectional title needs levy clearance from body corporate/HOA) → (6) Drafting & signing transfer documents (buyer gets statement for transfer duty + fees) → (7) Lodgement at Deeds Office → (8) Registration & final handover (price transferred to seller, keys handed over).
Quoted timeframes:
- Deeds Office checks each document "7–10 working days".
- "The typical house transfer process in South Africa takes 8–12 weeks from signing the offer to registration."
- FICA note: "Start gathering these documents early — missing paperwork is one of the most common causes of delay."
URL: https://ngoetjanaattorneys.co.za/the-property-transfer-and-conveyancing-process-in-south-africa-step-by-step-guide-for-buyers-and-sellers/

### Cape Coastal Homes — Documents required for transfer of ownership
Compliance certificates (seller's obligation): "Electrical, electric fence (where applicable), gas and beetle (coastal regions) compliance certificates." Rates clearance certificate (no arrears, typically prior 2 years). Levy clearance certificate (sectional title / body corporate). FICA documents (both parties; varies by natural person / company / trust). Transfer documents: Power of Attorney, Draft Deed, Transfer Duty Receipt. Certificates required are typically indicated in the OTP; bond attorneys may require them for lodging.
URL: https://www.cch.co.za/news/which-documents-are-required-for-the-transfer-of-ownership-when-you-sell-your-property/

---

## 3. Coastal / KZN South Coast considerations

- **Beetle / entomologist certificate (borer certificate):** Standard on the **KZN South Coast and all coastal regions**. It certifies the property is free of infestation by wood-destroying beetles (e.g. the Italian/Common Furniture beetle and Longhorn/Oxypleurus borers) that thrive in humid coastal timber. Cape Coastal Homes explicitly lists "beetle (coastal regions)" as a required certificate. **For Home Finders Coastal deals this certificate should be treated as a default/standard seller obligation**, not an optional one. Sourced via a registered entomologist / pest-control inspector.
- **Electrical Certificate of Compliance (COC):** Mandatory nationwide for every transfer (Electrical Installation Regulations). Valid 2 years. **Seller's legal obligation.**
- **Electric-fence COC:** Required **only where an electric fence is installed** (any fence installed/altered after 1 Oct 2012). Separate certificate from the electrical COC. **Seller's obligation where applicable.**
- **Gas certificate (Certificate of Conformity):** Required **only where a fixed gas installation exists** (gas hob, geyser, fireplace). **Seller's obligation where applicable.**
- **Plumbing certificate:** **Not a national requirement.** Municipality-specific — notably **mandatory in the City of Cape Town** (water-installation/plumbing certificate). **Not generally required by KZN South Coast municipalities**, so do not seed it as a default for HFC — flag it as conditional/municipality-driven.
- **Seller's legal obligations (the "must haves"):** electrical COC (always), + gas / electric-fence / beetle **where the trigger condition applies** (beetle effectively always for coastal HFC stock). Rates clearance is funded by the seller (arrears + advance). Levy clearance + HOA/estate consent apply for sectional-title and estate/HOA properties.
- **HOA / estate consent to transfer:** Many KZN coastal estates require the HOA to issue a **consent-to-transfer / levy clearance** before lodgement — a separate gate from municipal rates clearance. Treat as parallel with rates clearance (step 10) and a lodgement prerequisite.

---

## 4. Citations (every URL fetched)

- ooba home loans — https://www.ooba.co.za/resources/property-transfer-process/ (fetched, 200)
- STBB Quick Guide to Registering a Transfer (PDF) — https://stbb.co.za/wp-content/uploads/2019/11/STBB_Brochure_Quick-guide-to-registering-a-transfer.pdf (fetched — binary PDF not text-extractable; content captured via STBB web-search snippet)
- STBB Bond and Transfer Procedure Summary (PDF) — https://www.stbb.co.za/wp-content/uploads/2014/09/STBB_Bond-and-Transfer-Procedure-Summary.pdf (referenced)
- Snymans — role of a transfer attorney — https://www.snymans.com/advice/the-role-of-a-transfer-attorney-in-south-africa (fetched, 200)
- Snymans — documents required for transfer of property — https://www.snymans.com/advice/documents-required-for-transfer-of-property (referenced via search)
- Barter McKellar — property transfer timelines & costs — https://www.bartermckellar.law/conveyancing-explained/what-to-expect-during-a-property-transfer-timelines-and-costs-in-south-africa (fetched, 200)
- Ngoetjana Attorneys — step-by-step conveyancing guide — https://ngoetjanaattorneys.co.za/the-property-transfer-and-conveyancing-process-in-south-africa-step-by-step-guide-for-buyers-and-sellers/ (fetched, 200)
- Cape Coastal Homes — documents required for transfer of ownership — https://www.cch.co.za/news/which-documents-are-required-for-the-transfer-of-ownership-when-you-sell-your-property/ (fetched, 200)

**Note on the two original target URLs:** `stbb.co.za/the-transfer-process/` and `snymans.com/the-transfer-process/` both returned HTTP 404; equivalents were located via WebSearch and fetched (STBB Quick Guide PDF + search snippet; Snymans advice pages). ooba's canonical page was reached directly.
