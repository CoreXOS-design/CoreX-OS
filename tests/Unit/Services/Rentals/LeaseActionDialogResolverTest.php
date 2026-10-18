<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Services\Rentals\LeaseActionDialogResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-444/AT-441 follow-up (conductor, 2026-10-05) — pure-logic coverage for
 * which "Lease actions" dialog opens on load. See the resolver's own
 * docblock for why each case matters.
 */
final class LeaseActionDialogResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_renew_is_valid_only_for_an_active_lease(): void
    {
        $draft = $this->makeLease(['status' => Lease::STATUS_DRAFT]);
        $active = $this->makeLease(['status' => Lease::STATUS_ACTIVE]);

        self::assertArrayNotHasKey('renew', LeaseActionDialogResolver::validActionsFor($draft));
        self::assertArrayHasKey('renew', LeaseActionDialogResolver::validActionsFor($active));
    }

    public function test_month_to_month_is_invalid_once_already_month_to_month(): void
    {
        $lease = $this->makeLease(['status' => Lease::STATUS_ACTIVE, 'is_month_to_month' => true]);

        self::assertArrayNotHasKey('month-to-month', LeaseActionDialogResolver::validActionsFor($lease));
    }

    public function test_notice_actions_are_invalid_once_notice_already_given(): void
    {
        $lease = $this->makeLease([
            'status' => Lease::STATUS_ACTIVE,
            'notice_date' => now()->addDays(10)->toDateString(),
            'notice_given_by' => Lease::NOTICE_BY_TENANT,
        ]);

        $valid = LeaseActionDialogResolver::validActionsFor($lease);
        self::assertArrayNotHasKey('tenant-notice', $valid);
        self::assertArrayNotHasKey('landlord-notice', $valid);
        // Renew/month-to-month are unaffected by an active notice.
        self::assertArrayHasKey('renew', $valid);
    }

    public function test_url_action_opens_the_matching_dialog(): void
    {
        $lease = $this->makeLease(['status' => Lease::STATUS_ACTIVE]);

        self::assertSame('renew', LeaseActionDialogResolver::resolve($lease, 'renew', false, null));
        self::assertSame('tenant-notice', LeaseActionDialogResolver::resolve($lease, 'tenant-notice', false, null));
    }

    public function test_url_action_invalid_for_lease_state_is_ignored(): void
    {
        $draft = $this->makeLease(['status' => Lease::STATUS_DRAFT]);
        self::assertNull(LeaseActionDialogResolver::resolve($draft, 'renew', false, null));

        $alreadyMonthToMonth = $this->makeLease(['status' => Lease::STATUS_ACTIVE, 'is_month_to_month' => true]);
        self::assertNull(LeaseActionDialogResolver::resolve($alreadyMonthToMonth, 'month-to-month', false, null));
    }

    public function test_unrecognised_url_action_is_ignored(): void
    {
        $lease = $this->makeLease(['status' => Lease::STATUS_ACTIVE]);
        self::assertNull(LeaseActionDialogResolver::resolve($lease, 'cancel', false, null));
        self::assertNull(LeaseActionDialogResolver::resolve($lease, 'not-a-real-action', false, null));
    }

    public function test_reopen_on_validation_error_wins_over_the_url_action(): void
    {
        $lease = $this->makeLease(['status' => Lease::STATUS_ACTIVE]);

        // Agent followed a ?action=renew link but the dialog that actually
        // failed validation was tenant-notice (e.g. opened manually from
        // the menu in the same visit) — the reopen must win.
        self::assertSame(
            'tenant-notice',
            LeaseActionDialogResolver::resolve($lease, 'renew', true, 'tenant-notice')
        );
    }

    public function test_reopen_action_invalid_for_lease_state_is_ignored(): void
    {
        $alreadyMonthToMonth = $this->makeLease(['status' => Lease::STATUS_ACTIVE, 'is_month_to_month' => true]);

        self::assertNull(LeaseActionDialogResolver::resolve($alreadyMonthToMonth, null, true, 'month-to-month'));
    }

    public function test_no_errors_and_no_action_param_opens_nothing(): void
    {
        $lease = $this->makeLease(['status' => Lease::STATUS_ACTIVE]);
        self::assertNull(LeaseActionDialogResolver::resolve($lease, null, false, null));
    }

    /**
     * .ai/specs/rental-renewals.md §19 — "change-notice-outcome" is
     * menu-only (like reverse-notice/cancel), but a failed submission must
     * still reopen it with the entered values.
     */
    public function test_change_notice_outcome_reopens_on_error_when_notice_is_active(): void
    {
        $lease = $this->makeLease([
            'status' => Lease::STATUS_ACTIVE,
            'notice_date' => now()->toDateString(),
            'notice_given_by' => Lease::NOTICE_BY_TENANT,
        ]);

        self::assertSame('change-notice-outcome', LeaseActionDialogResolver::resolve($lease, null, true, 'change-notice-outcome'));
    }

    public function test_change_notice_outcome_is_never_url_triggerable(): void
    {
        $lease = $this->makeLease([
            'status' => Lease::STATUS_ACTIVE,
            'notice_date' => now()->toDateString(),
            'notice_given_by' => Lease::NOTICE_BY_TENANT,
        ]);

        self::assertNull(LeaseActionDialogResolver::resolve($lease, 'change-notice-outcome', false, null));
    }

    public function test_change_notice_outcome_reopen_is_invalid_without_an_active_notice(): void
    {
        $lease = $this->makeLease(['status' => Lease::STATUS_ACTIVE]);

        self::assertNull(LeaseActionDialogResolver::resolve($lease, null, true, 'change-notice-outcome'));
    }

    private function makeLease(array $overrides = []): Lease
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Test property ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);

        return Lease::create(array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 9000,
            'start_date' => now()->toDateString(),
            'source' => 'manual',
        ], $overrides));
    }
}
