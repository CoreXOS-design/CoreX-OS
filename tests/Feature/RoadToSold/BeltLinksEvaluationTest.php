<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Property;
use App\Services\CommandCenter\Calendar\CalendarEventCreator;
use App\Services\RoadToSold\BeltLinks;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * BeltLinks::linkEvaluation (road-to-sold.md 23.8) - the "empty patch" warning seen in the QA1 log:
 * an evaluation booked FROM Road to Sold already carries both keys, so there was nothing to write, and the
 * empty UPDATE (`update calendar_events set  where id = ?`) threw. The method swallowed it, logged a warning
 * and returned [null, null] - so EvaluationBooked was raised without the keys the booking had.
 */
final class BeltLinksEvaluationTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
    }

    public function test_an_appointment_that_already_has_both_keys_returns_them_and_logs_nothing(): void
    {
        $tp = $this->tp(['street_name' => 'Bairn Street', 'street_number' => '12']);
        $prop = Property::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'status' => 'prospecting',
            'title' => '12 Bairn Street', 'listing_type' => 'sale', 'property_type' => 'house', 'address' => '12 Bairn Street', 'suburb' => 'Margate']);
        DB::table('properties')->where('id', $prop->id)->update(['tracked_property_id' => $tp->id]);
        $p = app(ProspectService::class)->place($this->agent, (int) $tp->id, 'sales', ['property_id' => $prop->id, 'stage' => S::MANDATE_SIGNED]);

        $this->actingAs($this->agent);
        $event = app(CalendarEventCreator::class)->create([
            'title' => 'Evaluation', 'category' => 'property_evaluation', 'event_date' => '2026-11-03 10:00:00', 'property_id' => $prop->id,
            'tracked_property_id' => $tp->id, 'rts_prospect_id' => $p->id,
        ], $this->agent)->refresh();
        $this->assertSame((int) $tp->id, (int) $event->tracked_property_id);
        $this->assertSame((int) $p->id, (int) $event->rts_prospect_id);

        Log::spy();
        $out = app(BeltLinks::class)->linkEvaluation($event);

        $this->assertSame([(int) $tp->id, (int) $p->id], $out, 'the keys the booking already had are handed on, not [null, null]');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_plain_calendar_appointment_is_still_linked_to_the_agents_open_record(): void
    {
        $tp = $this->tp(['street_name' => 'Bairn Street', 'street_number' => '14']);
        $prop = Property::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'status' => 'prospecting',
            'title' => '14 Bairn Street', 'listing_type' => 'sale', 'property_type' => 'house', 'address' => '14 Bairn Street', 'suburb' => 'Margate']);
        DB::table('properties')->where('id', $prop->id)->update(['tracked_property_id' => $tp->id]);
        $p = app(ProspectService::class)->place($this->agent, (int) $tp->id, 'sales', ['property_id' => $prop->id, 'stage' => S::MANDATE_SIGNED]);

        $this->actingAs($this->agent);
        $event = app(CalendarEventCreator::class)->create(['title' => 'Evaluation', 'category' => 'property_evaluation', 'event_date' => '2026-11-04 10:00:00', 'property_id' => $prop->id], $this->agent)->refresh();

        $this->assertSame((int) $tp->id, (int) $event->tracked_property_id, 'matched from the property');
        $this->assertSame((int) $p->id, (int) $event->rts_prospect_id, 'matched to the creating agent\'s open record');
    }
}
