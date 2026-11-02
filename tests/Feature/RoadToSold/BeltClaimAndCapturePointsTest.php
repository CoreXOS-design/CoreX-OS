<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Events\Property\PropertyCaptured;
use App\Events\Prospecting\ClaimCreated;
use App\Listeners\Activity\CreditInstantActionListener;
use App\Models\ActivityDefinitionCalendarClass;
use App\Models\DailyActivityEntry;
use App\Models\Property;
use App\Models\ProspectingClaim;
use App\Models\User;
use App\Services\RoadToSold\BeltPoints;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\PitchPathService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v4 belt points (road-to-sold.md 23.16), the two corrections from cc2's finding:
 *   - a property the BELT makes for the agent from a MIC address (a row opened, Pitch now, an evaluation booked from a call) scores nothing;
 *     a property the agent captures themselves still scores property.captured;
 *   - the claim (station 2) pays mic.claim_taken once per prospect: a second claim row for the same agent and property pays nothing more.
 */
final class BeltClaimAndCapturePointsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        OutcomeDefinitionService::forgetMemo();
        SettingsService::forget();
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults((int) $this->agency->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_property_the_belt_makes_for_the_agent_scores_nothing_on_both_routes(): void
    {
        Event::fake([PropertyCaptured::class]);
        $prospects = app(ProspectService::class);
        $path = app(PitchPathService::class);

        // the advert (listing) route
        $tp1 = $this->tp(['street_number' => '12', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo']);
        $p1 = $prospects->place($this->agent, $tp1->id, 'sales', ['prospecting_listing_id' => $this->listing(['address' => '12 Marine Drive', 'suburb' => 'Uvongo'], $tp1)]);
        $this->assertNotNull($path->ensureProperty($p1, $this->agent));

        // the tracked-property route (no advert)
        $tp2 = $this->tp(['street_number' => '14', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo']);
        $p2 = $prospects->place($this->agent, $tp2->id, 'sales');
        $this->assertNotNull($path->ensureProperty($p2, $this->agent));

        $this->assertSame(2, Property::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count(), 'both properties were made');
        Event::assertNotDispatched(PropertyCaptured::class);
    }

    public function test_a_property_the_agent_captures_themselves_still_scores(): void
    {
        Event::fake([PropertyCaptured::class]);
        $this->capture();
        Event::assertDispatched(PropertyCaptured::class, fn ($e) => (int) $e->actorUserId === (int) $this->agent->id);

        // and the suppression is scoped: after a belt creation the next ordinary one scores again
        Property::withoutActivityPoints(fn () => $this->capture());
        Event::assertDispatchedTimes(PropertyCaptured::class, 1);
        $this->capture();
        Event::assertDispatchedTimes(PropertyCaptured::class, 2);
    }

    private function capture(): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'status' => Property::STATUS_PROSPECTING,
            'title' => 'T ' . uniqid(), 'address' => '1 Test Road', 'suburb' => 'Margate', 'price' => 0, 'beds' => 0, 'baths' => 0, 'garages' => 0,
            'property_type' => 'house', 'listing_type' => 'sale',
        ]);
    }

    public function test_the_claim_pays_once_per_prospect_and_a_claim_on_another_property_pays_again(): void
    {
        $defId = DB::table('activity_definitions')->insertGetId(['name' => '[Auto] mic.claim_taken', 'scope' => 'agency', 'agency_id' => $this->agency->id, 'weight' => 100, 'is_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('activity_definition_calendar_classes')->insert(['agency_id' => $this->agency->id, 'trigger_kind' => 'instant', 'slug' => 'mic.claim_taken', 'activity_definition_id' => $defId,
            'value_per_event' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $listener = app(CreditInstantActionListener::class);

        $tp = $this->tp(['street_number' => '5', 'street_name' => 'Bairn Street']);
        $make = fn (int $listingId, User $u) => ProspectingClaim::withoutEvents(fn () => ProspectingClaim::create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listingId, 'user_id' => $u->id, 'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now(),
        ]));
        $first = $make($this->listing([], $tp), $this->agent);
        $again = $make($this->listing([], $tp), $this->agent);   // the same property's other advert / the popup opening again
        $mate = $make($this->listing([], $tp), $this->mate);     // a different agent is a different prospect
        $other = $make($this->listing([]), $this->agent);        // another property

        // InstantPointService skips the testing environment; run the real one for this test only
        $env = app()->environment();
        app()->detectEnvironment(fn () => 'production');
        try {
            foreach ([$first, $again, $mate, $other] as $claim) {
                $listener->handleClaimCreated(new ClaimCreated($claim));
            }
        } finally {
            app()->detectEnvironment(fn () => $env);
        }

        $paid = DailyActivityEntry::query()->withoutGlobalScopes()->where('activity_definition_id', $defId)->orderBy('subject_id')->pluck('subject_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([(int) $first->id, (int) $mate->id, (int) $other->id], $paid, 'the second claim on the same property by the same agent paid nothing');
    }

    public function test_the_one_points_log_shows_the_claim_and_a_captured_property_with_their_addresses(): void
    {
        $prop = $this->capture()->id;
        DB::table('properties')->where('id', $prop)->update(['address' => '9 Capri Crescent', 'title' => '9 Capri Crescent']);
        $defId = DB::table('activity_definitions')->insertGetId(['name' => '[Auto] property.captured', 'scope' => 'agency', 'agency_id' => $this->agency->id, 'weight' => 50, 'is_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('activity_definition_calendar_classes')->insert(['agency_id' => $this->agency->id, 'trigger_kind' => 'instant', 'slug' => 'property.captured', 'activity_definition_id' => $defId,
            'value_per_event' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DailyActivityEntry::query()->withoutGlobalScopes()->create([
            'user_id' => $this->agent->id, 'activity_date' => '2026-10-14', 'period' => '2026-10', 'activity_definition_id' => $defId, 'subject_type' => Property::class, 'subject_id' => $prop,
            'agency_id' => $this->agency->id, 'value' => 1, 'point_state' => DailyActivityEntry::STATE_CONFIRMED, 'source' => DailyActivityEntry::SOURCE_AUTO_INSTANT,
        ]);

        $log = app(BeltPoints::class)->log($this->agent);
        $this->assertSame(1, $log->total());
        $this->assertSame('Property captured', $log->first()->event);
        $this->assertSame('9 Capri Crescent', $log->first()->property);
        $this->assertSame(50.0, (float) $log->first()->points);
    }
}
