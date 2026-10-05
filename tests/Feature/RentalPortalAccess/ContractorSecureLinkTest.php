<?php

namespace Tests\Feature\RentalPortalAccess;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrder;
use App\Models\User;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-445 — .ai/specs/rental-portal-access.md §4/§6/§11. The contractor's
 * secure link: expiry, revocation, and single-order scope, through the
 * real HTTP routes — never a route-model-bound {token}.
 */
class ContractorSecureLinkTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        $agency = Agency::create(['name' => 'Secure Link Agency', 'slug' => 'sla-' . uniqid()]);
        Branch::create(['agency_id' => $agency->id, 'name' => 'Main', 'code' => 'M-' . $agency->id, 'is_active' => true]);

        return $agency;
    }

    private function makeWorkOrder(Agency $agency, User $agent, string $title = 'Fix gate'): RentalWorkOrder
    {
        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => Branch::where('agency_id', $agency->id)->value('id'),
            'title' => 'Secure Link Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        return RentalWorkOrder::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => $property->branch_id, 'property_id' => $property->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER,
            'title' => $title, 'description' => 'Gate motor broken', 'status' => RentalWorkOrder::STATUS_ORDERED,
            'priority' => RentalWorkOrder::PRIORITY_NORMAL, 'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'reported_at' => now(),
        ]);
    }

    public function test_valid_link_shows_the_job(): void
    {
        $agency = $this->makeAgency();
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);
        $workOrder = $this->makeWorkOrder($agency, $agent);

        $issued = app(RentalSecureAccessTokenService::class)->issueFor($workOrder, $agent);

        $this->get('/secure/work-orders/' . $issued['raw_token'])
            ->assertOk()
            ->assertSee($workOrder->title);
    }

    public function test_unknown_token_shows_unavailable_not_a_500(): void
    {
        $this->get('/secure/work-orders/not-a-real-token')
            ->assertOk()
            ->assertSee('no longer available');
    }

    public function test_expired_link_shows_unavailable(): void
    {
        $agency = $this->makeAgency();
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);
        $workOrder = $this->makeWorkOrder($agency, $agent);

        $raw = RentalSecureAccessToken::generateRawToken();
        RentalSecureAccessToken::create([
            'agency_id' => $agency->id, 'rental_work_order_id' => $workOrder->id,
            'token_hash' => RentalSecureAccessToken::hashToken($raw),
            'expires_at' => now()->subDay(), 'created_by_user_id' => $agent->id,
        ]);

        $this->get('/secure/work-orders/' . $raw)->assertSee('no longer available');
    }

    public function test_revoked_link_dies_immediately(): void
    {
        $agency = $this->makeAgency();
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);
        $workOrder = $this->makeWorkOrder($agency, $agent);

        $service = app(RentalSecureAccessTokenService::class);
        $issued = $service->issueFor($workOrder, $agent);
        $service->revokeAllFor($workOrder);

        $this->get('/secure/work-orders/' . $issued['raw_token'])->assertSee('no longer available');
    }

    public function test_regenerating_a_link_invalidates_the_previous_one(): void
    {
        $agency = $this->makeAgency();
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);
        $workOrder = $this->makeWorkOrder($agency, $agent);

        $service = app(RentalSecureAccessTokenService::class);
        $first = $service->issueFor($workOrder, $agent);
        $second = $service->issueFor($workOrder, $agent);

        $this->get('/secure/work-orders/' . $first['raw_token'])->assertSee('no longer available');
        $this->get('/secure/work-orders/' . $second['raw_token'])->assertOk()->assertSee($workOrder->title);
    }

    public function test_link_is_scoped_to_exactly_one_work_order(): void
    {
        $agency = $this->makeAgency();
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);
        $workOrderA = $this->makeWorkOrder($agency, $agent, 'Fix gate A');
        $workOrderB = $this->makeWorkOrder($agency, $agent, 'Fix gate B');

        $service = app(RentalSecureAccessTokenService::class);
        $issuedA = $service->issueFor($workOrderA, $agent);

        $show = $this->get('/secure/work-orders/' . $issuedA['raw_token']);
        $show->assertSee($workOrderA->title);
        $show->assertDontSee($workOrderB->title);
    }

    public function test_link_dies_once_the_job_is_marked_done(): void
    {
        $agency = $this->makeAgency();
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);
        $workOrder = $this->makeWorkOrder($agency, $agent);

        $issued = app(RentalSecureAccessTokenService::class)->issueFor($workOrder, $agent);

        $this->post('/secure/work-orders/' . $issued['raw_token'] . '/mark-done')
            ->assertRedirect();

        $this->get('/secure/work-orders/' . $issued['raw_token'])->assertSee('no longer available');
    }

    public function test_contractor_submitted_quote_is_tagged_in_history_with_no_user_actor(): void
    {
        $agency = $this->makeAgency();
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);
        $workOrder = $this->makeWorkOrder($agency, $agent);
        $issued = app(RentalSecureAccessTokenService::class)->issueFor($workOrder, $agent);

        $this->post('/secure/work-orders/' . $issued['raw_token'] . '/quote', [
            'amount' => 1200, 'quote_date' => now()->toDateString(), 'detail_text' => 'New motor',
        ])->assertRedirect();

        $quote = $workOrder->quotes()->first();
        $this->assertNotNull($quote);
        $this->assertNull($quote->captured_by_user_id);

        $update = $workOrder->updates()->first();
        $this->assertStringContainsString('via contractor link', (string) $update->note);
    }
}
