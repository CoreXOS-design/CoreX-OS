<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\LeaseRecord;
use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\Property;
use App\Models\User;
use App\Notifications\LeaseExpirationAlert;
use App\Notifications\LeaseExpiryAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * AT-439 item 3 — CheckLeaseExpiry previously read the legacy
 * Docuperfect\LeaseRecord table only; the real rentals Lease model (the
 * one every other rentals feature hangs off) got no automated expiry
 * alert at all. Proves: it now reads Lease, honours each lease's OWN
 * agency's LeaseSetting::expiryNoticeWindowDaysFor() (not a hardcoded
 * 90-day window), notifies the agent (createdByUser), stays
 * database-only (never revives LeaseExpirationMail), is idempotent on
 * re-run, and correctly serves two DIFFERENT agencies' own windows in one
 * run — and that the untouched legacy LeaseRecord path still fires its
 * own, separate, unmodified alert.
 *
 * AT-439 hotfix, 2026-10-04 — also proves the command never changes
 * `status`: an unscoped verification run of an earlier version of this
 * command auto-flipped three real QA1 leases to 'expired'. Johan's
 * ruling: expiry is never automatic. The command iterates agencies
 * explicitly (never a single bulk query spanning all of them silently)
 * and only ever flags/alerts an overdue lease — the status change is the
 * agent's own act, through a recorded outcome.
 */
