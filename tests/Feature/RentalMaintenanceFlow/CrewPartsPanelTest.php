<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Events\Rentals\RentalCrewLinesSubmitted;
use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardPriceRequest;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\CrewJobService;
use App\Services\Rentals\CrewViewContext;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.5 — the crew's "Parts & labour": add / change / remove their own drafts, up to 3 photos
 * per line, "Send to office" once, and the invariant that matters most — crew lines change NOTHING the owner sees until the
 * office accepts them — over both the per-job link and the crew page. Nothing in any response ever carries selling.
 */
final class CrewPartsPanelTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;
    private string $rawToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Crew Panel');
        $this->card = $this->emptyCard(['status' => RentalJobCard::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);
        // an office line at a distinctive SELLING price (4,321.99 x 1) that must never reach the crew
        $this->officeLine($this->card, ['description' => 'Office part', 'type' => 'part', 'quantity' => 1, 'unit_cost' => 111.11, 'unit_price' => 4321.99]);
        $this->rawToken = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin)['raw_token'];
    }

    private function linkUrl(string $suffix = ''): string
    {
        return url('/secure/job-cards/' . $this->rawToken . $suffix);
    }

    private function postLine(array $data, array $files = [])
    {
        return $this->post(route('rentals.crew-job.lines.store', $this->rawToken), $data + ['photos' => $files]);
    }

    private function goodLine(array $over = []): array
    {
        return $over + ['type' => 'part', 'description' => 'Geyser element', 'quantity' => 2, 'unit' => 'each', 'unit_cost' => '48.50', 'note' => 'The old one is burnt out'];
    }

    // ── happy path ────────────────────────────────────────────────────────────────────────

    public function test_the_crew_adds_a_draft_with_a_cost_and_it_is_in_no_total_anywhere(): void
    {
        $before = ['total' => (float) $this->card->fresh()->total_amount, 'sig' => $this->card->quoteContentSignature()];

        $this->postLine($this->goodLine())->assertRedirect(route('rentals.crew-job.show', $this->rawToken))->assertSessionHas('success');

        $line = RentalJobCardLine::where('description', 'Geyser element')->firstOrFail();
        $this->assertSame(RentalJobCardLine::OFFICE_CREW_DRAFT, $line->office_status);
        $this->assertSame(RentalJobCardLine::ORIGIN_CREW_EXTRA, $line->origin, 'no price request is open, so it is an extra');
        $this->assertEquals(48.50, (float) $line->unit_cost);
        $this->assertEquals(97.00, (float) $line->cost_total);
        $this->assertNull($line->unit_price, 'the crew never sets a selling price');
        $this->assertNull($line->line_total);
        $this->assertSame('The old one is burnt out', $line->crew_note);
        $this->assertStringContainsString('Team 1', (string) $line->crew_added_by_label);

        $card = $this->card->fresh();
        $this->assertEquals($before['total'], (float) $card->total_amount, 'the owner-facing total did not move');
        $this->assertSame($before['sig'], $card->quoteContentSignature(), 'the quote did not move');
        $this->assertEquals(111.11, (float) $card->total_cost, 'cost total counts accepted lines only');
    }

    public function test_the_crew_page_and_the_link_show_the_line_with_a_state_chip_and_never_any_selling(): void
    {
        $this->postLine($this->goodLine())->assertRedirect();

        $body = $this->get($this->linkUrl())->assertOk()->getContent();

        $this->assertStringContainsString('Geyser element', $body);
        $this->assertStringContainsString('Draft — not sent yet', $body);
        $this->assertStringContainsString('R 97.00', $body, "the crew's own cost");
        $this->assertStringNotContainsString('4,321.99', $body);
        $this->assertStringNotContainsString('4321.99', $body);
    }

    public function test_the_same_panel_works_from_the_crew_page_with_its_own_audit_trail(): void
    {
        $crewToken = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];

        $this->post(route('rentals.crew-page.lines.store', [$crewToken, $this->card->id]), $this->goodLine(['description' => 'From the page']))
            ->assertRedirect(route('rentals.crew-page.job', [$crewToken, $this->card->id]));

        $line = RentalJobCardLine::where('description', 'From the page')->firstOrFail();
        $this->assertSame(RentalJobCardLine::OFFICE_CREW_DRAFT, $line->office_status);
        $this->assertStringContainsString('via crew page', (string) $line->crew_added_by_label);

        $this->post(route('rentals.crew-page.lines.send', [$crewToken, $this->card->id]), ['confirm' => 1])->assertRedirect();
        $this->assertSame(RentalJobCardLine::OFFICE_AWAITING, $line->fresh()->office_status);
        $this->assertTrue(\App\Models\RentalCrewLinkEvent::withoutGlobalScopes()->where('rental_job_card_id', $this->card->id)->where('note', 'like', 'Sent 1 part/labour line%')->exists(), "the crew's own link log records it");
    }

    // ── edit, remove, send ────────────────────────────────────────────────────────────────

    public function test_the_crew_can_change_and_remove_their_own_drafts_but_not_a_sent_one(): void
    {
        $this->postLine($this->goodLine());
        $line = RentalJobCardLine::where('description', 'Geyser element')->firstOrFail();

        $this->post(route('rentals.crew-job.lines.update', [$this->rawToken, $line->id]), $this->goodLine(['quantity' => 5, 'unit_cost' => '10', 'description' => 'Geyser element (brass)']))
            ->assertRedirect()->assertSessionHas('success');
        $line->refresh();
        $this->assertSame('Geyser element (brass)', $line->description);
        $this->assertEquals(5.0, (float) $line->quantity);
        $this->assertEquals(50.00, (float) $line->cost_total, 'cost total follows quantity x cost');

        $this->post(route('rentals.crew-job.lines.archive', [$this->rawToken, $line->id]))->assertRedirect()->assertSessionHas('success');
        $this->assertTrue($line->fresh()->trashed(), 'removal is a soft archive, never a hard delete');
        $this->assertNotNull(RentalJobCardLine::withTrashed()->find($line->id));

        // a sent line is the office's now
        $this->postLine($this->goodLine(['description' => 'Second']));
        $this->post(route('rentals.crew-job.lines.send', $this->rawToken), ['confirm' => 1])->assertRedirect();
        $sent = RentalJobCardLine::where('description', 'Second')->firstOrFail();
        $this->post(route('rentals.crew-job.lines.update', [$this->rawToken, $sent->id]), $this->goodLine(['description' => 'Tampered']))
            ->assertRedirect()->assertSessionHasErrors('crew');
        $this->assertSame('Second', $sent->fresh()->description);
        $this->post(route('rentals.crew-job.lines.archive', [$this->rawToken, $sent->id]))->assertSessionHasErrors('crew');
        $this->assertFalse($sent->fresh()->trashed());
    }

    public function test_a_line_id_of_another_card_is_a_404_not_a_foothold(): void
    {
        $other = $this->emptyCard(['status' => RentalJobCard::STATUS_SCHEDULED]);
        $foreign = $this->crewDraft($other);

        $this->post(route('rentals.crew-job.lines.update', [$this->rawToken, $foreign->id]), $this->goodLine())->assertNotFound();
        $this->post(route('rentals.crew-job.lines.archive', [$this->rawToken, $foreign->id]))->assertNotFound();
        $this->assertFalse($foreign->fresh()->trashed());
    }

    public function test_the_crew_can_never_touch_an_office_line_by_id(): void
    {
        $office = $this->card->lines()->where('description', 'Office part')->firstOrFail();

        $this->post(route('rentals.crew-job.lines.update', [$this->rawToken, $office->id]), $this->goodLine(['description' => 'Hijacked']))->assertNotFound();
        $this->post(route('rentals.crew-job.lines.archive', [$this->rawToken, $office->id]))->assertNotFound();
        $this->assertSame('Office part', $office->fresh()->description);
        $this->assertFalse($office->fresh()->trashed());
    }

    public function test_send_to_office_flips_every_draft_logs_once_and_notifies_the_agent_once(): void
    {
        Event::fake([RentalCrewLinesSubmitted::class]);
        $this->postLine($this->goodLine(['description' => 'One']));
        $this->postLine($this->goodLine(['description' => 'Two', 'type' => 'labour', 'unit' => 'hour', 'quantity' => 3, 'unit_cost' => '90']));

        $this->post(route('rentals.crew-job.lines.send', $this->rawToken), ['confirm' => 1])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2, RentalJobCardLine::where('office_status', RentalJobCardLine::OFFICE_AWAITING)->count());
        $this->assertSame(1, $this->card->updates()->where('update_type', 'crew_lines_sent')->count(), 'ONE audit row per send, never per line');
        $row = $this->card->updates()->where('update_type', 'crew_lines_sent')->first();
        $this->assertNull($row->created_by_user_id, 'the crew has no CoreX user');
        $this->assertStringContainsString('2 lines', $row->note);
        $this->assertStringContainsString('IP 127.0.0.1', (string) $row->note, 'who/where is recorded in the note');
        Event::assertDispatchedTimes(RentalCrewLinesSubmitted::class, 1);
        Event::assertDispatched(RentalCrewLinesSubmitted::class, fn ($e) => $e->count === 2 && $e->via === CrewViewContext::VIA_JOB_LINK);
    }

    public function test_the_real_listener_gives_the_property_agent_one_in_app_note_per_send(): void
    {
        $this->postLine($this->goodLine(['description' => 'One']));
        $this->postLine($this->goodLine(['description' => 'Two']));

        $this->post(route('rentals.crew-job.lines.send', $this->rawToken), ['confirm' => 1])->assertRedirect();

        $eventTypeId = NotificationEventType::where('key', 'rental_job_card.crew_lines_submitted')->value('id');
        $this->assertNotNull($eventTypeId, 'the F9 migration registered the key');
        $this->assertSame(1, NotificationDispatchLog::where('notification_event_type_id', $eventTypeId)->where('subject_id', $this->card->id)->count());
    }

    public function test_sending_nothing_or_without_the_tick_is_refused_in_plain_language(): void
    {
        $this->post(route('rentals.crew-job.lines.send', $this->rawToken), ['confirm' => 1])
            ->assertSessionHasErrors('crew');
        $this->assertStringContainsString('Add at least one part or labour line', session('errors')->first('crew'));

        $this->postLine($this->goodLine());
        $this->post(route('rentals.crew-job.lines.send', $this->rawToken), [])->assertSessionHasErrors('confirm');
        $this->assertSame(0, RentalJobCardLine::where('office_status', RentalJobCardLine::OFFICE_AWAITING)->count());
    }

    // ── required / empty / malformed input ───────────────────────────────────────────────

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badInput(): array
    {
        return [
            'no description and no item' => [['description' => ''], 'Say what the part or work is'],
            'no cost when money is captured' => [['unit_cost' => ''], 'Enter what it cost you'],
            'cost not a number' => [['unit_cost' => 'lots'], 'Enter the cost as a number'],
            'negative cost' => [['unit_cost' => '-4'], 'Enter the cost as a number'],
            'zero quantity' => [['quantity' => 0], 'Enter how many'],
            'text quantity' => [['quantity' => 'two'], 'Enter how many'],
            'bad type' => [['type' => 'gold'], 'Choose Part or Labour'],
            'unit too long' => [['unit' => str_repeat('u', 31)], 'unit is too long'],
            'note too long' => [['note' => str_repeat('n', 1001)], 'note is too long'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badInput')]
    public function test_bad_input_is_refused_with_a_message_the_crew_can_read_and_nothing_is_saved(array $override, string $message): void
    {
        $this->postLine($this->goodLine($override))->assertRedirect()->assertSessionHasErrors('crew');

        $this->assertStringContainsString($message, session('errors')->first('crew'));
        $this->assertSame(0, RentalJobCardLine::where('origin', '!=', RentalJobCardLine::ORIGIN_OFFICE)->count());
    }

    public function test_an_item_not_on_the_agencys_own_list_is_refused(): void
    {
        $this->postLine($this->goodLine(['rental_catalogue_item_id' => 99999999]))->assertSessionHasErrors('crew');
        $this->assertStringContainsString('not on the list', session('errors')->first('crew'));
    }

    public function test_a_picked_catalogue_item_fills_the_description_but_the_crew_never_gets_its_cost_or_price(): void
    {
        $item = $this->catalogueItem('ELEM', 'part', 600.00, 350.00);

        $this->postLine(['rental_catalogue_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => '300'])->assertRedirect();

        $line = RentalJobCardLine::where('rental_catalogue_item_id', $item->id)->firstOrFail();
        $this->assertSame('ELEM item', $line->description);
        $this->assertEquals(300.00, (float) $line->unit_cost, 'the crew typed 300 — the catalogue default (350) is the office\'s, never offered');
        $this->assertNull($line->unit_price);

        $body = $this->get($this->linkUrl())->getContent();
        $this->assertStringContainsString('ELEM', $body);
        $this->assertStringNotContainsString('600.00', $body, 'no catalogue price');
        $this->assertStringNotContainsString('R 600', $body);
        $this->assertStringNotContainsString('350.00', $body, 'no catalogue cost');
    }

    public function test_with_pricing_off_no_cost_is_asked_for_or_stored(): void
    {
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['capture_prices_on_job_cards' => false]);

        $this->postLine($this->goodLine(['unit_cost' => '']))->assertRedirect()->assertSessionHasNoErrors();

        $line = RentalJobCardLine::where('description', 'Geyser element')->firstOrFail();
        $this->assertNull($line->unit_cost);
        $this->assertStringNotContainsString('cost you', $this->get($this->linkUrl())->getContent());
    }

    // ── photos ────────────────────────────────────────────────────────────────────────────

    public function test_up_to_three_photos_are_stored_against_the_line_with_the_crew_link_uploader(): void
    {
        $photos = [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')];

        $this->postLine($this->goodLine(), $photos)->assertRedirect()->assertSessionHasNoErrors();

        $line = RentalJobCardLine::where('description', 'Geyser element')->firstOrFail();
        $stored = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_line_id', $line->id)->get();
        $this->assertCount(3, $stored);
        $this->assertSame([RentalWorkOrderPhoto::VIA_CREW_LINK], $stored->pluck('uploaded_via')->unique()->values()->all());
        $this->assertSame([RentalJobCardLine::PHOTO_TYPE], $stored->pluck('photo_type')->unique()->values()->all());

        // they appear against the line, not in the job gallery
        $payload = app(CrewJobService::class)->payload($this->card->fresh(), $this->crewCtx($this->card));
        $this->assertSame([], $payload['photos']);
        $this->assertCount(3, $payload['blocks']['pricing']['lines'][0]['photos']);
    }

    public function test_a_fourth_photo_is_refused(): void
    {
        $photos = [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg'), UploadedFile::fake()->image('d.jpg')];

        $this->postLine($this->goodLine(), $photos)->assertSessionHasErrors('photos');
        $this->assertSame(0, RentalJobCardLine::where('origin', '!=', RentalJobCardLine::ORIGIN_OFFICE)->count());
    }

    // ── the invariant ────────────────────────────────────────────────────────────────────

    public function test_awaiting_and_rejected_lines_are_in_no_total_quote_or_owner_figure_and_the_crew_sees_the_reason(): void
    {
        $line = $this->awaitingLine($this->card, ['description' => 'Waiting line', 'unit_cost' => 5000]);

        $card = $this->card->fresh();
        $this->assertEquals(4321.99, (float) $card->total_amount);
        $this->assertEquals(4321.99, app(\App\Services\Rentals\RentalJobCardVatService::class)->inclusiveTotal($card->load('lines')));

        $line->forceFill(['office_status' => RentalJobCardLine::OFFICE_REJECTED, 'reject_reason' => 'We already have one in stock'])->save();
        $body = $this->get($this->linkUrl())->getContent();
        $this->assertStringContainsString('Not accepted', $body);
        $this->assertStringContainsString('We already have one in stock', $body);
        $this->assertEquals(4321.99, (float) $this->card->fresh()->total_amount);
    }

    public function test_a_closed_card_takes_no_more_lines(): void
    {
        $this->card->forceFill(['status' => RentalJobCard::STATUS_COMPLETED])->save();
        RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $this->card->id)->update(['revoked_at' => null]);

        $this->postLine($this->goodLine());

        $this->assertSame(0, RentalJobCardLine::where('origin', '!=', RentalJobCardLine::ORIGIN_OFFICE)->count());
    }

    public function test_a_dead_or_forged_link_adds_nothing(): void
    {
        $this->post(route('rentals.crew-job.lines.store', str_repeat('a', 64)), $this->goodLine())->assertOk();
        $this->assertSame(0, RentalJobCardLine::where('origin', '!=', RentalJobCardLine::ORIGIN_OFFICE)->count());
    }

    public function test_a_price_request_marks_new_lines_as_pricing_lines_unless_the_crew_says_extra(): void
    {
        $request = $this->card->priceRequests()->create(['agency_id' => $this->agency->id, 'requested_by_user_id' => $this->admin->id, 'requested_at' => now(), 'status' => 'open', 'note' => 'Whole bathroom please']);

        $this->postLine($this->goodLine(['description' => 'Priced']));
        $this->postLine($this->goodLine(['description' => 'Extra', 'is_extra' => 1]));

        $priced = RentalJobCardLine::where('description', 'Priced')->firstOrFail();
        $extra = RentalJobCardLine::where('description', 'Extra')->firstOrFail();
        $this->assertSame(RentalJobCardLine::ORIGIN_CREW_PRICING, $priced->origin);
        $this->assertSame($request->id, $priced->rental_job_card_price_request_id);
        $this->assertSame(RentalJobCardLine::ORIGIN_CREW_EXTRA, $extra->origin);
        $this->assertNull($extra->rental_job_card_price_request_id);
        $this->assertSame(RentalJobCardPriceRequest::STATUS_OPEN, $request->fresh()->status);
    }
}
