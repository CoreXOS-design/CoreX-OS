<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Services\Docuperfect\SignaturePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * cc2, 2026-08-30 — audit-certificate.blade.php's document-level legal
 * footer (:99-103) stated, unconditionally, that the document "was signed
 * electronically in accordance with the ECT Act" — even when one or more
 * parties actually signed wet-ink (physically, scan uploaded and
 * verified). The per-party rows a few lines above ALREADY read each
 * party's own signing_method correctly ("Electronic signature" vs "Wet
 * ink (uploaded and verified)") — the footer just never consulted the
 * same data.
 *
 * Fix: derive the footer wording from the same partyProgress()
 * signing_method values the per-party rows already use — no new column,
 * no new state machine, a pure read of data already loaded for this view.
 *
 * The three wordings below are DRAFT — pending Johan/Andre legal
 * sign-off, not yet final. This test pins their CURRENT text and,
 * more importantly, pins the SELECTION LOGIC (all-electronic /
 * all-wet-ink / mixed) so a future wording change can't silently change
 * which case fires for which data.
 */
final class AuditCertificateSigningMethodFooterTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_electronic_footer_is_unchanged_from_todays_wording(): void
    {
        $template = $this->makeTemplate([
            'seller' => 'electronic',
            'buyer' => 'electronic',
        ]);

        $html = $this->renderCertificate($template);

        $this->assertStringContainsString('This document was signed electronically in accordance with the', $html);
        $this->assertStringContainsString('Electronic Communications and Transactions Act 25 of 2002 (ECT Act)', $html);
        $this->assertStringNotContainsString('signed in wet ink', $html);
        $this->assertStringNotContainsString('mixture of electronic and wet-ink', $html);
    }

    public function test_all_wet_ink_footer_states_wet_ink_not_the_ect_act(): void
    {
        $template = $this->makeTemplate([
            'seller' => 'wet_ink',
            'buyer' => 'wet_ink',
        ]);

        $html = $this->renderCertificate($template);

        $this->assertStringContainsString('This document was signed in wet ink', $html);
        $this->assertStringContainsString('verified by the agency', $html);
        // Must NOT claim the ECT Act governs a wet-ink-only document.
        $this->assertStringNotContainsString('Electronic Communications and Transactions Act', $html);
        $this->assertStringNotContainsString('signed electronically', $html);
    }

    public function test_mixed_footer_names_both_methods_and_points_to_the_party_list(): void
    {
        $template = $this->makeTemplate([
            'seller' => 'electronic',
            'buyer' => 'wet_ink',
            'witness' => 'electronic',
        ]);

        $html = $this->renderCertificate($template);

        $this->assertStringContainsString('mixture of electronic and wet-ink', $html);
        $this->assertStringContainsString('Electronic Communications and Transactions Act 25 of 2002 (ECT Act)', $html);
        $this->assertStringContainsString('verified by the agency', $html);
        $this->assertStringContainsString('Signing Parties section above', $html);
    }

    public function test_per_party_rows_are_byte_identical_across_all_three_footer_cases(): void
    {
        // Same party SHAPE (role/name/email) for "seller", electronic in
        // every case -- only the OTHER party's method differs, which changes
        // which footer case fires but must never change how seller's own
        // row renders. Proves the fix touched only the footer below it.
        $allElectronic = $this->renderCertificate($this->makeTemplate(['seller' => 'electronic', 'buyer' => 'electronic']));
        $allWetInk = $this->renderCertificate($this->makeTemplate(['seller' => 'electronic', 'buyer' => 'wet_ink', 'witness' => 'wet_ink']));
        $mixed = $this->renderCertificate($this->makeTemplate(['seller' => 'electronic', 'buyer' => 'wet_ink']));

        $sellerRowAllElectronic = $this->extractOnePartyRow($allElectronic, 'SELLER');
        $sellerRowAllWetInk = $this->extractOnePartyRow($allWetInk, 'SELLER');
        $sellerRowMixed = $this->extractOnePartyRow($mixed, 'SELLER');

        $this->assertSame($sellerRowAllElectronic, $sellerRowAllWetInk, 'an electronic party\'s own row must render identically regardless of the footer case its document falls into');
        $this->assertSame($sellerRowAllElectronic, $sellerRowMixed, 'an electronic party\'s own row must render identically regardless of the footer case its document falls into');
    }

    public function test_not_required_party_still_reads_as_all_electronic(): void
    {
        // A deceased/not-required party has NO SignatureRequest row at all
        // (never invited, never signs) -- partyProgress() itself falls back
        // to 'electronic' for a role with no matching request. The footer
        // must not be tricked into "mixed" by that fallback.
        $template = $this->makeTemplate(['seller' => 'electronic', 'buyer' => 'electronic']);
        $partiesJson = $template->parties_json;
        $partiesJson['estate_representative'] = ['role' => 'estate_representative', 'name' => 'Deceased Party Placeholder', 'email' => 'n/a@example.test'];
        $template->update(['parties_json' => $partiesJson]);

        $html = $this->renderCertificate($template->fresh());

        $this->assertStringContainsString('This document was signed electronically in accordance with the', $html);
        $this->assertStringNotContainsString('mixture of electronic and wet-ink', $html);
        $this->assertStringNotContainsString('This document was signed in wet ink', $html);
    }

    /** @param array<string,string> $partySigningMethods role => 'electronic'|'wet_ink' */
    private function makeTemplate(array $partySigningMethods, bool $forceWetInkLabelOnly = false): SignatureTemplate
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Agent', 'email' => 'a-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Footer Test Agency ' . Str::random(6), 'slug' => 'footer-test-' . strtolower(Str::random(6)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->where('id', $userId)->update(['agency_id' => $agencyId]);

        $tpl = DocuperfectTemplate::create([
            'name' => 'Footer test', 'render_type' => 'web',
            'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['owner_party'], 'field_mappings' => [], 'owner_id' => $userId,
        ]);
        $doc = Document::create([
            'name' => 'Footer Test Doc', 'document_type' => 'agreement',
            'owner_id' => $userId, 'template_id' => $tpl->id, 'agency_id' => $agencyId,
            'web_template_data' => ['merged_html' => ''],
        ]);

        $partiesJson = [];
        foreach ($partySigningMethods as $role => $method) {
            $partiesJson[$role] = ['role' => $role, 'name' => ucfirst($role) . ' Party', 'email' => "{$role}@example.test"];
        }

        $sigTpl = SignatureTemplate::create([
            'document_id' => $doc->id,
            'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING,
            'created_by' => $userId,
            'parties_json' => $partiesJson,
        ]);

        foreach ($partySigningMethods as $role => $method) {
            SignatureRequest::create([
                'signature_template_id' => $sigTpl->id,
                'party_role' => $role, 'role_index' => 1,
                'signer_name' => ucfirst($role) . ' Party', 'signer_email' => "{$role}@example.test",
                'token' => Str::random(40), 'token_expires_at' => now()->addDays(30), 'status' => 'completed',
                'signing_method' => $method,
                'completed_at' => now(),
            ]);
        }

        return $sigTpl;
    }

    private function renderCertificate(SignatureTemplate $template): string
    {
        $service = app(SignaturePdfService::class);
        $method = new ReflectionMethod($service, 'buildAuditData');
        $method->setAccessible(true);
        $data = $method->invoke($service, $template->fresh(['requests']), $template->document);

        return view('docuperfect.signatures.pdf.audit-certificate', $data)->render();
    }

    /** DOM-precise extraction of one party's own .party-row -- robust to its
     * position among a differing number of sibling rows across templates
     * (a crude substring "up to the next party-row" boundary is NOT, since
     * whether there IS a next sibling differs by template). */
    private function extractOnePartyRow(string $html, string $roleLabel): string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?><div id="r">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new \DOMXPath($dom);
        $nodes = $xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " party-row ")][.//div[@class="party-role" and normalize-space(text())="' . $roleLabel . '"]]');
        $this->assertGreaterThan(0, $nodes->length, "party row for {$roleLabel} not found");

        return $dom->saveHTML($nodes->item(0)) ?: '';
    }
}
