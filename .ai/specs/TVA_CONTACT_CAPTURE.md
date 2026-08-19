# TVA (The Virtual Agent) Contact Capture — Spec
Captured 12 Aug 2026 from Johan (via Claude desktop). Freeze lifted for this — public-ready work, not an enhancement.

What it is: TVA (app.thevirtualagent.co.za) KYC person lookup returns contact numbers + emails per SA ID (costs a data credit). Add a "Capture to CoreX" button — same pattern as the CMA/portal-capture extension — that pulls those contacts into CoreX, landing on the SAME deeds-capture suspense screen the CMA capture feeds. Agents get IDs from CMA first, so a deeds record almost always already exists.

Flow:
1. CMA -> deeds capture creates the suspense record (property + owners with IDs). [existing]
2. On the deeds record, each owner row gets a "Copy ID" button -> copies that ID number to clipboard.
3. Agent logs into TVA by hand, pastes the ID, Find, opens person -> Contact tab, clicks the extension-injected "Capture to CoreX".
4. Capture scrapes the Contact grid — per row: Type (Cell/Tel/Email), value (number or email), Date, Link Date. Plus identity (first name, surname, ID). Dates are CRITICAL — many numbers exist but only some are live; the agent judges by Date/Link Date. Emails are rows in the same grid (Type=email) — capture them too.

RED = OPTED OUT (required): TVA/CMA render opted-out IDs/values in red. Confirmed via live DOM: Bootstrap text-danger, computed color ~rgb(231,61,74) (hover rgb(215,27,41)) vs normal link-blue rgb(51,122,183). Detect by class text-danger OR computed color matching danger-red (check computed color, not class alone).
- ID number red -> whole person opted out -> do NOT capture the person (block).
- Contact number/email red -> only that value opted out -> skip it; the person's other non-red values still capture.
Handling (Johan's call): opted-out values are DROPPED — never sent to CoreX (safe model). Structure the receiver so a future retain-but-admin-lock mode (store the value, gate behind admin password for audit) is a config flag, not a rewrite — CPA law later this year may force retention. Apply the same red-detection on the CMA/portal-capture side for red ID numbers.

POST + matching: new CoreX endpoint mirroring deeds-capture. Match to the suspense record by ID number (TVA person ID == an owner ID on a suspense record) — no "arming" needed. No match (rare) -> land as a standalone captured-contact block headed by name+surname+ID.

CoreX display + ingest: captured TVA contacts display UNDER the CMA details on the same deeds screen (or their own block if standalone). Each number/email listed with Type + Date + Link Date. Agent TICKS which to ingest. Ticked values -> link to an existing contact (select) OR create a new contact. If tied to a CMA record -> create contact + property.

Payload per person: { id_number, first_name, surname, source:'tva', consent_status, contacts:[{ type:'cell'|'tel'|'email', value, date, link_date, opted_out:false }] }. Opted-out excluded before send in safe model.

Build notes: reuse the portal-capture extension structure (content script + injected button + POST) and the existing deeds-capture controller/screen. Screen keeps name "Deeds" for now (Johan renames later). Do NOT break the deeds-capture/promote fix just landed, or docuperfect. Verify like CMA/e-sign: test the button on a real TVA person page, confirm red rows excluded, confirm CoreX receives -> displays under CMA details -> tick-to-ingest -> link/create both work.

Recon reference (ID 8208150017086, both numbers non-red): person page /Search/Person/-1?idNumber={id}#tab_1_2 ; contact rows `<a href="tel:0715328243">`; Type cell "Cell"; Date + Link Date columns. Consent modal ("Keeping you Compliant") precedes the person page — agent always picks "I will obtain consent during my next contact" then PROCEED (spends 1 credit).

Work on QA1 only.
