<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds\Reference;

/**
 * AT-177 / WS2 (reference proof) + WS5 — the hand-authored compiled form (CDS v2) of the
 * zero-field reference templates 117 (Mandatory Disclosure / MDF) and 119 (Addendum B).
 *
 * These are the campaign's proving ground (spec §8.1): centrally-owned, legally-fixed,
 * hand-crafted documents with ZERO fields, so they isolate the signature-surface + letterhead
 * + pagination compilers from field-binding. The prose is reproduced faithfully from
 * `resources/views/docuperfect/web-templates/cds/template-{117,119}.blade.php`; the renderer
 * must reproduce this known-good content and its signable surfaces.
 *
 * A published `compiled_templates` row would carry one of these arrays in its `structure`
 * column. Provided as a reusable provider so the WS2 proof and the WS5 live-parity cutover
 * both bind to the same canonical reference.
 */
final class ReferencePackCds
{
    /** The HFC letterhead, faithful to the company-header partial (bordered needle + Times/Arial). */
    private static function letterhead(): array
    {
        $html = '<div style="border:1px solid #000; padding:4px 8px 4px 8px; margin-bottom:10pt;">'
            . '<div style="font-family:\'Times New Roman\',Times,serif; font-size:10pt; font-weight:bold; text-decoration:underline;">Home Finders Coastal (Pty) Ltd</div>'
            . '<div style="text-align:center; font-weight:bold; padding:6px 0;">HOME FINDERS COASTAL</div>'
            . '<div style="display:grid; grid-template-columns:1fr 1fr; font-family:Arial,Helvetica,sans-serif; font-size:9pt; font-weight:bold; line-height:1.5; border-top:1px solid #000; padding-top:4px;">'
            . '<div>Shop 5, Coastal Centre, Uvongo<br>Reg No: 2018/123456/07<br>VAT No: 4123456789<br>Email: info@hfcoastal.co.za</div>'
            . '<div style="text-align:right;">FFC No: 2024/HFC/001<br>FIC No: 987654<br>Tel: 039 315 0000</div>'
            . '</div></div>';

        return [
            'block_id' => 'letterhead',
            'type' => 'letterhead',
            'visibility' => ['mode' => 'all'],
            'editability' => ['mode' => 'none'],
            'condition' => ['kind' => 'always'],
            'html' => $html,
        ];
    }

    private static function prose(string $id, string $html): array
    {
        return [
            'block_id' => $id,
            'type' => 'prose',
            'visibility' => ['mode' => 'all'],
            'editability' => ['mode' => 'none'],
            'condition' => ['kind' => 'always'],
            'html' => $html,
        ];
    }

    private static function signature(string $id, string $partyKey): array
    {
        return [
            'block_id' => $id,
            'type' => 'signature',
            'visibility' => ['mode' => 'only', 'party_keys' => [$partyKey]],
            'editability' => ['mode' => 'none'],
            'condition' => ['kind' => 'always'],
            'anchors' => [['anchor_id' => $id . '_sig', 'kind' => 'signature', 'party_key' => $partyKey]],
        ];
    }

