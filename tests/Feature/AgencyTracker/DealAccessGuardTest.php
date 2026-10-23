<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Http\Middleware\EnsureDealAccess;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * AT-454 (23 Oct 2026, Johan's ruling): every Deal Register action that names a deal checks that the deal belongs
 * to the person asking (own / branch / all), the way the old register did.
 *
 * The check is the `deal.access` middleware on the deals-dr2 route group (App\Http\Middleware\EnsureDealAccess), so
 * this test does not list the actions by hand: it walks the REAL route table, takes every `deals-dr2.*` route that
 * carries a {deal} or {dealV2} id, and calls each one twice as an own-scope agent - once on the agent's own deal and
 * once on a colleague's. The colleague's must be refused (403) on every one; the agent's own must NOT be refused,
 * which proves the 403 comes from the ownership check and not from some other rule. A new route added to the group
 * later is covered the moment it exists - and fails here if it escapes the check.
 */
final class DealAccessGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $colleague;
    private int $ownDeal;
    private int $theirDeal;
    private int $ownTwin;
    private int $theirTwin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);

        foreach (['view_deals', 'create_deals', 'settle_deals', 'deals.create', 'deals.edit', 'deals_v2.distribute_documents', 'proforma.generate', 'sidebar.section.agents'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id]);
        }
        RolePermission::create(['role' => 'agent', 'permission_key' => 'deals.view', 'scope' => 'own', 'agency_id' => $this->agency->id]);
        Role::clearCache();
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        // The ad-hoc send is switchable per agency; without this it answers 403 for its own reason, which would hide the ownership proof.
        DB::table('agencies')->where('id', $this->agency->id)->update(['adhoc_document_distribution_enabled' => 1]);

        $this->agent = $this->user();
        $this->colleague = $this->user();

        [$this->ownDeal, $this->ownTwin] = $this->dealWithTwin($this->agent, '1 Own Rd');
        [$this->theirDeal, $this->theirTwin] = $this->dealWithTwin($this->colleague, '2 Theirs Rd');
    }

    private function user(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
    }

    /** @return array{0:int,1:int} deal id, twin id */
    private function dealWithTwin(User $owner, string $address): array
    {
        $dealId = (int) DB::table('deals')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'period' => '2026-10', 'deal_date' => '2026-10-05',
            'property_address' => $address, 'accepted_status' => 'G', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_user')->insert(['deal_id' => $dealId, 'user_id' => $owner->id, 'side' => 'listing', 'created_at' => now(), 'updated_at' => now()]);
        $twinId = (int) DB::table('deals_v2')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'legacy_deal_id' => $dealId, 'reference' => 'DR1-' . $dealId,
            'deal_type' => 'cash', 'status' => 'active', 'listing_agent_id' => $owner->id, 'purchase_price' => 1_000_000,
            'commission_amount' => 50_000, 'commission_vat' => 7_500, 'commission_status' => 'Not Paid', 'offer_date' => '2026-10-05',
            'overall_rag' => 'grey', 'created_by_id' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deals')->where('id', $dealId)->update(['deal_v2_id' => $twinId]);

        return [$dealId, $twinId];
    }

    /** @return list<LaravelRoute> every Deal Register route that names a deal */
    private function dealRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (str_starts_with($name, 'deals-dr2.') && (in_array('deal', $route->parameterNames(), true) || in_array('dealV2', $route->parameterNames(), true))) {
                $out[] = $route;
            }
        }

        return $out;
    }

    /** The route's own parameters filled in: the deal (or twin) under test, anything else a stand-in. */
    private function paramsFor(LaravelRoute $route, int $dealId, int $twinId): array
    {
        $params = [];
        foreach ($route->parameterNames() as $p) {
            $params[$p] = match ($p) {
                'deal' => $dealId, 'dealV2' => $twinId, 'user' => $this->agent->id, 'role' => 'buyer', default => 1,
            };
        }

        return $params;
    }

    /** Runs ONLY the ownership middleware for this route as the agent. Returns the HTTP status it answers with (200 = let through). */
    private function guardStatus(LaravelRoute $route, int $dealId, int $twinId): int
    {
        $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
        $request = Request::create(route($route->getName(), $this->paramsFor($route, $dealId, $twinId)), $method);
        $matched = Route::getRoutes()->match($request);
        $request->setRouteResolver(fn () => $matched);
        $this->actingAs($this->agent);

        try {
            (new EnsureDealAccess())->handle($request, fn () => response('through', 200));
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }

        return 200;
    }

    public function test_the_route_walk_finds_the_whole_deal_register_surface(): void
    {
        $names = collect($this->dealRoutes())->map->getName();

        $this->assertGreaterThan(40, $names->count(), 'the walk found the register, pipeline, communications, documents, distribution, settlement and work-order routes');
        foreach (['deals-dr2.properties.add', 'deals-dr2.properties.remove', 'deals-dr2.properties.restore', 'deals-dr2.properties.updatePrice',
                  'deals-dr2.settle.save', 'deals-dr2.pipeline.step.complete', 'deals-dr2.documents.store', 'deals-dr2.distribute.send',
                  'deals-dr2.communications.link', 'deals-dr2.work-order.send', 'deals-dr2.proforma.generate'] as $name) {
            $this->assertTrue($names->contains($name), "{$name} is in the walk");
        }
    }

    public function test_every_deal_register_action_carries_the_check_refuses_a_colleagues_deal_and_lets_the_agents_own_through(): void
    {
        $without = [];
        $notRefused = [];
        $wronglyRefused = [];

        foreach ($this->dealRoutes() as $route) {
            $name = $route->getName();
            if (! in_array('deal.access', $route->gatherMiddleware(), true)) {
                $without[] = $name;
            }
            if ($this->guardStatus($route, $this->theirDeal, $this->theirTwin) !== 403) {
                $notRefused[] = $name;
            }
            if ($this->guardStatus($route, $this->ownDeal, $this->ownTwin) !== 200) {
                $wronglyRefused[] = $name;
            }
        }

        $this->assertSame([], $without, 'actions with no ownership check on their route');
        $this->assertSame([], $notRefused, 'actions that did NOT refuse a colleague\'s deal');
        $this->assertSame([], $wronglyRefused, 'actions that refused the agent\'s OWN deal');
    }

    public function test_real_requests_through_the_whole_stack_are_refused_for_a_colleagues_deal_and_not_for_the_agents_own(): void
    {
        // One of each kind end to end (the walk above runs the check on its own): a page, a save, a file action, a step action.
        foreach ([
            ['get', 'deals-dr2.pipeline', []], ['post', 'deals-dr2.pipeline.decline', []], ['post', 'deals-dr2.documents.store', []],
            ['get', 'deals-dr2.settle', []], ['post', 'deals-dr2.communications.link', []], ['post', 'deals-dr2.pipeline.step.complete', [1]],
        ] as [$method, $name, $extra]) {
            $theirs = route($name, array_merge([$this->theirDeal], $extra));
            $own = route($name, array_merge([$this->ownDeal], $extra));
            $this->actingAs($this->agent)->json($method, $theirs)->assertForbidden();
            $this->assertNotSame(403, $this->actingAs($this->agent)->json($method, $own)->getStatusCode(), "{$name} on the agent's own deal");
        }
    }

    public function test_the_four_property_actions_named_in_the_ruling_each_refuse_a_colleague(): void
    {
        foreach ([
            ['post', route('deals-dr2.properties.add', $this->theirDeal)],
            ['delete', route('deals-dr2.properties.remove', [$this->theirDeal, 1])],
            ['post', route('deals-dr2.properties.restore', [$this->theirDeal, 1])],
            ['patch', route('deals-dr2.properties.updatePrice', [$this->theirDeal, 1])],
        ] as [$method, $url]) {
            $this->actingAs($this->agent)->{$method}($url, [])->assertForbidden();
        }
    }

    public function test_the_old_settlement_addresses_redirect_and_the_ownership_check_still_holds(): void
    {
        $this->actingAs($this->agent)->get(route('admin.deals.settle', [$this->ownDeal, 'x' => 'y']))
            ->assertStatus(301)->assertRedirect(route('deals-dr2.settle', [$this->ownDeal, 'x' => 'y']));
        $this->actingAs($this->agent)->post(route('admin.deals.settle.save', $this->ownDeal))
            ->assertStatus(308)->assertRedirect(route('deals-dr2.settle.save', $this->ownDeal));
        $this->actingAs($this->agent)->get(route('admin.deals.settle.print', $this->ownDeal))
            ->assertStatus(301)->assertRedirect(route('deals-dr2.settle.print', $this->ownDeal));
        $this->actingAs($this->agent)->get(route('admin.deals.settle.print.agent', [$this->ownDeal, $this->agent->id]))
            ->assertStatus(301)->assertRedirect(route('deals-dr2.settle.print.agent', [$this->ownDeal, $this->agent->id]));

        // ...and where they land, a colleague's deal is refused: view, save and both printouts.
        $this->actingAs($this->agent)->get(route('deals-dr2.settle', $this->theirDeal))->assertForbidden();
        $this->actingAs($this->agent)->post(route('deals-dr2.settle.save', $this->theirDeal), ['mark_paid' => 1])->assertForbidden();
        $this->actingAs($this->agent)->get(route('deals-dr2.settle.print', $this->theirDeal))->assertForbidden();
        $this->actingAs($this->agent)->get(route('deals-dr2.settle.print.agent', [$this->theirDeal, $this->agent->id]))->assertForbidden();
        $this->assertSame('Not Paid', DB::table('deals')->where('id', $this->theirDeal)->value('commission_status'), 'nothing was changed');
    }
}
