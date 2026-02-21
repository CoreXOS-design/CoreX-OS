<?php

namespace Tests\Unit\Presentations\Evidence;

use App\Services\Presentations\Evidence\Parsers\Property24ListingParserV1;
use PHPUnit\Framework\TestCase;

class Property24ListingParserV1Test extends TestCase
{
    private Property24ListingParserV1 $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new Property24ListingParserV1();
    }

    private function htmlWithJsonLd(array $data): string
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        return <<<HTML
<!DOCTYPE html><html><head>
<script type="application/ld+json">{$json}</script>
</head><body></body></html>
HTML;
    }

    public function test_returns_empty_array_for_empty_html(): void
    {
        $result = $this->parser->parseHtml('');
        $this->assertSame([], $result);
    }

    public function test_parser_version_constant_is_set(): void
    {
        $this->assertSame('p24_listing_v1', Property24ListingParserV1::PARSER_VERSION);
    }

    // ── Legacy JSON-LD format (offers.price) ──────────────────────────────

    public function test_extracts_from_legacy_json_ld(): void
    {
        $html = $this->htmlWithJsonLd([
            '@type' => 'Product',
            'offers' => ['price' => 2_500_000],
            'numberOfBedrooms' => 3,
            'numberOfBathroomsTotal' => 2,
            'floorSize' => ['value' => 120],
            'address' => ['addressLocality' => 'Claremont'],
        ]);

        $rows = $this->parser->parseHtml($html);

        $this->assertCount(1, $rows);
        $this->assertSame(2_500_000, $rows[0]['list_price_inc']);
        $this->assertSame(3, $rows[0]['beds']);
        $this->assertSame(2, $rows[0]['baths']);
        $this->assertSame(120, $rows[0]['size_m2']);
        $this->assertSame('Claremont', $rows[0]['suburb']);
    }

    // ── Real P24 JSON-LD format (@graph + priceSpecification + about) ─────

    public function test_extracts_from_graph_with_price_specification(): void
    {
        $html = $this->htmlWithJsonLd([
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'RealEstateListing',
                    'datePosted' => '2025-12-31',
                    'about' => [
                        '@type' => 'House',
                        'numberOfBedrooms' => 4,
                        'numberOfBathroomsTotal' => 2,
                        'address' => [
                            '@type' => 'PostalAddress',
                            'addressLocality' => 'Uvongo',
                            'addressRegion' => 'KwaZulu Natal',
                        ],
                    ],
                    'offers' => [
                        '@type' => 'Offer',
                        'priceSpecification' => [
                            '@type' => 'UnitPriceSpecification',
                            'price' => '1299990',
                            'priceCurrency' => 'ZAR',
                        ],
                    ],
                    'name' => '4 Bedroom House for sale in Uvongo',
                ],
            ],
        ]);

        $rows = $this->parser->parseHtml($html);

        $this->assertCount(1, $rows);
        $this->assertSame(1_299_990, $rows[0]['list_price_inc']);
        $this->assertSame(4, $rows[0]['beds']);
        $this->assertSame(2, $rows[0]['baths']);
        $this->assertSame('Uvongo', $rows[0]['suburb']);
        $this->assertSame('house', $rows[0]['property_type']);
        $this->assertSame('2025-12-31', $rows[0]['listing_date']);
    }

    public function test_skips_breadcrumb_graph(): void
    {
        $html = $this->htmlWithJsonLd([
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home'],
                    ],
                ],
            ],
        ]);

        $rows = $this->parser->parseHtml($html);
        $this->assertSame([], $rows);
    }

    // ── DOM fallback tests ────────────────────────────────────────────────

    public function test_dom_extracts_price_from_class(): void
    {
        $html = <<<HTML
<!DOCTYPE html><html><body>
<div class="p24_price">R 1 750 000</div>
<div>
    <img src="/icons/icon_bed_listing.svg" alt="Bedrooms"> 3
    <img src="/icons/icon_bath_listing.svg" alt="Bathrooms"> 2
</div>
</body></html>
HTML;
        $rows = $this->parser->parseHtml($html);
        $this->assertCount(1, $rows);
        $this->assertSame(1_750_000, $rows[0]['list_price_inc']);
        $this->assertSame(3, $rows[0]['beds']);
        $this->assertSame(2, $rows[0]['baths']);
    }

    public function test_dom_extracts_size_from_erf_icon(): void
    {
        $html = <<<HTML
<!DOCTYPE html><html><body>
<div class="price">R 2 000 000</div>
<span><img src="/icons/icon_erf_new.svg" alt="Erf Size"> 1 950 m²</span>
</body></html>
HTML;
        $rows = $this->parser->parseHtml($html);
        $this->assertCount(1, $rows);
        $this->assertSame(1950, $rows[0]['size_m2']);
    }

    public function test_dom_extracts_listing_number_from_js(): void
    {
        $html = <<<HTML
<!DOCTYPE html><html><body>
<h1>4 Bedroom House for sale in Uvongo R 1 299 990</h1>
<script>
window.listingLeadFormContext = {
    "listingNumber": {"number": 116765021, "isValid": true}
};
</script>
</body></html>
HTML;
        $rows = $this->parser->parseHtml($html);
        $this->assertCount(1, $rows);
        $this->assertSame(1_299_990, $rows[0]['list_price_inc']);
        $this->assertSame('116765021', $rows[0]['external_id']);
    }

    public function test_dom_extracts_beds_from_text_pattern(): void
    {
        $html = <<<HTML
<!DOCTYPE html><html><body>
<div class="price">R 1 500 000</div>
<p>4 Bedroom House with 2 Bathrooms</p>
</body></html>
HTML;
        $rows = $this->parser->parseHtml($html);
        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]['beds']);
        $this->assertSame(2, $rows[0]['baths']);
    }

    // ── Real HTML fixture ─────────────────────────────────────────────────

    public function test_parses_real_property24_listing_fixture(): void
    {
        $fixturePath = __DIR__ . '/../../../Fixtures/property24_listing.html';
        if (!file_exists($fixturePath)) {
            $this->markTestSkipped('Fixture file not found');
        }

        $html = file_get_contents($fixturePath);
        $rows = $this->parser->parseHtml($html);

        $this->assertCount(1, $rows);
        $this->assertSame(1_299_990, $rows[0]['list_price_inc']);
        $this->assertSame(4, $rows[0]['beds']);
        $this->assertSame(2, $rows[0]['baths']);
        $this->assertSame('Uvongo', $rows[0]['suburb']);
        $this->assertSame('house', $rows[0]['property_type']);
    }
}
