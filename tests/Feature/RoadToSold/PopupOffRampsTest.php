<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsParkReason;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RolePermission;
use App\Services\Performance\Period;
use App\Services\PermissionService;
use App\Services\RoadToSold\BeltFunnelService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\ResultOptionService;
use App\Services\RoadToSold\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v4 (road-to-sold.md 23.25 / 23.11, Johan 3 Nov): "Stops here (say why)" on every step of the pitch popup. The reasons are the agency's DATA, per step; the one
 * result service does the leaving; the agent's own record is the only one that can be stopped; both entry points (a Road to Sold row, MIC Pitch now) reach the same answer.
 */
final class PopupOffRampsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        ResultOptionService::forgetMemo();
        ParkReasonService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        foreach (['access_prospecting', 'access_road_to_sold', 'outreach.compose'] as $k) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $k, 'agency_id' => $this->agency->id]);
        }
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id], ['scope' => 'own']);
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function period(): Period
    {
        return new Period(CarbonImmutable::parse('2026-11-01')->startOfDay(), CarbonImmutable::parse('2026-11-30')->endOfDay(), 'Test', 'custom');
    }

    /** A claim (and so a belt record) at the stage given: [record, claim, listing id] */
    private function record(string $stage = S::CLAIMED, ?int $n = null): array
    {
        $tp = $this->tp(['street_name' => 'Popup Road', 'street_number' => (string) ($n ?? random_int(1, 900))]);
        $listing = $this->listing([], $tp);
        $claim = ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $listing, 'user_id' => $this->agent->id, 'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()])->refresh();
        $p = RtsProspect::withoutGlobalScopes()->findOrFail($claim->rts_prospect_id);
        $p->forceFill(['stage' => $stage])->save();

        return [$p->refresh(), $claim, $listing];
    }

    private function svc(): ParkReasonService
    {
        return app(ParkReasonService::class);
    }

    // ── the reasons are data ─────────────────────────────────────────────

    public function test_the_seed_is_idempotent_gives_existing_keys_a_step_and_ends_every_step_with_other(): void
    {
        $before = RtsParkReason::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count();
        $this->svc()->ensureStepReasons($this->agency->id);
        $once = RtsParkReason::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count();
        ParkReasonService::forgetMemo();
        $this->svc()->ensureStepReasons($this->agency->id);
        $this->assertSame($once, RtsParkReason::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count(), 'a second run adds nothing');
        $this->assertGreaterThan(0, $once - $before - 1 + 1);

        $dupes = RtsParkReason::withoutGlobalScopes()->withTrashed()->where('agency_id', $this->agency->id)->select('key')->groupBy('key')->havingRaw('count(*) > 1')->count();
        $this->assertSame(0, $dupes, 'mapped, not duplicated');
        $this->assertSame(4, (int) RtsParkReason::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'wrong_number')->value('step'), 'an existing key keeps its code and gets a step');

        foreach ([1, 2, 4, 5, 7, 8, 9] as $step) {
            $list = $this->svc()->forStep($this->agency->id, 'sales', $step);
            $this->assertTrue((bool) $list->last()->is_other, "step $step ends with Other");
            $this->assertSame(1, $list->where('is_other', true)->count());
        }
        $this->assertSame($this->svc()->forStep($this->agency->id, 'sales', 2)->pluck('key')->all(), $this->svc()->forStep($this->agency->id, 'sales', 3)->pluck('key')->all(), 'Pitch shows the Owner list');
        $this->assertSame($this->svc()->forStep($this->agency->id, 'sales', 7)->pluck('key')->all(), $this->svc()->forStep($this->agency->id, 'sales', 6)->pluck('key')->all(), 'Presentation shows the results list');
    }

    public function test_the_defaults_per_step_are_the_rulings(): void
    {
        $keys = fn (int $s) => $this->svc()->forStep($this->agency->id, 'sales', $s)->pluck('key')->all();
        foreach (['no_owner_found', 'no_contact_details'] as $k) { $this->assertContains($k, $keys(2)); }
        foreach (['opted_out', 'cannot_be_reached', 'not_selling_now', 'mandate_elsewhere', 'sold_elsewhere', 'off_market'] as $k) { $this->assertContains($k, $keys(4)); }
        $this->assertContains('seller_cannot_be_met', $keys(5));
        foreach (['price_unrealistic', 'wont_sign_documents', 'pres_not_selling', 'chose_other_agency'] as $k) { $this->assertContains($k, $keys(7)); }
        foreach (['paperwork_not_returned', 'seller_no_longer_selling'] as $k) { $this->assertContains($k, $keys(8)); }
        foreach (['active_paperwork_not_returned', 'active_no_longer_selling'] as $k) { $this->assertContains($k, $keys(9)); }
    }

    public function test_a_row_the_agency_already_edited_is_left_alone_by_the_seed(): void
    {
        $this->svc()->ensureStepReasons($this->agency->id);
        RtsParkReason::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'opted_out')->update(['label' => 'They said no', 'counts_as' => 'lost']);
        RtsParkReason::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'off_market')->first()->delete();
        ParkReasonService::forgetMemo();
        $this->svc()->ensureStepReasons($this->agency->id);

        $this->assertSame('They said no', RtsParkReason::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'opted_out')->value('label'));
        $this->assertNotContains('off_market', $this->svc()->forStep($this->agency->id, 'sales', 4)->pluck('key')->all(), 'an archived default stays archived');
    }

    // ── an off-ramp at every step, from both entry points ────────────────

    public function test_every_step_can_be_stopped_from_both_entry_points_and_it_is_counted_at_the_station_it_sat_at(): void
    {
        $stageOfStep = [1 => S::FOUND, 2 => S::CLAIMED, 3 => S::OWNER_FOUND, 4 => S::OWNER_FOUND, 5 => S::CONTACTED, 6 => S::APPOINTMENT_SET, 7 => S::PRESENTATION_PREPARED, 8 => S::EVALUATION_DONE, 9 => S::DRAFT];
        foreach ($stageOfStep as $step => $stage) {
            foreach (['row', 'mic'] as $entry) {
                [$p, $claim, $listing] = $this->record($stage);
                $reason = $this->svc()->forStep($this->agency->id, 'sales', $step)->firstWhere('is_other', false);
                $this->assertNotNull($reason, "step $step has a reason");

                $payload = ['step' => $step, 'reason' => $reason->key] + ($entry === 'row' ? ['prospect' => $p->id] : ['listing' => $listing]);
                $this->actingAs($this->agent)->postJson(route('seller-outreach.entry.pitch-offramp.store'), $payload)->assertOk()->assertJson(['ok' => true]);

                $p->refresh();
                $this->assertTrue($p->parked_at !== null || $p->closed_at !== null, "step $step via $entry: the record left the belt");
                $this->assertFalse((bool) $claim->fresh()->is_active, "step $step via $entry: the claim is closed");
                $ev = DB::table('rts_prospect_events')->where('prospect_id', $p->id)->whereIn('kind', ['park', 'close'])->orderByDesc('id')->first();
                $this->assertNotNull($ev);
                if ($ev->kind === 'park') {
                    $this->assertSame((string) $reason->label, (string) $ev->note, 'the history line carries the reason');
                    $this->assertSame($step, (int) (json_decode((string) $ev->meta, true)['step'] ?? 0), 'and the step it was said at');
                }
            }
        }
    }

    public function test_the_funnel_counts_each_reason_at_the_station_the_record_was_at(): void
    {
        foreach ([S::CLAIMED, S::CLAIMED, S::CONTACTED] as $i => $stage) {
            [$p] = $this->record($stage, 10 + $i);
            $step = $stage === S::CLAIMED ? 2 : 5;
            $key = $stage === S::CLAIMED ? 'no_owner_found' : 'seller_cannot_be_met';
            $this->actingAs($this->agent)->postJson(route('seller-outreach.entry.pitch-offramp.store'), ['prospect' => $p->id, 'step' => $step, 'reason' => $key])->assertOk();
        }
        $f = app(BeltFunnelService::class);
        $stations = collect($f->funnelOf([$this->agent->id], $f->figures($this->agency->id, $this->period()))['stations']);

        $this->assertSame(2, $stations->firstWhere('key', 'claimed')['reasons']['No owner found'] ?? 0);
        $this->assertSame(1, $stations->firstWhere('key', 'contacted')['reasons']['Seller cannot be met'] ?? 0);
    }

    // ── Other, behaviour, ownership ──────────────────────────────────────

    public function test_other_needs_a_few_words_and_keeps_them(): void
    {
        [$p] = $this->record(S::CONTACTED);
        $url = route('seller-outreach.entry.pitch-offramp.store');

        $this->actingAs($this->agent)->postJson($url, ['prospect' => $p->id, 'step' => 4, 'reason' => 'other_step_4'])->assertStatus(422);
        $this->actingAs($this->agent)->postJson($url, ['prospect' => $p->id, 'step' => 4, 'reason' => 'other_step_4', 'note' => 'ab'])->assertStatus(422);
        $this->assertNull($p->fresh()->parked_at, 'nothing changed without the words');

        $this->actingAs($this->agent)->postJson($url, ['prospect' => $p->id, 'step' => 4, 'reason' => 'other_step_4', 'note' => 'Emigrating next week'])->assertOk();
        $this->assertSame('Emigrating next week', $p->fresh()->park_note);
        $this->assertSame('Other', $p->fresh()->park_reason_label, 'the bar in the funnel is "Other", the words ride on the record');
    }

    public function test_a_reason_from_another_steps_list_is_refused_and_an_unknown_one_too(): void
    {
        [$p] = $this->record(S::CONTACTED);
        $url = route('seller-outreach.entry.pitch-offramp.store');

        $this->actingAs($this->agent)->postJson($url, ['prospect' => $p->id, 'step' => 4, 'reason' => 'wont_sign_documents'])->assertStatus(422);   // a step 7 reason
        $this->actingAs($this->agent)->postJson($url, ['prospect' => $p->id, 'step' => 4, 'reason' => 'nonsense'])->assertStatus(422);
        $this->assertNull($p->fresh()->parked_at);
    }

    public function test_it_behaves_like_not_selling_and_a_never_reason_closes_the_record(): void
    {
        [$p, $claim] = $this->record(S::CONTACTED);
        $this->actingAs($this->agent)->postJson(route('seller-outreach.entry.pitch-offramp.store'), ['prospect' => $p->id, 'step' => 4, 'reason' => 'not_selling_now'])->assertOk();
        $this->assertNotNull($p->fresh()->parked_at);
        $this->assertSame(ProspectingClaim::STATUS_NOT_INTERESTED, $claim->fresh()->status, 'the claim is closed as Not selling closes it');

        [$q] = $this->record(S::CONTACTED, 777);
        $this->actingAs($this->agent)->postJson(route('seller-outreach.entry.pitch-offramp.store'), ['prospect' => $q->id, 'step' => 4, 'reason' => 'opted_out'])->assertOk();
        $this->assertNotNull($q->fresh()->closed_at, 'opted out = never contacted again: the record is closed');
    }

    public function test_lost_to_another_agency_counts_as_lost(): void
    {
        [$p, $claim] = $this->record(S::EVALUATION_DONE);
        $this->actingAs($this->agent)->postJson(route('seller-outreach.entry.pitch-offramp.store'), ['prospect' => $p->id, 'step' => 7, 'reason' => 'chose_other_agency'])->assertOk();
        $this->assertSame(ProspectingClaim::STATUS_LOST, $claim->fresh()->status);
    }

    public function test_only_the_agents_own_open_record_can_be_stopped_and_only_once(): void
    {
        [$mine] = $this->record(S::CLAIMED, 21);
        $theirs = RtsProspect::withoutGlobalScopes()->findOrFail(
            ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listing([], $this->tp(['street_name' => 'Theirs', 'street_number' => '5'])), 'user_id' => $this->mate->id, 'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()])->refresh()->rts_prospect_id
        );
        $url = route('seller-outreach.entry.pitch-offramp.store');

        $this->actingAs($this->agent)->postJson($url, ['prospect' => $theirs->id, 'step' => 2, 'reason' => 'no_owner_found'])->assertStatus(422);
        $this->assertNull($theirs->fresh()->parked_at, "a colleague's record is not the agent's to stop");

        $this->actingAs($this->agent)->postJson($url, ['prospect' => $mine->id, 'step' => 2, 'reason' => 'no_owner_found'])->assertOk();
        $this->actingAs($this->agent)->postJson($url, ['prospect' => $mine->id, 'step' => 2, 'reason' => 'no_owner_found'])->assertStatus(422);
    }

    public function test_the_choices_answer_names_the_steps_reasons_and_says_so_when_there_is_no_record(): void
    {
        [$p] = $this->record(S::CONTACTED);
        $d = $this->actingAs($this->agent)->getJson(route('seller-outreach.entry.pitch-offramp', ['prospect' => $p->id, 'step' => 4]))->assertOk()->json();
        $this->assertSame('other_step_4', collect($d['reasons'])->last()['key']);
        $this->assertTrue((bool) collect($d['reasons'])->last()['other']);
        $this->assertContains('opted_out', array_column($d['reasons'], 'key'));

        $none = $this->actingAs($this->agent)->getJson(route('seller-outreach.entry.pitch-offramp', ['tracked' => $this->tp(['street_name' => 'Nobody', 'street_number' => '9'])->id, 'step' => 1]))->assertOk()->json();
        $this->assertNull($none['record']);
        $this->assertSame([], $none['reasons']);
    }

    public function test_both_entry_points_load_the_one_control_and_the_popup_exposes_its_state(): void
    {
        $partial = (string) file_get_contents(resource_path('views/layouts/partials/_pitch-popup.blade.php'));
        $this->assertStringContainsString('pitch-offramp.js', $partial);
        $this->assertStringContainsString("seller-outreach.entry.pitch-offramp", $partial);
        $js = (string) file_get_contents(public_path('js/pitch-offramp.js'));
        $this->assertStringContainsString('Stops here (say why)', $js);
        $this->assertStringContainsString("data-qa=\"offramp\"", str_replace("'data-qa': 'offramp'", 'data-qa="offramp"', $js));
        $this->assertSame(1, substr_count($js, "'data-qa': 'offramp'"), 'one control, written once');
    }

    // ── the agency's list screen ─────────────────────────────────────────

    private function admin()
    {
        foreach (['access_road_to_sold', 'road_to_sold.manage_offramp_reasons'] as $k) {
            RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => $k, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();

        return \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
    }

    public function test_the_list_screen_searches_filters_edits_archives_and_restores_but_never_removes_other(): void
    {
        $admin = $this->admin();
        $page = $this->actingAs($admin)->get(route('road-to-sold.settings.offramps.index'))->assertOk();
        $page->assertSee('data-qa="offramp-row"', false)->assertSee('opted_out');
        $this->actingAs($admin)->get(route('road-to-sold.settings.offramps.index', ['q' => 'opted']))->assertOk()->assertSee('opted_out')->assertDontSee('wont_sign_documents');
        $this->actingAs($admin)->get(route('road-to-sold.settings.offramps.index', ['step' => 7, 'sort' => 'label']))->assertOk()->assertSee('wont_sign_documents')->assertDontSee('data-key="opted_out"', false);

        $row = RtsParkReason::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'opted_out')->firstOrFail();
        $this->actingAs($admin)->put(route('road-to-sold.settings.offramps.update', $row->id), ['label' => 'They said no', 'step' => 4, 'counts_as' => 'lost', 'revisit_days' => 10])->assertSessionHasNoErrors();
        $this->assertSame('They said no', $row->fresh()->label);
        $this->assertSame('lost', $row->fresh()->counts_as);

        $this->actingAs($admin)->delete(route('road-to-sold.settings.offramps.archive', $row->id))->assertSessionHas('status');
        $this->assertNotContains('opted_out', $this->svc()->forStep($this->agency->id, 'sales', 4)->pluck('key')->all());
        $this->actingAs($admin)->post(route('road-to-sold.settings.offramps.restore', $row->id))->assertSessionHas('status');
        ParkReasonService::forgetMemo();
        $this->assertContains('opted_out', $this->svc()->forStep($this->agency->id, 'sales', 4)->pluck('key')->all());

        $other = RtsParkReason::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'other_step_4')->firstOrFail();
        $this->actingAs($admin)->delete(route('road-to-sold.settings.offramps.archive', $other->id))->assertStatus(422);
        $this->actingAs($admin)->post(route('road-to-sold.settings.offramps.toggle', $other->id))->assertStatus(422);
        $this->actingAs($admin)->put(route('road-to-sold.settings.offramps.update', $other->id), ['label' => 'Something else', 'step' => 9, 'counts_as' => 'not_selling', 'revisit_days' => 30]);
        $this->assertSame(4, (int) $other->fresh()->step, '"Other" stays on its own step');
        $this->assertNull($other->fresh()->deleted_at);
    }

    public function test_a_reason_the_agency_adds_shows_in_the_popup_list_before_other(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('road-to-sold.settings.offramps.store'), ['label' => 'Seller emigrating', 'step' => 4, 'counts_as' => 'not_selling', 'revisit_days' => 90])->assertSessionHasNoErrors();
        $keys = $this->svc()->forStep($this->agency->id, 'sales', 4);

        $this->assertNotNull($keys->firstWhere('label', 'Seller emigrating'));
        $this->assertTrue((bool) $keys->last()->is_other, 'Other is still last');
        $this->actingAs($admin)->post(route('road-to-sold.settings.offramps.store'), ['label' => '', 'step' => 4, 'counts_as' => 'lost'])->assertSessionHasErrors('label');
    }

    public function test_an_agent_without_the_permission_cannot_open_the_list(): void
    {
        $this->actingAs($this->agent)->get(route('road-to-sold.settings.offramps.index'))->assertForbidden();
    }
}
