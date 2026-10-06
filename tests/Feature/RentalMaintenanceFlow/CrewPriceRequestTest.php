<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Events\Rentals\RentalCrewLinesDecided;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardPriceRequest;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalApprovalGateService;
use App\Services\Rentals\RentalCrewScheduleService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.5.3-§17.5.5 — "Ask crew to price this job" (ask, banner, submit once, re-ask, the "To
 * price" group for a Draft card) and the office's Accept / Reject / Accept-all of what the crew sent.
 */
final class CrewPriceRequestTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Price Request');
        $this->card = $this->emptyCard(); // Draft, crew assigned
    }

    private function ask(?string $note = 'Please price the whole bathroom')
    {
        return $this->actingAs($this->admin)->post(route('corex.rental-job-cards.price-requests.store', $this->card), ['note' => $note]);
    }

    // ── ask ───────────────────────────────────────────────────────────────────────────────

    public function test_the_office_asks_and_the_card_shows_pricing_requested(): void
    {
        $this->ask()->assertRedirect(route('corex.rental-job-cards.show', $this->card))->assertSessionHas('success');

        $request = $this->card->priceRequests()->firstOrFail();
        $this->assertSame(RentalJobCardPriceRequest::STATUS_OPEN, $request->status);
        $this->assertSame('Please price the whole bathroom', $request->note);
        $this->assertSame($this->admin->id, $request->requested_by_user_id);
        $this->assertTrue($this->card->updates()->where('update_type', 'pricing_requested')->exists());

        $html = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))->getContent();
        $this->assertStringContainsString('Pricing requested', $html);
        $this->assertStringContainsString('data-chip-pricing', $html);
    }

    public function test_asking_needs_a_crew_pricing_switched_on_and_no_other_open_request(): void
    {
        $noCrew = $this->emptyCard(['rental_crew_id' => null]);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.price-requests.store', $noCrew), [])->assertSessionHasErrors('pricing');
        $this->assertStringContainsString('Assign a crew', session('errors')->first('pricing'));
        $this->assertSame(0, $noCrew->priceRequests()->count());

        $this->ask()->assertSessionHasNoErrors();
        $this->ask()->assertSessionHasErrors('pricing');
        $this->assertSame(1, $this->card->priceRequests()->count(), 'one OPEN request per card');

        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['capture_prices_on_job_cards' => false]);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.price-requests.store', $this->emptyCard()), [])->assertSessionHasErrors('pricing');
    }

    public function test_asking_is_refused_when_crew_links_are_switched_off(): void
    {
        \App\Models\RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_links_enabled' => false]);

        $this->ask()->assertSessionHasErrors('pricing');
        $this->assertStringContainsString('Crew links are switched off', session('errors')->first('pricing'));
        $this->assertSame(0, $this->card->priceRequests()->count());
    }

    public function test_asking_can_mint_and_email_the_crews_link_in_the_same_step(): void
    {
        Mail::fake();
        $this->crew->forceFill(['email' => 'team1@example.invalid'])->save();

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.price-requests.store', $this->card), ['note' => 'Go', 'generate_link' => 1])
            ->assertRedirect()->assertSessionHas('crew_link_url');

        $this->assertSame(1, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $this->card->id)->where('purpose', RentalSecureAccessToken::PURPOSE_CREW_JOB_CARD)->count());
        $this->assertTrue($this->card->updates()->where('update_type', 'link_emailed')->exists());
    }

    public function test_asking_needs_the_share_permission(): void
    {
        $agent = $this->agentHolding(['rental_job_cards.create']);

        $this->actingAs($agent)->post(route('corex.rental-job-cards.price-requests.store', $this->card), [])->assertForbidden();
        $this->assertSame(0, $this->card->priceRequests()->count());
    }

    // ── the crew's side ──────────────────────────────────────────────────────────────────

    public function test_the_crew_sees_the_banner_and_the_draft_card_is_listed_under_to_price_then_drops_off_when_closed(): void
    {
        $page = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];
        $link = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin)['raw_token'];
        $service = app(RentalCrewScheduleService::class);

        // a Draft card with NO request is not booked work: not reachable, not listed
        $this->assertNull($service->findOpenCard($this->agency->id, $this->crew->id, $this->card->id));
        $this->assertSame([], $service->schedule($this->agency->id, $this->crew->id)['to_price']);

        $this->ask();

        $this->assertNotNull($service->findOpenCard($this->agency->id, $this->crew->id, $this->card->id));
        $schedule = $service->schedule($this->agency->id, $this->crew->id);
        $this->assertCount(1, $schedule['to_price']);
        $this->assertTrue($schedule['to_price'][0]['to_price']);

        $this->get(url('/secure/crews/' . $page))->assertOk()->assertSee('To price')->assertSee('The office asked you to price this job');
        $job = $this->get(url('/secure/crews/' . $page . '/job-cards/' . $this->card->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-price-request', $job);
        $this->assertStringContainsString('Please price the whole bathroom', $job);
        $this->assertStringContainsString('data-crew-pricing', $this->get(url('/secure/job-cards/' . $link))->getContent());

        // the office closes the request: the draft card drops off the crew's page again
        $request = $this->card->priceRequests()->firstOrFail();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.price-requests.close', [$this->card, $request->id]))->assertRedirect();
        $this->assertSame(RentalJobCardPriceRequest::STATUS_CANCELLED, $request->fresh()->status);
        $this->assertNull($service->findOpenCard($this->agency->id, $this->crew->id, $this->card->id));
    }

    public function test_the_crew_submits_once_the_request_becomes_submitted_and_a_re_ask_opens_a_new_one(): void
    {
        $this->ask();
        $ctx = $this->crewCtx($this->card);
        $line = $this->crewDraft($this->card, ['description' => 'Tap', 'unit_cost' => 80], $ctx);
        $this->assertSame(RentalJobCardLine::ORIGIN_CREW_PRICING, $line->origin);

        app(\App\Services\Rentals\CrewJobService::class)->sendToOffice($this->card, $ctx);

        $request = $this->card->priceRequests()->firstOrFail();
        $this->assertSame(RentalJobCardPriceRequest::STATUS_SUBMITTED, $request->status);
        $this->assertNotNull($request->submitted_at);
        $this->assertStringContainsString('Team 1', (string) $request->submitted_label);
        $this->assertSame('203.0.113.7', $request->submitted_ip);
        $this->assertSame('TestPhone/1.0', $request->submitted_device);
        $this->assertTrue($this->card->updates()->where('update_type', 'pricing_submitted')->exists());

        // sending again with nothing new changes nothing and says so
        try {
            app(\App\Services\Rentals\CrewJobService::class)->sendToOffice($this->card, $ctx);
            $this->fail('a second send with nothing new must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Add at least one', $e->getMessage());
        }
        $this->assertSame(1, $this->card->priceRequests()->count());

        // re-asking after "submitted" opens a NEW request
        $this->ask('Second round');
        $this->assertSame(2, $this->card->priceRequests()->count());
        $this->assertSame(1, $this->card->priceRequests()->where('status', 'open')->count());
    }

    // ── accept / reject ──────────────────────────────────────────────────────────────────

    public function test_accepting_makes_the_line_count_priced_by_the_rules_and_closes_the_request(): void
    {
        Event::fake([RentalCrewLinesDecided::class]);
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['default_parts_markup_percent' => 20]);
        $this->ask();
        $ctx = $this->crewCtx($this->card);
        $line = $this->crewDraft($this->card, ['description' => 'Tap', 'unit_cost' => 100, 'quantity' => 2], $ctx);
        app(\App\Services\Rentals\CrewJobService::class)->sendToOffice($this->card, $ctx);
        $this->assertEquals(0.0, (float) $this->card->fresh()->total_amount, 'not counted while it awaits the office');

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-lines.accept', [$this->card, $line->id]), [])
            ->assertRedirect()->assertSessionHas('success');

        $line->refresh();
        $this->assertSame(RentalJobCardLine::OFFICE_ACCEPTED, $line->office_status);
        $this->assertEquals(120.00, (float) $line->unit_price, 'priced by the agency default markup (rule 6)');
        $this->assertEquals(240.00, (float) $line->line_total);
        $this->assertEquals(200.00, (float) $line->cost_total);
        $this->assertSame($this->admin->id, $line->office_decided_by_user_id);
        $card = $this->card->fresh();
        $this->assertEquals(240.00, (float) $card->total_amount);
        $this->assertEquals(200.00, (float) $card->total_cost);
        $this->assertSame(RentalJobCardPriceRequest::STATUS_CLOSED, $card->priceRequests()->first()->status);
        $this->assertTrue($card->updates()->where('update_type', 'crew_line_accepted')->exists());
        Event::assertDispatched(RentalCrewLinesDecided::class, fn ($e) => $e->accepted === 1 && $e->rejected === 0);
    }

    public function test_the_office_can_correct_the_cost_and_type_the_selling_price_when_accepting(): void
    {
        $line = $this->awaitingLine($this->card, ['unit_cost' => 100]);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-lines.accept', [$this->card, $line->id]), ['unit_cost' => 90, 'unit_price' => 150])->assertRedirect();

        $line->refresh();
        $this->assertEquals(90.00, (float) $line->unit_cost);
        $this->assertEquals(150.00, (float) $line->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_MANUAL, $line->selling_basis);
    }

    public function test_correcting_the_cost_needs_view_costs_but_accepting_does_not(): void
    {
        $line = $this->awaitingLine($this->card, ['unit_cost' => 100]);
        $agent = $this->agentHolding(['rental_job_cards.price']);

        $this->actingAs($agent)->post(route('corex.rental-job-cards.crew-lines.accept', [$this->card, $line->id]), ['unit_cost' => 1])->assertRedirect();

        $this->assertSame(RentalJobCardLine::OFFICE_ACCEPTED, $line->fresh()->office_status);
        $this->assertEquals(100.00, (float) $line->fresh()->unit_cost, 'a cost they cannot see is a cost they cannot change');
    }

    public function test_rejecting_needs_a_reason_and_the_crew_sees_it_and_nothing_counts(): void
    {
        $line = $this->awaitingLine($this->card, ['unit_cost' => 100]);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-lines.reject', [$this->card, $line->id]), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame(RentalJobCardLine::OFFICE_AWAITING, $line->fresh()->office_status);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-lines.reject', [$this->card, $line->id]), ['reason' => 'Not needed — already on site'])
            ->assertRedirect()->assertSessionHas('success');

        $line->refresh();
        $this->assertSame(RentalJobCardLine::OFFICE_REJECTED, $line->office_status);
        $this->assertSame('Not needed — already on site', $line->reject_reason);
        $this->assertEquals(0.0, (float) $this->card->fresh()->total_amount);
        $this->assertNull($line->unit_price);
        $this->assertTrue($this->card->updates()->where('update_type', 'crew_line_rejected')->exists());
    }

    public function test_an_already_decided_line_cannot_be_decided_again(): void
    {
        $line = $this->awaitingLine($this->card, ['unit_cost' => 100]);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-lines.accept', [$this->card, $line->id]), [])->assertRedirect();

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-lines.reject', [$this->card, $line->id]), ['reason' => 'late'])->assertSessionHasErrors('pricing');
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-lines.accept', [$this->card, $line->id]), [])->assertSessionHasErrors('pricing');
        $this->assertSame(RentalJobCardLine::OFFICE_ACCEPTED, $line->fresh()->office_status);
    }

    public function test_a_draft_the_crew_has_not_sent_cannot_be_accepted_from_outside(): void
    {
        $draft = $this->crewDraft($this->card);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-lines.accept', [$this->card, $draft->id]), [])->assertSessionHasErrors('pricing');
        $this->assertSame(RentalJobCardLine::OFFICE_CREW_DRAFT, $draft->fresh()->office_status);
    }

    public function test_a_line_of_another_card_is_a_404_through_this_card(): void
    {
        $other = $this->emptyCard();
        $line = $this->awaitingLine($other);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-lines.accept', [$this->card, $line->id]), [])->assertNotFound();
        $this->assertSame(RentalJobCardLine::OFFICE_AWAITING, $line->fresh()->office_status);
    }

    public function test_accept_all_prices_every_line_and_hands_the_new_total_to_the_gate_exactly_once(): void
    {
        $this->awaitingLine($this->card, ['description' => 'A', 'unit_cost' => 10, 'quantity' => 1]);
        $this->awaitingLine($this->card, ['description' => 'B', 'unit_cost' => 20, 'quantity' => 1]);
        $this->awaitingLine($this->card, ['description' => 'C', 'unit_cost' => 30, 'quantity' => 1]);

        $gate = \Mockery::mock(RentalApprovalGateService::class);
        $gate->shouldReceive('assessAfterLineChange')->once()->andReturn(null);
        $this->app->instance(RentalApprovalGateService::class, $gate);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-lines.accept-all', $this->card))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(3, $this->card->lines()->where('office_status', RentalJobCardLine::OFFICE_ACCEPTED)->count());
        $this->assertSame(0, $this->card->lines()->where('office_status', RentalJobCardLine::OFFICE_AWAITING)->count());
        $this->assertEquals(60.00, (float) $this->card->fresh()->total_amount, '0 % default markup: priced at cost');
    }

    public function test_a_line_accepted_after_the_quote_went_out_is_frozen_for_vat_at_once_and_flags_the_quote_as_changed(): void
    {
        $this->pricingWorld('Frozen Accept', [], true);
        $card = $this->emptyCard();
        $this->officeLine($card, ['description' => 'Sent line', 'unit_price' => 100, 'rental_vat_type_id' => $this->standardVat()->id]);
        app(\App\Services\Rentals\RentalJobCardService::class)->sendToOwnerAsQuote($card->fresh(), $this->admin, app(\App\Services\Rentals\RentalDocumentPdfService::class));
        $card = $card->fresh();
        $this->assertNotNull($card->vat_snapshotted_at, 'the quote froze the card');
        $this->assertFalse($card->quoteChangedSinceSent());

        $line = $this->awaitingLine($card, ['description' => 'Late extra', 'unit_cost' => 50, 'quantity' => 1, 'rental_vat_type_id' => null]);
        $this->assertFalse($card->fresh()->quoteChangedSinceSent(), 'a line still awaiting the office is not on the quote');

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-lines.accept', [$card, $line->id]), [])->assertRedirect();

        $line->refresh();
        $this->assertEquals(50.00, (float) $line->unit_price);
        $this->assertNotNull($line->vat_excl_snapshot, 'snapshotted the moment it was accepted (the §14.21 rule)');
        $this->assertEquals(50.00, (float) $line->vat_excl_snapshot);
        $this->assertTrue($card->fresh()->quoteChangedSinceSent(), 'accepting changed what the owner would see: the office is told to re-send');
        $this->assertEquals(150.00, (float) app(\App\Services\Rentals\RentalJobCardVatService::class)->breakdown($card->fresh()->load('lines'))['subtotalExcl']);
    }

    public function test_accepting_needs_the_price_permission(): void
    {
        $line = $this->awaitingLine($this->card, ['unit_cost' => 100]);
        $agent = $this->agentHolding(['rental_job_cards.create', 'rental_job_cards.share']);

        $this->actingAs($agent)->post(route('corex.rental-job-cards.crew-lines.accept', [$this->card, $line->id]), [])->assertForbidden();
        $this->actingAs($agent)->post(route('corex.rental-job-cards.crew-lines.reject', [$this->card, $line->id]), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($agent)->post(route('corex.rental-job-cards.crew-lines.accept-all', $this->card))->assertForbidden();
        $this->assertSame(RentalJobCardLine::OFFICE_AWAITING, $line->fresh()->office_status);
    }

    public function test_the_awaiting_block_shows_the_crews_lines_with_costs_only_for_view_costs(): void
    {
        $this->awaitingLine($this->card, ['description' => 'Distinctive washer', 'unit_cost' => 61.31, 'note' => 'Corroded']);
        $withCosts = $this->agentHolding(['rental_job_cards.price', 'rental_job_cards.view_costs']);
        $withoutCosts = $this->agentHolding(['rental_job_cards.price']);

        $seen = $this->actingAs($withCosts)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();
        $this->assertStringContainsString('data-crew-awaiting', $seen);
        $this->assertStringContainsString('Distinctive washer', $seen);
        $this->assertStringContainsString('R61.31', $seen);
        $this->assertStringContainsString('Priced by crew — awaiting you', $seen);

        $blind = $this->actingAs($withoutCosts)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();
        $this->assertStringContainsString('Distinctive washer', $blind);
        $this->assertStringNotContainsString('61.31', $blind);
    }

    public function test_the_awaiting_lines_never_enter_the_owner_facing_totals_before_acceptance(): void
    {
        $this->officeLine($this->card, ['description' => 'Office', 'unit_price' => 100]);
        $this->awaitingLine($this->card, ['unit_cost' => 9999]);

        $this->assertEquals(100.00, (float) $this->card->fresh()->total_amount);
        $this->assertSame(1, $this->card->fresh()->acceptedLines()->count());
        $this->assertSame(1, $this->card->fresh()->awaitingOfficeLines()->count());
    }
}
