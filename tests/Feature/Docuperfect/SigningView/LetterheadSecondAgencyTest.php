<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Docuperfect\Document;
use App\Support\Docuperfect\DocumentAgencyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * 14 Oct 2026 — the PUBLIC signing page (nobody signed in) re-resolved the stored document's letterhead through the shared
 * company-header, which fell back to the agency whose slug is hfc-coastal. So a second agency's signer saw Home Finders'
 * letterhead on the lease. The page now pins the DOCUMENT's own agency (SigningController::show -> DocumentAgencyContext).
 */
final class LetterheadSecondAgencyTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    public function test_a_second_agencys_signer_sees_that_agencys_letterhead_never_home_finders(): void
    {
        $this->withoutVite();
        Storage::fake('public');
        Storage::disk('public')->put('logos/hfc-logo.png', 'png');
        Storage::disk('public')->put('logos/cape-logo.png', 'png');

        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1);
        /** @var Document $document */
        $document = $session['document'];

        // The decoy: a real Home Finders row by its slug, with its own logo and details.
        $hfc = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-coastal']);
        $hfc->forceFill(['trading_name' => 'Johan and Elize Properties T/A', 'address' => 'Shop 5, Shelly Beach', 'phone' => '039 315 0857',
            'email' => 'admin@hfcoastal.co.za', 'logo_path' => 'logos/hfc-logo.png'])->save();

        // The document's agency: Cape Rentals.
        $cape = Agency::find($document->agency_id);
        $cape->forceFill(['name' => 'Cape Rentals', 'trading_name' => 'Cape Rentals (Pty) Ltd', 'address' => '12 Long Street, Cape Town',
            'phone' => '021 555 0100', 'email' => 'hello@caperentals.test', 'logo_path' => 'logos/cape-logo.png'])->save();

        // The stored snapshot carries a letterhead baked while HFC's context was in force (the stale-snapshot case the refresher exists for).
        $baked = DocumentAgencyContext::with($hfc->id, null, fn () => view('docuperfect.web-templates.components.company-header')->render());
        DocumentAgencyContext::flush();
        $data = $document->web_template_data;
        $data['merged_html'] = '<div class="corex-document">' . $baked . '<h1>Lease</h1></div>';
        unset($data['canonical_html'], $data['canonical_version']);
        $document->forceFill(['web_template_data' => $data])->save();

        $request = $session['recipients']->first();
        $html = $this->extractRenderedDocumentHtml($this->asRecipient($request));

        $this->assertStringContainsString('Cape Rentals (Pty) Ltd', $html);
        $this->assertStringContainsString('hello@caperentals.test', $html);
        $this->assertStringContainsString('logos/cape-logo.png', $html);
        foreach (['Home Finders', 'hfcoastal', 'Johan and Elize', '039 315 0857', 'logos/hfc-logo.png', 'Shelly Beach'] as $marker) {
            $this->assertStringNotContainsString($marker, $html, "the signing page shows '{$marker}'");
        }
    }

    public function test_the_signing_page_names_the_documents_agency_not_the_creators(): void
    {
        $this->withoutVite();
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1);
        $document = $session['document'];

        // The creator belongs to one agency, the document to another (an owner who switched agency to prepare it).
        $other = Agency::create(['name' => 'Other Co Realty', 'slug' => 'other-co-' . uniqid()]);
        $document->forceFill(['agency_id' => $other->id])->save();

        $body = (string) $this->asRecipient($session['recipients']->first())->getContent();

        $this->assertStringContainsString('Other Co Realty', $body);
        $this->assertMatchesRegularExpression('/Other Co Realty\s*&mdash; Secure Document Signing/', $body);
        $this->assertStringNotContainsString('Signing Session Test Agency &mdash;', $body);
    }
}
