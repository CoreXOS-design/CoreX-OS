<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalJobCard;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Rentals\RentalJobCardService;
use App\Support\RentalJobCardLineGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.24 (2026-10-06, Johan): the column
 * header, the saved lines and the add-line row under a task use ONE grid and
 * their text starts on the same x; the end-of-row ✎ / × / + are compact
 * icon-sized controls (the card-wide `button[type=submit]` rule made the ×
 * a 44x36 block).
 */
final class RentalJobCardGridAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private RentalJobCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Grid Agency', 'slug' => 'grid-' . uniqid()]);
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        $branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $branch->id,
            'title' => '1 Grid Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true, 'no_approval_spend_threshold' => 100000]);
        PerformanceSetting::set('vat_rate', '15', $this->agency->id);
        RentalVatType::seedDefaultsFor($this->agency->id);
        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);

        $service = app(RentalJobCardService::class);
        $this->card = $service->createForProperty($property, ['title' => 'Grid job'], $this->admin);
        $task = $service->addTask($this->card, 'Replace washer', $this->admin);
        $vat = RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_AGENCY_RATE)->firstOrFail();
        $service->addLine($this->card, ['description' => 'Washer', 'quantity' => 2, 'unit_price' => 15, 'rental_vat_type_id' => $vat->id], $this->admin, $task);
    }

    private function html(): string
    {
        return $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();
    }

    public function test_header_saved_row_and_add_row_use_the_same_grid_style(): void
    {
        $grid = RentalJobCardLineGrid::gridStyle(true, true);
        // header + saved row + add row (+ the General block's three) all carry the one grid style
        $this->assertGreaterThanOrEqual(3, substr_count($this->html(), 'style="' . $grid), 'header, saved row and add row must render from the one grid style string');
        $this->assertSame(
            'minmax(0,100px) minmax(70px,1fr) 90px 64px 50px 84px 84px 36px',
            implode(' ', RentalJobCardLineGrid::columns(true, true)),
        );
    }

    public function test_header_labels_and_saved_values_carry_the_same_text_inset_as_the_inputs(): void
    {
        $inset = RentalJobCardLineGrid::cellStyle();
        $this->assertSame('padding-left: 9px;', $inset); // 8px (px-2) + 1px border of the inputs below
        $html = $this->html();
        // every header label and the saved description/type/unit/qty/price cells
        $this->assertGreaterThanOrEqual(8, substr_count($html, $inset), 'header labels + saved values must carry the inset');
        $this->assertMatchesRegularExpression('/title="Washer"[^>]*style="padding-left: 9px;"/', $html);
    }

    public function test_the_end_of_row_controls_are_compact_icon_controls_that_beat_the_card_wide_submit_rule(): void
    {
        $html = $this->html();

        foreach (['title="Edit line"', 'title="Archive"', 'aria-label="Add line"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        // Each carries the compact style, with !important on the properties the card-wide rule forces.
        $this->assertMatchesRegularExpression('/title="Archive"[^>]*style="[^"]*width:17px; height:17px;[^"]*padding:0 !important;[^"]*background:transparent !important;/', $html);
        $this->assertMatchesRegularExpression('/title="Edit line"[^>]*style="[^"]*width:17px; height:17px;[^"]*padding:0 !important;/', $html);
        $this->assertMatchesRegularExpression('/aria-label="Add line"[^>]*style="[^"]*width:17px; height:17px;[^"]*padding:0 !important;[^"]*justify-self:end;/', $html);
        // …and the + is no longer the full-size outline button
        $this->assertDoesNotMatchRegularExpression('/aria-label="Add line"[^>]*class="[^"]*corex-btn/', $html);
    }

    public function test_the_add_row_stays_one_line_and_the_action_column_is_the_narrow_last_track(): void
    {
        $cols = RentalJobCardLineGrid::columns(true, true);
        $this->assertSame('36px', end($cols));
        // 17px control fits the 36px track with room to spare for the edit + archive pair (2 x 17 = 34)
        $this->assertLessThanOrEqual(36, 17 * 2);
    }

    public function test_a_closed_card_has_no_end_of_row_controls(): void
    {
        $this->card->forceFill(['status' => RentalJobCard::STATUS_CANCELLED])->save();
        $html = $this->html();
        $this->assertStringNotContainsString('title="Edit line"', $html);
        $this->assertStringNotContainsString('title="Archive"', $html);
        $this->assertStringNotContainsString('aria-label="Add line"', $html);
    }
}
