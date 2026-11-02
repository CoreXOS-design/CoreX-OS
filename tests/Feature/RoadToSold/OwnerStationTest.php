<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Contact;
use App\Models\ProspectingClaim;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsSetting;
use App\Services\RoadToSold\ClaimHoldService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\OwnerStationService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages;
use App\Services\RoadToSold\RoadToSoldException;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v4, belt step A2 (road-to-sold.md 23.6): Owner found = the owner contact linked AND a deed record (agency setting); the gate
 * (no contact outcome, MIC feedback or send until then); the back-fill; the Extend hold action on the MIC row.
 */
final class OwnerStationTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        config(['road-to-sold-belt.enforce_owner_gate' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function claimed(?\App\Models\User $agent = null): array
    {
        $agent ??= $this->agent;
        $tp = $this->tp(['street_name' => 'Bairn Street', 'street_number' => (string) random_int(1, 999)]);
        $listing = $this->listing([], $tp);
        $claim = ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $listing, 'user_id' => $agent->id,
            'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()])->refresh();

        return [$tp, $claim, $listing];
    }

    private function stageOf(TrackedProperty $tp): string
    {
        return (string) app(ProspectService::class)->find($this->agency->id, (int) $tp->id)->stage;
    }

    private function owner(): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345',
        ]);
    }

    private function def(string $key): Def
    {
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);

        return Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', $key)->firstOrFail();
    }

    public function test_owner_and_deed_together_move_the_claim_to_owner_found_in_either_order(): void
    {
        [$a] = $this->claimed();
        [$b] = $this->claimed();
        $this->assertSame(ProspectStages::CLAIMED, $this->stageOf($a));

        // owner first, deed later
        $a->update(['owner_contact_id' => $this->owner()->id]);
        $this->assertSame(ProspectStages::CLAIMED, $this->stageOf($a), 'an owner without a deed is not enough while the agency needs the deed');
        $a->update(['deeds_captured_at' => now()]);
        app(OwnerStationService::class)->evaluate($this->agency->id, (int) $a->id);   // the deeds capture raises TrackedPropertyEnriched, which does this
        $this->assertSame(ProspectStages::OWNER_FOUND, $this->stageOf($a));

        // deed first, owner later (the observer raises OwnerLinkedToTrackedProperty)
        $b->update(['deeds_captured_at' => now()]);
        $this->assertSame(ProspectStages::CLAIMED, $this->stageOf($b));
        $b->update(['owner_contact_id' => $this->owner()->id]);
        $this->assertSame(ProspectStages::OWNER_FOUND, $this->stageOf($b));
    }

    public function test_a_cma_info_capture_counts_as_the_deed_record(): void
    {
        [$tp] = $this->claimed();
        $tp->update(['owner_contact_id' => $this->owner()->id]);
        \Illuminate\Support\Facades\DB::table('tracked_property_external_refs')->insert([
            'agency_id' => $this->agency->id, 'tracked_property_id' => $tp->id, 'source_type' => 'cmainfo', 'source_ref' => 'CMA-1',
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue(app(OwnerStationService::class)->hasDeedRecord($this->agency->id, (int) $tp->id));
        $this->assertSame(1, app(OwnerStationService::class)->evaluate($this->agency->id, (int) $tp->id));
    }

    public function test_the_agency_can_say_the_owner_contact_alone_is_enough(): void
    {
        RtsSetting::query()->withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], ['owner_requires_deed' => false]);
        SettingsService::forget();
        [$tp] = $this->claimed();

        $tp->update(['owner_contact_id' => $this->owner()->id]);

        $this->assertSame(ProspectStages::OWNER_FOUND, $this->stageOf($tp));
    }

    public function test_an_outcome_is_refused_until_the_owner_is_found_but_the_owner_problem_outcomes_always_go_through(): void
    {
        [$tp] = $this->claimed();
        $this->actingAs($this->agent);
        $svc = app(OutcomeService::class);

        try {
            $svc->record($this->agent, (int) $tp->id, $this->def('no_answer')->id);
            $this->fail('the call should have been refused');
        } catch (RoadToSoldException $e) {
            $this->assertSame('Find the owner first, then contact them.', $e->getMessage());
        }
        $this->assertSame(0, \App\Models\RoadToSold\RtsContactOutcome::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->count());

        // reporting that the owner cannot be found, or must not be contacted, is always allowed
        $svc->record($this->agent, (int) $tp->id, $this->def('no_owner_found')->id);
        $svc->record($this->agent, (int) $tp->id, $this->def('wrong_number')->id);
        $svc->record($this->agent, (int) $tp->id, $this->def('do_not_contact')->id);
        $this->assertSame(3, \App\Models\RoadToSold\RtsContactOutcome::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->count());
    }

    public function test_once_the_owner_is_found_the_outcome_is_recorded(): void
    {
        [$tp] = $this->claimed();
        $tp->update(['owner_contact_id' => $this->owner()->id, 'deeds_captured_at' => now()]);
        $this->actingAs($this->agent);

        $o = app(OutcomeService::class)->record($this->agent, (int) $tp->id, $this->def('spoke_call_back')->id, ['next_action_at' => '2026-11-04']);

        $this->assertNotNull($o->id);
        $this->assertSame(ProspectStages::CONTACTED, $this->stageOf($tp));
    }

    public function test_the_gate_is_a_fact_of_the_property_not_of_the_stage_so_linking_the_owner_opens_it_at_once(): void
    {
        [$tp] = $this->claimed();
        $gate = app(OwnerStationService::class);
        $this->assertSame(OwnerStationService::MESSAGE, $gate->refusal($this->agent, (int) $tp->id, $this->def('no_answer')));
        $this->assertSame(['the owner contact', 'the deed record'], $gate->missing($this->agency->id, (int) $tp->id));

        $tp->update(['owner_contact_id' => $this->owner()->id, 'deeds_captured_at' => now()]);

        $this->assertNull($gate->refusal($this->agent, (int) $tp->id, $this->def('no_answer')));
        $this->assertSame([], $gate->missing($this->agency->id, (int) $tp->id));
    }

    public function test_mic_feedback_on_a_claim_on_the_belt_is_refused_without_an_owner_but_lost_goes_through(): void
    {
        [$tp, $claim, $listing] = $this->claimed();

        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $listing), ['status' => 'contacted'])
            ->assertSessionHas('error', 'Find the owner first, then contact them.');
        $this->assertSame(ProspectingClaim::STATUS_CLAIMED, $claim->fresh()->status);

        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $listing), ['status' => 'lost', 'notes' => 'wrong contact'])
            ->assertSessionHas('success');
        $this->assertFalse((bool) $claim->fresh()->is_active);
    }

    public function test_a_colleagues_property_and_a_property_off_the_belt_follow_the_same_rule_of_the_file(): void
    {
        [$tp] = $this->claimed($this->mate);
        // the file has no owner: nobody may contact it, whoever holds it
        $this->assertSame(OwnerStationService::MESSAGE, app(OwnerStationService::class)->refusal($this->agent, (int) $tp->id));
        $tp->update(['owner_contact_id' => $this->owner()->id, 'deeds_captured_at' => now()]);
        $this->assertNull(app(OwnerStationService::class)->refusal($this->agent, (int) $tp->id));
    }

    public function test_the_agent_extends_the_hold_once_from_the_row_and_the_row_shows_the_days_left(): void
    {
        [$tp, $claim] = $this->claimed();
        $claim->refresh();
        $was = $claim->hold_until->copy();

        // the MIC row data carries the viewer's own hold
        $this->actingAs($this->agent);
        $row = app(\App\Services\Prospecting\MicWork\MicWorkList::class)->forFiles([(int) $tp->id], $this->agency->id)[0];
        $this->assertSame((int) $claim->id, $row['hold']['claim_id']);
        $this->assertSame(30, $row['hold']['days_left']);
        $this->assertTrue($row['hold']['can_extend']);
        $this->assertTrue($row['hold']['find_owner']);
        $this->assertSame('the owner contact and the deed record', $row['hold']['missing']);

        $this->post(route('market-intelligence.claims.extend-hold', $claim->id))->assertSessionHas('success');
        $this->assertTrue($claim->fresh()->hold_until->gt($was));
        $this->assertSame(1, (int) $claim->fresh()->hold_extensions);

        $this->post(route('market-intelligence.claims.extend-hold', $claim->id))->assertSessionHas('error');
        $this->assertSame(1, (int) $claim->fresh()->hold_extensions);

        // a colleague who is not a manager cannot extend someone else's hold (a role with grants of its own, so "no grants at all" does not permit everything)
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        Cache::flush();
        \App\Services\PermissionService::clearCache();
        $this->actingAs($this->mate)->post(route('market-intelligence.claims.extend-hold', $claim->id));
        $this->assertSame(1, (int) $claim->fresh()->hold_extensions, 'a colleague did not extend it');
    }

    public function test_a_manager_may_grant_more_and_the_history_says_so(): void
    {
        [, $claim] = $this->claimed();
        $manager = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true]);
        $hold = app(ClaimHoldService::class);
        $this->assertTrue($hold->extend($claim, $this->agent)['ok']);

        $this->assertTrue($hold->extend($claim->fresh(), $manager, true, 'a long sale')['ok']);

        $note = \App\Models\RoadToSold\RtsProspectEvent::withoutGlobalScopes()->where('prospect_id', $claim->rts_prospect_id)->where('kind', 'hold')->orderByDesc('id')->value('note');
        $this->assertStringContainsString('(manager)', (string) $note);
    }

    public function test_the_back_fill_moves_records_whose_owner_is_already_linked_and_can_be_undone(): void
    {
        [$ready] = $this->claimed();
        [$notReady] = $this->claimed();
        // the owner and deed were on the file BEFORE the belt existed: no event ever fired for them
        \Illuminate\Support\Facades\DB::table('tracked_properties')->where('id', $ready->id)->update(['owner_contact_id' => $this->owner()->id, 'deeds_captured_at' => now()]);
        $dir = sys_get_temp_dir() . '/rts-owner-' . uniqid();
        $this->assertSame(ProspectStages::CLAIMED, $this->stageOf($ready));

        $this->artisan("rts:place-owner-stations --agency={$this->agency->id} --journal-dir={$dir}")->assertSuccessful();
        $this->assertSame(ProspectStages::CLAIMED, $this->stageOf($ready), 'a dry run writes nothing');

        $this->artisan("rts:place-owner-stations --agency={$this->agency->id} --apply --journal-dir={$dir}")->assertSuccessful();
        $this->assertSame(ProspectStages::OWNER_FOUND, $this->stageOf($ready));
        $this->assertSame(ProspectStages::CLAIMED, $this->stageOf($notReady));

        $journal = glob($dir . '/rts-place-owner-stations-agency' . $this->agency->id . '-*.jsonl');
        $this->assertCount(1, $journal);
        $this->artisan("rts:place-owner-stations --undo={$journal[0]}")->assertSuccessful();
        $this->assertSame(ProspectStages::CLAIMED, $this->stageOf($ready));
    }

    public function test_another_agencys_property_is_never_looked_at(): void
    {
        $b = \App\Models\Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]);
        $tpB = $this->tp(['street_name' => 'Other Street'], $b);

        $this->assertFalse(app(OwnerStationService::class)->ownerFound($this->agency->id, (int) $tpB->id));
        $this->assertSame(0, app(OwnerStationService::class)->evaluate($this->agency->id, (int) $tpB->id));
    }
}
