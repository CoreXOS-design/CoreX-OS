<?php

namespace Tests\Feature\Syndication;

use App\Http\Controllers\PrivateProperty\SyndicationController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-369 follow-up (2026-08-05) — agency master kill switch for the whole
 * PP-exclusivity sub-feature (`pp_exclusivity_enabled`, PerformanceSetting,
 * default enabled). Off must remove the tick from the syndication panel AND
 * refuse the request server-side — the panel hiding it is not the real gate,
 * same "never trust the client alone" doctrine as the P24 precheck.
 */
class PpExclusivityMasterSwitchTest extends TestCase
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
            'mandate_type' => 'sole', 'listing_type' => 'sale', 'p24_syndication_enabled' => false,
            'compliance_snapshot_at' => now(),
        ]);
        $p->forceFill($overrides)->save();

        return $p;
    }

    public function test_submit_refuses_exclusive_days_when_agency_switch_is_off(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        PerformanceSetting::set('pp_exclusivity_enabled', 0, $agency->id);
        $p = $this->makeProperty($agency, $branch, $agent);

        $this->actingAs($agent);
        $request = Request::create('/x', 'POST', ['pp_exclusive_days' => 5]);
        $response = app(SyndicationController::class)->submit($request, $p->fresh());

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertStringContainsString('turned off', $body['message']);
        $this->assertNull($p->fresh()->pp_exclusive_days);
    }

    public function test_submit_allows_exclusive_days_when_agency_switch_defaults_on(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        // No PerformanceSetting row at all — default must be enabled (existing
        // agencies who never touched the new setting keep AT-369's behaviour).
        $p = $this->makeProperty($agency, $branch, $agent);

        $this->actingAs($agent);
        $request = Request::create('/x', 'POST', ['pp_exclusive_days' => 5]);
        app(SyndicationController::class)->submit($request, $p->fresh());

        $this->assertSame(5, $p->fresh()->pp_exclusive_days);
    }

    public function test_untick_always_clears_regardless_of_agency_switch(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        PerformanceSetting::set('pp_exclusivity_enabled', 0, $agency->id);
        $p = $this->makeProperty($agency, $branch, $agent, ['pp_exclusive_days' => 10]);

        $this->actingAs($agent);
        $request = Request::create('/x', 'POST', ['pp_exclusive_days' => 0]);
        app(SyndicationController::class)->submit($request, $p->fresh());

        $this->assertNull($p->fresh()->pp_exclusive_days);
    }

    public function test_settings_saver_persists_the_toggle_agency_scoped(): void
    {
        [$agency, , $agent] = $this->seedWorld();
        $agent->update(['role' => 'owner']);

        $this->actingAs($agent);
        $request = Request::create('/x', 'POST', ['pp_exclusivity_enabled' => '0']);
        app(\App\Http\Controllers\CoreX\SettingsController::class)->updateSyndicationPortals($request);

        $this->assertFalse((bool) PerformanceSetting::get('pp_exclusivity_enabled', 1, $agency->id));
    }
}