final class CheckLeaseExpiryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lease_outside_the_agencys_notice_window_is_not_alerted(): void
    {
        [$agency, $branch, $agent, $property] = $this->makeAgencyBranchAgentProperty();
        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 30]);

        $lease = $this->makeLease($agency, $branch, $property, $agent, now()->addDays(45)->toDateString());

        Notification::fake();
        $this->artisan('signatures:check-lease-expiry')->assertExitCode(0);

        Notification::assertNothingSentTo($agent);
        self::assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status, 'Still well within term — must not flip to expired.');
    }

    public function test_a_lease_inside_the_agencys_notice_window_alerts_the_agent_who_created_it(): void
    {
        [$agency, $branch, $agent, $property] = $this->makeAgencyBranchAgentProperty();
        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 60]);

        $lease = $this->makeLease($agency, $branch, $property, $agent, now()->addDays(20)->toDateString());

        Notification::fake();
        $this->artisan('signatures:check-lease-expiry')->assertExitCode(0);

        Notification::assertSentTo($agent, LeaseExpiryAlert::class, function (LeaseExpiryAlert $n) use ($lease) {
            return $n->lease->id === $lease->id && $n->level === 'urgent';
        });
        // Delivery stays database-only — the legacy dead email path must never fire.
        Notification::assertNothingSent(fn ($n) => $n instanceof \Illuminate\Notifications\Messages\MailMessage);
    }

    /**
     * AT-439 hotfix, 2026-10-04 — Johan's ruling after an unscoped
     * verification run of an earlier version of this command auto-flipped
     * real QA1 leases: expiry is NEVER automatic. A lease past its
     * end_date stays 'active' and is flagged via the alert for the agent
     * to record the real outcome; the status change only happens when the
     * agent acts (LeaseRenewalService/LeaseActivationService, AT-444).
     */
    public function test_an_overdue_lease_is_flagged_but_never_auto_expired(): void
    {
        [$agency, $branch, $agent, $property] = $this->makeAgencyBranchAgentProperty();
        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 60]);

        $lease = $this->makeLease($agency, $branch, $property, $agent, now()->subDays(2)->toDateString());

        Notification::fake();
        $this->artisan('signatures:check-lease-expiry')->assertExitCode(0);

        self::assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status, 'This command must NEVER change lease status — only the agent, via a recorded outcome, does.');
        Notification::assertSentTo($agent, LeaseExpiryAlert::class, fn (LeaseExpiryAlert $n) => $n->level === 'expired');
    }

    public function test_re_running_the_command_does_not_double_alert_the_same_lease_and_level(): void
    {
        [$agency, $branch, $agent, $property] = $this->makeAgencyBranchAgentProperty();
        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 60]);
        $this->makeLease($agency, $branch, $property, $agent, now()->addDays(20)->toDateString());

        Notification::fake();
        $this->artisan('signatures:check-lease-expiry')->assertExitCode(0);
        Notification::assertSentToTimes($agent, LeaseExpiryAlert::class, 1);

        // Re-run immediately — same lease, same tier, must not alert twice
        // within the 7-day dedup window.
        $this->artisan('signatures:check-lease-expiry')->assertExitCode(0);
        Notification::assertSentToTimes($agent, LeaseExpiryAlert::class, 1);
    }

    public function test_each_agency_is_alerted_against_its_own_configured_window(): void
    {
        [$agencyNarrow, $branchNarrow, $agentNarrow, $propertyNarrow] = $this->makeAgencyBranchAgentProperty();
        LeaseSetting::create(['agency_id' => $agencyNarrow->id, 'expiry_notice_window_days' => 10]);
        $leaseNarrow = $this->makeLease($agencyNarrow, $branchNarrow, $propertyNarrow, $agentNarrow, now()->addDays(25)->toDateString());

        [$agencyWide, $branchWide, $agentWide, $propertyWide] = $this->makeAgencyBranchAgentProperty();
        LeaseSetting::create(['agency_id' => $agencyWide->id, 'expiry_notice_window_days' => 90]);
        $leaseWide = $this->makeLease($agencyWide, $branchWide, $propertyWide, $agentWide, now()->addDays(25)->toDateString());

        Notification::fake();
        $this->artisan('signatures:check-lease-expiry')->assertExitCode(0);

        // 25 days out: outside the 10-day-window agency's own notice window,
        // inside the 90-day-window agency's own notice window — one global
        // run, two different real outcomes, purely from each lease's OWN
        // agency_id resolving its OWN LeaseSetting row.
        Notification::assertNothingSentTo($agentNarrow);
        Notification::assertSentTo($agentWide, LeaseExpiryAlert::class);
    }

    public function test_a_lease_with_no_creator_is_skipped_gracefully_not_errored(): void
    {
        [$agency, $branch, $agent, $property] = $this->makeAgencyBranchAgentProperty();
        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 60]);

        Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000,
            'start_date' => now()->subMonth(), 'end_date' => now()->addDays(20)->toDateString(),
            'source' => 'manual', 'created_by_user_id' => null,
        ]);

        Notification::fake();
        $this->artisan('signatures:check-lease-expiry')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_legacy_lease_record_path_is_untouched_and_still_fires_its_own_alert(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgentProperty();

        $document = \App\Models\Docuperfect\Document::create(['name' => 'Lease', 'owner_id' => $agent->id, 'branch_id' => $branch->id]);
        $template = \App\Models\Docuperfect\SignatureTemplate::create(['document_id' => $document->id, 'status' => 'completed']);
        LeaseRecord::create([
            'document_id' => $document->id, 'signature_template_id' => $template->id,
            'property_address' => '1 Legacy Lane', 'tenant_name' => 'T', 'tenant_email' => 't@example.test',
            'landlord_name' => 'L', 'landlord_email' => 'l@example.test', 'rental_amount' => 8000,
            'lease_start_date' => now()->subMonth(), 'lease_end_date' => now()->addDays(20)->toDateString(),
            'status' => LeaseRecord::STATUS_ACTIVE,
        ]);

        Notification::fake();
        $this->artisan('signatures:check-lease-expiry')->assertExitCode(0);

        // The legacy command path (LeaseRecord/LeaseExpirationAlert) must keep
        // working byte-for-byte as it did before this change.
        Notification::assertSentTo($agent, LeaseExpirationAlert::class);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeAgencyBranchAgentProperty(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::forceCreate(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Test property ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);

        return [$agency, $branch, $agent, $property];
    }

    private function makeLease(Agency $agency, Branch $branch, Property $property, User $agent, string $endDate): Lease
    {
        // Fresh per-call cache key avoids the 7-day dedup carrying across
        // unrelated tests that happen to run in the same process.
        Cache::flush();

        return Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000,
            'start_date' => now()->subMonth(), 'end_date' => $endDate,
            'source' => 'manual', 'created_by_user_id' => $agent->id,
        ]);
    }
}
