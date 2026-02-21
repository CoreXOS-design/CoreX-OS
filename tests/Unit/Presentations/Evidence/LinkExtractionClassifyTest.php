<?php

namespace Tests\Unit\Presentations\Evidence;

use App\Services\Presentations\Evidence\LinkExtractionService;
use PHPUnit\Framework\TestCase;

class LinkExtractionClassifyTest extends TestCase
{
    private LinkExtractionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LinkExtractionService();
    }

    public function test_classifies_p24_search_url(): void
    {
        $url = 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359?sp=pf%3d1000000%26pt%3d2000000%26bd%3d4&PropertyCategory=House%2cApartmentOrFlat';
        $result = $this->service->classifyUrl($url);

        $this->assertSame('property24', $result['site']);
        $this->assertSame('search_results', $result['subtype']);
    }

    public function test_classifies_p24_listing_url(): void
    {
        $url = 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359/116765021?plId=2325240&plt=2&plsIds=2343882';
        $result = $this->service->classifyUrl($url);

        $this->assertSame('property24', $result['site']);
        $this->assertSame('listing', $result['subtype']);
    }

    public function test_four_digit_area_code_is_search_not_listing(): void
    {
        // 6359 is 4 digits — area code, not listing ID
        $url = 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359';
        $result = $this->service->classifyUrl($url);

        $this->assertSame('property24', $result['site']);
        $this->assertSame('search_results', $result['subtype']);
    }

    public function test_classifies_pp_listing_url(): void
    {
        $url = 'https://www.privateproperty.co.za/for-sale/cape-town/sea-point/12345678';
        $result = $this->service->classifyUrl($url);

        $this->assertSame('private_property', $result['site']);
        $this->assertSame('listing', $result['subtype']);
    }

    public function test_classifies_pp_search_url(): void
    {
        $url = 'https://www.privateproperty.co.za/for-sale-in-sea-point/cape-town';
        $result = $this->service->classifyUrl($url);

        $this->assertSame('private_property', $result['site']);
        $this->assertSame('search_results', $result['subtype']);
    }

    public function test_classifies_other_url(): void
    {
        $url = 'https://www.example.com/some-page';
        $result = $this->service->classifyUrl($url);

        $this->assertSame('other', $result['site']);
        $this->assertSame('unknown', $result['subtype']);
    }
}
