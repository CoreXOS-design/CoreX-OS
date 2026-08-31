<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Services\Docuperfect\CdsParserService;
use Tests\TestCase;
use ZipArchive;

/**
 * AT-390 (Johan 2026-08-31) — importing the Letting Mandate V5 rendered
 * only as far as the SECOND numbered clause and then stopped. Root cause,
 * confirmed against the actual stored draft on Staging: CdsParserService::
 * detectSignatureSections() backward-scanned the parsed sections for
 * substrings like "signature" — RAW substring, no word boundary — and
 * treated the FIRST hit as the start of the signature block, collapsing
 * everything from there to the end of the document into one unstructured,
 * unrendered "preamble" string.
 *
 * The trigger was clause 3, immediately after the cut-off point: "The sole
 * mandate hereby granted shall commence on date of signature hereof..." —
 * an entirely ordinary mandate-period clause. The substring "signature"
 * inside it was enough. Confirmed by reading the actual stored cds_json:
 * its synthetic signature_section's "preamble" field contained clause 3
 * through clause 7, the bank-details block, AND the Other Conditions
 * block, verbatim, concatenated — proving nothing was lost, only misfiled
 * and left unrendered by the builder.
 *
 * The fix: prefer the document's own explicit %%%% signature marker
 * (already detected structurally by detectMarkers(), earlier in the same
 * pipeline) as the authoritative trigger. Every document in this estate
 * marks its real signature line explicitly, so when the marker exists the
 * fuzzy text-scan never runs at all. The text-scan survives only as a
 * fallback for documents with no marker, tightened to word-boundary
 * matching plus a length cap (a real signature line is a few words, never
 * a 400-character clause that happens to mention "signature" in passing).
 */
final class SignatureSectionMarkerPreferredTest extends TestCase
{
    // No RefreshDatabase — CdsParserService::parse() is pure XML/array
    // processing, no DB involved. Skipping it avoids the shared test-DB
    // schema-load contention entirely (four other lanes are on it tonight).

    /**
     * Faithful reproduction of the Letting Mandate V5's structure around
     * the cut-off point, using the DOCUMENT'S OWN wording verbatim (pulled
     * from the actual stored cds_draft on Staging, not paraphrased):
     * clause 1, clause 2 (the long one, ending "...clause 4."), clause 3
     * (the true trigger — "date of signature hereof"), more clauses, a
     * bank-details block, an Other Conditions marker, and a real %%%%
     * signature marker at the true end.
     */
    private function lettingMandateParagraphs(): array
    {
        return [
            'Mandate entered into between',
            'The Parties',
            "The Owner/s:\t\t\t@@@@",
            "Home Finders Coastal (Agent):\t@@@@",
            'The owner hereby grants to the Agent a Mandate to offer to let the property known as @@@@ subject to the conditions set out in this agreement.',
            'The rental amount required by the Owner for the property is R@@@@ which includes the commission as stated in clause 4.  In the event of the Agency not finding a suitable Tenant to rent the property at such rental amount, then, between the Owner and the Agency they will agree to an acceptable rental amount prior to allowing any tenant taking occupation of the said property, which includes commission as stated in clause 4.',
            'The sole mandate hereby granted shall commence on date of signature hereof and shall remain in force until 22h00 on the @@@@.',
            'The Owner will pay to the Agent a commission, calculated at a percentage of @@@@% plus VAT on the letting price of the property.',
            'The Agency will screen all possible tenants prior to occupation to ensure a hassle free letting of the property.',
            'The Agent will deposit the monthly rental collections into the following Bank Account supplied by the Owner, by no later than the 7th day of every month.',
            "Account Holder's Name: @@@@",
            'Branch Name and Code: @@@@',
            "Owner's Contact details: @@@@",
            "Owner's Email Address: @@@@",
            'The Owner shall supply the Agency with water and lights service usage charges every month, so the Agency may add this to the statement forwarded to the Tenant.',
            '~~~~OTHER_CONDITIONS~~~~',
            '',
            '',
            'Agent: %%%%',
        ];
    }

