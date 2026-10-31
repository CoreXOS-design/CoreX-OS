<?php

namespace Tests\Feature\Syndication;

use App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-523 - a non-production environment never sends a "withdraw" to P24 / Private Property (Staging and QA1 hold the agency's real portal logins copied from live;
 * 44 failed rows were P24 401s). A GUARD, not a retry. Switched on per environment with P24_ALLOW_NONPROD_DESYNDICATION=true (the test suite does, so the
 * other syndication tests keep proving the portal calls).
 */
final class DesyndicateNonProductionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function live(): Property
    {
        Queue::fake();
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin']);
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id, 'external_id' => (string) Str::uuid(), 'title' => 'Listing',
            'suburb' => 'Uvongo', 'property_type' => 'house', 'listing_type' => 'rental', 'status' => 'withdrawn', 'price' => 9500,
        ]);
        $p->forceFill(['pp_syndication_enabled' => true, 'pp_syndication_status' => 'active', 'pp_ref' => 'PP-1', 'p24_syndication_enabled' => true, 'p24_syndication_status' => 'active', 'p24_ref' => '99887766'])->saveQuietly();

        return $p->fresh();
    }

    private function portals(int $calls): void
    {
        $this->mock(Property24SyndicationService::class, fn ($m) => $m->shouldReceive('deactivateListing')->times($calls)->andReturn(['success' => true]));
        $this->mock(PrivatePropertySyndicationService::class, fn ($m) => $m->shouldReceive('deactivateListing')->times($calls)->andReturn(['success' => true]));
    }

    public function test_off_production_the_portal_steps_do_not_run_and_nothing_fails(): void
    {
        config(['services.property24_syndication.allow_non_production_desyndication' => false]);
        $p = $this->live();
        $this->portals(0);

        (new DesyndicatePropertyFromPortalsJob($p, removeFromWebsite: false))->handle();

        $this->addToAssertionCount(1);   // no exception (the job would have been retried and failed), no portal call (the mocks expect zero)
    }

    public function test_when_the_environment_is_explicitly_allowed_the_portals_are_called(): void
    {
        config(['services.property24_syndication.allow_non_production_desyndication' => true]);
        $p = $this->live();
        $this->portals(1);

        (new DesyndicatePropertyFromPortalsJob($p, removeFromWebsite: false))->handle();

        $this->addToAssertionCount(1);
    }
}
