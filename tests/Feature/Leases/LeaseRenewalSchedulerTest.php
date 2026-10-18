<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Johan's ruling, 2026-10-05: no automatic process generates leases — a
 * renewal starts ONLY when a user clicks "Renew lease". This asserts
 * routes/console.php never re-grows a scheduled entry for the retired
 * rentals:prepare-renewal-drafts command (or its class), so a future
 * change can't silently bring unattended drafting back.
 */
final class LeaseRenewalSchedulerTest extends TestCase
{
    public function test_the_scheduler_has_no_renewal_draft_entry(): void
    {
        $schedule = app(Schedule::class);

        $commands = collect($schedule->events())
            ->map(fn ($event) => $event->command)
            ->filter();

        self::assertTrue(
            $commands->every(fn ($command) => ! str_contains($command, 'rentals:prepare-renewal-drafts')),
            'The scheduler must never contain an entry for the retired rentals:prepare-renewal-drafts command.'
        );
    }

    public function test_the_renewal_draft_command_class_no_longer_exists(): void
    {
        self::assertFalse(
            class_exists(\App\Console\Commands\PrepareLeaseRenewalDrafts::class),
            'PrepareLeaseRenewalDrafts was retired — it must not be re-added as a schedulable or unattended-draft-creating command.'
        );
    }
}
