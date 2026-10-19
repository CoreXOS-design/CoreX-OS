<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalWorkOrderPhoto;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md section 17.43 (19 Oct 2026) - Line entry, simplified. CrewLineEntryService is the ONE implementation of
 * the crew's add / change / remove / undo; the per-job link and the crew page both call it. The no-reload row talks JSON to the same routes
 * the plain form posts to (JavaScript off still saves and redirects).
 */
final class LineEntryServiceTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;
    private string $raw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Line Entry');
        $this->card = $this->emptyCard(['status' => RentalJobCard::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);
        $this->raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin)['raw_token'];
    }

    /** The no-reload row's request: Accept JSON, files as files. */
    private function ajax(string $uri, array $data = [], array $files = []): TestResponse
    {
        return $this->call('POST', $uri, $data, [], $files, ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    private function add(array $data, array $files = []): TestResponse
    {
        return $this->ajax(route('rentals.crew-job.lines.store', $this->raw), $data, $files);
    }

    private function part(array $over = []): array
    {
        return $over + ['type' => 'part', 'description' => 'Basin trap', 'quantity' => 1, 'unit_cost' => '85.00'];
    }

    // ── add ─────────────────────────────────────────────────────────────────────────────

    public function test_add_saves_a_draft_and_answers_with_the_row_and_the_send_bar(): void
    {
        $res = $this->add($this->part())->assertOk()->assertJson(['ok' => true, 'draft_count' => 1, 'replayed' => false]);

        $line = RentalJobCardLine::where('description', 'Basin trap')->firstOrFail();
        $this->assertSame(RentalJobCardLine::OFFICE_CREW_DRAFT, $line->office_status);
        $this->assertSame($line->id, $res->json('line.id'));
        $this->assertTrue($res->json('line.editable'));
        $this->assertSame('85.00', $res->json('line.unit_cost'));
        $this->assertStringContainsString('Send to office (', $res->json('send_html'));
        $this->assertStringContainsString('data-send-btn', $res->json('send_html'));
    }

    public function test_a_picked_item_decides_part_or_labour_unit_and_cost_without_the_crew_sending_them(): void
    {
        $part = $this->catalogueItem('ELEM', 'part', 600.00, 350.00);
        $labour = $this->catalogueItem('HOUR', 'labour', 100.00, 80.00);

        // only the item and a quantity - no type, no unit, no cost
        $this->add(['rental_catalogue_item_id' => $part->id, 'quantity' => 1])->assertOk();
        $this->add(['rental_catalogue_item_id' => $labour->id, 'quantity' => 2])->assertOk();

        $p = RentalJobCardLine::where('rental_catalogue_item_id', $part->id)->firstOrFail();
        $this->assertSame('part', $p->type);
        $this->assertSame('Each', $p->unit);
        $this->assertSame('350.00', number_format((float) $p->unit_cost, 2, '.', ''), 'the cost on file stands unless the crew typed another');
        $l = RentalJobCardLine::where('rental_catalogue_item_id', $labour->id)->firstOrFail();
        $this->assertSame('labour', $l->type);
        $this->assertSame(2.0, (float) $l->quantity, 'labour is hours');
    }

    public function test_a_free_typed_row_with_no_type_is_a_part(): void
    {
        $this->add(['description' => 'Washer', 'quantity' => 1, 'unit_cost' => '4'])->assertOk();

        $this->assertSame('part', RentalJobCardLine::where('description', 'Washer')->firstOrFail()->type);
    }

    public function test_a_row_that_cannot_be_saved_answers_422_in_plain_language_and_creates_nothing(): void
    {
        $this->add(['type' => 'part', 'description' => 'Washer', 'quantity' => 1])
            ->assertStatus(422)->assertJson(['ok' => false, 'message' => 'Enter what it cost you (the actual cost).']);
        $this->add(['type' => 'part', 'quantity' => 1, 'unit_cost' => '4'])
            ->assertStatus(422)->assertJson(['message' => 'Say what the part or work is — pick an item or type a description.']);
        $this->add(['type' => 'labour', 'description' => 'Fit it', 'quantity' => 1, 'unit_cost' => '50'])
            ->assertStatus(422)->assertJson(['message' => 'Labour is priced by the office — enter the hours only.']);

        $this->assertSame(0, RentalJobCardLine::count());
    }

    public function test_a_retried_add_with_the_same_key_returns_the_first_line_and_never_a_second(): void
    {
        $key = 'retry-key-1234-abcd';
        $first = $this->add($this->part(['client_key' => $key]))->assertOk();
        $again = $this->add($this->part(['client_key' => $key]))->assertOk()->assertJson(['replayed' => true]);

        $this->assertSame($first->json('line.id'), $again->json('line.id'));
        $this->assertSame(1, RentalJobCardLine::count());

        // a different row (another key) is a different line
        $this->add($this->part(['client_key' => 'another-key-5678-wxyz', 'description' => 'Second']))->assertOk();
        $this->assertSame(2, RentalJobCardLine::count());
    }

    public function test_a_plain_form_post_still_saves_and_redirects_with_a_banner(): void
    {
        $this->post(route('rentals.crew-job.lines.store', $this->raw), $this->part())
            ->assertRedirect(route('rentals.crew-job.show', $this->raw))->assertSessionHas('success');

        $this->assertSame(1, RentalJobCardLine::count());
    }

    // ── receipt ─────────────────────────────────────────────────────────────────────────

    public function test_a_receipt_taken_with_the_row_is_attached_to_that_line_and_not_to_a_labour_line(): void
    {
        $res = $this->add($this->part(['description' => 'Element', 'unit_cost' => '48.50']), ['receipt_camera' => UploadedFile::fake()->image('receipt.jpg')])->assertOk();

        $line = RentalJobCardLine::findOrFail($res->json('line.id'));
        $receipts = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_line_id', $line->id)->where('photo_type', RentalJobCardLine::RECEIPT_PHOTO_TYPE)->get();
        $this->assertCount(1, $receipts);
        $this->assertSame($receipts->first()->storage_path, $res->json('line.receipt_url'));
        $this->assertCount(0, RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_line_id', $line->id)->where('photo_type', RentalJobCardLine::PHOTO_TYPE)->get(), 'a receipt is not one of the up-to-3 photos');

        $labour = $this->add(['type' => 'labour', 'description' => 'Fit it', 'quantity' => 1], ['receipt_camera' => UploadedFile::fake()->image('r.jpg')])->assertOk();
        $this->assertNull($labour->json('line.receipt_url'), 'labour has no receipt');
    }

    public function test_a_receipt_added_later_to_a_saved_row_replaces_the_one_shown(): void
    {
        $id = $this->add($this->part(['description' => 'Element', 'unit_cost' => '48.50']), ['receipt_camera' => UploadedFile::fake()->image('one.jpg')])->json('line.id');
        $second = $this->ajax(route('rentals.crew-job.lines.update', [$this->raw, $id]), $this->part(['description' => 'Element', 'unit_cost' => '48.50']), ['receipt_file' => UploadedFile::fake()->image('two.png')])->assertOk();

        $newest = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_line_id', $id)->where('photo_type', RentalJobCardLine::RECEIPT_PHOTO_TYPE)->latest('id')->firstOrFail();
        $this->assertSame($newest->storage_path, $second->json('line.receipt_url'));
    }

    // ── change, remove, undo ────────────────────────────────────────────────────────────

    public function test_a_saved_row_saves_its_own_changes(): void
    {
        $id = $this->add($this->part())->json('line.id');

        $this->ajax(route('rentals.crew-job.lines.update', [$this->raw, $id]), $this->part(['quantity' => 3, 'unit_cost' => '90']))
            ->assertOk()->assertJson(['ok' => true, 'line' => ['quantity' => '3', 'unit_cost' => '90.00']]);

        $this->assertSame(3.0, (float) RentalJobCardLine::findOrFail($id)->quantity);
    }

    public function test_remove_archives_and_undo_restores_the_same_line(): void
    {
        $id = $this->add($this->part())->json('line.id');

        $gone = $this->ajax(route('rentals.crew-job.lines.archive', [$this->raw, $id]))->assertOk()->assertJson(['ok' => true, 'draft_count' => 0, 'send_html' => '']);
        $this->assertNotNull(RentalJobCardLine::withTrashed()->findOrFail($id)->deleted_at, 'archived (soft delete), never removed from the table');
        $this->assertSame(1, RentalJobCardLine::withTrashed()->count());

        $back = $this->ajax(route('rentals.crew-job.lines.restore', [$this->raw, $id]))->assertOk()->assertJson(['ok' => true, 'draft_count' => 1]);
        $this->assertSame($id, $back->json('line.id'));
        $this->assertNull(RentalJobCardLine::findOrFail($id)->deleted_at);
        $this->assertStringContainsString('Send to office (', $back->json('send_html'));

        // Undo twice is harmless
        $this->ajax(route('rentals.crew-job.lines.restore', [$this->raw, $id]))->assertOk();
        $this->assertSame(1, RentalJobCardLine::count());
        $this->assertNotNull($gone);
    }

    public function test_a_line_already_sent_to_the_office_cannot_be_changed_removed_or_restored_from_the_link(): void
    {
        $id = $this->add($this->part())->json('line.id');
        $this->post(route('rentals.crew-job.lines.send', $this->raw), ['findings' => 'Found a fault.'])->assertRedirect();

        $this->ajax(route('rentals.crew-job.lines.archive', [$this->raw, $id]))->assertStatus(422)->assertJson(['ok' => false]);
        $this->ajax(route('rentals.crew-job.lines.restore', [$this->raw, $id]))->assertStatus(422)->assertJson(['ok' => false]);
        $this->ajax(route('rentals.crew-job.lines.update', [$this->raw, $id]), $this->part(['quantity' => 9]))->assertStatus(422);
        $this->assertNull(RentalJobCardLine::findOrFail($id)->deleted_at);
    }

    public function test_a_plain_form_remove_and_undo_still_redirect_with_a_banner(): void
    {
        $id = $this->add($this->part())->json('line.id');

        $this->post(route('rentals.crew-job.lines.archive', [$this->raw, $id]))->assertRedirect()->assertSessionHas('success', 'Line removed.');
        $this->post(route('rentals.crew-job.lines.restore', [$this->raw, $id]))->assertRedirect()->assertSessionHas('success', 'Line put back on your list.');
        $this->assertNull(RentalJobCardLine::findOrFail($id)->deleted_at);
    }

    public function test_a_line_of_another_card_is_a_404_on_every_handler(): void
    {
        $other = $this->emptyCard(['status' => RentalJobCard::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);
        $foreign = $this->crewDraft($other);

        $this->ajax(route('rentals.crew-job.lines.update', [$this->raw, $foreign->id]), $this->part())->assertNotFound();
        $this->ajax(route('rentals.crew-job.lines.archive', [$this->raw, $foreign->id]))->assertNotFound();
        $this->ajax(route('rentals.crew-job.lines.restore', [$this->raw, $foreign->id]))->assertNotFound();
    }

    // ── the crew page uses the SAME service ─────────────────────────────────────────────

    public function test_the_crew_page_add_remove_and_undo_go_through_the_same_service(): void
    {
        $page = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];
        $base = "/secure/crews/{$page}/job-cards/{$this->card->id}/lines";

        $id = $this->ajax($base, $this->part(['description' => 'Page line']))->assertOk()->assertJson(['ok' => true, 'draft_count' => 1])->json('line.id');
        $line = RentalJobCardLine::findOrFail($id);
        $this->assertSame('crew_page', $this->crewViaOf($line));

        $this->ajax("{$base}/{$id}", $this->part(['description' => 'Page line', 'quantity' => 4]))->assertOk()->assertJson(['line' => ['quantity' => '4']]);
        $this->ajax("{$base}/{$id}/archive")->assertOk()->assertJson(['draft_count' => 0]);
        $this->ajax("{$base}/{$id}/restore")->assertOk()->assertJson(['draft_count' => 1]);
        $this->add($this->part(['description' => 'Page line']))->assertOk(); // the job link still works on the same card

        $this->post($base, $this->part(['description' => 'Plain']))->assertRedirect()->assertSessionHas('success');
    }

    private function crewViaOf(RentalJobCardLine $line): string
    {
        return str_contains((string) $line->crew_added_by_label, 'crew page') ? 'crew_page' : 'crew_link';
    }

    // ── the crew never sees markup or a selling price ───────────────────────────────────

    public function test_the_row_and_the_page_never_carry_markup_or_selling(): void
    {
        $item = $this->catalogueItem('ELEM', 'part', 777.77, 350.00);
        $row = $this->add(['rental_catalogue_item_id' => $item->id, 'quantity' => 1])->assertOk()->json('line');

        $this->assertSame([], array_values(preg_grep('/selling|markup|margin|unit_price|line_total/i', array_keys($row))), 'no money key but the crew\'s own cost');
        $this->assertStringNotContainsString('777.77', json_encode($row));

        $html = $this->get(route('rentals.crew-job.show', $this->raw))->assertOk()->getContent();
        $this->assertStringNotContainsString('777.77', $html);
        $this->assertStringNotContainsStringIgnoringCase('markup', $html);
    }

    public function test_the_page_opens_with_one_blank_row_and_no_add_a_part_or_labour_toggle(): void
    {
        $html = $this->get(route('rentals.crew-job.show', $this->raw))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="cj-line-form cj-entry is-new"'), 'one blank row, open');
        $this->assertStringContainsString('Add new line', $html);
        $this->assertStringNotContainsString('+ Add a part or labour', $html);
        $this->assertStringNotContainsString('Change this line', $html);
        $this->assertStringNotContainsString('id="cj-send-form"', $html, 'no Send bar until a line exists');

        $this->add($this->part())->assertOk();
        $html = $this->get(route('rentals.crew-job.show', $this->raw))->getContent();
        $this->assertSame(1, substr_count($html, 'class="cj-line-form cj-entry is-saved"'));
        $this->assertSame(1, substr_count($html, 'class="cj-line-form cj-entry is-new"'), 'still exactly one blank row after a saved line');
        $this->assertStringContainsString('id="cj-send-form"', $html);
    }
}
