<?php

namespace Tests\Unit\PrivateProperty;

use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use PHPUnit\Framework\TestCase;

/**
 * The "View on PP" button was dead because pp_syndication_status never stayed
 * 'active': submitListing() reset it to 'submitted' on every re-push, and the
 * activation-sync job only reconciles rows with a NULL pp_ref — so a published
 * (ref'd) listing was stranded at 'submitted' forever. postSubmitStatus() now
 * keeps an already-published listing 'active' across re-pushes.
 *
 * Pure unit test — no DB, no SOAP.
 */
class PpPostSubmitStatusTest extends TestCase
{
    public function test_published_listing_stays_active_across_resubmit(): void
    {
        $p = (new Property())->forceFill(['pp_ref' => 'T5538118']);

        $this->assertSame('active', PrivatePropertySyndicationService::postSubmitStatus($p));
    }

    public function test_first_submit_without_ref_is_submitted(): void
    {
        $p = (new Property())->forceFill(['pp_ref' => null]);

        $this->assertSame('submitted', PrivatePropertySyndicationService::postSubmitStatus($p));

        // Empty-string ref is treated as no ref (awaiting PP publish).
        $p2 = (new Property())->forceFill(['pp_ref' => '']);
        $this->assertSame('submitted', PrivatePropertySyndicationService::postSubmitStatus($p2));
    }
}
