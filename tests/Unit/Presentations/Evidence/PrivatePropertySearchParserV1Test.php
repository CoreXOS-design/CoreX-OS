<?php

namespace Tests\Unit\Presentations\Evidence;

use App\Services\Presentations\Evidence\Parsers\PrivatePropertySearchParserV1;
use PHPUnit\Framework\TestCase;

class PrivatePropertySearchParserV1Test extends TestCase
{
    private PrivatePropertySearchParserV1 $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PrivatePropertySearchParserV1();
    }

    public function test_returns_empty_for_empty_html(): void
    {
        $this->assertSame([], $this->parser->parseHtml(''));
    }

    public function test_extracts_listing_from_json_ld(): void
    {
        $data = ['@type' => 'Residence', 'offers' => ['price' => 1_900_000], 'numberOfRooms' => 4];
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $html = "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";

        $rows = $this->parser->parseHtml($html);

        $this->assertCount(1, $rows);
        $this->assertSame(1_900_000, $rows[0]['list_price_inc']);
        $this->assertSame(4, $rows[0]['beds']);
    }

    public function test_extracts_multiple_from_item_list(): void
    {
        $data = [
            '@type' => 'ItemList',
            'itemListElement' => [
                ['item' => ['offers' => ['price' => 1_000_000]]],
                ['item' => ['offers' => ['price' => 2_000_000]]],
                ['item' => ['offers' => ['price' => 3_000_000]]],
            ],
        ];
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $html = "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";

        $rows = $this->parser->parseHtml($html);
        $this->assertCount(3, $rows);
    }

    public function test_parser_version_constant(): void
    {
        $this->assertSame('pp_search_v1', PrivatePropertySearchParserV1::PARSER_VERSION);
    }

    public function test_source_type_constant(): void
    {
        $this->assertSame('private_property_search', PrivatePropertySearchParserV1::SOURCE_TYPE);
    }

    public function test_skips_items_without_valid_price(): void
    {
        $data = ['@type' => 'Residence', 'price' => 500]; // price too low
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $html = "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";

        $rows = $this->parser->parseHtml($html);
        $this->assertSame([], $rows);
    }

    public function test_suburb_extracted_from_address(): void
    {
        $data = [
            'offers' => ['price' => 2_000_000],
            'address' => ['addressLocality' => 'Stellenbosch'],
        ];
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $html = "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";

        $rows = $this->parser->parseHtml($html);
        $this->assertSame('Stellenbosch', $rows[0]['suburb']);
    }

    public function test_size_extracted_from_floor_size(): void
    {
        $data = [
            'offers'    => ['price' => 2_000_000],
            'floorSize' => ['value' => 150],
        ];
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $html = "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";

        $rows = $this->parser->parseHtml($html);
        $this->assertSame(150, $rows[0]['size_m2']);
    }
}
