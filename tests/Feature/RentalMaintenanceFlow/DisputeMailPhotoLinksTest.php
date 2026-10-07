<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Mail\Rentals\RentalDisputeSentBackMail;
use App\Mail\Rentals\RentalLandlordDisputeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * Reconciliation 7 Oct 2026 (found in the real-browser walk): the tenant's dispute photos were listed in the crew's "Please revisit" mail and in
 * the owner's dispute mail as the raw relative path ("/storage/properties/…") — a dead link in an inbox. The mails map each path to an absolute
 * URL, but they also keep the raw list in a PUBLIC property, and a Mailable's public properties override same-named keys of with(). The mapped list
 * now travels under its own name, so what is rendered is the absolute link.
 */
final class DisputeMailPhotoLinksTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private const RELATIVE = '/storage/properties/55/tenant-photo.png';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld('Photo Links');
    }

    public function test_the_crews_send_back_mail_links_the_photo_with_an_absolute_url(): void
    {
        [$workOrder] = $this->internalJob();
        $html = (new RentalDisputeSentBackMail($workOrder, 'Team Ramsgate', 'Still dripping', [self::RELATIVE], 'https://example.test/secure/job-cards/abc', $this->admin))->render();

        $this->assertStringContainsString('href="' . url(self::RELATIVE) . '"', $html);
        $this->assertStringNotContainsString('href="' . self::RELATIVE . '"', $html, 'a relative path is a dead link in an inbox');
        $this->assertStringStartsWith('http', url(self::RELATIVE));
    }

    public function test_the_owners_dispute_mail_links_the_photo_with_an_absolute_url(): void
    {
        [$workOrder] = $this->internalJob();
        $html = (new RentalLandlordDisputeMail($workOrder, 'Pieter', 'Still dripping', [self::RELATIVE], $this->admin))->render();

        $this->assertStringContainsString('href="' . url(self::RELATIVE) . '"', $html);
        $this->assertStringNotContainsString('href="' . self::RELATIVE . '"', $html);
    }

    public function test_an_already_absolute_link_is_left_alone(): void
    {
        [$workOrder] = $this->internalJob();
        $html = (new RentalDisputeSentBackMail($workOrder, 'Team Ramsgate', 'Still dripping', ['https://cdn.example.test/p.png'], null, $this->admin))->render();

        $this->assertStringContainsString('href="https://cdn.example.test/p.png"', $html);
    }
}
