<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RoadToSold\RtsAgentStart;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\ActivationService;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\WorkdayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** Road to Sold v4 (spec 23.13): "Start my Road to Sold", pause / resume, the manager's panel, and what they do to the list and the working days. */
final class BeltActivationTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';

    private User $manager;
    private User $fresh;   // an agent who has never worked Road to Sold

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::WED . ' 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        $this->manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true, 'name' => 'Mia Manager']);
        config(['road-to-sold-belt.enforce_start' => true]);
        $this->fresh = $this->makeAgent();
        \App\Models\RoadToSold\RtsSetting::query()->withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], ['agent_start_required' => true]);
        SettingsService::forget();
        foreach (['agent' => 'own', 'branch_manager' => 'branch'] as $role => $scope) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => $scope]);
        }
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'road_to_sold.pause_others', 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function svc(): ActivationService
    {
        return new ActivationService();   // a fresh memo each time
    }

    public function test_an_agent_who_has_not_started_sees_the_start_card_and_no_list_is_built_for_them(): void
    {
        $html = $this->actingAs($this->fresh)->get(route('road-to-sold.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="belt-start-card"', $html);
        $this->assertStringContainsString('Start my Road to Sold', $html);
        $this->assertStringNotContainsString('data-qa="belt-tiles"', $html);
        $this->assertSame(ActivationService::STATE_NOT_STARTED, $this->svc()->state($this->fresh)['state']);
        $this->assertFalse($this->svc()->mayBuild($this->fresh, self::WED));
        $this->assertCount(0, app(ListBuilderService::class)->buildFor($this->fresh, self::WED), 'nothing loads before Start');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('rts_list_items')->where('user_id', $this->fresh->id)->count());
    }

    public function test_pressing_start_records_it_and_the_first_list_is_the_next_working_day(): void
    {
        $this->actingAs($this->fresh)->post(route('road-to-sold.activation.start'))->assertRedirect(route('road-to-sold.index'));
        $row = RtsAgentStart::query()->withoutGlobalScopes()->where('user_id', $this->fresh->id)->first();
        $this->assertNotNull($row->started_at);
        $this->assertSame('2026-10-15', $row->first_list_date->toDateString(), 'Wednesday start, Thursday first list');
        $this->actingAs($this->fresh)->post(route('road-to-sold.activation.start'));   // idempotent
        $this->assertSame(1, RtsAgentStart::query()->withoutGlobalScopes()->where('user_id', $this->fresh->id)->count());

        $s = $this->svc();
        $this->assertTrue($s->isStarted($this->fresh));
        $this->assertFalse($s->mayBuild($this->fresh, '2026-10-14'), 'not on the day they pressed Start');
        $this->assertTrue($s->mayBuild($this->fresh, '2026-10-15'));
        $html = $this->actingAs($this->fresh)->get(route('road-to-sold.index'))->getContent();
        $this->assertStringContainsString('data-qa="belt-tiles"', $html);
        $this->assertStringNotContainsString('data-qa="belt-start-card"', $html);
    }

    public function test_an_agent_who_already_works_road_to_sold_counts_as_started_without_a_row(): void
    {
        app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '1', 'street_name' => 'Old Road'])->id);
        $this->assertTrue($this->svc()->isStarted($this->agent));
        $this->assertTrue($this->svc()->mayBuild($this->agent, self::WED));
        $this->assertSame(0, RtsAgentStart::query()->withoutGlobalScopes()->count(), 'no row is written for them');
    }

    public function test_a_pause_stops_the_list_and_takes_the_days_out_of_the_working_days_and_resume_puts_them_back(): void
    {
        app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '1', 'street_name' => 'Old Road'])->id);
        $this->actingAs($this->agent)->post(route('road-to-sold.activation.pause'), ['until' => '2026-10-16', 'reason' => 'Training'])->assertRedirect();
        $w = WorkdayService::make();
        $this->assertSame(0, $w->targetFor($this->agent, '2026-10-14'), 'a paused Wednesday has no target');
        $this->assertSame(0, $w->targetFor($this->agent, '2026-10-16'), 'the last paused day too');
        $this->assertGreaterThan(0, $w->targetFor($this->agent, '2026-10-19'), 'Monday the 19th is a working day again');
        $this->assertFalse($w->isWorkday($this->agent, '2026-10-15'));
        $this->assertFalse($this->svc()->mayBuild($this->agent, '2026-10-15'));
        $this->assertTrue($this->svc()->mayBuild($this->agent, '2026-10-19'));
        $html = $this->actingAs($this->agent)->get(route('road-to-sold.index'))->getContent();
        $this->assertStringContainsString('data-qa="belt-paused"', $html);
        $this->assertStringContainsString('Training', $html);

        $this->actingAs($this->agent)->post(route('road-to-sold.activation.resume'))->assertRedirect();
        $this->assertGreaterThan(0, WorkdayService::make()->targetFor($this->agent, '2026-10-15'), 'back to a working day');
    }

    public function test_a_pause_in_the_past_or_without_a_date_is_refused_in_plain_words(): void
    {
        $r = $this->actingAs($this->agent)->from(route('road-to-sold.index'))->post(route('road-to-sold.activation.pause'), ['until' => '2026-10-10']);
        $r->assertSessionHasErrors('until');
        $this->assertStringContainsString('cannot end in the past', session('errors')->first('until'));
        $this->actingAs($this->agent)->post(route('road-to-sold.activation.pause'), [])->assertSessionHasErrors('until');
        $this->assertSame(0, RtsAgentStart::query()->withoutGlobalScopes()->count());
    }

    public function test_a_manager_sees_who_has_not_started_and_who_is_paused_and_may_start_pause_and_resume_for_them(): void
    {
        app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '1', 'street_name' => 'Old Road'])->id);
        $this->actingAs($this->manager)->post(route('road-to-sold.activation.pause'), ['agent' => $this->agent->id, 'until' => '2026-10-20', 'reason' => 'Leave'])->assertRedirect();

        $html = $this->actingAs($this->manager)->get(route('road-to-sold.index', ['agent' => $this->agent->id]))->getContent();
        $this->assertStringContainsString('data-qa="belt-roster"', $html);
        $this->assertStringContainsString($this->fresh->name, $html);
        $this->assertStringContainsString('has not started', $html);
        $this->assertStringContainsString('paused until 20 Oct', $html);
        $this->assertStringContainsString('Leave', $html);
        $this->assertStringContainsString('set by Mia Manager', $html);

        $this->actingAs($this->manager)->post(route('road-to-sold.activation.start'), ['agent' => $this->fresh->id])->assertRedirect();
        $this->assertTrue($this->svc()->isStarted($this->fresh));
        $this->actingAs($this->manager)->post(route('road-to-sold.activation.resume'), ['agent' => $this->agent->id])->assertRedirect();
        $this->assertSame(ActivationService::STATE_ACTIVE, $this->svc()->state($this->agent)['state']);
    }

    public function test_an_agent_cannot_start_or_pause_a_colleague_and_a_manager_cannot_reach_outside_their_breadth(): void
    {
        $this->actingAs($this->agent)->post(route('road-to-sold.activation.start'), ['agent' => $this->fresh->id])->assertForbidden();
        $this->actingAs($this->agent)->post(route('road-to-sold.activation.pause'), ['agent' => $this->fresh->id, 'until' => '2026-10-20'])->assertForbidden();
        $otherBranch = \App\Models\Branch::create(['agency_id' => $this->agency->id, 'name' => 'Other']);
        $far = $this->makeAgent(null, $otherBranch);
        $this->actingAs($this->manager)->post(route('road-to-sold.activation.start'), ['agent' => $far->id])->assertNotFound();
        $this->assertSame(0, RtsAgentStart::query()->withoutGlobalScopes()->count());
    }

    public function test_the_setting_off_means_everyone_counts_as_started(): void
    {
        \App\Models\RoadToSold\RtsSetting::query()->withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], ['agent_start_required' => false]);
        SettingsService::forget();
        $this->assertTrue($this->svc()->isStarted($this->fresh));
        $this->assertTrue($this->svc()->mayBuild($this->fresh, self::WED));
        $this->assertStringNotContainsString('data-qa="belt-start-card"', $this->actingAs($this->fresh)->get(route('road-to-sold.index'))->getContent());
    }

    public function test_the_empty_list_says_not_started_or_paused_instead_of_a_wrong_reason(): void
    {
        $html = $this->actingAs($this->fresh)->get(route('road-to-sold.index'))->getContent();
        $this->assertStringContainsString('have not pressed Start yet', $html);
        $this->assertStringNotContainsString('matches the list rules', $html);

        app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '1', 'street_name' => 'Old Road'])->id);
        $this->actingAs($this->agent)->post(route('road-to-sold.activation.pause'), ['until' => '2026-10-20', 'reason' => 'Leave']);
        $p = $this->actingAs($this->agent)->get(route('road-to-sold.index'))->getContent();
        $this->assertStringContainsString('Road to Sold is paused until 20 Oct 2026 (Leave). No list loads until then.', $p);
        $this->assertStringNotContainsString('not a working day for you', $p);
    }

    public function test_start_pause_and_resume_raise_their_events_once_and_write_the_history(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\RoadToSold\RoadToSoldStarted::class, \App\Events\RoadToSold\RoadToSoldPaused::class, \App\Events\RoadToSold\RoadToSoldResumed::class]);
        $this->actingAs($this->fresh)->post(route('road-to-sold.activation.start'));
        $this->actingAs($this->fresh)->post(route('road-to-sold.activation.start'));   // pressing it again changes and raises nothing
        $this->actingAs($this->fresh)->post(route('road-to-sold.activation.resume'));   // resuming what is not paused raises nothing
        $this->actingAs($this->manager)->post(route('road-to-sold.activation.pause'), ['agent' => $this->fresh->id, 'until' => '2026-10-20', 'reason' => 'Leave']);
        $this->actingAs($this->manager)->post(route('road-to-sold.activation.resume'), ['agent' => $this->fresh->id]);

        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\RoadToSold\RoadToSoldStarted::class, 1);
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\RoadToSold\RoadToSoldStarted::class, fn ($e) => $e->userId === $this->fresh->id && $e->firstListDate === '2026-10-15' && $e->actorUserId === $this->fresh->id && $e->agencyId === $this->agency->id);
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\RoadToSold\RoadToSoldPaused::class, 1);
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\RoadToSold\RoadToSoldPaused::class, fn ($e) => $e->pausedUntil === '2026-10-20' && $e->reason === 'Leave' && $e->actorUserId === $this->manager->id);
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\RoadToSold\RoadToSoldResumed::class, 1);

        $h = $this->svc()->history($this->fresh);
        $this->assertSame(['resumed', 'paused', 'started'], array_column($h, 'kind'), 'newest first, one row per press that did something');
        $this->assertStringContainsString('Paused until 20 Oct 2026 (Leave)', $h[1]['detail']);
        $this->assertSame('Mia Manager', $h[1]['by']);
        $page = $this->actingAs($this->manager)->get(route('road-to-sold.index', ['agent' => $this->fresh->id]))->getContent();
        $this->assertStringContainsString('data-qa="belt-history"', $page);
        $this->assertSame(3, substr_count($page, 'data-qa="history-row"'));
    }
}
