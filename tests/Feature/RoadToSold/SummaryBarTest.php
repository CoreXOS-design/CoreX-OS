<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Performance\Period;
use App\Services\RoadToSold\DrilldownService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\SummaryBarService;
use App\Services\RoadToSold\WorkdayService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 (lane 3) - the summary bar and the rows behind its figures. Spec section 10.6.
 *
 * Paths proven: calls / conversations / evaluations booked counted from the call log and agreeing with
 * the drill-down rows; should-be-by-today from the weekday targets; green / amber / red from the
 * agency's two lines; steps without a target show the actual only; a step switched off is gone;
 * listings live from the Agency Tracker listings target; evaluations done (appointment + feedback) and
 * mandates signed (completed mandate document) counted; the drill-down endpoint (envelope, unknown
 * figure 422, other agent 404 by direct URL, cross-agency nothing); the bar renders on the page.
 */
final class SummaryBarTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;
    private User $other;
    private TrackedProperty $tp;

    protected function setUp(): void
    {
        parent::setUp();
        OutcomeDefinitionService::forgetMemo();
        SettingsService::forget();
        Carbon::setTestNow('2026-10-14 09:00:00');

        $this->agencyId = (int) DB::table('agencies')->insertGetId(['name' => 'T ' . Str::random(5), 'slug' => 't-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true, 'name' => 'Anna Agent']);
        $this->other = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true, 'name' => 'Bheki Other']);
        $this->tp = TrackedProperty::create(['agency_id' => $this->agencyId, 'street_number' => '14', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo']);
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agencyId);

        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agencyId, 'scope' => 'own']);
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function log(User $by, string $key, int $n = 1, array $extra = []): void
    {
        $def = Def::withoutGlobalScopes()->where('agency_id', $this->agencyId)->where('key', $key)->firstOrFail();
        for ($i = 0; $i < $n; $i++) {
            DB::table('rts_contact_outcomes')->insert($extra + [
                'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'track' => 'sales', 'user_id' => $by->id,
                'tracked_property_id' => $this->tp->id, 'outcome_definition_id' => $def->id, 'outcome_key' => $def->key,
                'outcome_label' => $def->label, 'kind' => $def->kind, 'next_action' => 'none', 'is_current' => false,
                'occurred_at' => '2026-10-12 10:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function bar(?User $u = null): array
    {
        return app(SummaryBarService::class)->forAgent($u ?? $this->agent, '2026-10-14');
    }

    private function step(array $bar, string $key): array
    {
        return collect($bar['steps'])->firstWhere('key', $key);
    }

    public function test_calls_conversations_and_evaluations_booked_count_from_the_log_and_other_agents_and_months_are_left_out(): void
    {
        $this->log($this->agent, 'no_answer', 3);
        $this->log($this->agent, 'spoke_call_back', 2);
        $this->log($this->agent, 'spoke_evaluation_booked', 1, ['valuation_booked' => true]);
        $this->log($this->other, 'no_answer', 4);
        $this->log($this->agent, 'no_answer', 2, ['occurred_at' => '2026-09-30 10:00:00']);   // last month
        $this->log($this->agent, 'no_answer', 1, ['occurred_at' => '2026-10-15 10:00:00']);   // after the day shown

        $bar = $this->bar();

        $this->assertSame('October 2026', $bar['month']);
        $this->assertSame(6, $this->step($bar, 'calls_made')['actual']);
        $this->assertSame(3, $this->step($bar, 'conversations')['actual']);
        $this->assertSame(1, $this->step($bar, 'evaluations_booked')['actual']);
    }

    public function test_should_be_by_today_comes_from_the_weekday_targets_and_the_colour_from_the_agencys_lines(): void
    {
        $should = app(WorkdayService::class)->monthTargetToDate($this->agent, '2026-10-14');
        $this->assertGreaterThan(0, $should);

        $this->log($this->agent, 'no_answer', $should);
        $green = $this->step($this->bar(), 'calls_made');
        $this->assertSame($should, $green['should_be']);
        $this->assertSame(100.0, (float) $green['pct']);
        $this->assertSame('green', $green['status']);

        DB::table('rts_contact_outcomes')->where('user_id', $this->agent->id)->limit($should - (int) floor($should * 0.8))->delete();   // ~80%
        $this->assertSame('amber', $this->step($this->bar(), 'calls_made')['status']);

        DB::table('rts_contact_outcomes')->where('user_id', $this->agent->id)->delete();
        $this->log($this->agent, 'no_answer', (int) floor($should * 0.3));
        $this->assertSame('red', $this->step($this->bar(), 'calls_made')['status']);
    }

    public function test_the_agencys_own_colour_lines_are_used(): void
    {
        DB::table('rts_settings')->insert(['agency_id' => $this->agencyId, 'track' => 'sales', 'summary_green_from_pct' => 50, 'summary_red_below_pct' => 20, 'created_at' => now(), 'updated_at' => now()]);
        SettingsService::forget();
        $should = app(WorkdayService::class)->monthTargetToDate($this->agent, '2026-10-14');
        $this->log($this->agent, 'no_answer', (int) ceil($should * 0.55));

        $this->assertSame('green', $this->step($this->bar(), 'calls_made')['status']);
    }

    public function test_steps_without_a_monthly_target_show_the_actual_only(): void
    {
        $this->log($this->agent, 'spoke_call_back');
        $conv = $this->step($this->bar(), 'conversations');

        $this->assertNull($conv['should_be']);
        $this->assertNull($conv['pct']);
        $this->assertSame('none', $conv['status']);
    }

    public function test_a_step_the_agency_switched_off_is_gone(): void
    {
        $steps = collect(SettingsService::forAgency($this->agencyId)->summarySteps())
            ->map(fn ($s) => ['key' => $s['key'], 'enabled' => $s['key'] !== 'evaluations_done'])->all();
        DB::table('rts_settings')->insert(['agency_id' => $this->agencyId, 'track' => 'sales', 'summary_steps' => json_encode($steps), 'created_at' => now(), 'updated_at' => now()]);
        SettingsService::forget();

        $keys = collect($this->bar()['steps'])->pluck('key')->all();
        $this->assertNotContains('evaluations_done', $keys);
        $this->assertContains('calls_made', $keys);
    }

    public function test_listings_live_uses_the_agency_tracker_listings_target_and_the_listed_date(): void
    {
        DB::table('targets')->insert(['period' => '2026-10', 'user_id' => $this->agent->id, 'agency_id' => $this->agencyId, 'listings_target' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $mk = fn (User $a, string $listed, string $type = 'sale') => DB::table('properties')->insert([
            'agency_id' => $this->agencyId, 'agent_id' => $a->id, 'branch_id' => $this->agencyId, 'external_id' => (string) Str::uuid(),
            'title' => 'L ' . Str::random(4), 'listing_type' => $type, 'status' => 'active', 'property_type' => 'house', 'price' => 900000,
            'listed_date' => $listed, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mk($this->agent, '2026-10-05');
        $mk($this->agent, '2026-10-13');
        $mk($this->agent, '2026-09-20');            // last month
        $mk($this->agent, '2026-10-06', 'rental');  // not a sale
        $mk($this->other, '2026-10-06');

        $live = $this->step($this->bar(), 'listings_live');

        $this->assertSame(2, $live['actual']);
        $this->assertGreaterThan(0, $live['should_be']);
        $this->assertLessThan(10, $live['should_be']);
        $this->assertNotNull($live['pct']);
    }

    public function test_evaluations_done_and_mandates_signed_are_counted(): void
    {
        $evt = DB::table('calendar_events')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'user_id' => $this->agent->id, 'created_by_id' => $this->agent->id,
            'event_type' => 'manual', 'category' => 'property_evaluation', 'title' => 'Evaluation: 14 Marine Drive', 'event_date' => '2026-10-10 10:00:00',
            'status' => 'completed', 'priority' => 'normal', 'source_type' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $noFeedback = DB::table('calendar_events')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'user_id' => $this->agent->id, 'created_by_id' => $this->agent->id,
            'event_type' => 'manual', 'category' => 'property_evaluation', 'title' => 'Evaluation: no feedback', 'event_date' => '2026-10-11 10:00:00',
            'status' => 'pending', 'priority' => 'normal', 'source_type' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('calendar_event_feedback')->insert([
            'calendar_event_id' => $evt, 'agency_id' => $this->agencyId, 'captured_by_user_id' => $this->agent->id, 'captured_at' => '2026-10-10 12:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tplId = DB::table('docuperfect_templates')->insertGetId(['name' => 'Sole mandate', 'template_type' => 'mandate', 'created_at' => now(), 'updated_at' => now()]);
        $mk = function (string $type, User $owner, string $status, string $at) use ($tplId) {
            $docId = DB::table('docuperfect_documents')->insertGetId([
                'name' => 'Doc', 'owner_id' => $owner->id, 'agency_id' => $this->agencyId, 'document_type' => $type === 'mandate' ? 'mandate' : 'otp',
                'template_id' => $type === 'mandate' ? $tplId : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('signature_templates')->insert(['document_id' => $docId, 'agency_id' => $this->agencyId, 'status' => $status, 'completed_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
        };
        $mk('mandate', $this->agent, 'completed', '2026-10-09 15:00:00');
        $mk('mandate', $this->agent, 'signing', '2026-10-09 15:00:00');    // not complete
        $mk('otp', $this->agent, 'completed', '2026-10-09 15:00:00');      // not a mandate
        $mk('mandate', $this->other, 'completed', '2026-10-09 15:00:00');  // someone else's

        $bar = $this->bar();

        $this->assertSame(1, $this->step($bar, 'evaluations_done')['actual']);
        $this->assertSame(1, $this->step($bar, 'mandates_signed')['actual']);
        $this->assertNotNull($noFeedback);
    }

    public function test_the_drilldown_rows_agree_with_the_figure(): void
    {
        $this->log($this->agent, 'no_answer', 2, ['note' => 'Rang twice']);
        $this->log($this->agent, 'spoke_call_back');

        $url = route('road-to-sold.drilldown', ['metric' => 'calls_made', 'agent_id' => $this->agent->id, 'period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-14']);
        $res = $this->actingAs($this->agent)->getJson($url)->assertOk();

        $res->assertJsonPath('total', 3)->assertJsonCount(3, 'rows')->assertJsonPath('truncated', false);
        $res->assertJsonPath('rows.0.property', '14 Marine Drive, Uvongo');
        $this->assertStringContainsString('Calls made', $res->json('title'));
        $this->assertSame($this->step($this->bar(), 'calls_made')['actual'], $res->json('total'));

        $conv = $this->actingAs($this->agent)->getJson(route('road-to-sold.drilldown', ['metric' => 'conversations', 'agent_id' => $this->agent->id, 'period' => 'this_month']))->assertOk();
        $conv->assertJsonPath('total', 1)->assertJsonPath('rows.0.outcome', 'Spoke - call back');
    }

    public function test_drilldown_refusals_and_isolation(): void
    {
        $this->log($this->other, 'no_answer', 2);
        $base = ['period' => 'this_month'];

        $this->actingAs($this->agent)->getJson(route('road-to-sold.drilldown', $base + ['metric' => 'nonsense']))->assertStatus(422);
        $this->actingAs($this->agent)->getJson(route('road-to-sold.drilldown', ['metric' => 'calls_made', 'period' => 'bogus']))->assertStatus(422);
        $this->actingAs($this->agent)->getJson(route('road-to-sold.drilldown', ['metric' => 'calls_made', 'period' => 'custom', 'start' => '2026-10-10']))->assertStatus(422);
        $this->actingAs($this->agent)->getJson(route('road-to-sold.drilldown', $base + ['metric' => 'calls_made', 'agent_id' => $this->other->id]))->assertNotFound();
        // no agent given: only what the viewer's breadth allows - own, so nothing of the other agent's
        $this->actingAs($this->agent)->getJson(route('road-to-sold.drilldown', $base + ['metric' => 'calls_made']))->assertOk()->assertJsonPath('total', 0);

        $bId = (int) DB::table('agencies')->insertGetId(['name' => 'Other', 'slug' => 'o-' . Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $bId, 'agency_id' => $bId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $bAgent = User::factory()->create(['agency_id' => $bId, 'branch_id' => $bId, 'role' => 'agent', 'is_active' => true]);
        foreach (['access_road_to_sold', 'road_to_sold.view'] as $k) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $k, 'agency_id' => $bId, 'scope' => 'own']);
        }
        PermissionService::clearCache();
        $this->actingAs($bAgent)->getJson(route('road-to-sold.drilldown', $base + ['metric' => 'calls_made', 'agent_id' => $this->other->id]))->assertNotFound();
        $this->assertSame(0, app(DrilldownService::class)->count('calls_made', [$this->other->id], new Period(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), 'x', 'custom'), $bId));
    }

    public function test_the_bar_renders_on_the_page_with_the_drill_modal(): void
    {
        $this->log($this->agent, 'no_answer', 2);

        $res = $this->actingAs($this->agent)->get(route('road-to-sold.index'));

        $res->assertOk()->assertSee('Calls made')->assertSee('Conversations')->assertSee('Evaluations booked')->assertSee('Listings live');
        $res->assertSee('buyersReport(', false)->assertSee('Click to see the rows behind this figure', false);
        $res->assertDontSee('Social');
    }
}