    public function test_letting_mandate_reproduction_imports_completely_with_the_fix(): void
    {
        $cds = $this->parseDocxParagraphs($this->lettingMandateParagraphs());

        // Structured content ONLY (never the signature_section's preamble —
        // that field is exactly where the AT-390 bug hides swallowed clauses
        // as flat, unrendered text, so a check that would still pass with
        // the content sitting there proves nothing).
        $structuredPlain = collect($cds['sections'])
            ->filter(fn ($s) => ($s['type'] ?? null) !== 'signature_section')
            ->map(fn ($s) => $this->sectionPlainText($s))
            ->implode(' | ');

        $this->assertStringContainsString('date of signature hereof', $structuredPlain, 'fixture sanity — the real trigger phrase must be present, as real structured content');
        $this->assertStringContainsString('Bank Account supplied by the Owner', $structuredPlain, 'the bank-details block must survive as real structured content, not text trapped in an unrendered preamble');
        $this->assertStringContainsString('water and lights service usage charges', $structuredPlain, 'the last ordinary clause before Other Conditions must survive as real structured content');

        $otherConditionsBlock = collect($cds['sections'])
            ->flatMap(fn ($s) => $s['content'] ?? [])
            ->first(fn ($item) => is_array($item) && ($item['purpose'] ?? null) === 'other_conditions');
        $this->assertNotNull($otherConditionsBlock, 'the Other Conditions block must survive as a real insertable block, not text trapped in an unrendered preamble');

        $sig = collect($cds['sections'])->first(fn ($s) => ($s['type'] ?? null) === 'signature_section');
        $this->assertNotNull($sig, 'a real signature_section must still be produced');
        $this->assertStringNotContainsString('Bank Account', $sig['preamble'] ?? '', 'the signature preamble must NOT have swallowed the bank-details clause — that is exactly the AT-390 bug');
        $this->assertStringNotContainsString('date of signature hereof', $sig['preamble'] ?? '', 'the signature preamble must NOT have swallowed clause 3 — that is exactly the AT-390 bug');
    }

    public function test_marker_present_skips_text_scanning_entirely_even_with_a_trigger_word_clause(): void
    {
        // Same shape, isolated: one ordinary clause containing "signature" as
        // a substring, THEN a real %%%% marker. The marker must win outright.
        $cds = $this->parseDocxParagraphs([
            'Clause one is here.',
            'This clause mentions the word signature in passing and is otherwise ordinary body text that must survive.',
            'Clause three, the last ordinary clause, must also survive completely intact.',
            'Agent: %%%%',
        ]);

        $plain = collect($cds['sections'])->map(fn ($s) => $this->sectionPlainText($s))->implode(' | ');
        $this->assertStringContainsString('Clause three, the last ordinary clause', $plain, 'a clause AFTER the false-positive-triggering one must not be swallowed once a real marker exists');

        $sig = collect($cds['sections'])->first(fn ($s) => ($s['type'] ?? null) === 'signature_section');
        $this->assertNotNull($sig);
        $this->assertStringNotContainsString('Clause three', $sig['preamble'] ?? '');
        $this->assertStringNotContainsString('mentions the word signature', $sig['preamble'] ?? '');
    }

    public function test_fallback_text_scan_requires_a_whole_word_not_a_substring(): void
    {
        // No %%%% marker anywhere — forces the fallback. "assigned" and
        // "designated" both contain "signed" as a literal substring; neither
        // may trigger the old bug under the tightened, word-boundary fallback.
        $cds = $this->parseDocxParagraphs([
            'The rights under this mandate may not be assigned without written consent.',
            'The designated agent shall administer this mandate on behalf of the Agency.',
            'This final ordinary clause must survive intact for the fallback fix to be proven.',
        ]);

        $plain = collect($cds['sections'])->map(fn ($s) => $this->sectionPlainText($s))->implode(' | ');
        $this->assertStringContainsString('This final ordinary clause must survive intact', $plain, '"assigned"/"designated" must never trigger the signature scan as substrings');

        $sig = collect($cds['sections'])->first(fn ($s) => ($s['type'] ?? null) === 'signature_section');
        $this->assertNull($sig, 'no real signature keyword or marker exists in this fixture at all — nothing should be collapsed');
    }

    public function test_fallback_text_scan_still_catches_a_genuine_short_signature_line_with_no_marker(): void
    {
        // No %%%% marker — a genuine short signature line must still work
        // via the tightened (word-boundary + length-capped) fallback.
        $cds = $this->parseDocxParagraphs([
            'This is an ordinary clause about the mandate period and other matters.',
            'SIGNED at Uvongo on this 3rd day of March 2026.',
        ]);

        $sig = collect($cds['sections'])->first(fn ($s) => ($s['type'] ?? null) === 'signature_section');
        $this->assertNotNull($sig, 'a genuine short SIGNED line must still be caught by the tightened fallback when no marker exists');
    }

    public function test_fallback_text_scan_never_matches_a_long_clause_even_with_a_whole_word_hit(): void
    {
        // No %%%% marker. A LONG clause containing the whole word "signature"
        // — exactly the AT-390 shape — must never match even under the
        // fallback, because of the length cap. Only the length cap
        // distinguishes this from test_fallback_..._survives above once
        // word-boundary alone would otherwise still match "signature" here.
        $longClause = 'The sole mandate hereby granted shall commence on date of signature hereof and shall remain in force until 22h00 on the specified expiry date, during which period the Agency shall have the exclusive right to market and let the property on behalf of the Owner under the terms set out elsewhere in this agreement.';
        $cds = $this->parseDocxParagraphs([
            $longClause,
            'This is the final ordinary clause and must survive.',
        ]);

        $plain = collect($cds['sections'])->map(fn ($s) => $this->sectionPlainText($s))->implode(' | ');
        $this->assertStringContainsString('This is the final ordinary clause and must survive', $plain, 'a long clause containing the whole word "signature" must not trigger the fallback — length cap must hold');
        $sig = collect($cds['sections'])->first(fn ($s) => ($s['type'] ?? null) === 'signature_section');
        $this->assertNull($sig);
    }

