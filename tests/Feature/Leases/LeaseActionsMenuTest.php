<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Services\Rentals\LeaseActivationService;
use App\Services\Rentals\LeaseRenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-444 follow-up (conductor, 2026-10-05) — the Lease Hub header's "Lease
 * actions" menu. Before this, Renew/Goes month-to-month/Tenant gave
 * notice/Landlord not renewing/Reverse notice were only reachable via the
 * Lease Hub's "next step" card (only shown when that outcome happened to be
 * the suggested next step) or by navigating directly to the renewal screen
 * — an agent on an active lease that wasn't flagged as "next" had no way to
 * record an outcome at all. This menu is always present on a draft/active
 * lease and offers every outcome unconditionally.
 */
final class LeaseActionsMenuTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Menu Agency', 'slug' => 'menu-' . uniqid()]);
        $this->branch = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Branch A']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Menu test property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function activeLease(array $overrides = []): Lease
    {
        $lease = Lease::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 9000, 'start_date' => now()->subMonth()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ], $overrides));

        return app(LeaseActivationService::class)->activate($lease);
    }

    public function test_active_lease_with_no_notice_shows_every_outcome_and_no_reverse_actions(): void
    {
        $lease = $this->activeLease();

        $response = $this->actingAs($this->agent)->get(route('corex.leases.show', $lease));

        $response->assertOk();
        $response->assertSee('Lease actions');
        $response->assertSee('Renew lease');
        $response->assertSee('Goes month-to-month');
        $response->assertSee('Tenant gave notice');
        $response->assertSee('Landlord not renewing');
        $response->assertSee('Cancel lease');
        $response->assertDontSee('Reverse notice');
        $response->assertDontSee('Reverse month-to-month');
    }

    public function test_an_active_notice_swaps_the_two_notice_actions_for_reverse_notice(): void
    {
        $lease = $this->activeLease();
        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, now()->addDays(30)->toDateString(), null, $this->agent, Lease::NOTICE_OUTCOME_LEAVE);

        $response = $this->actingAs($this->agent)->get(route('corex.leases.show', $lease->fresh()));

        $response->assertOk();
        $response->assertSee('Reverse notice');
        $response->assertSee('Change notice outcome');
        // Stale-test fix, 2026-10-05 (Johan's ruling): a bare assertDontSee()
        // here false-failed against the SAME lease's own tenancy-log entry
        // ("Tenant gave notice — move-out …"), which legitimately renders on
        // this page once a notice is recorded — that text is correct and
        // intentional, not a menu leak. The menu's own button carries a
        // trailing "&hellip;" (see show.blade.php's "Lease actions" items)
        // that the tenancy-log description never does — asserting against
        // that exact raw-HTML string is what actually proves the MENU
        // button is gone, without being defeated by the unrelated, correct
        // log text. This was wrong the day it shipped (120658d99,
        // 2026-10-05) — reproduced on a clean checkout before this ticket
        // touched anything.
        $response->assertDontSee('Tenant gave notice&hellip;', false);
        $response->assertDontSee('Landlord not renewing&hellip;', false);
    }

    public function test_month_to_month_swaps_goes_month_to_month_for_reverse(): void
    {
        $lease = $this->activeLease();
        app(LeaseRenewalService::class)->recordMonthToMonth($lease, null, $this->agent);

        $response = $this->actingAs($this->agent)->get(route('corex.leases.show', $lease->fresh()));

        $response->assertOk();
        $response->assertSee('Reverse month-to-month');
        $response->assertDontSee('Goes month-to-month');
    }

    public function test_a_draft_lease_shows_the_menu_with_only_cancel_not_renewal_actions(): void
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 9000, 'start_date' => now()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);

        $response = $this->actingAs($this->agent)->get(route('corex.leases.show', $lease));

        $response->assertOk();
        $response->assertSee('Lease actions');
        $response->assertSee('Cancel lease');
        $response->assertDontSee('Renew lease');
        $response->assertDontSee('Goes month-to-month');
    }

    public function test_a_cancelled_lease_shows_no_lease_actions_menu(): void
    {
        $lease = $this->activeLease();
        $this->actingAs($this->agent)->post(route('corex.leases.cancel', $lease), ['cancel_reason' => 'Tenant breach']);

        $response = $this->actingAs($this->agent)->get(route('corex.leases.show', $lease->fresh()));

        $response->assertOk();
        $response->assertDontSee('Lease actions');
    }

    public function test_the_tenant_notice_dialog_posts_to_the_real_renewal_route(): void
    {
        $lease = $this->activeLease();

        $response = $this->actingAs($this->agent)->get(route('corex.leases.show', $lease));

        $response->assertOk();
        $response->assertSee(route('corex.leases.renewal.tenant-notice', $lease), false);
        $response->assertSee(route('corex.leases.renewal.landlord-notice', $lease), false);
        $response->assertSee(route('corex.leases.renewal.month-to-month', $lease), false);
    }
}
