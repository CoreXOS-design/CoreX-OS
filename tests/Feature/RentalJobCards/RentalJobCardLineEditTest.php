<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalJobCardVatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Job card lines editable in place (Johan, 2026-10-05, "a saved line cannot
 * be changed") — item, description, type, unit, qty, unit price, VAT all
 * persist; totals recalculate (task subtotal, card total, VAT breakdown);
 * a blanked field really clears; validation failures change nothing and
 * reopen the editor; a closed card refuses edits; a card whose VAT is
 * already frozen at quote time shows the edit in its totals while every
 * OTHER line keeps the figures it was issued with; agency isolation.
 */
final class RentalJobCardLineEditTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;
    private RentalJobCardService $service;
    private RentalJobCardVatService $vat;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Line Edit Agency', 'slug' => 'line-edit-' . uniqid()]);
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Edit Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Jane', 'last_name' => 'Landlord', 'email' => 'jane.landlord-' . uniqid() . '@example.test',
        ]);
        ContactPropertyLinker::link($landlord->id, $this->property->id, 'landlord');

        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true, 'no_approval_spend_threshold' => 100000]);
        PerformanceSetting::set('vat_rate', '15', $this->agency->id);
        RentalVatType::seedDefaultsFor($this->agency->id);
        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);
        $this->service = app(RentalJobCardService::class);
        $this->vat = app(RentalJobCardVatService::class);
    }

    private function jobCard(): RentalJobCard
    {
        return $this->service->createForProperty($this->property, ['title' => 'Edit job'], $this->admin);
    }

    private function item(string $code, string $kind, float $price): RentalCatalogueItem
    {
        return RentalCatalogueItem::create([
            'agency_id' => $this->agency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', $kind)->firstOrFail()->id,
            'code' => $code, 'description' => $code . ' item', 'default_price' => $price, 'sort_order' => 1,
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'created_by_user_id' => $this->admin->id,
        ]);
    }

    private function standardVat(): RentalVatType
    {
        return RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_AGENCY_RATE)->firstOrFail();
    }

    private function noVat(): RentalVatType
    {
        return RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_FIXED)->firstOrFail();
    }

    private function updateLine(RentalJobCard $card, RentalJobCardLine $line, array $data)
    {
        return $this->actingAs($this->admin)->put(route('corex.rental-job-cards.lines.update', [$card, $line]), $data);
    }

    // ── Every editable field persists, totals follow ─────────────────────

    public function test_edit_persists_type_unit_qty_price_vat_and_description_and_recalculates_totals(): void
    {
        $card = $this->jobCard();
        $task = $this->service->addTask($card, 'Fix tap', $this->admin);
        $line = $this->service->addLine($card, ['description' => 'Washer', 'type' => 'part', 'unit' => 'Each', 'quantity' => 1, 'unit_price' => 100, 'rental_vat_type_id' => $this->standardVat()->id], $this->admin, $task);

        $this->updateLine($card, $line, [
            'description' => 'Washer — brass', 'type' => 'labour', 'unit' => 'Hour', 'quantity' => 3, 'unit_price' => 250.50,
            'rental_vat_type_id' => $this->noVat()->id,
        ])->assertRedirect(route('corex.rental-job-cards.show', $card))->assertSessionHas('jc_focus_line', $line->id);

        $line->refresh();
        $this->assertSame('Washer — brass', $line->description);
        $this->assertSame('labour', $line->type);
        $this->assertSame('Hour', $line->unit);
        $this->assertEquals(3.0, (float) $line->quantity);
        $this->assertEquals(250.50, (float) $line->unit_price);
        $this->assertEquals(751.50, (float) $line->line_total);
        $this->assertSame($this->noVat()->id, $line->rental_vat_type_id);

        $this->assertEquals(751.50, (float) $card->fresh()->total_amount);
        $this->assertEquals(751.50, $task->fresh()->subtotal());
        $breakdown = $this->vat->breakdown($card->fresh()->load('lines'));
        $this->assertEquals(751.50, $breakdown['totalIncl'], 'No-VAT type: incl total equals the line total.');
    }

    public function test_changing_to_a_different_catalogue_item_recopies_its_code_and_free_text_clears_it(): void
    {
        $card = $this->jobCard();
        $a = $this->item('PLUMB-01', 'part', 85);
        $b = $this->item('PAINT-5L', 'part', 750);
        $line = $this->service->addLine($card, ['rental_catalogue_item_id' => $a->id], $this->admin);
        $this->assertSame('PLUMB-01', $line->code);

        $this->updateLine($card, $line, ['rental_catalogue_item_id' => $b->id, 'description' => 'Paint', 'quantity' => 1, 'unit_price' => 750])->assertRedirect();
        $line->refresh();
        $this->assertSame($b->id, $line->rental_catalogue_item_id);
        $this->assertSame('PAINT-5L', $line->code);

        // The item box emptied ("— Free text —") posts a blank id: the line becomes free text.
        $this->updateLine($card, $line, ['rental_catalogue_item_id' => '', 'description' => 'Custom paint', 'quantity' => 1, 'unit_price' => 700])->assertRedirect();
        $line->refresh();
        $this->assertNull($line->rental_catalogue_item_id);
        $this->assertNull($line->code);
        $this->assertSame('Custom paint', $line->description);
    }

    public function test_an_unchanged_item_selection_keeps_the_link_and_a_blank_price_and_unit_really_clear(): void
    {
        $card = $this->jobCard();
        $a = $this->item('PLUMB-01', 'part', 85);
        $line = $this->service->addLine($card, ['rental_catalogue_item_id' => $a->id, 'unit' => 'Each', 'unit_price' => 85], $this->admin);

        $this->updateLine($card, $line, ['rental_catalogue_item_id' => $a->id, 'description' => 'PLUMB-01 item', 'unit' => '', 'quantity' => 2, 'unit_price' => ''])->assertRedirect();
        $line->refresh();

        $this->assertSame($a->id, $line->rental_catalogue_item_id);
        $this->assertSame('PLUMB-01', $line->code);
        $this->assertNull($line->unit, 'unit "—" must clear, not silently keep "Each".');
        $this->assertNull($line->unit_price);
        $this->assertNull($line->line_total);
        $this->assertEquals(0.0, (float) $card->fresh()->total_amount);
    }

    // ── Validation: nothing changes, the editor reopens ──────────────────

    public function test_validation_failure_changes_nothing_and_flashes_the_line_id_for_the_editor_to_reopen(): void
    {
        $card = $this->jobCard();
        $line = $this->service->addLine($card, ['description' => 'Washer', 'quantity' => 1, 'unit_price' => 100], $this->admin);

        $response = $this->actingAs($this->admin)->from(route('corex.rental-job-cards.show', $card))
            ->put(route('corex.rental-job-cards.lines.update', [$card, $line]), ['_edit_line_id' => $line->id, 'description' => '', 'quantity' => 0, 'unit_price' => -5, 'type' => 'wizardry']);

        $response->assertRedirect(route('corex.rental-job-cards.show', $card));
        $response->assertSessionHasErrors(['description', 'quantity', 'unit_price', 'type']);
        $this->assertEquals($line->id, session()->getOldInput('_edit_line_id'));
        $line->refresh();
        $this->assertSame('Washer', $line->description);
        $this->assertEquals(100.0, (float) $line->unit_price);
    }

    public function test_a_vat_type_or_catalogue_item_from_another_agency_is_rejected(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        RentalVatType::seedDefaultsFor($other->id);
        RentalCatalogueItemType::seedDefaultsFor($other->id);
        $foreignVat = RentalVatType::where('agency_id', $other->id)->firstOrFail();
        $foreignItem = RentalCatalogueItem::create([
            'agency_id' => $other->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $other->id)->firstOrFail()->id,
            'code' => 'X-1', 'description' => 'Foreign', 'default_price' => 1, 'sort_order' => 1, 'created_by_user_id' => $this->admin->id,
        ]);

        $card = $this->jobCard();
        $line = $this->service->addLine($card, ['description' => 'Washer', 'quantity' => 1, 'unit_price' => 100], $this->admin);

        $this->updateLine($card, $line, ['description' => 'Washer', 'quantity' => 1, 'rental_vat_type_id' => $foreignVat->id])->assertSessionHasErrors('rental_vat_type_id');
        $this->updateLine($card, $line, ['description' => 'Washer', 'quantity' => 1, 'rental_catalogue_item_id' => $foreignItem->id])->assertSessionHasErrors('rental_catalogue_item_id');
        $this->assertNull($line->fresh()->rental_catalogue_item_id);
    }

    // ── Closed cards, and other agencies, can never edit ─────────────────

    public function test_a_completed_or_cancelled_card_refuses_line_edits(): void
    {
        foreach ([RentalJobCard::STATUS_COMPLETED, RentalJobCard::STATUS_CANCELLED] as $status) {
            $card = $this->jobCard();
            $line = $this->service->addLine($card, ['description' => 'Washer', 'quantity' => 1, 'unit_price' => 100], $this->admin);
            $card->forceFill(['status' => $status])->save();

            $this->updateLine($card, $line, ['description' => 'Changed', 'quantity' => 5, 'unit_price' => 999])->assertSessionHasErrors('rental_job_card');
            $this->assertSame('Washer', $line->fresh()->description, "A {$status} card's lines never change.");
        }
    }

    public function test_another_agency_cannot_edit_the_line(): void
    {
        $card = $this->jobCard();
        $line = $this->service->addLine($card, ['description' => 'Washer', 'quantity' => 1, 'unit_price' => 100], $this->admin);

        $otherAgency = Agency::create(['name' => 'Intruder Agency', 'slug' => 'intruder-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Elsewhere', 'agency_id' => $otherAgency->id]);
        $intruder = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);

        $this->actingAs($intruder)->put(route('corex.rental-job-cards.lines.update', [$card, $line]), ['description' => 'Hacked', 'quantity' => 1])->assertNotFound();
        $this->assertSame('Washer', $line->fresh()->description);
    }

    // ── A card whose VAT is already frozen (quote sent) ──────────────────

    public function test_editing_on_a_quoted_card_refreshes_only_that_lines_frozen_figures(): void
    {
        $card = $this->jobCard();
        $one = $this->service->addLine($card, ['description' => 'One', 'quantity' => 1, 'unit_price' => 100, 'rental_vat_type_id' => $this->standardVat()->id], $this->admin);
        $two = $this->service->addLine($card, ['description' => 'Two', 'quantity' => 1, 'unit_price' => 200, 'rental_vat_type_id' => $this->standardVat()->id], $this->admin);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect();
        $card->refresh();
        $this->assertNotNull($card->vat_snapshotted_at);
        $this->assertEquals(345.0, $this->vat->breakdown($card->load('lines'))['totalIncl']);

        // The agency's VAT rate moves AFTER the quote — untouched lines must stay as issued.
        PerformanceSetting::set('vat_rate', '20', $this->agency->id);

        $this->updateLine($card, $one, ['description' => 'One', 'quantity' => 2, 'unit_price' => 100, 'rental_vat_type_id' => $this->noVat()->id])->assertRedirect();

        $card = $card->fresh()->load('lines');
        $breakdown = $this->vat->breakdown($card);
        // Line one: 2 x 100 at no VAT = 200 (refreshed). Line two: still 200 + 15% = 230 (as issued).
        $this->assertEquals(430.0, $breakdown['totalIncl'], 'Edited line shows its new figure; the other line keeps its issued figure.');
        $this->assertEquals(30.0, $breakdown['totalVat']);
        $this->assertEquals(230.0, (float) $two->fresh()->vat_incl_snapshot);
    }

    // ── The screen ───────────────────────────────────────────────────────

    public function test_show_screen_offers_an_edit_control_per_line_only_while_the_card_is_open(): void
    {
        $card = $this->jobCard();
        $task = $this->service->addTask($card, 'Fix tap', $this->admin);
        $line = $this->service->addLine($card, ['description' => 'Washer', 'quantity' => 1, 'unit_price' => 100], $this->admin, $task);

        $open = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card));
        $open->assertOk();
        $open->assertSee('data-line-id="' . $line->id . '"', false);
        $open->assertSee('aria-label="Edit line"', false);
        $open->assertSee('data-keep-scroll', false);

        $card->forceFill(['status' => RentalJobCard::STATUS_COMPLETED])->save();
        $closed = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card));
        $closed->assertOk();
        $closed->assertDontSee('aria-label="Edit line"', false);
    }

    public function test_archive_and_restore_still_work_and_flash_the_line_for_the_screen_to_focus(): void
    {
        $card = $this->jobCard();
        $line = $this->service->addLine($card, ['description' => 'Washer', 'quantity' => 1, 'unit_price' => 100], $this->admin);

        $this->actingAs($this->admin)->delete(route('corex.rental-job-cards.lines.destroy', [$card, $line]))->assertRedirect();
        $this->assertSoftDeleted('rental_job_card_lines', ['id' => $line->id]);
        $this->assertEquals(0.0, (float) $card->fresh()->total_amount);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.lines.restore', [$card, $line->id]))
            ->assertRedirect()->assertSessionHas('jc_focus_line', $line->id);
        $this->assertNull($line->fresh()->deleted_at);
        $this->assertEquals(100.0, (float) $card->fresh()->total_amount);
    }

    public function test_adding_a_line_flashes_its_id_for_the_screen_to_focus(): void
    {
        $card = $this->jobCard();

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.lines.store', $card), ['description' => 'New line', 'quantity' => 1, 'unit_price' => 10])
            ->assertRedirect()
            ->assertSessionHas('jc_focus_line', $card->lines()->where('description', 'New line')->value('id'));
    }
}
