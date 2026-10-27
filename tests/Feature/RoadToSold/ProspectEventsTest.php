<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Events\Deal\DealClosed;
use App\Events\Deal\DealCreated;
use App\Events\Deal\DealStageAdvanced;
use App\Events\Document\DocumentFiledToProperty;
use App\Events\Evaluation\EvaluationBooked;
use App\Events\Evaluation\EvaluationCompleted;
use App\Events\Viewing\ViewingScheduled;
use App\Models\Deal;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\User;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\RecipeService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v3 - CoreX events move each agent's prospect from stage to stage, and a deal credits the agents ON it
 * (road-to-sold.md 22.2, 22.8): forward only, once per event, never onto another agent's property, and the person who clicked on a deal is
 * not credited unless they are on it.
 */
final class ProspectEventsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private ProspectService $prospects;
    private User $office;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        SettingsService::forget();
        $this->setUpAgency();
        $this->prospects = app(ProspectService::class);
        $this->office = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true, 'name' => 'Olive Office',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A property of the agency's with a prospect for $agent attached to it. @return array{0:int,1:RtsProspect} */
    private function prospectOnProperty(User $agent): array
    {
        $propertyId = $this->property($agent->id);
        $tp = $this->tp(['promoted_to_property_id' => $propertyId]);
        $p = $this->prospects->place($agent, $tp->id, 'sales', ['property_id' => $propertyId]);

        return [$propertyId, $p];
    }

    private function deal(int $propertyId, array $agents): Deal
    {
        $id = DB::table('deals')->insertGetId([
            'agency_id' => $this->agency->id, 'period' => '2026-10', 'deal_date' => '2026-10-10', 'property_value' => 1500000,
            'total_commission' => 90000, 'property_id' => $propertyId, 'accepted_status' => 'P', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($agents as $i => $a) {
            DB::table('deal_user')->insert(['deal_id' => $id, 'user_id' => $a->id, 'side' => $i === 0 ? 'listing' : 'selling', 'created_at' => now(), 'updated_at' => now()]);
        }

        return Deal::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_an_evaluation_booked_and_done_move_the_agents_prospect_with_the_appointment_date(): void
    {
        [$propertyId, $p] = $this->prospectOnProperty($this->agent);

        event(new EvaluationBooked(calendarEventId: 11, agencyId: $this->agency->id, userId: $this->agent->id, propertyId: $propertyId, contactId: null, startsAt: '2026-10-20 10:00:00', actorUserId: $this->agent->id));
        $p->refresh();
        $this->assertSame(S::APPOINTMENT_SET, $p->stage);
        $this->assertSame('Do the evaluation', $p->next_action_label);
        $this->assertSame('2026-10-20', $p->next_action_at->toDateString());

        event(new EvaluationCompleted(calendarEventId: 11, feedbackId: 5, agencyId: $this->agency->id, userId: $this->agent->id, propertyId: $propertyId, contactId: null, completedAt: '2026-10-20 11:00:00', actorUserId: $this->agent->id));
        $this->assertSame(S::EVALUATION_DONE, $p->refresh()->stage);

        // the same event again changes nothing
        event(new EvaluationCompleted(calendarEventId: 11, feedbackId: 5, agencyId: $this->agency->id, userId: $this->agent->id, propertyId: $propertyId, contactId: null, completedAt: '2026-10-20 11:00:00', actorUserId: $this->agent->id));
        $this->assertSame(1, DB::table('rts_prospect_events')->where('prospect_id', $p->id)->where('to_stage', S::EVALUATION_DONE)->count());
    }

    public function test_an_event_on_a_property_another_agent_owns_does_not_touch_their_prospect(): void
    {
        [$propertyId, $p] = $this->prospectOnProperty($this->agent);

        event(new EvaluationCompleted(calendarEventId: 12, feedbackId: 6, agencyId: $this->agency->id, userId: $this->mate->id, propertyId: $propertyId, contactId: null, completedAt: null, actorUserId: $this->mate->id));

        $this->assertSame(S::FOUND, $p->refresh()->stage);
        $this->assertSame(1, RtsProspect::withoutGlobalScopes()->count(), 'and no second prospect is made for the same property');
    }

    public function test_an_event_for_a_property_the_agent_has_no_prospect_on_creates_one_at_that_stage(): void
    {
        $propertyId = $this->property($this->agent->id);

        event(new DocumentFiledToProperty(documentId: 91, propertyId: $propertyId, documentTypeSlug: 'mandate', agencyId: $this->agency->id, actorUserId: $this->office->id));

        $p = RtsProspect::withoutGlobalScopes()->where('property_id', $propertyId)->firstOrFail();
        $this->assertSame($this->agent->id, (int) $p->user_id, 'the property\'s agent, not the person who filed it');
        $this->assertSame(S::MANDATE_SIGNED, $p->stage);
    }

    public function test_a_deal_moves_every_agent_on_it_and_a_lost_deal_puts_the_property_back_on_the_market(): void
    {
        [$propertyId, $p1] = $this->prospectOnProperty($this->agent);
        $deal = $this->deal($propertyId, [$this->agent, $this->mate]);

        event(new DealCreated($deal, $this->office->id));
        $this->assertSame(S::DEAL, $p1->refresh()->stage);
        $p2 = RtsProspect::withoutGlobalScopes()->where('user_id', $this->mate->id)->first();
        $this->assertNull($p2, 'the second agent has no prospect on a property another agent owns: the owner\'s only moves');

        event(new DealClosed($deal, 'lost', $this->office->id));
        $this->assertSame(S::ADVERTISING, $p1->refresh()->stage, 'the deal fell through: back on the market');
        $this->assertNull($p1->closed_at);

        event(new DealStageAdvanced($deal, 'G', 'R', $this->office->id));
        $this->assertSame(S::REGISTERED, $p1->refresh()->stage);
        $this->assertNotNull($p1->closed_at, 'registered ends the story');
    }

    public function test_deal_credit_goes_to_the_agents_on_the_deal_not_to_the_person_who_clicked(): void
    {
        app(RecipeService::class)->start($this->agency->id, 'sales');
        $task = RtsTask::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'deal-opened')->firstOrFail();
        $level = RtsLevel::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('number', 1)->firstOrFail();
        RtsLevelTask::withoutGlobalScopes()->forceCreate(['agency_id' => $this->agency->id, 'level_id' => $level->id, 'task_id' => $task->id, 'target' => 1, 'points' => 2, 'required_for_signoff' => false]);

        $propertyId = $this->property($this->agent->id);
        $deal = $this->deal($propertyId, [$this->agent, $this->mate]);
        event(new DealCreated($deal, $this->office->id));

        $credited = RtsTaskCompletion::withoutGlobalScopes()->where('task_id', $task->id)->pluck('user_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $this->assertSame([$this->agent->id, $this->mate->id], $credited, 'both agents on the deal');
        $this->assertNotContains($this->office->id, $credited, 'the person who pressed the button is not credited');
        $this->assertSame([$this->office->id], RtsTaskCompletion::withoutGlobalScopes()->where('task_id', $task->id)->pluck('recorded_by_user_id')->map(fn ($v) => (int) $v)->unique()->values()->all(), 'but is recorded as having done it');

        event(new DealCreated($deal, $this->office->id));
        $this->assertSame(2, RtsTaskCompletion::withoutGlobalScopes()->where('task_id', $task->id)->count(), 'the same deal twice credits once each');
    }

    public function test_a_viewing_put_in_the_calendar_raises_viewing_scheduled_once(): void
    {
        Event::fake([ViewingScheduled::class]);
        $propertyId = $this->property($this->agent->id);
        $this->actingAs($this->agent);

        app(\App\Services\CommandCenter\Calendar\CalendarEventCreator::class)->create([
            'title' => 'Viewing', 'category' => 'viewing', 'event_date' => '2026-10-20 10:00:00', 'property_id' => $propertyId,
        ], $this->agent);
        app(\App\Services\CommandCenter\Calendar\CalendarEventCreator::class)->create([
            'title' => 'Lunch', 'category' => 'personal', 'event_date' => '2026-10-20 12:00:00',
        ], $this->agent);

        Event::assertDispatchedTimes(ViewingScheduled::class, 1);
    }

    public function test_a_viewing_scheduled_moves_the_prospect(): void
    {
        [$propertyId, $p] = $this->prospectOnProperty($this->agent);

        event(new ViewingScheduled(calendarEventId: 31, propertyId: $propertyId, contactId: null, agencyId: $this->agency->id, branchId: $this->branch->id, userId: $this->agent->id, startsAt: '2026-10-20 10:00:00', actorUserId: $this->agent->id));

        $this->assertSame(S::VIEWING_SCHEDULED, $p->refresh()->stage);
        $this->assertSame('Send the viewing pack', $p->next_action_label);
    }

    public function test_a_lost_presentation_parks_the_prospect_with_a_reason(): void
    {
        [$propertyId, $p] = $this->prospectOnProperty($this->agent);
        $presentationId = DB::table('presentations')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'property_id' => $propertyId, 'status' => 'draft', 'title' => 'Seller presentation',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        event(new \App\Events\Presentation\PresentationOutcomeRecorded(
            presentationOutcomeId: 1, presentationId: $presentationId, outcome: 'lost_to_competitor', cancellationReason: null,
            decisionAt: null, resultedInDealId: null, agencyIdValue: $this->agency->id, actorUserIdValue: $this->agent->id,
        ));

        $p->refresh();
        $this->assertSame(S::PRESENTATION_FEEDBACK, $p->stage);
        $this->assertSame('Seller chose another agency', $p->park_reason_label, 'dead stock goes to Parked with its reason');
    }
}
