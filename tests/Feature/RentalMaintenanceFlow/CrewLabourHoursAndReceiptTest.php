<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalWorkOrderPhoto;
use App\Services\Rentals\CrewJobService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * AT-459 (.ai/specs/rental-work-orders.md §14.32 / §14.32) — the crew link's Parts & labour after Johan's walk on job card 97:
 * the crew was asked "What it cost you, each (R)" for an hourly plumbing rate, could not answer, and the browser silently stopped the
 * form — the labour line never reached the office. The crew never prices labour: they enter HOURS, the cost is worked out on the
 * server from the catalogue's cost rate (or arrives blank for the office to price). A part has an optional receipt photo.
 */
final class CrewLabourHoursAndReceiptTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;
    private string $rawToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Labour Hours');
        $this->card = $this->emptyCard(['status' => RentalJobCard::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);
        $this->rawToken = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin)['raw_token'];
    }

    /** POST a form, sending any UploadedFile (or list of them) as files and everything else as fields. */
    private function postForm(string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        $files = [];
        foreach ($data as $key => $value) {
            $isFile = $value instanceof UploadedFile;
            $isFileList = is_array($value) && $value !== [] && $value[array_key_first($value)] instanceof UploadedFile;
            if ($isFile || $isFileList) {
                $files[$key] = $value;
                unset($data[$key]);
            }
        }

        return $this->call('POST', $uri, $data, [], $files);
    }

    private function postLine(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->postForm(route('rentals.crew-job.lines.store', $this->rawToken), $data);
    }

    private function labourItem(string $code, ?float $cost, string $unit = 'Hour', ?float $price = 100.0): RentalCatalogueItem
    {
        return RentalCatalogueItem::create([
            'agency_id' => $this->agency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'labour')->firstOrFail()->id,
            'code' => $code, 'description' => $code . ' labour', 'default_price' => $price, 'default_cost' => $cost, 'sort_order' => 1,
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', $unit)->firstOrFail()->id,
            'created_by_user_id' => $this->admin->id,
        ]);
    }

    // ── labour: hours, never a crew-typed cost ───────────────────────────────────────────

    public function test_labour_from_the_list_arrives_with_cost_equal_to_hours_times_the_catalogue_rate(): void
    {
        $item = $this->labourItem('PLUMB-HOUR', 80.00);

        $this->postLine(['rental_catalogue_item_id' => $item->id, 'type' => 'labour', 'quantity' => '2.5'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $line = RentalJobCardLine::where('rental_catalogue_item_id', $item->id)->firstOrFail();
        $this->assertSame('labour', $line->type);
        $this->assertEquals(2.5, (float) $line->quantity, 'the hours');
        $this->assertSame('Hour', $line->unit);
        $this->assertEquals(80.00, (float) $line->unit_cost, "the catalogue's cost rate, not anything the crew sent");
        $this->assertEquals(200.00, (float) $line->cost_total, '2.5 hours x R80');
        $this->assertNull($line->unit_price, 'the crew line carries no selling');

        // and it reaches the office when the crew presses Send
        $this->post(route('rentals.crew-job.lines.send', $this->rawToken), ['confirm' => '1'])->assertRedirect();
        $this->assertSame(RentalJobCardLine::OFFICE_AWAITING, $line->fresh()->office_status);
    }

    public function test_a_crew_supplied_cost_on_labour_is_refused_and_nothing_is_saved(): void
    {
        $item = $this->labourItem('PLUMB-HOUR', 80.00);

        $this->postLine(['rental_catalogue_item_id' => $item->id, 'type' => 'labour', 'quantity' => 2, 'unit_cost' => '5'])
            ->assertRedirect()->assertSessionHasErrors('crew');

        $this->assertStringContainsString('priced by the office', session('errors')->first('crew'));
        $this->assertSame(0, RentalJobCardLine::where('rental_catalogue_item_id', $item->id)->count());
    }

    public function test_a_labour_item_cannot_be_turned_into_a_part_to_type_a_price_against_it(): void
    {
        $item = $this->labourItem('PLUMB-HOUR', 80.00);

        // the form says "part" and carries a cost: the picked item decides, so this is labour with a typed cost — refused
        $this->postLine(['rental_catalogue_item_id' => $item->id, 'type' => 'part', 'quantity' => 1, 'unit_cost' => '1'])
            ->assertRedirect()->assertSessionHasErrors('crew');
        $this->assertSame(0, RentalJobCardLine::where('rental_catalogue_item_id', $item->id)->count());

        // without a cost it is saved as what the catalogue says it is
        $this->postLine(['rental_catalogue_item_id' => $item->id, 'type' => 'part', 'quantity' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('labour', RentalJobCardLine::where('rental_catalogue_item_id', $item->id)->firstOrFail()->type);
    }

    public function test_free_typed_labour_is_description_and_hours_and_arrives_with_a_blank_cost(): void
    {
        $this->postLine(['type' => 'labour', 'description' => 'Unblock the drain', 'quantity' => 3])
            ->assertRedirect()->assertSessionHasNoErrors();

        $line = RentalJobCardLine::where('description', 'Unblock the drain')->firstOrFail();
        $this->assertSame('labour', $line->type);
        $this->assertEquals(3.0, (float) $line->quantity);
        $this->assertSame('Hour', $line->unit);
        $this->assertNull($line->unit_cost, 'blank for the office to price');
        $this->assertNull($line->cost_total);
    }

    public function test_labour_without_hours_is_refused_in_plain_language(): void
    {
        $this->postLine(['type' => 'labour', 'description' => 'Unblock the drain', 'quantity' => ''])
            ->assertRedirect()->assertSessionHasErrors('crew');
        $this->assertStringContainsString('Enter the hours', session('errors')->first('crew'));

        $this->postLine(['type' => 'labour', 'description' => 'Unblock the drain', 'quantity' => '0'])->assertSessionHasErrors('crew');
        $this->assertSame(0, RentalJobCardLine::where('description', 'Unblock the drain')->count());
    }

    public function test_labour_counted_in_days_keeps_the_items_own_unit(): void
    {
        $item = $this->labourItem('PLUMB-DAY', 640.00, 'Day');

        $this->postLine(['rental_catalogue_item_id' => $item->id, 'quantity' => 2])->assertRedirect()->assertSessionHasNoErrors();

        $line = RentalJobCardLine::where('rental_catalogue_item_id', $item->id)->firstOrFail();
        $this->assertSame('Day', $line->unit);
        $this->assertEquals(1280.00, (float) $line->cost_total);
    }

    public function test_labour_from_an_item_with_no_cost_on_file_arrives_blank_for_the_office(): void
    {
        $item = $this->labourItem('PLUMB-HOUR', null);   // like PLUMBING-HOURLY-RATE on QA1: a selling price, no cost rate

        $this->postLine(['rental_catalogue_item_id' => $item->id, 'quantity' => 4])->assertRedirect()->assertSessionHasNoErrors();

        $line = RentalJobCardLine::where('rental_catalogue_item_id', $item->id)->firstOrFail();
        $this->assertNull($line->unit_cost);
        $this->assertNull($line->cost_total);
        $this->assertNull($line->unit_price, 'the selling price (R100) is never copied onto a crew line');
    }

    public function test_changing_a_labour_draft_keeps_the_rule(): void
    {
        $item = $this->labourItem('PLUMB-HOUR', 80.00);
        $this->postLine(['rental_catalogue_item_id' => $item->id, 'quantity' => 1])->assertRedirect();
        $line = RentalJobCardLine::where('rental_catalogue_item_id', $item->id)->firstOrFail();

        $this->postForm(route('rentals.crew-job.lines.update', [$this->rawToken, $line->id]), ['rental_catalogue_item_id' => $item->id, 'quantity' => 4, 'unit_cost' => '9'])
            ->assertSessionHasErrors('crew');
        $this->postForm(route('rentals.crew-job.lines.update', [$this->rawToken, $line->id]), ['rental_catalogue_item_id' => $item->id, 'quantity' => 4])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals(320.00, (float) $line->fresh()->cost_total, '4 hours x R80');
    }

    // ── parts: cost prefilled from the list; required only when nothing was picked ───────

    public function test_a_part_from_the_list_without_a_typed_cost_takes_the_catalogue_cost_and_a_typed_cost_wins(): void
    {
        $item = $this->catalogueItem('ELEM', 'part', 600.00, 350.00);

        $this->postLine(['rental_catalogue_item_id' => $item->id, 'quantity' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $line = RentalJobCardLine::where('rental_catalogue_item_id', $item->id)->latest('id')->firstOrFail();
        $this->assertEquals(350.00, (float) $line->unit_cost);
        $this->assertEquals(700.00, (float) $line->cost_total);

        $this->postLine(['rental_catalogue_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => '300'])->assertRedirect();
        $this->assertEquals(300.00, (float) RentalJobCardLine::where('rental_catalogue_item_id', $item->id)->latest('id')->firstOrFail()->unit_cost, 'what they actually paid');
    }

    public function test_a_part_with_nothing_picked_still_needs_its_cost(): void
    {
        $this->postLine(['type' => 'part', 'description' => 'Washer', 'quantity' => 1])->assertSessionHasErrors('crew');
        $this->assertStringContainsString('Enter what it cost you', session('errors')->first('crew'));
    }

    public function test_the_form_never_blocks_a_labour_line_in_the_browser_and_shows_no_labour_money(): void
    {
        $labour = $this->labourItem('PLUMB-HOUR', 80.00);
        $part = $this->catalogueItem('ELEM', 'part', 600.00, 350.00);

        $html = $this->get(route('rentals.crew-job.show', $this->rawToken))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/name="unit_cost"[^>]*\brequired\b/', $html, 'a browser-required cost box is what silently stopped the labour line');
        $this->assertStringContainsString('name="receipt_camera"', $html);
        $this->assertStringContainsString('capture="environment"', $html);
        $this->assertStringContainsString('name="receipt_file"', $html);
        $this->assertStringContainsString('Take photo', $html);
        $this->assertStringContainsString('Choose from phone', $html);
        $this->assertStringContainsString('data-cost="350.00"', $html, 'a part is pre-filled with the cost on file');
        $this->assertStringNotContainsString('80.00', $html, 'no labour rate');
        $this->assertStringNotContainsString('600.00', $html, 'no selling price');
        $this->assertStringNotContainsString('100.00', $html, 'no labour selling price');
    }

    public function test_the_crew_never_sees_a_labour_rate_or_amount_on_their_own_labour_line(): void
    {
        $item = $this->labourItem('PLUMB-HOUR', 80.00);
        $this->postLine(['rental_catalogue_item_id' => $item->id, 'quantity' => 2])->assertRedirect();
        $this->post(route('rentals.crew-job.lines.send', $this->rawToken), ['confirm' => '1'])->assertRedirect();
        $line = RentalJobCardLine::where('rental_catalogue_item_id', $item->id)->firstOrFail();

        $block = \App\Services\Rentals\CrewPricingBlock::for($this->card->fresh(), $this->crewCtx($this->card->fresh()));
        $row = collect($block['lines'])->firstWhere('id', $line->id);
        $this->assertNull($row['unit_cost']);
        $this->assertNull($row['cost_total']);
        $this->assertNotContains('80.00', array_values(array_filter($row, 'is_string')));
    }

    // ── receipts ─────────────────────────────────────────────────────────────────────────

    public function test_a_part_receipt_from_the_camera_is_stored_against_the_line_and_is_not_one_of_the_three_photos(): void
    {
        $photos = [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')];
        $this->postForm(route('rentals.crew-job.lines.store', $this->rawToken), [
            'type' => 'part', 'description' => 'Geyser element', 'quantity' => 1, 'unit_cost' => '48.50', 'photos' => $photos,
        ] + ['receipt_camera' => UploadedFile::fake()->image('receipt.jpg')])->assertRedirect()->assertSessionHasNoErrors();

        $line = RentalJobCardLine::where('description', 'Geyser element')->firstOrFail();
        $stored = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_line_id', $line->id)->get();
        $this->assertCount(3, $stored->where('photo_type', RentalJobCardLine::PHOTO_TYPE), 'the three ordinary photos');
        $receipt = $stored->where('photo_type', RentalJobCardLine::RECEIPT_PHOTO_TYPE);
        $this->assertCount(1, $receipt);
        $this->assertSame($this->card->id, (int) $receipt->first()->rental_job_card_id);
        $this->assertSame('crew_link', $receipt->first()->uploaded_via);
    }

    public function test_a_receipt_chosen_from_the_phone_works_too_and_the_crew_sees_it_was_added(): void
    {
        $this->postForm(route('rentals.crew-job.lines.store', $this->rawToken), [
            'type' => 'part', 'description' => 'Washer', 'quantity' => 1, 'unit_cost' => '4',
        ] + ['receipt_file' => UploadedFile::fake()->image('r.png')])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, RentalWorkOrderPhoto::withoutGlobalScopes()->where('photo_type', RentalJobCardLine::RECEIPT_PHOTO_TYPE)->count());
        $html = $this->get(route('rentals.crew-job.show', $this->rawToken))->getContent();
        $this->assertStringContainsString('data-receipt-added', $html);
    }

    public function test_a_receipt_that_is_not_a_picture_is_refused(): void
    {
        $this->postForm(route('rentals.crew-job.lines.store', $this->rawToken), [
            'type' => 'part', 'description' => 'Washer', 'quantity' => 1, 'unit_cost' => '4',
        ] + ['receipt_file' => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf')])->assertSessionHasErrors('receipt_file');
        $this->assertSame(0, RentalJobCardLine::where('description', 'Washer')->count());
    }

    public function test_labour_has_no_receipt_even_if_one_is_sent(): void
    {
        $this->postForm(route('rentals.crew-job.lines.store', $this->rawToken), [
            'type' => 'labour', 'description' => 'Fix tap', 'quantity' => 1,
        ] + ['receipt_camera' => UploadedFile::fake()->image('r.jpg')])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, RentalWorkOrderPhoto::withoutGlobalScopes()->where('photo_type', RentalJobCardLine::RECEIPT_PHOTO_TYPE)->count());
    }

    public function test_a_receipt_is_the_offices_alone_never_in_the_job_gallery_or_the_owner_line_photos(): void
    {
        $this->postForm(route('rentals.crew-job.lines.store', $this->rawToken), [
            'type' => 'part', 'description' => 'Washer', 'quantity' => 1, 'unit_cost' => '4',
        ] + ['receipt_camera' => UploadedFile::fake()->image('r.jpg')])->assertRedirect();
        $line = RentalJobCardLine::where('description', 'Washer')->firstOrFail();

        $payload = app(CrewJobService::class)->payload($this->card->fresh(), $this->crewCtx($this->card->fresh()));
        $this->assertSame([], $payload['photos'], 'the receipt is not a job photo');

        // the same filter the owner PDF and the owner API apply to a line's photos
        $ownerSees = RentalWorkOrderPhoto::withoutGlobalScopes()->whereIn('rental_job_card_line_id', [$line->id])
            ->where('photo_type', RentalJobCardLine::PHOTO_TYPE)->count();
        $this->assertSame(0, $ownerSees);
    }

    public function test_the_office_sees_the_receipt_on_the_awaiting_line(): void
    {
        $this->postForm(route('rentals.crew-job.lines.store', $this->rawToken), [
            'type' => 'part', 'description' => 'Washer', 'quantity' => 1, 'unit_cost' => '4',
        ] + ['receipt_camera' => UploadedFile::fake()->image('r.jpg')])->assertRedirect();
        $this->post(route('rentals.crew-job.lines.send', $this->rawToken), ['confirm' => '1'])->assertRedirect();
        $line = RentalJobCardLine::where('description', 'Washer')->firstOrFail();
        $receipt = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_line_id', $line->id)->where('photo_type', RentalJobCardLine::RECEIPT_PHOTO_TYPE)->firstOrFail();

        $html = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();

        $this->assertStringContainsString('data-awaiting-line="' . $line->id . '"', $html);
        $this->assertStringContainsString('data-receipt-thumb', $html);
        $this->assertStringContainsString($receipt->storage_path, $html);
    }
}
