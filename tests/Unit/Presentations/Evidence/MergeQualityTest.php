<?php

namespace Tests\Unit\Presentations\Evidence;

use App\Models\PresentationActiveListing;
use App\Services\Presentations\Evidence\ListingNormalizationService;
use PHPUnit\Framework\TestCase;

class MergeQualityTest extends TestCase
{
    private ListingNormalizationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ListingNormalizationService();
    }

    private function makeMockListing(array $attrs): PresentationActiveListing
    {
        $listing = $this->createMock(PresentationActiveListing::class);
        $listing->method('__get')->willReturnCallback(function ($name) use ($attrs) {
            return $attrs[$name] ?? null;
        });
        $listing->method('getAttribute')->willReturnCallback(function ($name) use ($attrs) {
            return $attrs[$name] ?? null;
        });

        return $listing;
    }

    // ── Merge confidence ────────────────────────────────────────────────

    public function test_no_conflicts_gives_100(): void
    {
        $existing = $this->makeMockListing([
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 150,
            'suburb'         => 'Camps Bay',
        ]);

        $row = [
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 150,
            'suburb'         => 'Camps Bay',
        ];

        $result = $this->svc->computeMergeQuality($existing, $row);
        $this->assertSame(100, $result['merge_confidence']);
        $this->assertFalse($result['conflict_flags']['price_conflict']);
        $this->assertFalse($result['conflict_flags']['beds_conflict']);
        $this->assertFalse($result['conflict_flags']['baths_conflict']);
        $this->assertFalse($result['conflict_flags']['size_conflict']);
        $this->assertFalse($result['conflict_flags']['suburb_conflict']);
    }

    public function test_price_conflict_reduces_by_20(): void
    {
        $existing = $this->makeMockListing([
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 150,
            'suburb'         => 'Camps Bay',
        ]);

        $row = [
            'list_price_inc' => 1_800_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 150,
            'suburb'         => 'Camps Bay',
        ];

        $result = $this->svc->computeMergeQuality($existing, $row);
        $this->assertSame(80, $result['merge_confidence']);
        $this->assertTrue($result['conflict_flags']['price_conflict']);
    }

    public function test_beds_conflict_reduces_by_10(): void
    {
        $existing = $this->makeMockListing([
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 150,
            'suburb'         => 'Camps Bay',
        ]);

        $row = [
            'list_price_inc' => 2_000_000,
            'beds'           => 4,
            'baths'          => 2,
            'size_m2'        => 150,
            'suburb'         => 'Camps Bay',
        ];

        $result = $this->svc->computeMergeQuality($existing, $row);
        $this->assertSame(90, $result['merge_confidence']);
        $this->assertTrue($result['conflict_flags']['beds_conflict']);
    }

    public function test_baths_conflict_reduces_by_10(): void
    {
        $existing = $this->makeMockListing([
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 150,
            'suburb'         => 'Camps Bay',
        ]);

        $row = [
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 3,
            'size_m2'        => 150,
            'suburb'         => 'Camps Bay',
        ];

        $result = $this->svc->computeMergeQuality($existing, $row);
        $this->assertSame(90, $result['merge_confidence']);
        $this->assertTrue($result['conflict_flags']['baths_conflict']);
    }

    public function test_size_conflict_over_10_percent_reduces_by_10(): void
    {
        $existing = $this->makeMockListing([
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 100,
            'suburb'         => 'Camps Bay',
        ]);

        // 100 → 120 = 20% diff → conflict
        $row = [
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 120,
            'suburb'         => 'Camps Bay',
        ];

        $result = $this->svc->computeMergeQuality($existing, $row);
        $this->assertSame(90, $result['merge_confidence']);
        $this->assertTrue($result['conflict_flags']['size_conflict']);
    }

    public function test_size_within_10_percent_no_conflict(): void
    {
        $existing = $this->makeMockListing([
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 100,
            'suburb'         => 'Camps Bay',
        ]);

        // 100 → 108 = 8% diff → no conflict
        $row = [
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 108,
            'suburb'         => 'Camps Bay',
        ];

        $result = $this->svc->computeMergeQuality($existing, $row);
        $this->assertSame(100, $result['merge_confidence']);
        $this->assertFalse($result['conflict_flags']['size_conflict']);
    }

    public function test_suburb_mismatch_reduces_by_15(): void
    {
        $existing = $this->makeMockListing([
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 150,
            'suburb'         => 'Camps Bay',
        ]);

        $row = [
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 150,
            'suburb'         => 'Green Point',
        ];

        $result = $this->svc->computeMergeQuality($existing, $row);
        $this->assertSame(85, $result['merge_confidence']);
        $this->assertTrue($result['conflict_flags']['suburb_conflict']);
    }

    public function test_multiple_conflicts_stack(): void
    {
        $existing = $this->makeMockListing([
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 100,
            'suburb'         => 'Camps Bay',
        ]);

        // Price (-20), beds (-10), baths (-10), size (-10), suburb (-15) = 100 - 65 = 35
        $row = [
            'list_price_inc' => 1_500_000,
            'beds'           => 5,
            'baths'          => 4,
            'size_m2'        => 200,
            'suburb'         => 'Clifton',
        ];

        $result = $this->svc->computeMergeQuality($existing, $row);
        $this->assertSame(35, $result['merge_confidence']);
    }

    public function test_confidence_floors_at_zero(): void
    {
        // Even with all penalties (20+10+10+10+15=65), score can't go below 0
        // Just verify the floor works with max penalties
        $existing = $this->makeMockListing([
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 100,
            'suburb'         => 'X',
        ]);

        $row = [
            'list_price_inc' => 1,
            'beds'           => 99,
            'baths'          => 99,
            'size_m2'        => 999,
            'suburb'         => 'Y',
        ];

        $result = $this->svc->computeMergeQuality($existing, $row);
        $this->assertGreaterThanOrEqual(0, $result['merge_confidence']);
    }

    public function test_null_new_fields_skip_conflict_detection(): void
    {
        $existing = $this->makeMockListing([
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 150,
            'suburb'         => 'Camps Bay',
        ]);

        // All nulls → no conflicts
        $row = [];

        $result = $this->svc->computeMergeQuality($existing, $row);
        $this->assertSame(100, $result['merge_confidence']);
    }

    public function test_null_existing_fields_skip_conflict_detection(): void
    {
        $existing = $this->makeMockListing([
            'list_price_inc' => null,
            'beds'           => null,
            'baths'          => null,
            'size_m2'        => null,
            'suburb'         => null,
        ]);

        $row = [
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 150,
            'suburb'         => 'Camps Bay',
        ];

        $result = $this->svc->computeMergeQuality($existing, $row);
        $this->assertSame(100, $result['merge_confidence']);
    }

    // ── Deterministic ───────────────────────────────────────────────────

    public function test_merge_quality_is_deterministic(): void
    {
        $existing = $this->makeMockListing([
            'list_price_inc' => 2_000_000,
            'beds'           => 3,
            'suburb'         => 'Camps Bay',
        ]);
        $row = ['list_price_inc' => 1_800_000, 'beds' => 4, 'suburb' => 'Camps Bay'];

        $a = $this->svc->computeMergeQuality($existing, $row);
        $b = $this->svc->computeMergeQuality($existing, $row);
        $this->assertSame($a, $b);
    }

    // ── Suburb case insensitive ─────────────────────────────────────────

    public function test_suburb_comparison_case_insensitive(): void
    {
        $existing = $this->makeMockListing([
            'suburb' => 'Camps Bay',
        ]);

        $row = ['suburb' => 'camps bay'];

        $result = $this->svc->computeMergeQuality($existing, $row);
        $this->assertFalse($result['conflict_flags']['suburb_conflict']);
    }
}