    // ── three already-working real documents, unchanged (Johan's bound) ───
    // Real .docx uploads are never retained after import (Laravel discards
    // the temp file), so these reproduce each document's REAL tail content
    // verbatim, pulled from its own stored cds_json on Staging — not
    // paraphrased. Each was independently confirmed (read-only query)
    // to have NEVER produced a synthetic signature_section under the
    // pre-fix code — i.e. these three already exercise
    // findSignatureMarkerSectionIndex()/the fallback returning null, and
    // must keep doing so byte-for-byte.

    /**
     * EXCLUSIVE AUTHORITY TO SELL (template 85) — carries THREE %%%%
     * markers (mid-document, signing off individual clauses), none within
     * 15 sections of the true end. This is the exact case the marker
     * window-cap exists for: an uncapped "nearest marker wins" would
     * wrongly swallow the warranty clause, "Other" conditions, Show House
     * Security, the HFC Pledge, and the POPI consent clause — 8 real
     * clauses/headings — into one unrendered blob.
     */
    public function test_eats_v10_shape_unaffected_no_marker_within_reach_stays_uncollapsed(): void
    {
        $paragraphs = [
            'The seller warrants that the Exclusive Authority To Sell price is sufficient to cover the outstanding bonds / Professional Fee, rates and taxes, electrical certificates and any other costs associated with the sale.',
            'Other: ~~~~OTHER_CONDITIONS~~~~',
            'SHOW HOUSE SECURITY',
            'Please ensure that all valuables are safely packed / locked away as we cannot be held responsible for any losses due to theft during a Show house.',
            'THE HOME FINDERS COASTAL PLEDGE',
            'Granting a Home Finders Coastal Exclusive Authority To Sell, to sell your Property is a major decision and I/we, as your Property Practitioner accept the responsibility of:',
            'Adhering at all times to the Code of Conduct of the Property Practitioners Regulatory Authority.',
            'Timeously advising my colleagues that your Property is on the market, and any changes to the Exclusive Authority To Sell price and other information on the mandate.',
            'Giving you honest and professional advice on all offers that you receive.',
            'Advertising your Property on our internet sites until expiry of the Exclusive Authority To Sell.',
            'Advertising your Property as often as possible within the constraints of my company’s policies on advertising.',
            'The Seller/s insists that any potential Purchaser/s viewing the above said Property with the intent to buy the above said Property will be positively identified.',
            'The Seller/s insists that a Buyers questionnaire be completed prior to viewing the above said Property.',
            'The Seller/s insists that all potential Buyers must prove to the Property Practitioner that they have the financial means to buy the above mentioned Property.',
            'PROTECTION OF PERSONAL INFORMATION',
            'The Seller/s hereby give their consent to the Estate Agency/ies involved in the Exclusive Authority To Sell to process their personal information for the purposes of this agreement.',
        ];
        // The mid-document marker: three clauses earlier, well outside the window.
        $withMidDocMarker = array_merge(
            ['Clause acknowledged by seller. %%%%'],
            $paragraphs
        );

        $cds = $this->parseDocxParagraphs($withMidDocMarker);

        $sig = collect($cds['sections'])->first(fn ($s) => ($s['type'] ?? null) === 'signature_section');
        $this->assertNull($sig, 'EATS-shape: no marker within reach of the true end — must stay uncollapsed exactly as it always has (never produced a signature_section)');

        $structuredPlain = collect($cds['sections'])->map(fn ($s) => $this->sectionPlainText($s))->implode(' | ');
        $this->assertStringContainsString('THE HOME FINDERS COASTAL PLEDGE', $structuredPlain);
        $this->assertStringContainsString('PROTECTION OF PERSONAL INFORMATION', $structuredPlain);
        $this->assertStringContainsString('financial means to buy the above mentioned Property', $structuredPlain, 'the last real clause must survive as real content — this is the exact shape a naive uncapped marker fix would have broken');
    }

