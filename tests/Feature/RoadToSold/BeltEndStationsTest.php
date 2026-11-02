<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Events\Property\PropertyCompliancePassed;
use App\Events\Property\PropertyPublished;
use App\Events\Property\PropertyWentLiveOnPortal;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Contact;
use App\Models\DailyActivityEntry;
use App\Models\Property;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Activity\ActivityPointsService;
use App\Services\CommandCenter\Calendar\CalendarEventCreator;
use App\Services\RoadToSold\BeltPoints;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\ResultOptionService;
use App\Services\RoadToSold\ResultService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v4, belt step A7 (road-to-sold.md 23.4 / 23.16 / 23.7a): stations 9-11 follow the property (Draft, Active, Advertised), the two credits that wait for them
 * (evaluation feedback, advertised) and the logged off-ramp credit, and the contact list that shows only what fits the record.
 */
final class BeltEndStationsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        ResultOptionService::forgetMemo();
        ParkReasonService::forgetMemo();
        ActivityPointsService::forgetCutovers();
        Cache::flush();
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0:Property,1:RtsProspect} a prospecting property on a file, the agent's open record at $stage */
    private function belt(string $stage = S::MANDATE_SIGNED, array $property = []): array
    {
        $tp = $this->tp(['street_name' => 'Bairn Street', 'street_number' => (string) random_int(1, 900)]);
        $prop = Property::withoutGlobalScopes()->create($property + ['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'status' => 'prospecting',
            'title' => '12 Bairn Street', 'listing_type' => 'sale', 'property_type' => 'house', 'address' => '12 Bairn Street', 'suburb' => 'Margate']);
        DB::table('properties')->where('id', $prop->id)->update(['tracked_property_id' => $tp->id]);
        $p = app(ProspectService::class)->place($this->agent, (int) $tp->id, 'sales', ['property_id' => $prop->id, 'stage' => $stage]);

        return [$prop->refresh(), $p];
    }

    private function stage(RtsProspect $p): string
    {
        return (string) $p->fresh()->stage;
    }

    private function earned(string $slug, ?User $who = null): float
    {
        $defId = DB::table('activity_definition_calendar_classes')->where('agency_id', $this->agency->id)->where('slug', $slug)->value('activity_definition_id');
        if (! $defId) {
            return 0.0;
        }
        $n = (float) DailyActivityEntry::query()->withoutGlobalScopes()->where('user_id', ($who ?? $this->agent)->id)->where('activity_definition_id', $defId)
            ->whereIn('point_state', DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)->sum('value');

        return $n * BeltPoints::defaultPoints($slug);
    }

    // ── station 9: Draft ─────────────────────────────────────────────────

    public function test_a_property_that_becomes_a_draft_takes_the_record_to_draft(): void
    {
        [$prop, $p] = $this->belt(S::MANDATE_SIGNED);

        $prop = Property::withoutGlobalScopes()->find($prop->id);   // a loaded property, as the property page's button has it
        $prop->status = 'draft';
        $prop->save();

        $this->assertSame(S::DRAFT, $this->stage($p));
    }

    public function test_the_won_result_flows_all_the_way_to_draft(): void
    {
        [$prop, $p] = $this->belt(S::EVALUATION_DONE);

        app(ResultService::class)->record($p->fresh(), 'won_mandate', null, null, $this->agent, 'belt_card');

        $this->assertSame('draft', $prop->fresh()->status);
        $this->assertSame(S::DRAFT, $this->stage($p), 'Won -> the property is a Draft -> the record is at Draft');
    }

    public function test_a_property_nobody_works_on_the_belt_creates_no_record_and_another_agents_record_is_left_alone(): void
    {
        $prop = Property::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'status' => 'prospecting',
            'title' => 'x', 'listing_type' => 'sale', 'property_type' => 'house', 'address' => 'x', 'suburb' => 'x']);
        $prop->status = 'draft';
        $prop->save();
        $this->assertSame(0, RtsProspect::withoutGlobalScopes()->where('property_id', $prop->id)->count(), 'a belt record is never created by a status change');

        [$prop2, $mates] = $this->belt(S::MANDATE_SIGNED);
        $mates->forceFill(['user_id' => $this->mate->id])->save();
        DB::table('properties')->where('id', $prop2->id)->update(['agent_id' => $this->agent->id]);
        $prop2 = Property::withoutGlobalScopes()->find($prop2->id);
        $prop2->status = 'draft';
        $prop2->save();
        $this->assertSame(S::DRAFT, $this->stage($mates), 'the record that names this property moves, whoever holds it');
    }

    // ── station 10: Active ───────────────────────────────────────────────

    public function test_active_needs_compliance_passed_and_published_in_either_order(): void
    {
        [$a, $pa] = $this->belt(S::DRAFT, ['status' => 'draft']);
        [$b, $pb] = $this->belt(S::DRAFT, ['status' => 'draft']);

        // compliance first, publish second
        $a->forceFill(['compliance_snapshot_at' => now()])->saveQuietly();
        event(new PropertyCompliancePassed(property: $a, actorUserId: $this->agent->id));
        $this->assertSame(S::DRAFT, $this->stage($pa), 'compliance alone is not Active');
        $a->forceFill(['published_at' => now()])->saveQuietly();
        event(new PropertyPublished(property: $a, actorUserId: $this->agent->id));
        $this->assertSame(S::ACTIVE, $this->stage($pa));

        // publish first, compliance second
        $b->forceFill(['published_at' => now()])->saveQuietly();
        event(new PropertyPublished(property: $b, actorUserId: $this->agent->id));
        $this->assertSame(S::DRAFT, $this->stage($pb), 'published alone is not Active');
        $b->forceFill(['compliance_snapshot_at' => now()])->saveQuietly();
        event(new PropertyCompliancePassed(property: $b, actorUserId: $this->agent->id));
        $this->assertSame(S::ACTIVE, $this->stage($pb));
    }

    // ── station 11: Advertised and its credit ────────────────────────────

    public function test_going_live_on_a_portal_advertises_the_record_and_pays_once(): void
    {
        [$prop, $p] = $this->belt(S::ACTIVE, ['status' => 'active', 'published_at' => now(), 'compliance_snapshot_at' => now()]);

        event(new PropertyWentLiveOnPortal(property: $prop, portal: 'p24', actorUserId: $this->agent->id));
        event(new PropertyWentLiveOnPortal(property: $prop, portal: 'pp', actorUserId: $this->agent->id));   // the second portal

        $this->assertSame(S::LISTING_LIVE, $this->stage($p));
        $this->assertSame(50.0, $this->earned('rts.advertised'), 'Advertised pays 50 once per property, however many portals');
    }

    public function test_the_advertised_credit_is_the_agencys_own_amount(): void
    {
        app(BeltPoints::class)->setAmount($this->agency->id, 'rts.advertised', 80);
        [$prop] = $this->belt(S::ACTIVE, ['status' => 'active']);

        event(new PropertyWentLiveOnPortal(property: $prop, portal: 'p24', actorUserId: $this->agent->id));

        $this->assertSame(80, app(BeltPoints::class)->amount($this->agency->id, 'rts.advertised'));
        $this->assertSame(1, DailyActivityEntry::query()->withoutGlobalScopes()->where('user_id', $this->agent->id)->where('subject_type', RtsProspect::class)->count());
    }

    // ── the other credits ────────────────────────────────────────────────

    private function evaluation(Property $prop, array $kind): CalendarEventFeedback
    {
        $this->actingAs($this->agent);
        $event = app(CalendarEventCreator::class)->create(['title' => 'Evaluation', 'category' => 'property_evaluation', 'event_date' => '2026-11-03 10:00:00', 'property_id' => $prop->id], $this->agent)->refresh();

        return CalendarEventFeedback::withoutGlobalScopes()->create([
            'calendar_event_id' => $event->id, 'property_id' => $prop->id, 'feedback_kind' => 'listing_presentation', 'visibility' => 'internal_only',
            'kind_specific_data' => $kind, 'captured_by_user_id' => $this->agent->id, 'captured_at' => now(), 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ]);
    }

    public function test_feedback_on_a_held_evaluation_pays_50_once_and_a_no_show_pays_nothing(): void
    {
        [$prop] = $this->belt(S::APPOINTMENT_SET);
        $fb = $this->evaluation($prop, ['eval_status' => 'held', 'outcome' => 'Considering']);
        $this->assertSame(50.0, $this->earned('rts.evaluation_feedback'));

        $fb->update(['internal_notes' => 'edited']);   // a re-save
        $this->assertSame(50.0, $this->earned('rts.evaluation_feedback'), 'a re-save never pays twice');

        [$prop2] = $this->belt(S::APPOINTMENT_SET);
        $before = $this->earned('rts.evaluation_feedback');
        $this->evaluation($prop2, ['eval_status' => 'no_show']);
        $this->assertSame($before, $this->earned('rts.evaluation_feedback'), 'a no-show is not feedback on a held evaluation');
    }

    public function test_a_dead_end_or_a_not_now_result_pays_the_logged_off_ramp_credit_and_a_win_does_not(): void
    {
        [, $a] = $this->belt(S::EVALUATION_DONE);
        [, $b] = $this->belt(S::EVALUATION_DONE);
        [, $c] = $this->belt(S::EVALUATION_DONE);

        app(ResultService::class)->record($a->fresh(), 'won_mandate', null, null, $this->agent, 'belt_card');
        $this->assertSame(0.0, $this->earned('rts.offramp_logged'));

        app(ResultService::class)->record($b->fresh(), 'lost_price', null, null, $this->agent, 'belt_card');
        $this->assertSame(20.0, $this->earned('rts.offramp_logged'), 'work done: the dead end earns, it is not punished');

        app(ResultService::class)->record($c->fresh(), 'undecided', null, null, $this->agent, 'belt_card');
        $this->assertSame(40.0, $this->earned('rts.offramp_logged'));

        app(ResultService::class)->record($b->fresh(), 'lost_price', null, null, $this->agent, 'belt_card');   // the same answer again today
        $this->assertSame(40.0, $this->earned('rts.offramp_logged'));
    }

    public function test_the_two_credits_are_marked_live_on_the_points_settings(): void
    {
        $this->assertTrue(BeltPoints::CREDITS['rts.evaluation_feedback'][4]);
        $this->assertTrue(BeltPoints::CREDITS['rts.advertised'][4]);
    }

    // ── the contact list fits the record ─────────────────────────────────

    private function keys($defs): array
    {
        return $defs->pluck('key')->all();
    }

    public function test_a_record_with_no_owner_can_only_report_the_owner_problem(): void
    {
        config(['road-to-sold-belt.enforce_owner_gate' => true]);
        $svc = app(OutcomeDefinitionService::class);

        $keys = $this->keys($svc->fitting($this->agency->id, 'sales', S::CLAIMED, false));

        $this->assertEqualsCanonicalizing(['no_owner_found', 'wrong_number', 'do_not_contact'], $keys, 'anything else is refused until the owner is linked');
    }

    public function test_once_the_owner_is_linked_no_owner_found_and_wrong_number_are_gone(): void
    {
        $svc = app(OutcomeDefinitionService::class);

        $keys = $this->keys($svc->fitting($this->agency->id, 'sales', S::OWNER_FOUND, true));

        $this->assertNotContains('no_owner_found', $keys);
        $this->assertNotContains('wrong_number', $keys);
        $this->assertContains('no_answer', $keys);
        $this->assertContains('spoke_evaluation_booked', $keys);
        $this->assertContains('do_not_contact', $keys, 'do not contact is always possible');
        $this->assertNotContains('eval_undecided', $keys, 'the after-the-evaluation answers wait for an appointment');
        $after = $this->keys($svc->fitting($this->agency->id, 'sales', S::APPOINTMENT_SET, true));
        $this->assertContains('eval_undecided', $after);
    }

    public function test_the_log_contact_page_offers_only_what_fits_and_uses_plain_words(): void
    {
        config(['road-to-sold-belt.enforce_owner_gate' => true, 'road-to-sold-belt.require_channel' => true]);
        $owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345']);
        $tp = $this->tp(['street_name' => 'Marine Drive', 'street_number' => '14', 'owner_contact_id' => $owner->id, 'deeds_captured_at' => now()]);
        \App\Models\RoadToSold\RtsListItem::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $this->agent->id, 'list_date' => '2026-10-30', 'track' => 'sales',
            'tracked_property_id' => $tp->id, 'selection' => 'price_drops', 'origin' => 'auto', 'position' => 1, 'status' => 'open', 'reason' => 'Price dropped',
        ]);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id], ['scope' => 'own']);
        \App\Services\PermissionService::clearCache();

        $res = $this->actingAs($this->agent)->get(route('road-to-sold.index'))->assertOk();
        $html = $res->getContent();

        $fit = array_map('strval', $res->viewData('outcomeFit')[$tp->id] ?? []);
        $ids = Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->whereIn('key', ['no_owner_found', 'wrong_number'])->pluck('id')->map(fn ($v) => (string) $v)->all();
        $this->assertNotEmpty($fit, 'the page carries the list that fits this record');
        $this->assertEmpty(array_intersect($ids, $fit), 'the owner is linked, so the owner-problem outcomes are not offered');
        $this->assertStringNotContainsString("'conversation' ? 'conversation' : 'attempt'", $html, 'plain words only: no attempt / conversation labels');
    }

    public function test_the_phone_call_list_uses_the_same_rule(): void
    {
        config(['road-to-sold-belt.enforce_owner_gate' => true, 'road-to-sold-belt.require_channel' => true]);
        [$prop, $p] = $this->belt(S::CLAIMED);   // no owner on the file
        $contact = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345']);
        $this->actingAs($this->agent);

        $form = app(\App\Services\SellerOutreach\PitchContactService::class)->phoneForm($this->agent, $contact, $prop);
        $labels = collect($form['groups'])->flatMap(fn ($g) => $g['items'])->pluck('label')->all();

        $this->assertEqualsCanonicalizing(['No owner found', 'Wrong number', 'Do not contact'], $labels);
    }
}
