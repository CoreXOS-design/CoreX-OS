<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalWorkOrderPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md section 17.43 (19 Oct 2026), Part B - the OFFICE job card's line entry. Add, change, archive and restore answer a
 * fetch with JSON (the screen swaps the figures without a reload) and a plain form post with the redirect they always gave; a free-typed line
 * is a PART; a part line can carry a receipt through the same service method the crew's receipt uses; markup hides behind one "Markup" link.
 */
final class OfficeLineEntryTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private User $user;
    private RentalJobCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Office Line Entry');
        $this->user = $this->agentHolding(['rental_job_cards.create', 'rental_job_cards.price', 'rental_job_cards.view_costs', 'rental_catalogue.view']);
        $this->card = $this->emptyCard();
    }

    private function ajax(string $method, string $uri, array $data = [], array $files = []): TestResponse
    {
        return $this->actingAs($this->user)->call($method, $uri, $data, [], $files, ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    private function store(array $data, array $files = []): TestResponse
    {
        return $this->ajax('POST', route('corex.rental-job-cards.lines.store', $this->card), $data, $files);
    }

    private function receiptsOf(RentalJobCardLine $line)
    {
        return RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_line_id', $line->id)->where('photo_type', RentalJobCardLine::RECEIPT_PHOTO_TYPE)->get();
    }

    // ── add ─────────────────────────────────────────────────────────────────────────────

    public function test_add_answers_json_and_a_free_typed_line_with_no_type_is_a_part(): void
    {
        $res = $this->store(['description' => 'Washer', 'quantity' => 2, 'unit_cost' => '3.50'])->assertOk()->assertJson(['ok' => true, 'message' => 'Line added.']);

        $line = RentalJobCardLine::findOrFail($res->json('line_id'));
        $this->assertSame('part', $line->type, "Johan's ruling: free-typed lines default to Part, not Labour");
        $this->assertSame(RentalJobCardLine::OFFICE_ACCEPTED, $line->office_status);
    }

    public function test_a_plain_form_add_still_redirects_with_a_banner_and_the_focus_flash(): void
    {
        $this->actingAs($this->user)->post(route('corex.rental-job-cards.lines.store', $this->card), ['description' => 'Washer', 'quantity' => 1])
            ->assertRedirect(route('corex.rental-job-cards.show', $this->card))->assertSessionHas('success', 'Line added.')->assertSessionHas('jc_focus_line');
    }

    public function test_a_refused_add_answers_422_with_the_message_and_creates_nothing(): void
    {
        $this->store(['quantity' => 1])->assertStatus(422)->assertJson(['ok' => false, 'message' => 'Pick a catalogue item or type a description.']);
        $this->store(['description' => 'Washer', 'quantity' => 'lots'])->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['quantity']]);

        $this->assertSame(0, RentalJobCardLine::count());
    }

    public function test_a_closed_card_refuses_every_line_action_in_json(): void
    {
        $line = $this->officeLine($this->card, ['description' => 'Washer']);
        $this->card->forceFill(['status' => RentalJobCard::STATUS_CANCELLED])->save();

        $this->store(['description' => 'More'])->assertStatus(422)->assertJson(['ok' => false]);
        $this->ajax('PUT', route('corex.rental-job-cards.lines.update', [$this->card, $line]), ['description' => 'x', 'quantity' => 1])->assertStatus(422);
        $this->ajax('DELETE', route('corex.rental-job-cards.lines.destroy', [$this->card, $line]))->assertStatus(422);
        $this->assertNull($line->fresh()->deleted_at);
    }

    // ── receipt ─────────────────────────────────────────────────────────────────────────

    public function test_a_receipt_posted_with_a_new_part_line_is_stored_against_it_like_the_crews(): void
    {
        $res = $this->store(['type' => 'part', 'description' => 'Element', 'quantity' => 1, 'unit_cost' => '48.50'], ['receipt_camera' => UploadedFile::fake()->image('receipt.jpg')])->assertOk();

        $line = RentalJobCardLine::findOrFail($res->json('line_id'));
        $receipts = $this->receiptsOf($line);
        $this->assertCount(1, $receipts);
        $this->assertNotSame('crew_link', $receipts->first()->uploaded_via, 'an office receipt is not a crew upload');
        $this->assertSame($this->user->id, (int) $receipts->first()->uploaded_by_user_id);
        $this->assertSame(0, RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_line_id', $line->id)->where('photo_type', RentalJobCardLine::PHOTO_TYPE)->count(), 'not one of a crew line\'s photos');
    }

    public function test_labour_has_no_receipt(): void
    {
        $res = $this->store(['type' => 'labour', 'description' => 'Fit it', 'quantity' => 1], ['receipt_camera' => UploadedFile::fake()->image('r.jpg')])->assertOk();

        $this->assertCount(0, $this->receiptsOf(RentalJobCardLine::findOrFail($res->json('line_id'))));
    }

    public function test_a_receipt_added_when_changing_a_line_replaces_the_one_shown_and_the_thumb_is_on_the_screen(): void
    {
        $id = $this->store(['type' => 'part', 'description' => 'Element', 'quantity' => 1, 'unit_cost' => '48.50'], ['receipt_file' => UploadedFile::fake()->image('one.jpg')])->json('line_id');
        $line = RentalJobCardLine::findOrFail($id);

        $this->ajax('PUT', route('corex.rental-job-cards.lines.update', [$this->card, $line]), [
            'description' => 'Element', 'type' => 'part', 'quantity' => 1, 'unit_cost' => '48.50',
        ], ['receipt_camera' => UploadedFile::fake()->image('two.png')])->assertOk()->assertJson(['ok' => true, 'message' => 'Line updated.']);

        $receipts = $this->receiptsOf($line)->sortBy('id')->values();
        $this->assertCount(2, $receipts, 'photo rows are immutable: the newer one supersedes on screen');
        $html = $this->actingAs($this->user)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();
        $this->assertStringContainsString('data-open-receipt="' . $receipts->last()->storage_path . '"', $html);
        $this->assertStringNotContainsString('data-open-receipt="' . $receipts->first()->storage_path . '"', $html);
    }

    // ── change, archive, undo ───────────────────────────────────────────────────────────

    public function test_archive_returns_what_undo_needs_and_restore_brings_the_same_line_back(): void
    {
        $line = $this->officeLine($this->card, ['description' => 'Washer', 'unit_cost' => '5']);

        $this->ajax('DELETE', route('corex.rental-job-cards.lines.destroy', [$this->card, $line]))
            ->assertOk()->assertJson(['ok' => true, 'removed_line_id' => $line->id, 'description' => 'Washer']);
        $this->assertNotNull(RentalJobCardLine::withTrashed()->findOrFail($line->id)->deleted_at, 'archived (soft delete), never removed from the table');

        $this->ajax('POST', route('corex.rental-job-cards.lines.restore', [$this->card, $line->id]))->assertOk()->assertJson(['ok' => true, 'line_id' => $line->id]);
        $this->assertNull(RentalJobCardLine::findOrFail($line->id)->deleted_at);
    }

    public function test_plain_form_archive_and_restore_still_redirect(): void
    {
        $line = $this->officeLine($this->card, ['description' => 'Washer']);

        $this->actingAs($this->user)->delete(route('corex.rental-job-cards.lines.destroy', [$this->card, $line]))->assertRedirect()->assertSessionHas('success', 'Line archived.');
        $this->actingAs($this->user)->post(route('corex.rental-job-cards.lines.restore', [$this->card, $line->id]))->assertRedirect()->assertSessionHas('success', 'Line restored.');
    }

    public function test_a_line_of_another_card_cannot_be_changed_or_archived_through_this_card(): void
    {
        $other = $this->emptyCard();
        $foreign = $this->officeLine($other, ['description' => 'Not yours']);

        $this->ajax('DELETE', route('corex.rental-job-cards.lines.destroy', [$this->card, $foreign]))->assertNotFound();
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    // ── the screen ──────────────────────────────────────────────────────────────────────

    public function test_markup_is_one_link_and_not_a_row_under_every_line_and_the_add_row_defaults_to_part(): void
    {
        $this->officeLine($this->card, ['description' => 'Washer']);
        $html = $this->actingAs($this->user)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();

        $this->assertStringNotContainsString('Markup on this line', $html, 'the always-open markup row is gone');
        $this->assertMatchesRegularExpression('/<details class="jc-markup" data-line-markup[^>]*>\s*<summary>Markup<\/summary>/', $html);
        $this->assertStringContainsString('Add new line', $html);
        $this->assertStringContainsString('data-line-ajax="add"', $html);
        $this->assertStringContainsString('data-line-ajax="remove"', $html);
        $this->assertStringNotContainsString('data-confirm="Archive this line?"', $html, 'Remove has Undo, not a confirm dialog');

        // the add row's Type select: Part is the selected option
        preg_match('/<form[^>]*data-line-ajax="add".*?<select name=type[^>]*>(.*?)<\/select>/s', $html, $m);
        $this->assertNotEmpty($m, 'the add row has a Type select');
        $this->assertMatchesRegularExpression('/<option value="part"\s+selected/', $m[1]);
        $this->assertDoesNotMatchRegularExpression('/<option value="labour"\s+selected/', $m[1]);
    }

    public function test_the_receipt_control_is_on_the_add_row_and_matches_the_crews_wording(): void
    {
        $html = $this->actingAs($this->user)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();

        $this->assertStringContainsString('name="receipt_camera"', $html);
        $this->assertStringContainsString('capture="environment"', $html);
        $this->assertStringContainsString('name="receipt_file"', $html);
        $this->assertStringContainsString('<span>Take photo</span>', $html);
        $this->assertStringContainsString('<span>Choose from phone</span>', $html);
    }
}