    /**
     * Rental Marketing Permission (template 98) — its lone %%%% marker sits
     * in the FINAL section (bare, no surrounding text) and, like EATS,
     * never produced a synthetic signature_section under the pre-fix code
     * either (no keyword ever matched its plain "Net Amount to Lessor"-
     * style trailing content). Proves the fix doesn't invent a
     * signature_section where none existed before, on a document whose
     * marker IS within the window this time — the boundary case between
     * "found and used" and "found but too far."
     */
    public function test_rental_marketing_permission_shape_unaffected(): void
    {
        $cds = $this->parseDocxParagraphs([
            'Total Rental Amount 				@@@@',
            'Less Agent’s Service Fee (Including VAT)	@@@@',
            'Let’s Assist					@@@@',
            'Net Amount to Lessor				@@@@',
            '             %%%%',
        ]);

        $structuredPlain = collect($cds['sections'])
            ->filter(fn ($s) => ($s['type'] ?? null) !== 'signature_section')
            ->map(fn ($s) => $this->sectionPlainText($s))
            ->implode(' | ');
        $this->assertStringContainsString('Net Amount to Lessor', $structuredPlain, 'nothing before the marker must be swallowed or altered');
        $this->assertStringContainsString('Total Rental Amount', $structuredPlain);
    }

    /**
     * EXCLUSIVE AUTHORITY TO SELL - VL (template 86) — the sibling of 85,
     * same shape (multiple mid-document markers, real trailing content).
     * Independent confirmation the fix generalises, not a one-off.
     */
    public function test_eats_vl_shape_unaffected_no_marker_within_reach_stays_uncollapsed(): void
    {
        $cds = $this->parseDocxParagraphs([
            'Seller acknowledges receipt of the mandate copy. %%%%',
            'The seller warrants that the Exclusive Authority To Sell price is sufficient to cover the outstanding bonds / Professional Fee, rates and taxes.',
            'Other: ~~~~OTHER_CONDITIONS~~~~',
            'SHOW HOUSE SECURITY',
            'Please ensure that all valuables are safely packed / locked away as we cannot be held responsible for any losses due to theft during a Show house.',
            'THE HOME FINDERS COASTAL PLEDGE',
            'Granting a Home Finders Coastal Exclusive Authority To Sell, to sell your Property is a major decision.',
            'Adhering at all times to the Code of Conduct of the Property Practitioners Regulatory Authority.',
            'Advertising your Property on our internet sites until expiry of the Exclusive Authority To Sell.',
            'The Seller/s insists that a Buyers questionnaire be completed prior to viewing the above said Property.',
            'The Seller/s insists that all potential Buyers must prove to the Property Practitioner that they have the financial means to buy the above mentioned Property.',
            'Advertising your Property as often as possible within the constraints of my company’s policies on advertising.',
            'The Seller/s insists that any potential Purchaser/s viewing the above said Property with the intent to buy the above said Property will be positively identified.',
            'The Seller/s insists that a Buyers questionnaire be completed prior to viewing the above said Property and the Agency will keep all relevant documents.',
            'The Seller/s insists that all potential Buyers must prove to the Property Practitioner that they have the financial means to buy the above mentioned Property before viewing.',
            'PROTECTION OF PERSONAL INFORMATION',
            'The Seller/s hereby give their consent to the Estate Agency/ies involved in the Exclusive Authority To Sell to process their personal information for the purposes of this agreement.',
        ]);

        $sig = collect($cds['sections'])->first(fn ($s) => ($s['type'] ?? null) === 'signature_section');
        $this->assertNull($sig, 'EATS-VL-shape: marker far outside the window — must stay uncollapsed');

        $structuredPlain = collect($cds['sections'])->map(fn ($s) => $this->sectionPlainText($s))->implode(' | ');
        $this->assertStringContainsString('PROTECTION OF PERSONAL INFORMATION', $structuredPlain);
        $this->assertStringContainsString('financial means to buy the above mentioned Property', $structuredPlain);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function sectionPlainText(array $section): string
    {
        $ref = new \ReflectionMethod(CdsParserService::class, 'contentToPlainText');
        $ref->setAccessible(true);
        $viaContent = isset($section['content']) ? $ref->invoke(app(CdsParserService::class), $section['content']) : '';
        return $viaContent . ' ' . ($section['preamble'] ?? '');
    }

    private function parseDocxParagraphs(array $paragraphs): array
    {
        $path = $this->writeDocxParagraphs($paragraphs);
        $result = app(CdsParserService::class)->parse($path);
        @unlink($path);
        return $result;
    }

    /** Multi-paragraph .docx — same minimal-but-valid shape as the existing
     * MarkerSyntaxConformanceTest::writeDocx(), extended to one <w:p> per
     * array entry so a real multi-clause document can be reproduced. */
    private function writeDocxParagraphs(array $paragraphs): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cds') . '.docx';

        $body = '';
        foreach ($paragraphs as $text) {
            $body .= '<w:p><w:r><w:t xml:space="preserve">' . $this->esc($text) . '</w:t></w:r></w:p>';
        }

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body . '</w:body>'
            . '</w:document>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $document);
        $zip->close();

        return $path;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
