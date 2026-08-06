<?php

namespace Tests\Feature\Syndication;

use App\Http\Controllers\Property24\P24SyndicationController;
use App\Jobs\SubmitListingToProperty24;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Services\Syndication\Property24\Property24ListingMapper;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-369 — a PP-exclusive listing must never reach Property24. Before this fix
 * the only "gate" was a cosmetic client-side Alpine disable on one button; every
 * server-side entry point (controller, queued job, service, bulk/console
 * commands) was wide open. This test proves the real, server-side gate at the
 * entry points that have their own logic (controller, service backstop, job);
 * the bulk/console commands are proven by equivalence — they call
 * Property24SyndicationService::submitListing() with no gating logic of their
 * own, so the service-layer assertions here cover them too.
 */
class PpExclusivityP24GateTest extends TestCase
{
    use RefreshDatabase;

    private function serviceThatWouldSucceedIfCalled(User $agent): Property24SyndicationService
    {
        // If the gate fails to block, this stub would report success — so a
        // green "blocked" assertion here is only meaningful because the HTTP
        // layer is faked to a real ok response, not because everything 500s.
        Http::fake([
            '*' => Http::response(
                [['id' => 77, 'sourceReference' => 'CoreX-Agent-' . $agent->id, 'agencyId' => 123]],
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);

        $mapper = new class extends Property24ListingMapper {
            public function map(Property $property, bool $includePhotos = true): array
            {
                return ['sourceReference' => 'CoreX-' . $property->id, 'photos' => null];
            }

            public function validate(array $payload): array
            {
                return [];
            }

            public function checkReadiness(Property $property): array
            {
                return [];
            }
        };

        return new Property24SyndicationService(new Property24ApiClient(), $mapper);
    }

    private function makeProperty(Agency $agency, Branch $branch, User $agent, array $overrides = []): Property
    {
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000,
            'mandate_type' => 'sole', 'listing_type' => 'sale',
            // Bypasses MarketingReadinessService's document/FICA checklist (irrelevant
            // to this test — it's about the PP-exclusivity gate, not compliance docs).
            // Same bypass real pre-vetted/imported stock uses in production.
            'compliance_snapshot_at' => now(),
        ]);
        $p->forceFill($overrides)->save();

        return $p;
    }

    private function seedWorld(): array
    {
        Queue::fake();
        $agency = Agency::create([
            'name' => 'Coastal', 'slug' => 'coastal',
            'p24_username' => 'u', 'p24_password' => 'p', 'p24_agency_id' => '123',
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return [$agency, $branch, $agent];
    }

    public function test_service_backstop_blocks_submit_while_pp_exclusive_active(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        $p = $this->makeProperty($agency, $branch, $agent, ['pp_delay_until' => now()->addDays(10)]);

        $result = $this->serviceThatWouldSucceedIfCalled($agent)->submitListing($p);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('exclusiv', $result['message']);
        $fresh = $p->fresh();
        $this->assertSame('error', $fresh->p24_syndication_status);
        $this->assertStringContainsString('exclusiv', $fresh->p24_last_error);
    }

    public function test_service_backstop_allows_submit_when_pp_delay_is_past(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        $p = $this->makeProperty($agency, $branch, $agent, ['pp_delay_until' => now()->subDays(2)]);

        $result = $this->serviceThatWouldSucceedIfCalled($agent)->submitListing($p);

        $this->assertTrue($result['success'], 'a lapsed exclusivity window must not block the submit: ' . ($result['message'] ?? ''));
    }

    public function test_service_backstop_allows_submit_when_pp_delay_is_null(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        $p = $this->makeProperty($agency, $branch, $agent, ['pp_delay_until' => null]);

        $result = $this->serviceThatWouldSucceedIfCalled($agent)->submitListing($p);

        $this->assertTrue($result['success'], 'no exclusivity set must not block the submit: ' . ($result['message'] ?? ''));
    }

    public function test_queued_job_never_reaches_a_live_call_while_pp_exclusive(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        $p = $this->makeProperty($agency, $branch, $agent, ['pp_delay_until' => now()->addDays(5)]);

        $this->app->instance(Property24SyndicationService::class, $this->serviceThatWouldSucceedIfCalled($agent));

        (new SubmitListingToProperty24($p))->handle(app(Property24SyndicationService::class));

        $fresh = $p->fresh();
        $this->assertSame('error', $fresh->p24_syndication_status);
        $this->assertStringContainsString('exclusiv', $fresh->p24_last_error);
    }

    public function test_controller_toggle_refuses_to_enable_while_pp_exclusive(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        $p = $this->makeProperty($agency, $branch, $agent, [
            'pp_delay_until' => now()->addDays(10),
            'p24_syndication_enabled' => false,
        ]);

        $this->actingAs($agent);
        $response = app(P24SyndicationController::class)->toggle(Request::create('/x', 'POST'), $p->fresh());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($p->fresh()->p24_syndication_enabled);
    }

    public function test_controller_submit_refuses_before_queuing_while_pp_exclusive(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        $p = $this->makeProperty($agency, $branch, $agent, ['pp_delay_until' => now()->addDays(10)]);

        $this->actingAs($agent);
        $response = app(P24SyndicationController::class)->submit(Request::create('/x', 'POST'), $p->fresh());

        $this->assertSame(422, $response->getStatusCode());
        Queue::assertNotPushed(SubmitListingToProperty24::class);
    }

    public function test_controller_toggle_off_is_never_blocked_by_pp_exclusivity(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        $p = $this->makeProperty($agency, $branch, $agent, [
            'pp_delay_until' => now()->addDays(10),
            'p24_syndication_enabled' => true,
            'p24_ref' => null,
        ]);

        $this->actingAs($agent);
        $response = app(P24SyndicationController::class)->toggle(Request::create('/x', 'POST'), $p->fresh());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($p->fresh()->p24_syndication_enabled, 'turning OFF must always succeed regardless of PP exclusivity');
    }
}
