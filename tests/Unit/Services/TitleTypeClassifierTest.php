<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TitleTypeClassifier;
use PHPUnit\Framework\TestCase;

/**
 * 2026-08-25 fix — fromPropertyType() used to default unrecognized text to
 * TITLE_FULL (freehold), which silently misclassified any property_type word
 * it didn't know, including "Residence"/"Residential" (the exact CMA-Info
 * word used for both freehold and sectional stock) and "Commercial"/
 * "Industrial"/"Business" (land-use words that say nothing about title
 * tenure). These real-world values were pulled from `properties` and
 * `market_report_comp_rows` in production.
 */
class TitleTypeClassifierTest extends TestCase
{
    private TitleTypeClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new TitleTypeClassifier();
    }

    public function test_ambiguous_residence_word_returns_null_not_freehold(): void
    {
        $this->assertNull($this->classifier->fromPropertyType('Residence'));
        $this->assertNull($this->classifier->fromPropertyType('Residential'));
    }

    public function test_land_use_words_return_null_not_freehold(): void
    {
        $this->assertNull($this->classifier->fromPropertyType('Business'));
        $this->assertNull($this->classifier->fromPropertyType('Commercial'));
        $this->assertNull($this->classifier->fromPropertyType('Commercial Property'));
        $this->assertNull($this->classifier->fromPropertyType('Industrial'));
        $this->assertNull($this->classifier->fromPropertyType('Industrial Property'));
    }

    public function test_confidently_freehold_words_still_return_full(): void
    {
        $this->assertSame(TitleTypeClassifier::TITLE_FULL, $this->classifier->fromPropertyType('house'));
        $this->assertSame(TitleTypeClassifier::TITLE_FULL, $this->classifier->fromPropertyType('Farm'));
    }

    public function test_sectional_and_vacant_keywords_are_unaffected(): void
    {
        $this->assertSame(TitleTypeClassifier::TITLE_SECTIONAL, $this->classifier->fromPropertyType('Apartment / Flat'));
        $this->assertSame(TitleTypeClassifier::TITLE_SECTIONAL, $this->classifier->fromPropertyType('sectional_title'));
        $this->assertSame(TitleTypeClassifier::TITLE_SECTIONAL, $this->classifier->fromPropertyType('townhouse'));
        $this->assertSame(TitleTypeClassifier::TITLE_VACANT, $this->classifier->fromPropertyType('Vacant Land / Plot'));
        $this->assertSame(TitleTypeClassifier::TITLE_VACANT, $this->classifier->fromPropertyType('Land'));
    }

    public function test_blank_input_still_returns_null(): void
    {
        $this->assertNull($this->classifier->fromPropertyType(null));
        $this->assertNull($this->classifier->fromPropertyType(''));
    }
}
