<?php

namespace Tests\Unit\Presentations;

use App\Services\Presentations\Evidence\ListingNormalizationService;
use PHPUnit\Framework\TestCase;

class ListingNormalizationServiceTest extends TestCase
{
    private ListingNormalizationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ListingNormalizationService();
    }

    // ── External key ────────────────────────────────────────────────────

    public function test_external_key_format(): void
    {
        $row = ['external_id' => '12345678'];
        $key = $this->svc->buildExternalKey($row, 'p24_search');

        // Uses platform prefix 'p24' not source_type 'p24_search'
        $this->assertSame('p24:12345678', $key);
    }

    public function test_external_key_same_platform_across_search_and_listing(): void
    {
        $row = ['external_id' => 'PROP-42'];

        $fromSearch  = $this->svc->buildExternalKey($row, 'p24_search');
        $fromListing = $this->svc->buildExternalKey($row, 'p24_listing');

        $this->assertSame($fromSearch, $fromListing, 'Search and listing must share the same external_key');
        $this->assertSame('p24:PROP-42', $fromSearch);
    }

    public function test_external_key_private_property_platform(): void
    {
        $row = ['external_id' => 'PP-123'];

        $this->assertSame('pp:PP-123', $this->svc->buildExternalKey($row, 'private_property_search'));
        $this->assertSame('pp:PP-123', $this->svc->buildExternalKey($row, 'private_property_listing'));
        $this->assertSame('pp:PP-123', $this->svc->buildExternalKey($row, 'private_property'));
    }

    public function test_external_key_null_when_no_id(): void
    {
        $this->assertNull($this->svc->buildExternalKey([], 'p24_search'));
        $this->assertNull($this->svc->buildExternalKey(['external_id' => null], 'p24_search'));
        $this->assertNull($this->svc->buildExternalKey(['external_id' => ''], 'p24_search'));
    }

    public function test_external_key_deterministic(): void
    {
        $row = ['external_id' => 'ABC-123'];
        $a   = $this->svc->buildExternalKey($row, 'p24_listing');
        $b   = $this->svc->buildExternalKey($row, 'p24_listing');

        $this->assertSame($a, $b);
    }

    // ── Fingerprint ─────────────────────────────────────────────────────

    public function test_fingerprint_deterministic(): void
    {
        $row = [
            'suburb'        => 'Camps Bay',
            'property_type' => 'house',
            'beds'          => 3,
            'baths'         => 2,
            'size_m2'       => 150,
            'list_price_inc'=> 2_500_000,
        ];

        $a = $this->svc->buildFingerprint($row);
        $b = $this->svc->buildFingerprint($row);

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a), 'SHA-256 hex digest must be 64 chars');
    }

    public function test_fingerprint_different_for_different_rows(): void
    {
        $rowA = ['suburb' => 'Camps Bay', 'beds' => 3, 'list_price_inc' => 2_500_000];
        $rowB = ['suburb' => 'Camps Bay', 'beds' => 4, 'list_price_inc' => 2_500_000];

        $this->assertNotSame(
            $this->svc->buildFingerprint($rowA),
            $this->svc->buildFingerprint($rowB),
        );
    }

    public function test_fingerprint_same_price_bucket_matches(): void
    {
        // 2,498,000 and 2,501,000 both round to 2,500,000 bucket
        $rowA = ['suburb' => 'X', 'beds' => 3, 'list_price_inc' => 2_498_000];
        $rowB = ['suburb' => 'X', 'beds' => 3, 'list_price_inc' => 2_501_000];

        $this->assertSame(
            $this->svc->buildFingerprint($rowA),
            $this->svc->buildFingerprint($rowB),
            'Prices within same 5k bucket should produce same fingerprint',
        );
    }

    public function test_fingerprint_different_price_bucket_differs(): void
    {
        // 2,500,000 → bucket 2,500,000; 2,510,000 → bucket 2,510,000
        $rowA = ['suburb' => 'X', 'beds' => 3, 'list_price_inc' => 2_500_000];
        $rowB = ['suburb' => 'X', 'beds' => 3, 'list_price_inc' => 2_510_000];

        $this->assertNotSame(
            $this->svc->buildFingerprint($rowA),
            $this->svc->buildFingerprint($rowB),
        );
    }

    public function test_fingerprint_case_insensitive_suburb(): void
    {
        $rowA = ['suburb' => 'Camps Bay', 'beds' => 3];
        $rowB = ['suburb' => 'camps bay', 'beds' => 3];

        $this->assertSame(
            $this->svc->buildFingerprint($rowA),
            $this->svc->buildFingerprint($rowB),
        );
    }

    // ── Price bucket ────────────────────────────────────────────────────

    public function test_price_bucket_rounds_to_nearest_5k(): void
    {
        $this->assertSame(2_500_000, $this->svc->priceBucket(2_500_000));
        $this->assertSame(2_500_000, $this->svc->priceBucket(2_502_499));
        $this->assertSame(2_505_000, $this->svc->priceBucket(2_502_500));
        $this->assertSame(2_505_000, $this->svc->priceBucket(2_504_999));
        $this->assertSame(0, $this->svc->priceBucket(0));
    }

    // ── Source rank ─────────────────────────────────────────────────────

    public function test_source_ranks(): void
    {
        $this->assertSame(5,  $this->svc->sourceRank('internal_import'));
        $this->assertSame(10, $this->svc->sourceRank('p24_listing'));
        $this->assertSame(10, $this->svc->sourceRank('private_property_listing'));
        $this->assertSame(20, $this->svc->sourceRank('p24_search'));
        $this->assertSame(20, $this->svc->sourceRank('private_property_search'));
        $this->assertSame(20, $this->svc->sourceRank('private_property'));
        $this->assertSame(50, $this->svc->sourceRank('unknown'));
    }

    public function test_listing_rank_is_better_than_search(): void
    {
        $this->assertLessThan(
            $this->svc->sourceRank('p24_search'),
            $this->svc->sourceRank('p24_listing'),
        );
    }

    // ── Fingerprint handles missing fields gracefully ──────────────────

    public function test_fingerprint_with_all_nulls(): void
    {
        $fp = $this->svc->buildFingerprint([]);
        $this->assertSame(64, strlen($fp));
    }

    public function test_fingerprint_partial_fields(): void
    {
        $fp = $this->svc->buildFingerprint(['suburb' => 'Green Point']);
        $this->assertSame(64, strlen($fp));
    }
}
