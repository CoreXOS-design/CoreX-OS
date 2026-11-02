<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Events\Document\DocumentFiledToProperty;
use App\Events\Marketing\MarketingPostPublished;
use App\Events\Marketing\SellerLinkCreated;
use App\Events\Mandate\MandateSigned;
use App\Events\Presentation\PresentationOutcomeRecorded;
use App\Events\Presentation\PresentationPresented;
use App\Models\Property;
use App\Models\PropertyMarketingPost;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * The production line moves by itself (road-to-sold.md 22.24): each CoreX action that is a station moves the agent's prospect there, forward only,
 * once, and the move is written to the station history with its date. The events already covered elsewhere (evaluation booked / done, viewing
 * scheduled, listing live, lead / contacted / Core Match, pack, held, OTP, deal, registered, lost presentation) live in ProspectEventsTest and
 * SellStockStageWiringTest; this file proves the rest and guards that no station is left without a way in.
 */
final class ProductionLineAutoAdvanceTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private ProspectService $prospects;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        SettingsService::forget();
        $this->setUpAgency();
        $this->prospects = app(ProspectService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0:int,1:RtsProspect} */
    private function prospectOnProperty(): array
    {
        $propertyId = $this->property($this->agent->id);
        $tp = $this->tp(['promoted_to_property_id' => $propertyId]);

        return [$propertyId, $this->prospects->place($this->agent, $tp->id, 'sales', ['property_id' => $propertyId])];
    }

    private function presentation(int $propertyId): int
    {
        return DB::table('presentations')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'property_id' => $propertyId,
            'status' => 'draft', 'title' => 'Seller presentation', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_presentation_prepared_for_the_property_moves_the_prospect_to_presentation_prepared_and_giving_it_moves_nothing(): void
    {
        [$pid, $p] = $this->prospectOnProperty();
        $presentation = $this->presentation($pid);

        // v4 (23.4): "presented" is no longer a station - the presentation is given at the evaluation - so the event moves nothing ...
        event(new PresentationPresented(presentationId: $presentation, deliveryId: 1, agencyId: $this->agency->id, userId: $this->agent->id, channel: 'email', propertyId: $pid));
        $this->assertSame(S::FOUND, $p->refresh()->stage);

        // ... and generating it is the move
        event(new \App\Events\PresentationGenerated(\App\Models\Presentation::query()->withoutGlobalScopes()->findOrFail($presentation), new \App\Models\PresentationVersion()));

        $p->refresh();
        $this->assertSame(S::PRESENTATION_PREPARED, $p->stage);
        $this->assertSame('Do the evaluation', $p->next_action_label);
        $this->assertSame('PresentationGenerated', class_basename((string) DB::table('rts_prospect_events')->where('prospect_id', $p->id)->where('to_stage', S::PRESENTATION_PREPARED)->value('event_class')));
    }

    public function test_the_sellers_answer_moves_it_to_presentation_feedback_and_pending_does_not(): void
    {
        [$pid, $p] = $this->prospectOnProperty();
        $presentation = $this->presentation($pid);
        $outcome = fn (string $o, int $id) => event(new PresentationOutcomeRecorded(
            presentationOutcomeId: $id, presentationId: $presentation, outcome: $o, cancellationReason: null, decisionAt: null, resultedInDealId: null,
            agencyIdValue: $this->agency->id, actorUserIdValue: $this->agent->id,
        ));

        $outcome('still_pending', 1);
        $this->assertSame(S::FOUND, $p->refresh()->stage, 'still pending is neither active nor dead');

        $outcome('won_mandate', 2);
        $p->refresh();
        $this->assertSame(S::PRESENTATION_FEEDBACK, $p->stage);
        $this->assertSame('Get the mandate signed', $p->next_action_label);
        $this->assertNull($p->parked_at, 'a won answer keeps it on the line');
    }

    public function test_a_signed_mandate_and_a_filed_mandate_both_move_it_to_mandate_signed_once(): void
    {
        [$pid, $p] = $this->prospectOnProperty();
        $property = Property::withoutGlobalScopes()->findOrFail($pid);

        event(new MandateSigned(mandate: $property, property: $property, agencyIdHint: $this->agency->id, actorUserId: $this->agent->id, userId: $this->agent->id));
        $this->assertSame(S::MANDATE_SIGNED, $p->refresh()->stage);
        $this->assertSame('Take the listing live', $p->next_action_label);

        // the filed copy arriving later moves nothing back or twice
        event(new DocumentFiledToProperty(documentId: 5, propertyId: $pid, documentTypeSlug: 'mandate', agencyId: $this->agency->id, actorUserId: $this->agent->id));
        $this->assertSame(S::MANDATE_SIGNED, $p->refresh()->stage);
        $this->assertSame(1, DB::table('rts_prospect_events')->where('prospect_id', $p->id)->where('to_stage', S::MANDATE_SIGNED)->where('kind', 'stage')->count());

        // a paper mandate (filed, never e-signed) on its own also moves it
        [$pid2, $q] = $this->prospectOnProperty();
        event(new DocumentFiledToProperty(documentId: 6, propertyId: $pid2, documentTypeSlug: 'mandate', agencyId: $this->agency->id, actorUserId: $this->agent->id));
        $this->assertSame(S::MANDATE_SIGNED, $q->refresh()->stage);
    }

    public function test_an_ad_or_social_post_and_a_seller_link_move_it_to_advertising(): void
    {
        [$pid, $p] = $this->prospectOnProperty();
        $post = (new PropertyMarketingPost())->forceFill(['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'property_id' => $pid]);

        event(new MarketingPostPublished($post));
        $p->refresh();
        $this->assertSame(S::ADVERTISING, $p->stage);
        $this->assertSame('Follow up leads', $p->next_action_label);

        [$pid2, $q] = $this->prospectOnProperty();
        event(new SellerLinkCreated(linkId: 9, propertyId: $pid2, contactId: null, agencyId: $this->agency->id, branchId: $this->branch->id, userId: $this->agent->id, actorUserId: $this->agent->id));
        $this->assertSame(S::ADVERTISING, $q->refresh()->stage);
    }

    public function test_each_move_is_written_to_the_station_history_with_its_date_and_days_at_the_station_are_real(): void
    {
        [$pid, $p] = $this->prospectOnProperty();
        $presentation = $this->presentation($pid);
        Carbon::setTestNow('2026-10-16 15:30:00');
        event(new \App\Events\PresentationGenerated(\App\Models\Presentation::query()->withoutGlobalScopes()->findOrFail($presentation), new \App\Models\PresentationVersion()));
        Carbon::setTestNow('2026-10-21 08:00:00');

        $rows = DB::table('rts_prospect_events')->where('prospect_id', $p->id)->whereIn('kind', ['placed', 'stage'])->orderBy('id')->get(['kind', 'from_stage', 'to_stage', 'occurred_at']);
        $this->assertSame(['placed:found', 'stage:presentation_prepared'], $rows->map(fn ($r) => $r->kind . ':' . $r->to_stage)->all());
        $this->assertSame(S::FOUND, $rows[1]->from_stage);
        $this->assertSame('2026-10-16 15:30:00', (string) $rows[1]->occurred_at);

        $journey = app(\App\Services\RoadToSold\PipelineLineService::class)->journeys([$p->id]);
        $this->assertSame([$p->id => ['found' => '2026-10-14', 'presentation_prepared' => '2026-10-16']], $journey);

        $row = app(\App\Services\RoadToSold\ProspectListService::class)->line($this->agent, $this->agent->id, [], 'sales')['rows'][0];
        $this->assertSame(5, $row['days_in_stage'], 'in at the station on the 16th, today is the 21st');
    }

    public function test_every_station_after_contacted_has_a_way_in_from_the_listener(): void
    {
        // Found is placement, Contacted is the call outcome (ProspectService::applyOutcome); every other station is entered by a CoreX event.
        $source = file_get_contents(app_path('Listeners/RoadToSold/AdvanceProspectsFromDomainEvent.php'));
        $constants = [
            S::APPOINTMENT_SET => 'APPOINTMENT_SET', S::EVALUATION_DONE => 'EVALUATION_DONE',
            S::PRESENTATION_FEEDBACK => 'PRESENTATION_FEEDBACK', S::MANDATE_SIGNED => 'MANDATE_SIGNED', S::LISTING_LIVE => 'LISTING_LIVE',
            S::ADVERTISING => 'ADVERTISING', S::LEAD => 'LEAD', S::LEAD_CONTACTED => 'LEAD_CONTACTED', S::CORE_MATCH => 'CORE_MATCH',
            S::VIEWING_SCHEDULED => 'VIEWING_SCHEDULED', S::VIEWING_PACK => 'VIEWING_PACK', S::VIEWING_FEEDBACK => 'VIEWING_FEEDBACK',
            S::OFFER => 'OFFER', S::DEAL => 'DEAL', S::REGISTERED => 'REGISTERED',
        ];
        $this->assertCount(15, $constants);   // the legacy Presentation given station has no way in any more (23.4)
        foreach ($constants as $key => $name) {
            $this->assertStringContainsString('S::' . $name, $source, "no way into the {$key} station");
        }
        // v4 (23.4): the belt adds Claimed (the MIC claim), Owner found, Presentation prepared, Draft and Active, each entered by its own code (the claim listener, the owner
        // link, the presentation generator, the property status), not by this listener; the two presentation stages stay as legacy keys.
        $this->assertSame(array_keys(S::ALL), [
            S::FOUND, S::CLAIMED, S::OWNER_FOUND, S::CONTACTED, S::APPOINTMENT_SET, S::PRESENTATION_PREPARED, S::EVALUATION_DONE, S::PRESENTATION_GIVEN, S::PRESENTATION_FEEDBACK,
            S::MANDATE_SIGNED, S::DRAFT, S::ACTIVE, S::LISTING_LIVE, S::ADVERTISING, S::LEAD, S::LEAD_CONTACTED, S::CORE_MATCH, S::VIEWING_SCHEDULED, S::VIEWING_PACK,
            S::VIEWING_FEEDBACK, S::OFFER, S::DEAL, S::REGISTERED,
        ], 'the stages are exactly these');
        $this->assertSame([S::PRESENTATION_GIVEN, S::PRESENTATION_FEEDBACK], S::LEGACY);
    }

    public function test_an_event_for_a_colleagues_property_does_not_move_my_line(): void
    {
        $propertyId = $this->property($this->mate->id);
        $tp = $this->tp(['promoted_to_property_id' => $propertyId]);
        $mine = $this->prospects->place($this->agent, $this->tp()->id);
        $theirs = $this->prospects->place($this->mate, $tp->id, 'sales', ['property_id' => $propertyId]);
        $presentation = $this->presentation($propertyId);
        DB::table('presentations')->where('id', $presentation)->update(['created_by_user_id' => $this->mate->id]);

        event(new \App\Events\PresentationGenerated(\App\Models\Presentation::query()->withoutGlobalScopes()->findOrFail($presentation), new \App\Models\PresentationVersion()));

        $this->assertSame(S::FOUND, $mine->refresh()->stage);
        $this->assertSame(S::PRESENTATION_PREPARED, $theirs->refresh()->stage);
    }
}
