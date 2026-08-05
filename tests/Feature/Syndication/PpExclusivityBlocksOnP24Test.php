<?php

namespace Tests\Feature\Syndication;

use App\Http\Controllers\PrivateProperty\SyndicationController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-369 follow-up (2026-08-05) — mirror image of PpExclusivityP24GateTest: PP
 * exclusivity must never be REQUESTED while Property24 is switched on for the
 * listing. Before this fix the only check was the pre-existing sole-mandate/Sale
 * + agency-max validation in SyndicationController::validateAndSaveExclusiveDays();
 * nothing stopped `pp_exclusive_days > 0` from being accepted while
 * p24_syndication_enabled was true. Proven at the controller entry point that
 * owns this validation (submit() and reactivate() both delegate to it).
 */
class PpExclusivityBlocksOnP24Test extends TestCase
{
    use RefreshDatabase;

    private function seedWorld(): array
    {
        $agency = Agency::create([
            'name' => 'Coastal', 'slug' => 'coastal-' . Str::random(6),
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return [$agency, $branch, $agent];
    }

    private function makeProperty(Agency $agency, Branch $branch, User $agent, array $overrides = []): Property
    {
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000,
            'mandate_type' => 'sole', 'listing_type' => 'sale',
            // Bypasses MarketingReadinessService's document/FICA checklist — irrelevant to
            // this test, which is about the exclusivity-vs-P24 precheck, not compliance docs.
            'compliance_snapshot_at' => now(),
        ]);
        $p->forceFill($overrides)->save();

        return $p;
    }

    public function test_submit_refuses_exclusive_days_while_p24_is_enabled(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        $p = $this->makeProperty($agency, $branch, $agent, ['p24_syndication_enabled' => true]);

        $this->actingAs($agent);
        $request = Request::create('/x', 'POST', ['pp_exclusive_days' => 5]);
        $response = app(SyndicationController::class)->submit($request, $p->fresh());

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Property24', $body['message']);
        $this->assertNull($p->fresh()->pp_exclusive_days, 'must not persist the requested days when the P24 gate rejects it');
    }

    public function test_submit_allows_exclusive_days_when_p24_is_disabled(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        $p = $this->makeProperty($agency, $branch, $agent, ['p24_syndication_enabled' => false]);

        $this->actingAs($agent);
        $request = Request::create('/x', 'POST', ['pp_exclusive_days' => 5]);
        app(SyndicationController::class)->submit($request, $p->fresh());

        // The P24 gate must not be what blocks this — persisted before the mapper
        // readiness check runs (which will separately fail this bare-bones fixture,
        // but that's unrelated to the gate under test).
        $this->assertSame(5, $p->fresh()->pp_exclusive_days);
    }

    public function test_untick_always_clears_regardless_of_p24_state(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        $p = $this->makeProperty($agency, $branch, $agent, [
            'p24_syndication_enabled' => true,
            'pp_exclusive_days' => 10,
        ]);

        $this->actingAs($agent);
        $request = Request::create('/x', 'POST', ['pp_exclusive_days' => 0]);
        app(SyndicationController::class)->submit($request, $p->fresh());

        $this->assertNull($p->fresh()->pp_exclusive_days, 'a value of 0 must always clear it, P24 state is irrelevant to switching OFF');
    }

    public function test_acknowledge_explainer_persists_timestamp_self_scoped(): void
    {
        [, , $agent] = $this->seedWorld();
        $this->assertNull($agent->pp_exclusivity_explainer_seen_at);

        $this->actingAs($agent);
        $response = app(SyndicationController::class)->acknowledgeExclusivityExplainer(Request::create('/x', 'POST'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($agent->fresh()->pp_exclusivity_explainer_seen_at);
    }
}
