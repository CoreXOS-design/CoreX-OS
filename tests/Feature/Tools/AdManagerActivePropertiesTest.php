<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Http\Controllers\Tools\AdManagerController;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ad Manager (ad-manager.md §20.2) — every ACTIVE property of every agent the user may see
 * is listed, whether or not it is currently published on the website / Property24 /
 * Private Property. Unpublished ones are flagged `is_live = false` (the card says
 * "Not published yet"); sold / let / withdrawn stock never appears; data scope is unchanged.
 *
 * Calls the controller directly, like AdManagerGeneratedCounterTest — the `tools.ad-manager*`
 * routes sit behind a `feature:ad-manager` flag a fresh test agency does not have.
 */
final class AdManagerActivePropertiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_property_that_is_not_published_anywhere_is_listed_and_flagged(): void
    {
        [$agency, $branch] = $this->agencyWithBranch();
        $admin = $this->agencyUser($agency, $branch, 'admin');
        $agent = $this->agencyUser($agency, $branch, 'agent');

        $this->property($agency, $branch, $agent, 'ZZZ-Published', ['p24_ref' => 'P24-1', 'p24_syndication_status' => 'active']);
        $this->property($agency, $branch, $agent, 'ZZZ-Unpublished');

        $rows = $this->listed($admin)->keyBy('title');

        $this->assertTrue($rows['ZZZ-Published']['is_live']);
        $this->assertFalse($rows['ZZZ-Unpublished']['is_live']);
    }

    public function test_off_market_properties_never_appear_even_if_still_marked_published(): void
    {
        [$agency, $branch] = $this->agencyWithBranch();
        $admin = $this->agencyUser($agency, $branch, 'admin');
        $agent = $this->agencyUser($agency, $branch, 'agent');

        $this->property($agency, $branch, $agent, 'ZZZ-Active');
        foreach (['sold', 'withdrawn', 'rented', 'Expired'] as $i => $status) {
            $this->property($agency, $branch, $agent, "ZZZ-Dead-$i", [
                'status' => $status, 'p24_ref' => "P24-D$i", 'p24_syndication_status' => 'active',
            ]);
        }

        $titles = $this->listed($admin)->pluck('title')->all();

        $this->assertSame(['ZZZ-Active'], $titles);
    }

    public function test_an_agents_group_lists_every_one_of_their_active_properties_with_no_cap(): void
    {
        [$agency, $branch] = $this->agencyWithBranch();
        $admin = $this->agencyUser($agency, $branch, 'admin');
        $agent = $this->agencyUser($agency, $branch, 'agent');
        for ($i = 1; $i <= 40; $i++) {
            $this->property($agency, $branch, $agent, sprintf('ZZZ-Bulk-%02d', $i));
        }

        $view = $this->view($admin);
        $agentGroup = $view->getData()['agents']->firstWhere('id', $agent->id);

        $this->assertSame(40, $agentGroup['count']);
        $this->assertSame(40, $view->getData()['properties']->where('agent_id', $agent->id)->count());
    }

    public function test_data_scope_is_unchanged_an_own_scope_agent_sees_only_their_own(): void
    {
        [$agency, $branch] = $this->agencyWithBranch();
        $agent = $this->agencyUser($agency, $branch, 'agent');
        $other = $this->agencyUser($agency, $branch, 'agent');
        $this->property($agency, $branch, $agent, 'ZZZ-Mine');
        $this->property($agency, $branch, $other, 'ZZZ-Theirs');

        $this->assertSame(['ZZZ-Mine'], $this->listed($agent)->pluck('title')->all());
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function view(User $user)
    {
        $this->actingAs($user);
        $request = Request::create('/tools/ad-manager', 'GET');
        $request->setUserResolver(fn () => $user);

        return app(AdManagerController::class)->index($request);
    }

    private function listed(User $user)
    {
        return $this->view($user)->getData()['properties'];
    }

    private function agencyWithBranch(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, $branchId];
    }

    private function agencyUser(int $agencyId, int $branchId, string $role): User
    {
        return User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => $role]);
    }

    private function property(int $agencyId, int $branchId, User $agent, string $title, array $extra = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $agencyId,
            'branch_id'     => $branchId,
            'agent_id'      => $agent->id,
            'title'         => $title,
            'status'        => 'active',
            'listing_type'  => 'sale',
            'property_type' => 'house',
        ], $extra));
    }
}
