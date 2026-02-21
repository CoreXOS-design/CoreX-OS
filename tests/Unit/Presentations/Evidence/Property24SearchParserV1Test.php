<?php

namespace Tests\Unit\Presentations\Evidence;

use App\Services\Presentations\Evidence\Parsers\Property24SearchParserV1;
use PHPUnit\Framework\TestCase;

class Property24SearchParserV1Test extends TestCase
{
    private Property24SearchParserV1 $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new Property24SearchParserV1();
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

    public function test_extracts_listing_from_json_ld_product(): void
    {
        $html = $this->htmlWithJsonLd([
            '@type' => 'Product',
            'offers' => ['price' => 2_500_000],
            'numberOfRooms' => 3,
            'numberOfBathroomsTotal' => 2,
            'floorSize' => ['value' => 120, 'unitCode' => 'MTK'],
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

    public function test_extracts_multiple_listings_from_item_list(): void
    {
        $html = $this->htmlWithJsonLd([
            '@type' => 'ItemList',
            'itemListElement' => [
                ['item' => ['@type' => 'House', 'offers' => ['price' => 1_800_000]]],
                ['item' => ['@type' => 'House', 'offers' => ['price' => 2_200_000]]],
            ],
        ]);

        $rows = $this->parser->parseHtml($html);
        $this->assertCount(2, $rows);
        $this->assertSame(1_800_000, $rows[0]['list_price_inc']);
        $this->assertSame(2_200_000, $rows[1]['list_price_inc']);
    }

    public function test_skips_items_without_price(): void
    {
        $html = $this->htmlWithJsonLd([
            '@type' => 'ItemList',
            'itemListElement' => [
                ['item' => ['@type' => 'House', 'offers' => ['price' => 1_500_000]]],
                ['item' => ['@type' => 'House']], // no price
            ],
        ]);

        $rows = $this->parser->parseHtml($html);
        $this->assertCount(1, $rows);
    }

    public function test_returns_empty_for_no_listing_json_ld_and_no_dom(): void
    {
        $html = $this->htmlWithJsonLd(['@type' => 'Organization', 'name' => 'Acme']);
        $rows = $this->parser->parseHtml($html);
        $this->assertSame([], $rows);
    }

    public function test_resolves_property_type_house(): void
    {
        $html = $this->htmlWithJsonLd([
            '@type' => 'SingleFamilyResidence',
            'offers' => ['price' => 2_000_000],
        ]);
        $rows = $this->parser->parseHtml($html);
        $this->assertSame('house', $rows[0]['property_type']);
    }

    public function test_resolves_property_type_unit(): void
    {
        $html = $this->htmlWithJsonLd([
            '@type' => 'Apartment',
            'offers' => ['price' => 1_200_000],
        ]);
        $rows = $this->parser->parseHtml($html);
        $this->assertSame('unit', $rows[0]['property_type']);
    }

    public function test_parser_version_constant_is_set(): void
    {
        $this->assertSame('p24_search_v1', Property24SearchParserV1::PARSER_VERSION);
    }

    public function test_dom_fallback_extracts_price(): void
    {
        $html = <<<HTML
<!DOCTYPE html><html><body>
<div class="listing-result" data-listing-number="12345">
  <span class="price">R2,750,000</span>
  <span class="bed-count">3</span>
</div>
</body></html>
HTML;
        $rows = $this->parser->parseHtml($html);
        $this->assertCount(1, $rows);
        $this->assertSame(2_750_000, $rows[0]['list_price_inc']);
    }

    // ── JSON-LD @graph wrapper (real P24 format) ──────────────────────────

    public function test_handles_graph_wrapper_with_real_estate_listing(): void
    {
        $html = $this->htmlWithJsonLd([
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'RealEstateListing',
                    'about' => [
                        '@type' => 'House',
                        'numberOfBedrooms' => 4,
                        'numberOfBathroomsTotal' => 2,
                        'address' => ['addressLocality' => 'Uvongo'],
                    ],
                    'offers' => [
                        '@type' => 'Offer',
                        'priceSpecification' => [
                            'price' => '1299990',
                            'priceCurrency' => 'ZAR',
                        ],
                    ],
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
    }

    public function test_handles_price_specification_nested_format(): void
    {
        $html = $this->htmlWithJsonLd([
            '@type' => 'RealEstateListing',
            'offers' => [
                'priceSpecification' => ['price' => '2500000'],
            ],
        ]);

        $rows = $this->parser->parseHtml($html);
        $this->assertCount(1, $rows);
        $this->assertSame(2_500_000, $rows[0]['list_price_inc']);
    }

    public function test_skips_breadcrumb_only_graph(): void
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

    // ── DOM: P24 tile containers ──────────────────────────────────────────

    public function test_dom_p24_tile_containers(): void
    {
        $html = <<<HTML
<!DOCTYPE html><html><body>
<div class="p24_regularTile" data-listing-number="116765021">
    <div class="p24_price">R 1 299 990</div>
    <div class="p24_featureDetails">
        <img src="/icons/icon_bed_listing.svg" alt="Bedrooms"> 4
        <img src="/icons/icon_bath_listing.svg" alt="Bathrooms"> 2
        <img src="/icons/icon_erf_new.svg" alt="Erf Size"> 1 950 m²
    </div>
    <span class="suburb">Uvongo</span>
</div>
</body></html>
HTML;
        $rows = $this->parser->parseHtml($html);
        $this->assertCount(1, $rows);
        $this->assertSame(1_299_990, $rows[0]['list_price_inc']);
        $this->assertSame(4, $rows[0]['beds']);
        $this->assertSame(2, $rows[0]['baths']);
        $this->assertSame(1950, $rows[0]['size_m2']);
        $this->assertSame('Uvongo', $rows[0]['suburb']);
    }

    // ── Real HTML fixture ─────────────────────────────────────────────────

    public function test_parses_real_property24_search_fixture(): void
    {
        $fixturePath = __DIR__ . '/../../../Fixtures/property24_search.html';
        if (!file_exists($fixturePath)) {
            $this->markTestSkipped('Fixture file not found');
        }

        $html = file_get_contents($fixturePath);
        $rows = $this->parser->parseHtml($html);

        $this->assertGreaterThanOrEqual(3, count($rows), 'Expected at least 3 listings from search fixture');

        // First listing
        $this->assertSame(1_299_990, $rows[0]['list_price_inc']);
        $this->assertSame(4, $rows[0]['beds']);
        $this->assertSame(2, $rows[0]['baths']);

        // Second listing
        $this->assertSame(1_750_000, $rows[1]['list_price_inc']);

        // Third listing
        $this->assertSame(1_995_000, $rows[2]['list_price_inc']);
    }

    // ── Price pattern last resort ─────────────────────────────────────────

    public function test_price_pattern_fallback(): void
    {
        $html = <<<HTML
<!DOCTYPE html><html><body>
<div>
    <p>Beautiful 4 Bedroom home in Uvongo</p>
    <p>R 1 850 000</p>
    <p>4 Bedrooms, 2 Bathrooms</p>
</div>
<div>
    <p>Spacious townhouse</p>
    <p>R 2 100 000</p>
    <p>3 Bedrooms, 2 Bathrooms</p>
</div>
</body></html>
HTML;
        $rows = $this->parser->parseHtml($html);
        $this->assertGreaterThanOrEqual(2, count($rows));
        $this->assertSame(1_850_000, $rows[0]['list_price_inc']);
        $this->assertSame(2_100_000, $rows[1]['list_price_inc']);
    }
}
