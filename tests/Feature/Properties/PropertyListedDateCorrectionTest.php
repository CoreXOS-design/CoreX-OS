<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Role;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Correct listed date" (PUT /corex/properties/{property}/listed-date): a reason is mandatory, the change is
 * written to the property notes and audited - and, since 2026-10-08, only roles holding
 * `properties.listed_date.correct` may do it. Default grant: admin (all-minus-exclude) + branch manager;
 * agents and viewers do not have it. Spec: .ai/specs/property-listed-date-correction.md
 */
final class PropertyListedDateCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->withoutVite();

        foreach (['super_admin', 'admin', 'branch_manager', 'agent', 'viewer', 'office_admin'] as $name) {
            Role::forceCreate(['name' => $name, 'label' => ucfirst($name), 'agency_id' => null, 'is_owner' => $name === 'super_admin']);
        }
        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $owner = $this->user('agent');
        $this->property = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $owner->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Unit ' . Str::random(4), 'suburb' => 'Uvongo',
            'property_type' => 'apartment', 'listing_type' => 'sale', 'status' => 'active', 'price' => 1_000_000,
            'listed_date' => now()->subDays(30)->toDateString(),
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role]);
    }

    private function correct(User $u, array $body)
    {
        return $this->actingAs($u)->put(route('corex.properties.listed-date.update', $this->property), $body);
    }

    public function test_the_key_is_defined_and_defaults_to_admin_and_branch_manager_only(): void
    {
        $this->assertContains('properties.listed_date.correct', array_column(config('corex-permissions.permissions'), 'key'));

        $this->assertTrue($this->user('admin')->hasPermission('properties.listed_date.correct'));
        $this->assertTrue($this->user('branch_manager')->hasPermission('properties.listed_date.correct'));
        $this->assertFalse($this->user('agent')->hasPermission('properties.listed_date.correct'));
        $this->assertFalse($this->user('viewer')->hasPermission('properties.listed_date.correct'));
        $this->assertFalse($this->user('office_admin')->hasPermission('properties.listed_date.correct'));
    }

    public function test_an_agent_cannot_correct_the_listed_date_even_on_their_own_listing(): void
    {
        $agent = User::find($this->property->agent_id);

        $this->correct($agent, ['listed_date' => now()->subDays(5)->toDateString(), 'reason' => 'Portal shows a later date'])->assertForbidden();

        $this->assertSame(now()->subDays(30)->toDateString(), $this->property->fresh()->listed_date->toDateString());
        $this->assertSame(0, $this->property->notes()->count());
    }

    public function test_a_branch_manager_and_an_admin_can_correct_it_with_a_reason_which_lands_in_the_notes(): void
    {
        foreach (['branch_manager' => 5, 'admin' => 7] as $role => $daysAgo) {
            $u = $this->user($role);
            $this->correct($u, ['listed_date' => now()->subDays($daysAgo)->toDateString(), 'reason' => "Corrected by $role"])
                ->assertSessionHasNoErrors();

            $this->assertSame(now()->subDays($daysAgo)->toDateString(), $this->property->fresh()->listed_date->toDateString());
            $this->assertTrue($this->property->notes()->where('content', 'like', "%Corrected by $role%")->exists());
        }
    }

    public function test_a_reason_is_still_required_for_those_who_may_correct(): void
    {
        $bm = $this->user('branch_manager');

        $this->correct($bm, ['listed_date' => now()->subDays(5)->toDateString(), 'reason' => ''])->assertSessionHasErrors('reason');
        $this->correct($bm, ['listed_date' => now()->addDays(3)->toDateString(), 'reason' => 'Future date'])->assertSessionHasErrors('listed_date');

        $this->assertSame(now()->subDays(30)->toDateString(), $this->property->fresh()->listed_date->toDateString());
    }
}
