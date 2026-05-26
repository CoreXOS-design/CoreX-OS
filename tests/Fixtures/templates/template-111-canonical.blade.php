{{--
    Canonical test fixture — template-111 in its INTENDED single-seller-block form.

    What Johan keeps trying to save in the CDS builder before it reverts to 4
    hardcoded seller blocks. Owned by tests, lives in tests/Fixtures/templates/,
    so production templates can drift without breaking the contract.

    Shape mirrors the real-world template 111 pathologies investigated in
    .ai/audits/esign-reset-investigation-2026-05-27.md:

    1. Opening paragraph carries a STRAY `[Seller Name Surname ID]` reference
       (one seller field on its own). Below, the main seller block carries
       four MORE seller fields (name/address/phone/email). The two field
       runs are separated by a non-seller paragraph and a heading, so the
       Recipient-Loop detector sees TWO disjoint clusters of role=seller —
       this is what trips Commit 3's multi-cluster bailout fix.

    2. The "Conditions" section carries BOTH a clean marker
       (`~~~~OTHER_CONDITIONS~~~~`) AND a malformed one
       (`~~~~Other Contitions~~~~` with embedded <span>) — the latter is
       what Commit 4's three-layer tolerance fix has to resolve.

    The fixture is raw HTML (no Blade compilation) so it loads as-is into
    `Document.web_template_data['merged_html']` and the SigningController's
    pipeline runs on it unchanged.
--}}

<div class="contract corex-document">

    <h1 class="document-title">Exclusive Authority to Sell</h1>

    <p class="document-intro">
        The undersigned, <span class="corex-field-value" data-field="seller_name_surname_id">[Seller Name Surname ID]</span>,
        being the registered owner of the property described below, hereby grants the Agent the exclusive authority to sell.
    </p>

    <h2>1. The Property</h2>
    <p>Address: <span class="corex-field-value" data-field="property_address">[Property Address]</span></p>
    <p>Erf number: <span class="corex-field-value" data-field="property_erf_number">[Erf Number]</span></p>

    <h2>2. The Seller</h2>
    <div class="seller-block">
        <p>Full name: <span class="corex-field-value" data-field="seller_first_name">[Seller First Name]</span> <span class="corex-field-value" data-field="seller_last_name">[Seller Last Name]</span></p>
        <p>ID number: <span class="corex-field-value" data-field="seller_id_number">[Seller ID Number]</span></p>
        <p>Physical address: <span class="corex-field-value" data-field="seller_address">[Seller Address]</span></p>
        <p>Phone: <span class="corex-field-value" data-field="seller_phone">[Seller Phone]</span></p>
        <p>Email: <span class="corex-field-value" data-field="seller_email">[Seller Email]</span></p>
    </div>

    <h2>3. Conditions of Sale</h2>
    <p>3.1 The Seller warrants ownership of the property.</p>
    <p>3.2 The mandate runs for ninety (90) days from the date of signature.</p>
    <p>3.7 ~~~~OTHER_CONDITIONS~~~~</p>
    <p>3.8 ~~~~<span class="corex-clause-text">Other Contitions</span>~~~~</p>

    <h2>4. The Agent</h2>
    <div class="agent-block">
        <p>Agent: <span class="corex-field-value" data-field="agent_name">[Agent Name]</span></p>
        <p>FFC number: <span class="corex-field-value" data-field="agent_ffc">[Agent FFC]</span></p>
    </div>

    <h2>5. Signatures</h2>
    <div class="signature-block">
        <p>Signed by the Seller at the place and on the date stated below.</p>
        <p class="signature-line" data-marker-party="seller" data-marker-type="signature"></p>
        <p>Signed by the Agent at the place and on the date stated below.</p>
        <p class="signature-line" data-marker-party="agent" data-marker-type="signature"></p>
    </div>

</div>
