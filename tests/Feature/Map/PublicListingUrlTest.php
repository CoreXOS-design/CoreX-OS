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
    /** M17 — no live signal → all URLs null. P24: status != active. PP: off-market. */
    public function test_m17_inactive_statuses_yield_null_urls(): void
    {
        $p = new Property();
        $p->forceFill([
            'p24_ref'                => 'P24-123',
            'p24_syndication_status' => 'pending',
            // PP gates on pp_ref, but suppresses the link once off-market.
            'pp_ref'                 => 'PP-456',
            'pp_syndication_status'  => 'deactivated',
            'suburb' => 'Uvongo', 'city' => 'Margate', 'province' => 'KZN',
            'listing_type' => 'sale',
        ]);

        $urls = $p->publicListingUrls();
        $this->assertNull($urls['p24'], 'P24 url null when status != active');
        $this->assertNull($urls['pp'],  'PP url null when listing is deactivated');
        $this->assertNull($urls['hfc']);
    }

    /**
     * Regression — the "View on PP" button was dead (href '#', reopening the
     * CoreX page) for every listing that had ever been re-pushed: submitListing()
     * resets pp_syndication_status to 'submitted', and the activation-sync job
     * skips rows that already have a pp_ref, so the status never returns to
     * 'active'. The PP URL now gates on pp_ref (PP's durable "published" signal),
     * not on the flapping status — suppressed only once explicitly off-market.
     */
    public function test_pp_url_built_from_ref_regardless_of_submitted_status(): void
    {
        $mk = function (string $status): Property {
            return (new Property())->forceFill([
                'pp_ref'                => 'T5538118',
                'pp_syndication_status' => $status,
                'suburb' => 'Uvongo', 'city' => 'Margate', 'province' => 'kwazulu-natal',
                'listing_type' => 'sale',
            ]);
        };

        foreach (['submitted', 'pending', 'active', ''] as $status) {
            $this->assertSame(
                'https://www.privateproperty.co.za/for-sale/kwazulu-natal/margate/uvongo/T5538118',
                $mk($status)->publicListingUrls()['pp'],
                "PP url should build for a listing with a pp_ref (status '{$status}')",
            );
        }

        // Off-market states suppress the link even with a ref.
        foreach (['deactivated', 'disabled', 'archived', 'removed', 'expired'] as $status) {
            $this->assertNull($mk($status)->publicListingUrls()['pp'], "off-market '{$status}' → null");
        }

        // No ref at all → null (nothing to resolve on PP yet).
        $this->assertNull(
            (new Property())->forceFill(['pp_syndication_status' => 'submitted'])->publicListingUrls()['pp'],
        );
    }

    /** M18 — P24-only-active returns P24, PP-only-active returns PP, both-active prefers P24. */
    public function test_m18_priority_p24_over_pp_over_hfc(): void
    {
        // P24 only active.
        $p24Only = new Property();
        $p24Only->forceFill([
            'p24_ref' => '12345', 'p24_syndication_status' => 'active',
            // No pp_ref → PP has not published this one, so PP url stays null.
            'pp_ref'  => null, 'pp_syndication_status'  => 'submitted',
            'suburb'  => 'Uvongo', 'city' => 'Margate', 'province' => 'kwazulu-natal',
            'pp_suburb_id' => 999, 'listing_type' => 'sale',
        ]);
        $u = $p24Only->publicListingUrls();
        $this->assertNotNull($u['p24']);
        $this->assertStringContainsString('property24.com/for-sale/uvongo/margate', $u['p24']);
        $this->assertStringContainsString('/12345', $u['p24'], 'P24 url ends with the ref');
        $this->assertNull($u['pp']);

        // PP only active. PP resolves by the trailing ref; the slug segments
        // are SEO. The legacy `/search?q=` hop 404s, so we build the path.
        $ppOnly = new Property();
        $ppOnly->forceFill([
            'p24_ref' => null, 'p24_syndication_status' => null,
            'pp_ref'  => 'T5535694', 'pp_syndication_status'  => 'active',
            'suburb'  => 'Manaba Beach', 'city' => 'Margate', 'province' => 'KwaZulu Natal',
            'listing_type' => 'sale',
        ]);
        $u = $ppOnly->publicListingUrls();
        $this->assertNull($u['p24']);
        $this->assertSame(
            'https://www.privateproperty.co.za/for-sale/kwazulu-natal/margate/manaba-beach/T5535694',
            $u['pp'],
        );
        $this->assertStringNotContainsString('search?q=', $u['pp'], 'no more 404 search hop');

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