    /**
     * Template 117 — Immovable Property Condition Report (Mandatory Disclosure Form).
     * Signed by Seller + Agent, acknowledged by Buyer (PPRA Act 22/2019 s70; Regs 2022 s36).
     */
    public static function template117(): array
    {
        return [
            'family' => '117',
            'data_dictionary_version' => 1,
            'legal_class' => 'general',
            'delivery_modes' => ['web_esign', 'pdf_wetink', 'download'],
            'parties' => [
                ['key' => 'seller', 'role' => 'Seller', 'cardinality' => 'one_or_more', 'ordering' => 1],
                ['key' => 'agent', 'role' => 'Agent', 'cardinality' => 'one', 'ordering' => 2],
                ['key' => 'buyer', 'role' => 'Buyer', 'cardinality' => 'one_or_more', 'ordering' => 3],
            ],
            'blocks' => [
                self::letterhead(),
                self::prose('title', '<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text"><u><strong>IMMOVABLE PROPERTY CONDITION REPORT IN RELATION TO THE SALE OF ANY IMMOVABLE PROPERTY</strong></u> (Property Practitioner Act 22 of 2019, Section 70 – Property Practitioners Regulations 2022 Section 36 – Mandatory Disclosure)</span></div>'),
                self::prose('c1_disclaimer', '<div class="corex-clause"><span class="corex-clause-number">1</span> <strong>Disclaimer.</strong> This condition report concerns the immovable property (the "Property"). This report does not constitute a guarantee or warranty of any kind by the owner of the Property or by the property practitioners representing that owner in any transaction, and should not be regarded as a substitute for any inspections or warranties that prospective purchasers may wish to obtain prior to concluding an agreement of sale.</span></div>'),
                self::prose('c2_definitions', '<div class="corex-clause"><span class="corex-clause-number">2</span> <strong>Definitions.</strong> "to be aware" means to have actual notice or knowledge of a certain fact or state of affairs; "defect" means any condition, latent or patent, that would or could have a significant deleterious or adverse impact on the value of the property, impair the health or safety of future occupants, or shorten the expected normal lifespan of the Property.</span></div>'),
                self::prose('c3_4_disclosure', '<div class="corex-clause"><span class="corex-clause-number">3</span> <strong>Disclosure of information.</strong> The owner discloses the information hereunder in the full knowledge that prospective purchasers may rely on such information when deciding whether, and on what terms, to purchase the Property, and authorises the appointed property practitioner to disclose it in connection with any actual or anticipated sale. <span class="corex-clause-number">4</span> <strong>Provision of additional information.</strong> The owner represents that responses have been accurately noted as "yes", "no" or "not applicable"; any "yes" must be fully explained in the additional information area.</span></div>'),
                self::prose('c5_statements', '<div class="corex-clause"><span class="corex-clause-number">5</span> <strong>Statements in connection with Property.</strong></div><table class="corex-table"><thead><tr><th></th><th>YES</th><th>NO</th><th>N/A</th></tr></thead><tbody>'
                    . '<tr><td>I am aware of the defects in the roof</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>I am aware of the defects in the electrical systems</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>I am aware of the defects in the plumbing system, including in the swimming pool (if any)</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>I am aware of the defects in the heating and air conditioning systems</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>I am aware of the defects in the septic or other sanitary disposal systems</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>I am aware of any defects in the basement or foundations, including cracks, seepage, flooding, dampness, mould or defective drainage</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>I am aware of structural defects in the Property</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>I am aware of boundary line disputes, encroachments, or encumbrances</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>I am aware that remodelling and refurbishment have affected the structure of the Property</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>I am aware that any additions or improvements were made only after required consents and permits were obtained</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>I am aware that a structure on the Property has been earmarked as a historic structure or heritage site</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>ADDITIONAL INFORMATION</td><td></td><td></td><td></td></tr></tbody></table>'),
                self::prose('c6_9_certification', '<div class="corex-clause"><span class="corex-clause-number">6</span> <strong>Owner\'s certification.</strong> The owner certifies the information provided is, to the best of the owner\'s knowledge and belief, true and correct as at the date of signature. <span class="corex-clause-number">7</span> <strong>Certification by person supplying information.</strong> A person other than the owner must certify due authorisation and correctness. <span class="corex-clause-number">8</span> <strong>Notice regarding advice or inspections.</strong> Both owner and buyers may wish to obtain professional advice and/or a professional inspection. <span class="corex-clause-number">9</span> <strong>Buyer\'s acknowledgement.</strong> The prospective buyer acknowledges that professional expertise may be required to detect defects, and acknowledges receipt of a copy of this statement.</span></div>'),
                self::prose('signed_heading', '<div class="corex-signature-section"><div class="corex-signature-section-title">THUS DONE AND SIGNED</div></div>'),
                self::signature('sig_seller', 'seller'),
                self::signature('sig_agent', 'agent'),
                self::signature('sig_buyer', 'buyer'),
            ],
            'assets' => [],
        ];
    }

    /**
     * Template 119 — Addendum B (extra information / compliance certificates). Seller + Agent.
     */
    public static function template119(): array
    {
        return [
            'family' => '119',
            'data_dictionary_version' => 1,
            'legal_class' => 'general',
            'delivery_modes' => ['web_esign', 'pdf_wetink', 'download'],
            'parties' => [
                ['key' => 'seller', 'role' => 'Seller', 'cardinality' => 'one_or_more', 'ordering' => 1],
                ['key' => 'agent', 'role' => 'Agent', 'cardinality' => 'one', 'ordering' => 2],
            ],
            'blocks' => [
                self::letterhead(),
                self::prose('title', '<div class="corex-h1">ADDENDUM B</div>'),
                self::prose('extra_info', '<table class="corex-table"><thead><tr><th>EXTRA INFORMATION</th></tr></thead><tbody>'
                    . '<tr><td>Are there registered building plans for the whole property, all improvements and solid roof structures (e.g. carport, pools, etc)</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>Are you in possession of a valid Certificate of Compliance for the following:</td></tr>'
                    . '<tr><td>Electrical Compliance Certificate – If Yes, when was it issued?</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>Electrical Fence Certificate – If Yes, when was it issued?</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>Gas Compliance Certificate – If Yes, when was it issued?</td><td></td><td></td><td></td></tr>'
                    . '<tr><td>Entomology Certificate – If Yes, when was it issued?</td><td></td><td></td><td></td></tr></tbody></table>'),
                self::prose('signed_heading', '<div class="corex-signature-section"><div class="corex-signature-section-title">THUS DONE AND SIGNED</div></div>'),
                self::signature('sig_seller', 'seller'),
                self::signature('sig_agent', 'agent'),
            ],
            'assets' => [],
        ];
    }
}
