<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\SigningSurfaceResolver;
use Tests\TestCase;

/**
 * Two faults left over from the 13 Oct rentals walk:
 *
 *  1. The lease document's breakdown rows and date/time inputs went near-black on the white page in
 *     the app's dark theme (corex.css paints even <tbody> rows and every input).
 *  2. A lease's signature section printed a SECOND block "Thus done and signed by the Buyer (tenant) /
 *     Seller (landlord)" under the Lessor/Lessee one. Cause: the e-sign wizard decided "sale" from the
 *     property's source table alone, and a lease is built on a property in agency stock too.
 */
final class LeaseDocumentDarkThemeAndLabelsTest extends TestCase
{
    private function leaseTemplate(): Template
    {
        // Mirrors QA template 88: web lease, generic owner/acquiring party tokens, category 'rentals'.
        return new Template([
            'name'            => 'Lease Agreement',
            'template_type'   => 'rental',
            'category'        => 'rentals',
            'signing_parties' => ['owner_party', 'acquiring_party', 'agent'],
        ]);
    }

    private function saleTemplate(): Template
    {
        return new Template([
            'name'            => 'Sole Mandate',
            'template_type'   => 'sale',
            'category'        => 'sales',
            'signing_parties' => ['owner_party', 'acquiring_party', 'agent'],
        ]);
    }

    public function test_a_lease_template_is_never_a_sale_even_when_the_property_is_in_agency_stock(): void
    {
        $this->assertFalse($this->leaseTemplate()->isSalesDocument('properties'));
        $this->assertSame('rentals', $this->leaseTemplate()->resolvedTransactionCategory('properties'));
        $this->assertTrue($this->saleTemplate()->isSalesDocument('properties'));
    }

    public function test_wizard_no_longer_derives_sale_from_the_property_source_alone(): void
    {
        $src = (string) file_get_contents(base_path('app/Http/Controllers/Docuperfect/ESignWizardController.php'));

        $this->assertStringNotContainsString("\$isSalesContext = (\$propSource === 'properties')", $src);
        $this->assertSame(
            2,
            substr_count($src, '$isSalesContext = $template->isSalesDocument($propSource);'),
            'Both the Fill & Review party builder and the web-template signing path must ask the template.'
        );
    }

    public function test_resolver_given_a_lease_prints_lease_words_only(): void
    {
        $body = '<div class="signature-section"><p><strong>Signatures</strong></p>'
            . '<div class="sig-party-block"><p class="sig-text">Thus done and signed by the Lessor</p>'
            . '<span class="sig-field" data-marker-party="landlord" data-marker-type="signature"></span></div>'
            . '<div class="sig-party-block"><p class="sig-text">Thus done and signed by the Lessee</p>'
            . '<span class="sig-field" data-marker-party="tenant" data-marker-type="signature"></span></div>'
            . '<div class="sig-party-block"><p class="sig-text">Thus done and signed by the Agent</p>'
            . '<span class="sig-field" data-marker-party="agent" data-marker-type="signature"></span></div></div>';

        $isSales = $this->leaseTemplate()->isSalesDocument('properties');
        $html = app(SigningSurfaceResolver::class)->resolve($body, [
            ['role' => 'landlord', 'name' => 'Lola Landlord'],
            ['role' => 'tenant', 'name' => 'Tim Tenant'],
        ], 'Amy Agent', $isSales);

        $this->assertStringNotContainsString('Buyer', $html);
        $this->assertStringNotContainsString('Seller', $html);
        $this->assertStringNotContainsString('data-resolver-injected', $html, 'Every party already has a surface — nothing is injected.');
        $this->assertStringNotContainsString('data-marker-party="buyer"', $html);
        $this->assertStringNotContainsString('data-marker-party="seller"', $html);
    }

