<?php

namespace Tests\Unit\Presentations\Evidence;

use App\Models\PresentationActiveListing;
use App\Services\Presentations\Evidence\ListingLifecycleService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ListingLifecycleServiceTest extends TestCase
{
    private ListingLifecycleService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ListingLifecycleService();
    }

    private function makeMockListing(?string $firstSeen, ?string $lastSeen, bool $isActive): PresentationActiveListing
    {
        $props = [
            'first_seen_at' => $firstSeen ? Carbon::parse($firstSeen) : null,
            'last_seen_at'  => $lastSeen ? Carbon::parse($lastSeen) : null,
            'is_active'     => $isActive,
        ];

        $listing = $this->createMock(PresentationActiveListing::class);
        $listing->method('__get')->willReturnCallback(function ($name) use ($props) {
            return $props[$name] ?? null;
        });

        return $listing;
    }

    // ── DOM calculation ─────────────────────────────────────────────────

    public function test_dom_active_listing_uses_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-20'));

        $listing = $this->makeMockListing('2026-01-21', '2026-02-15', true);

        // Active → diff from first_seen_at to now (2026-02-20)
        $dom = $this->svc->calculateDom($listing);
        $this->assertSame(30, $dom);

        Carbon::setTestNow();
    }

    public function test_dom_inactive_listing_uses_last_seen(): void
    {
        $listing = $this->makeMockListing('2026-01-01', '2026-02-10', false);

        $dom = $this->svc->calculateDom($listing);
        $this->assertSame(40, $dom);
    }

    public function test_dom_zero_when_no_first_seen(): void
    {
        $listing = $this->makeMockListing(null, null, true);

        $this->assertSame(0, $this->svc->calculateDom($listing));
    }

    public function test_dom_zero_when_same_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-20'));

        $listing = $this->makeMockListing('2026-02-20', null, true);

        $this->assertSame(0, $this->svc->calculateDom($listing));

        Carbon::setTestNow();
    }

    public function test_dom_inactive_no_last_seen_falls_back_to_first_seen(): void
    {
        $listing = $this->makeMockListing('2026-01-15', null, false);

        // last_seen_at is null, falls back to first_seen_at → DOM = 0
        $this->assertSame(0, $this->svc->calculateDom($listing));
    }

    // ── DOM buckets ─────────────────────────────────────────────────────

    public function test_dom_bucket_fresh(): void
    {
        $this->assertSame('fresh', $this->svc->getDomBucket(0));
        $this->assertSame('fresh', $this->svc->getDomBucket(15));
        $this->assertSame('fresh', $this->svc->getDomBucket(30));
    }

    public function test_dom_bucket_normal(): void
    {
        $this->assertSame('normal', $this->svc->getDomBucket(31));
        $this->assertSame('normal', $this->svc->getDomBucket(45));
        $this->assertSame('normal', $this->svc->getDomBucket(60));
    }

    public function test_dom_bucket_aging(): void
    {
        $this->assertSame('aging', $this->svc->getDomBucket(61));
        $this->assertSame('aging', $this->svc->getDomBucket(90));
        $this->assertSame('aging', $this->svc->getDomBucket(120));
    }

    public function test_dom_bucket_stale(): void
    {
        $this->assertSame('stale', $this->svc->getDomBucket(121));
        $this->assertSame('stale', $this->svc->getDomBucket(200));
        $this->assertSame('stale', $this->svc->getDomBucket(365));
    }

    // ── Deterministic ───────────────────────────────────────────────────

    public function test_dom_calculation_is_deterministic(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-20'));

        $listing = $this->makeMockListing('2026-01-01', null, true);

        $a = $this->svc->calculateDom($listing);
        $b = $this->svc->calculateDom($listing);
        $this->assertSame($a, $b);

        Carbon::setTestNow();
    }

    public function test_dom_bucket_is_deterministic(): void
    {
        $a = $this->svc->getDomBucket(45);
        $b = $this->svc->getDomBucket(45);
        $this->assertSame($a, $b);
    }
}
