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
use App\Models\RentalVatType;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalJobCardVatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * VAT per line on job cards (Pastel-style), Johan 2026-10-05. Proves: an
 * agency that isn't VAT registered is byte-identical to the pre-VAT
 * behaviour; a registered agency computes excl/VAT/incl per line correctly
 * in both excl and incl capture modes; a mixed Standard/None/Custom card
 * groups totals by rate; per-line rounding; the figures freeze at
 * send-quote and never move again even if the agency's VAT settings change
 * afterwards; the landlord no-approval threshold is compared VAT-INCLUSIVE;
 * VAT types are agency-isolated.
 */
final class RentalJobCardVatTest extends TestCase
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
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'VAT Test Agency', 'slug' => 'vat-test-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Jane', 'last_name' => 'Landlord', 'email' => 'jane.landlord-' . uniqid() . '@example.test',
        ]);
        ContactPropertyLinker::link($landlord->id, $this->property->id, 'landlord');

        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true]);
        // Agency::create() does not fire AgencyCreated in tests (that
        // happens in the real agency-provisioning flow) — seed explicitly,
        // same convention every other per-agency seeded list's tests use.
        RentalVatType::seedDefaultsFor($this->agency->id);
        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);
        $this->service = app(RentalJobCardService::class);
        $this->vat = app(RentalJobCardVatService::class);
    }

    private function partTypeId(?Agency $agency = null): int
    {
        return RentalCatalogueItemType::where('agency_id', ($agency ?? $this->agency)->id)->where('kind', 'part')->firstOrFail()->id;
    }

    private function eachUnitId(?Agency $agency = null): int
    {
        return RentalCatalogueUnit::where('agency_id', ($agency ?? $this->agency)->id)->where('name', 'Each')->firstOrFail()->id;
    }

    private function makeJobCard(): RentalJobCard
    {
        return $this->service->createForProperty($this->property, ['title' => 'VAT job'], $this->admin);
    }

    private function standardType(): RentalVatType
    {
        return RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_AGENCY_RATE)->firstOrFail();
    }

    private function noVatType(): RentalVatType
    {
        return RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_FIXED)->firstOrFail();
    }

    private function customType(): RentalVatType
    {
        return RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_CUSTOM_PER_LINE)->firstOrFail();
    }

    // ── Not registered — byte-identical to pre-VAT behaviour ─────────────

    public function test_agency_not_vat_registered_has_no_vat_breakdown_and_unchanged_totals(): void
    {
        $this->agency->update(['vat_registered' => false]);
        $jobCard = $this->makeJobCard();
        $this->service->addLine($jobCard, ['description' => 'Call-out', 'quantity' => 2, 'unit_price' => 100], $this->admin);

        $breakdown = $this->vat->breakdown($jobCard->fresh());

        $this->assertFalse($breakdown['registered']);
        $this->assertSame([], $breakdown['groups']);
        $this->assertEquals(200.0, (float) $jobCard->fresh()->total_amount);
        $this->assertEquals(200.0, $this->vat->inclusiveTotal($jobCard->fresh()));
    }

    // ── Registered — excl and incl capture ───────────────────────────────

    public function test_registered_excl_capture_computes_vat_from_captured_excl_price(): void
    {
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        PerformanceSetting::set('vat_rate', '15', $this->agency->id);

        $jobCard = $this->makeJobCard();
        $this->service->addLine($jobCard, [
            'description' => 'Plumber call-out', 'quantity' => 1, 'unit_price' => 100,
            'rental_vat_type_id' => $this->standardType()->id,
        ], $this->admin);

        $breakdown = $this->vat->breakdown($jobCard->fresh());

        $this->assertTrue($breakdown['registered']);
        $this->assertEquals(100.0, $breakdown['subtotalExcl']);
        $this->assertEquals(15.0, $breakdown['totalVat']);
        $this->assertEquals(115.0, $breakdown['totalIncl']);
    }

    public function test_registered_incl_capture_derives_excl_from_captured_incl_price(): void
    {
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_INCL]);
        PerformanceSetting::set('vat_rate', '15', $this->agency->id);

        $jobCard = $this->makeJobCard();
        // 115 captured AS INCLUSIVE — excl must derive back to 100.
        $this->service->addLine($jobCard, [
            'description' => 'Plumber call-out', 'quantity' => 1, 'unit_price' => 115,
            'rental_vat_type_id' => $this->standardType()->id,
        ], $this->admin);

        $breakdown = $this->vat->breakdown($jobCard->fresh());

        $this->assertEquals(100.0, $breakdown['subtotalExcl']);
        $this->assertEquals(15.0, $breakdown['totalVat']);
        $this->assertEquals(115.0, $breakdown['totalIncl']);
    }

    // ── Mixed-rate card: Standard + No VAT + Custom on one card ──────────

    public function test_mixed_rate_card_groups_totals_by_rate_and_omits_zero(): void
    {
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        PerformanceSetting::set('vat_rate', '15', $this->agency->id);

        $jobCard = $this->makeJobCard();
        $this->service->addLine($jobCard, ['description' => 'Standard line', 'quantity' => 1, 'unit_price' => 100, 'rental_vat_type_id' => $this->standardType()->id], $this->admin);
        $this->service->addLine($jobCard, ['description' => 'Exempt line', 'quantity' => 1, 'unit_price' => 50, 'rental_vat_type_id' => $this->noVatType()->id], $this->admin);
        $this->service->addLine($jobCard, ['description' => 'Custom line', 'quantity' => 1, 'unit_price' => 200, 'rental_vat_type_id' => $this->customType()->id, 'custom_vat_rate' => 10], $this->admin);

        $breakdown = $this->vat->breakdown($jobCard->fresh());

        $this->assertEquals(350.0, $breakdown['subtotalExcl']); // 100+50+200
        $this->assertEquals(35.0, $breakdown['totalVat']);      // 15 + 0 + 20
        $this->assertEquals(385.0, $breakdown['totalIncl']);

        $labels = collect($breakdown['groups'])->pluck('label')->sort()->values()->all();
        $this->assertSame(['VAT @ 10%', 'VAT @ 15%'], $labels); // 0% group never listed
        $this->assertEquals(15.0, collect($breakdown['groups'])->firstWhere('label', 'VAT @ 15%')['amount']);
        $this->assertEquals(20.0, collect($breakdown['groups'])->firstWhere('label', 'VAT @ 10%')['amount']);
    }

    // ── Rounding — per line, 2 decimals ───────────────────────────────────

    public function test_per_line_rounding_to_two_decimals(): void
    {
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        PerformanceSetting::set('vat_rate', '15', $this->agency->id);

        $jobCard = $this->makeJobCard();
        // 10.01 * 15% = 1.5015 -> rounds to 1.50; incl = 11.51
        $this->service->addLine($jobCard, ['description' => 'Odd price', 'quantity' => 1, 'unit_price' => 10.01, 'rental_vat_type_id' => $this->standardType()->id], $this->admin);

        $breakdown = $this->vat->breakdown($jobCard->fresh());

        $this->assertEquals(10.01, $breakdown['subtotalExcl']);
        $this->assertEquals(1.50, $breakdown['totalVat']);
        $this->assertEquals(11.51, $breakdown['totalIncl']);
    }

    // ── Snapshot freezes at send-quote — later setting changes never alter it ─

    public function test_snapshot_freezes_at_quote_send_and_later_rate_change_never_alters_it(): void
    {
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        PerformanceSetting::set('vat_rate', '15', $this->agency->id);
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['no_approval_spend_threshold' => 1000]);

        $jobCard = $this->makeJobCard();
        $this->service->addLine($jobCard, ['description' => 'Line', 'quantity' => 1, 'unit_price' => 100, 'rental_vat_type_id' => $this->standardType()->id], $this->admin);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $jobCard))->assertRedirect();
        $jobCard->refresh();

        $this->assertNotNull($jobCard->vat_snapshotted_at);
        $line = $jobCard->lines()->first();
        $this->assertEquals(15.0, (float) $line->vat_amount_snapshot);
        $this->assertEquals(115.0, (float) $line->vat_incl_snapshot);

        // Agency's VAT rate changes AFTER the quote was sent.
        PerformanceSetting::set('vat_rate', '20', $this->agency->id);
        $this->agency->update(['vat_capture_mode' => Agency::VAT_CAPTURE_INCL]);

        $frozen = $this->vat->breakdown($jobCard->fresh());
        $this->assertEquals(15.0, $frozen['totalVat'], 'A later rate/capture-mode change must never alter an issued quote.');
        $this->assertEquals(115.0, $frozen['totalIncl']);

        // The quote amount itself was also the VAT-inclusive figure.
        $quote = $jobCard->quotes()->first();
        $this->assertEquals(115.0, (float) $quote->amount);
    }

    // ── Landlord no-approval threshold compared VAT-INCLUSIVE ────────────

    public function test_threshold_is_compared_vat_inclusive_not_excl(): void
    {
        // Excl total 450 is AT the 500 threshold (would auto-approve with no VAT);
        // incl at 15% = 517.50, OVER the threshold — must now require approval.
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        PerformanceSetting::set('vat_rate', '15', $this->agency->id);
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['no_approval_spend_threshold' => 500]);

        $catalogueItem = RentalCatalogueItem::create([
            'agency_id' => $this->agency->id, 'rental_catalogue_item_type_id' => $this->partTypeId(),
            'name' => 'Geyser element', 'rental_catalogue_unit_id' => $this->eachUnitId(), 'default_price' => 450, 'sort_order' => 1,
            'default_rental_vat_type_id' => $this->standardType()->id, 'created_by_user_id' => $this->admin->id,
        ]);
        $jobCard = $this->makeJobCard();
        $this->service->addLine($jobCard, ['rental_catalogue_item_id' => $catalogueItem->id, 'quantity' => 1], $this->admin);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $jobCard))->assertRedirect();
        $jobCard->refresh();

        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $jobCard->workOrder->owner_approval_status);
        $this->assertSame(RentalJobCard::STATUS_QUOTED, $jobCard->status);
        $this->assertEquals(517.50, (float) $jobCard->quotes()->first()->amount);
    }

    // ── VAT types are agency-isolated ─────────────────────────────────────

    public function test_vat_types_are_seeded_per_agency_and_isolated(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        RentalVatType::seedDefaultsFor($other->id);

        $mine = RentalVatType::where('agency_id', $this->agency->id)->pluck('id');
        $theirs = RentalVatType::where('agency_id', $other->id)->pluck('id');

        $this->assertCount(3, $mine);
        $this->assertCount(3, $theirs);
        $this->assertEmpty($mine->intersect($theirs));
    }

    public function test_default_vat_type_resolution_falls_back_to_catalogue_then_agency_default(): void
    {
        $this->agency->update(['vat_registered' => true]);
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agency->id, 'rental_catalogue_item_type_id' => $this->partTypeId(),
            'name' => 'Tap washer', 'rental_catalogue_unit_id' => $this->eachUnitId(), 'default_price' => 20,
            'default_rental_vat_type_id' => $this->noVatType()->id, 'created_by_user_id' => $this->admin->id,
        ]);

        $resolvedForItem = $this->vat->defaultVatTypeIdFor($this->agency->id, $item);
        $this->assertSame($this->noVatType()->id, $resolvedForItem);

        $resolvedForFreeText = $this->vat->defaultVatTypeIdFor($this->agency->id, null);
        $this->assertSame($this->standardType()->id, $resolvedForFreeText);
    }

    // ── Pastel-style enhancement, 2026-10-05 — catalogue default_price is
    //    always excl-VAT; a job card line picking the item converts it to
    //    whatever the agency currently captures on lines ──────────────────

    public function test_line_inherits_catalogue_price_converted_to_incl_when_agency_captures_incl(): void
    {
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_INCL]);
        \App\Models\PerformanceSetting::set('vat_rate', '15', $this->agency->id);
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agency->id, 'rental_catalogue_item_type_id' => $this->partTypeId(),
            'name' => 'Ballcock valve', 'rental_catalogue_unit_id' => $this->eachUnitId(), 'default_price' => 100,
            'default_rental_vat_type_id' => $this->standardType()->id, 'created_by_user_id' => $this->admin->id,
        ]);

        $jobCard = $this->makeJobCard();
        $line = $this->service->addLine($jobCard, ['rental_catalogue_item_id' => $item->id, 'quantity' => 1], $this->admin);

        // Catalogue default_price is 100 excl; agency captures incl -> line unit_price should be 115.
        $this->assertSame('115.00', (string) $line->unit_price);
        $this->assertSame($this->standardType()->id, $line->rental_vat_type_id);
    }

    public function test_line_inherits_catalogue_price_unconverted_when_agency_captures_excl(): void
    {
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        \App\Models\PerformanceSetting::set('vat_rate', '15', $this->agency->id);
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agency->id, 'rental_catalogue_item_type_id' => $this->partTypeId(),
            'name' => 'Ballcock valve', 'rental_catalogue_unit_id' => $this->eachUnitId(), 'default_price' => 100,
            'default_rental_vat_type_id' => $this->standardType()->id, 'created_by_user_id' => $this->admin->id,
        ]);

        $jobCard = $this->makeJobCard();
        $line = $this->service->addLine($jobCard, ['rental_catalogue_item_id' => $item->id, 'quantity' => 1], $this->admin);

        $this->assertSame('100.00', (string) $line->unit_price);
    }

    public function test_line_inherits_catalogue_unit_and_type_name(): void
    {
        $item = RentalCatalogueItem::create([
            'agency_id' => $this->agency->id, 'rental_catalogue_item_type_id' => $this->partTypeId(),
            'name' => 'Screws (box)', 'rental_catalogue_unit_id' => \App\Models\RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Box')->firstOrFail()->id,
            'default_price' => 50, 'created_by_user_id' => $this->admin->id,
        ]);

        $jobCard = $this->makeJobCard();
        $line = $this->service->addLine($jobCard, ['rental_catalogue_item_id' => $item->id, 'quantity' => 2], $this->admin);

        $this->assertSame('Box', $line->unit);
        $this->assertSame('part', $line->type);
    }
}