    public function test_resolver_injects_lease_words_when_a_lease_party_has_no_surface(): void
    {
        $body = '<div class="signature-section"><p><strong>Signatures</strong></p></div>';
        $html = app(SigningSurfaceResolver::class)->resolve($body, [
            ['role' => 'landlord', 'name' => 'Lola Landlord'],
            ['role' => 'tenant', 'name' => 'Tim Tenant'],
        ], 'Amy Agent', false);

        $this->assertStringContainsString('signed by the Lessor (Lola Landlord)', $html);
        $this->assertStringContainsString('signed by the Lessee (Tim Tenant)', $html);
        $this->assertStringNotContainsString('Buyer', $html);
        $this->assertStringNotContainsString('Seller', $html);
    }

    public function test_resolver_given_a_sale_still_prints_sale_words(): void
    {
        $body = '<div class="signature-section"><p><strong>Signatures</strong></p></div>';
        $html = app(SigningSurfaceResolver::class)->resolve($body, [
            ['role' => 'seller', 'name' => 'Sam Seller'],
            ['role' => 'buyer', 'name' => 'Bea Buyer'],
        ], 'Amy Agent', true);

        $this->assertStringContainsString('signed by the Seller (Sam Seller)', $html);
        $this->assertStringContainsString('signed by the Buyer (Bea Buyer)', $html);
    }

    /** @return array<string, array{0: string}> */
    public static function documentCssFiles(): array
    {
        // .docuperfect-document-body = wizard preview / fill & review; .corex-document-wrapper = the
        // staff sign / review / view-live / download pages. Each loads one of these two stylesheets.
        return [
            'wizard + external sign surface' => ['public/css/docuperfect-recipient-blocks.css'],
            'staff sign / review / view-live' => ['public/css/corex-document.css'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documentCssFiles')]
    public function test_document_css_pins_rows_headers_and_inputs_to_paper_colours_in_dark_theme(string $file): void
    {
        $css = (string) file_get_contents(base_path($file));
        $scope = 'html\.dark :is\(\.docuperfect-document-body, \.corex-document-wrapper\)';

        // The things corex.css's `html.dark` rules paint, each re-pinned inside the document with at least
        // the same specificity (and !important where corex.css uses it).
        $this->assertMatchesRegularExpression('/' . $scope . ' tbody tr:nth-child\(even\)[^{]*\{[^}]*background-color:\s*#fff;/s', $css);
        $this->assertMatchesRegularExpression('/' . $scope . ' thead th\s*\{[^}]*background-color:\s*#f3f4f6 !important;/s', $css);
        $this->assertMatchesRegularExpression(
            '/' . $scope . ' input:not\(\[type="checkbox"\]\)[^{]*\{[^}]*background-color:\s*#fff !important;[^}]*color:\s*#1a1a1a !important;/s',
            $css
        );
        $this->assertMatchesRegularExpression('/:is\(\.docuperfect-document-body, \.corex-document-wrapper\)\s*\{\s*color-scheme:\s*light;/', $css);
    }

    public function test_corex_dark_rules_the_document_css_must_out_rank_still_exist_as_assumed(): void
    {
        // If corex.css stops painting these, this guard flags that the document CSS can be revisited.
        $dark = (string) file_get_contents(resource_path('css/corex.css'));
        $this->assertStringContainsString('html.dark tbody tr:nth-child(even) { background-color: var(--surface-2); }', $dark);
        $this->assertStringContainsString('html.dark thead th {', $dark);
        $this->assertStringContainsString('html.dark input:not([type="checkbox"])', $dark);
    }

    public function test_lease_breakdown_cells_carry_an_explicit_paper_background(): void
    {
        $blade = (string) file_get_contents(resource_path('views/docuperfect/web-templates/lease-agreement-popi-v8.blade.php'));

        $this->assertMatchesRegularExpression(
            '/\.financial-table td\s*\{[^}]*background:\s*#fff;[^}]*color:\s*#1a1a1a;/s',
            $blade
        );
    }
}
