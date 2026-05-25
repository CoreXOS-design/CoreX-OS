<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Models\Property;
use Tests\TestCase;

/**
 * Phase A.2.1 — Property::publicListingUrls() + preferredPublicListingUrl()
 * accessor coverage (M17, M18, M19).
 *
 * These are pure-unit-ish — no DB needed. Each test instantiates a Property
 * with forceFill() and asserts the accessor output.
 */
final class PublicListingUrlTest extends TestCase
{
    /** M17 — both syndication statuses inactive → all URLs null. */
    public function test_m17_inactive_statuses_yield_null_urls(): void
    {
        $p = new Property();
        $p->forceFill([
            'p24_ref'                => 'P24-123',
            'p24_syndication_status' => 'pending',
            'pp_ref'                 => 'PP-456',
            'pp_syndication_status'  => 'submitted',
            'suburb' => 'Uvongo', 'city' => 'Margate', 'province' => 'KZN',
            'listing_type' => 'sale',
        ]);

        $urls = $p->publicListingUrls();
        $this->assertNull($urls['p24'], 'P24 url null when status != active');
        $this->assertNull($urls['pp'],  'PP url null when status != active');
        $this->assertNull($urls['hfc']);
    }

    /** M18 — P24-only-active returns P24, PP-only-active returns PP, both-active prefers P24. */
    public function test_m18_priority_p24_over_pp_over_hfc(): void
    {
        // P24 only active.
        $p24Only = new Property();
        $p24Only->forceFill([
            'p24_ref' => '12345', 'p24_syndication_status' => 'active',
            'pp_ref'  => 'PP-9', 'pp_syndication_status'  => 'submitted',
            'suburb'  => 'Uvongo', 'city' => 'Margate', 'province' => 'kwazulu-natal',
            'pp_suburb_id' => 999, 'listing_type' => 'sale',
        ]);
        $u = $p24Only->publicListingUrls();
        $this->assertNotNull($u['p24']);
        $this->assertStringContainsString('property24.com/for-sale/uvongo/margate', $u['p24']);
        $this->assertStringContainsString('/12345', $u['p24'], 'P24 url ends with the ref');
        $this->assertNull($u['pp']);

        // PP only active.
        $ppOnly = new Property();
        $ppOnly->forceFill([
            'p24_ref' => null, 'p24_syndication_status' => null,
            'pp_ref'  => 'PP-9', 'pp_syndication_status'  => 'active',
        ]);
        $u = $ppOnly->publicListingUrls();
        $this->assertNull($u['p24']);
        $this->assertSame('https://www.privateproperty.co.za/search?q=PP-9', $u['pp']);

        // Both active — P24 wins on priority.
        $both = new Property();
        $both->forceFill([
            'p24_ref' => '777', 'p24_syndication_status' => 'active',
            'pp_ref'  => 'PP-1', 'pp_syndication_status' => 'active',
            'suburb' => 'Uvongo', 'city' => 'Margate', 'province' => 'kwazulu-natal',
            'pp_suburb_id' => 1, 'listing_type' => 'sale',
        ]);
        $this->assertStringContainsString('property24.com', $both->preferredPublicListingUrl(),
            'P24 outranks PP when both active');
    }

    /** M19 — preferredPublicListingUrl returns the priority-correct URL or null. */
    public function test_m19_preferred_priority_or_null(): void
    {
        $nothing = new Property();
        $nothing->forceFill([
            'p24_syndication_status' => 'pending',
            'pp_syndication_status'  => 'pending',
        ]);
        $this->assertNull($nothing->preferredPublicListingUrl());

        $ppOnly = new Property();
        $ppOnly->forceFill([
            'pp_ref' => 'X', 'pp_syndication_status' => 'active',
        ]);
        $this->assertStringContainsString('privateproperty.co.za', $ppOnly->preferredPublicListingUrl());
    }
}
