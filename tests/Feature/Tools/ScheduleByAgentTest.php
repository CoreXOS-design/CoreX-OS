<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\AdSchedulePattern;
use App\Models\AgentSocialAccount;
use App\Models\Property;
use App\Models\PropertyMarketingPost;
use App\Models\User;
use App\Services\Marketing\ScheduledPostService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-456 §D.9 — "Schedule by agent" in the Ad Manager: each agent's Nth ad on the Nth ticked day, agents a set gap
 * apart in A-to-Z or the user's own order, "too many ads" refused, the pattern remembered per user per agency.
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §D.9.
 *
 * Clock frozen at Tue 2026-10-20 08:00 UTC = 10:00 Africa/Johannesburg (UTC+2). "Starting from" Mon 2026-10-26, so
 * Tuesday = 27 Oct and Thursday = 29 Oct. Local 09:00 = 07:00 UTC.
 */
final class ScheduleByAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 20, 8, 0, 0, 'UTC'));
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PermissionService::clearCache();
        parent::tearDown();
    }

    public function test_each_agents_ads_go_one_per_ticked_day_agents_thirty_minutes_apart_a_to_z(): void
    {
        [$agency, $branch, $admin] = $this->world();
        $zola      = $this->agent($agency, $branch, 'Zola Nkosi');
        $angelique = $this->agent($agency, $branch, 'Angelique Botha');
        $bongani   = $this->agent($agency, $branch, 'Bongani Dlamini');

        // Batch order deliberately not alphabetical; Zola has only one ad.
        $items = $this->items($admin, [
            $this->property($agency, $branch, $zola, 'Z1'),
            $this->property($agency, $branch, $angelique, 'A1'),
            $this->property($agency, $branch, $bongani, 'B1'),
            $this->property($agency, $branch, $angelique, 'A2'),
            $this->property($agency, $branch, $bongani, 'B2'),
        ]);

        $this->actingAs($admin)->postJson(route('api.v1.ad-manager.scheduled.store'), $this->byAgent($items, ['2026-10-27', '2026-10-29'], '09:00', 30))
            ->assertOk()->assertJsonPath('scheduled', 5);

        $this->assertEquals([
            'A1' => '2026-10-27 07:00', 'B1' => '2026-10-27 07:30', 'Z1' => '2026-10-27 08:00',
            'A2' => '2026-10-29 07:00', 'B2' => '2026-10-29 07:30',
        ], $this->slots());
    }

    public function test_own_order_puts_the_chosen_agents_first_and_any_left_out_after_them_a_to_z(): void
    {
        [$agency, $branch, $admin] = $this->world();
        $zola      = $this->agent($agency, $branch, 'Zola Nkosi');
        $angelique = $this->agent($agency, $branch, 'Angelique Botha');
        $bongani   = $this->agent($agency, $branch, 'Bongani Dlamini');

        $items = $this->items($admin, [
            $this->property($agency, $branch, $angelique, 'A1'),
            $this->property($agency, $branch, $bongani, 'B1'),
            $this->property($agency, $branch, $zola, 'Z1'),
        ]);

        // Zola first, then Angelique; Bongani not in the list; 999999 is not in the batch and is ignored.
        $this->actingAs($admin)->postJson(route('api.v1.ad-manager.scheduled.store'),
            $this->byAgent($items, ['2026-10-27'], '09:00', 45, 'custom', [$zola->id, 999999, $angelique->id]))
            ->assertOk();

        $this->assertEquals(['A1' => '2026-10-27 07:45', 'B1' => '2026-10-27 08:30', 'Z1' => '2026-10-27 07:00'], $this->slots());
    }

    public function test_an_agent_with_more_ads_than_ticked_days_is_named_and_nothing_is_scheduled(): void
    {
        [$agency, $branch, $admin] = $this->world();
        $angelique = $this->agent($agency, $branch, 'Angelique Botha');
        $bongani   = $this->agent($agency, $branch, 'Bongani Dlamini');

        $items = $this->items($admin, [
            $this->property($agency, $branch, $angelique, 'A1'),
            $this->property($agency, $branch, $angelique, 'A2'),
            $this->property($agency, $branch, $angelique, 'A3'),
            $this->property($agency, $branch, $bongani, 'B1'),
        ]);

        $this->actingAs($admin)->postJson(route('api.v1.ad-manager.scheduled.store'), $this->byAgent($items, ['2026-10-27', '2026-10-29'], '09:00', 30))
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Angelique Botha has 3 ads, but only 2 days are ticked. Each agent gets one ad per day: remove ads or tick more days.']);

        $this->assertSame(0, PropertyMarketingPost::withoutGlobalScopes()->count());
        $this->assertSame(0, AdSchedulePattern::withoutGlobalScopes()->count());
    }

    public function test_a_whole_week_with_five_ads_per_agent(): void
    {
        [$agency, $branch, $admin] = $this->world();
        $a = $this->agent($agency, $branch, 'Anna');
        $b = $this->agent($agency, $branch, 'Ben');

        $props = [];
        foreach ([$a, $b] as $agent) {
            for ($i = 1; $i <= 5; $i++) {
                $props[] = $this->property($agency, $branch, $agent, $agent->name . $i);
            }
        }
        $week = ['2026-10-30', '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29']; // order sent does not matter

        $this->actingAs($admin)->postJson(route('api.v1.ad-manager.scheduled.store'), $this->byAgent($this->items($admin, $props), $week, '08:00', 30))
            ->assertOk()->assertJsonPath('scheduled', 10);

        $slots = $this->slots();
        foreach (['26', '27', '28', '29', '30'] as $n => $day) {
            $this->assertSame("2026-10-{$day} 06:00", $slots['Anna' . ($n + 1)]);
            $this->assertSame("2026-10-{$day} 06:30", $slots['Ben' . ($n + 1)]);
        }
    }

    public function test_a_day_that_would_run_past_midnight_is_refused(): void
    {
        [$agency, $branch, $admin] = $this->world();
        $props = [];
        foreach (['Anna', 'Ben', 'Cara'] as $name) {
            $props[] = $this->property($agency, $branch, $this->agent($agency, $branch, $name), $name);
        }

        $this->actingAs($admin)->postJson(route('api.v1.ad-manager.scheduled.store'), $this->byAgent($this->items($admin, $props), ['2026-10-27'], '23:00', 30))
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'The posts on Tuesday 27 October would run past midnight. Start earlier or make the time between agents shorter.']);
        $this->assertSame(0, PropertyMarketingPost::withoutGlobalScopes()->count());
    }

    public function test_the_five_minute_rule_still_applies_to_the_first_post(): void
    {
        [$agency, $branch, $admin] = $this->world();
        $items = $this->items($admin, [$this->property($agency, $branch, $this->agent($agency, $branch, 'Anna'), 'A1')]);

        // Today (Tue 20 Oct) at 10:02 local = 2 minutes from now.
        $this->actingAs($admin)->postJson(route('api.v1.ad-manager.scheduled.store'), $this->byAgent($items, ['2026-10-20'], '10:02', 30))
            ->assertStatus(422)->assertJsonFragment(['error' => 'Pick a time at least 5 minutes from now.']);
    }

    public function test_company_ads_and_listings_outside_the_users_scope_are_refused(): void
    {
        [$agency, $branch, $admin] = $this->world();
        $agent     = $this->agent($agency, $branch, 'Anna');
        $colleague = $this->agent($agency, $branch, 'Ben');
        $this->connect($agent);
        $theirs = $this->property($agency, $branch, $colleague, 'Ben1');

        // An agent (own scope) cannot schedule a colleague's listing by agent.
        $this->actingAs($agent)->postJson(route('api.v1.ad-manager.scheduled.store'), $this->byAgent($this->items($agent, [$theirs]), ['2026-10-27'], '09:00', 30))
            ->assertStatus(422)->assertJsonFragment(['error' => 'One of those listings is not yours to post for.']);

        $this->actingAs($admin)->postJson(route('api.v1.ad-manager.scheduled.store'), [
            'items' => [['company_ad_id' => 1, 'copy' => 'Hi', 'image_path' => $this->storedImage($admin)]],
            'platforms' => ['facebook'], 'mode' => 'agents', 'time' => '09:00', 'days' => ['2026-10-27'], 'gap' => 30, 'order' => 'alpha',
        ])->assertStatus(422)->assertJsonFragment(['error' => 'Schedule by agent is for listing ads only.']);

        $this->assertSame(0, PropertyMarketingPost::withoutGlobalScopes()->count());
    }

    public function test_the_pattern_is_remembered_per_user_and_agency_and_opens_the_window_next_time(): void
    {
        [$agency, $branch, $admin] = $this->world();
        $zola      = $this->agent($agency, $branch, 'Zola Nkosi');
        $angelique = $this->agent($agency, $branch, 'Angelique Botha');
        $items = $this->items($admin, [$this->property($agency, $branch, $zola, 'Z1'), $this->property($agency, $branch, $angelique, 'A1')]);

        $this->actingAs($admin)->postJson(route('api.v1.ad-manager.scheduled.store'),
            $this->byAgent($items, ['2026-10-27', '2026-10-29'], '08:30', 45, 'custom', [$zola->id, $angelique->id]))->assertOk();

        $svc = app(ScheduledPostService::class);
        $this->assertSame(['weekdays' => [2, 4], 'time' => '08:30', 'gap' => 45, 'order' => 'custom', 'agents' => [$zola->id, $angelique->id]], $svc->savedPattern($admin));

        // The Ad Manager hands it to the Schedule window.
        $this->get(route('tools.ad-manager'))->assertOk()->assertSee('"pattern":{"weekdays":[2,4]', false)->assertSee('By agent');

        // Choosing A to Z next time keeps the own order for later; still one row.
        $items2 = $this->items($admin, [$this->property($agency, $branch, $zola, 'Z2')]);
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->byAgent($items2, ['2026-10-28'], '09:00', 30, 'alpha', [$zola->id]))->assertOk();
        $this->assertSame(['weekdays' => [3], 'time' => '09:00', 'gap' => 30, 'order' => 'alpha', 'agents' => [$zola->id, $angelique->id]], $svc->savedPattern($admin));
        $this->assertSame(1, AdSchedulePattern::withoutGlobalScopes()->count());

        // A second agency's user sees nothing of it.
        [, , $otherAdmin] = $this->world();
        $this->assertNull($svc->savedPattern($otherAdmin));
        $this->actingAs($otherAdmin)->get(route('tools.ad-manager'))->assertOk()->assertSee('"pattern":null', false);
    }

    public function test_the_service_orders_agents_a_to_z_with_unassigned_listings_last(): void
    {
        $svc = app(ScheduledPostService::class);
        $names = [7 => 'zola', 0 => 'Unassigned', 3 => 'Angelique', 5 => 'bongani'];

        $this->assertSame([3, 5, 7, 0], $svc->orderAgents($names));
        $this->assertSame([7, 3, 5, 0], $svc->orderAgents($names, [7, 0, 42]));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{0:int,1:int,2:User} [agencyId, branchId, admin with a connected Facebook page] */
    private function world(): array
    {
        $agency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = (int) DB::table('branches')->insertGetId(['agency_id' => $agency, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $admin  = User::factory()->create(['agency_id' => $agency, 'branch_id' => $branch, 'role' => 'admin', 'name' => 'Office Admin']);
        $this->connect($admin);

        return [$agency, $branch, $admin];
    }

    private function agent(int $agency, int $branch, string $name): User
    {
        return User::factory()->create(['agency_id' => $agency, 'branch_id' => $branch, 'role' => 'agent', 'name' => $name]);
    }

    private function property(int $agency, int $branch, User $agent, string $title): Property
    {
        $p = Property::create([
            'agency_id' => $agency, 'branch_id' => $branch, 'agent_id' => $agent->id, 'title' => $title,
            'status' => 'active', 'listing_type' => 'sale', 'property_type' => 'house',
        ]);
        DB::table('properties')->where('id', $p->id)->update(['compliance_snapshot_at' => now()]);

        return $p->fresh();
    }

    private function connect(User $user): void
    {
        AgentSocialAccount::create([
            'agency_id' => $user->agency_id, 'user_id' => $user->id, 'platform' => 'facebook',
            'platform_page_id' => '1000' . $user->id, 'platform_page_name' => 'Test Page',
            'access_token' => 'EAABsecrettokenvalue1234567890', 'token_expires_at' => now()->addDays(30), 'is_active' => true,
        ]);
    }

    private function storedImage(User $user): string
    {
        $path = "marketing-scheduled/{$user->agency_id}/{$user->id}/" . Str::uuid() . '.jpg';
        Storage::disk('public')->put($path, 'jpeg-bytes');

        return $path;
    }

    /** @param list<Property> $props @return list<array<string,mixed>> */
    private function items(User $by, array $props): array
    {
        return array_map(fn (Property $p) => ['property_id' => $p->id, 'copy' => 'Come see ' . $p->title, 'image_path' => $this->storedImage($by)], $props);
    }

    /** @return array<string,mixed> */
    private function byAgent(array $items, array $days, string $time, int $gap, string $order = 'alpha', array $agents = []): array
    {
        return ['items' => $items, 'platforms' => ['facebook'], 'mode' => 'agents', 'time' => $time, 'days' => $days, 'gap' => $gap, 'order' => $order, 'agents' => $agents];
    }

    /** @return array<string,string> listing title => UTC slot */
    private function slots(): array
    {
        return PropertyMarketingPost::withoutGlobalScopes()->with('property')->get()
            ->mapWithKeys(fn ($p) => [$p->property->title => $p->scheduled_for->format('Y-m-d H:i')])
            ->sortKeys()->all();
    }
}
