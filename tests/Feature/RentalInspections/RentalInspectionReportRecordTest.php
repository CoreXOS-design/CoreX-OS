<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\User;
use App\Services\Rentals\RentalInspectionReportPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §45.13 — inspections bug-fix batch (7 Oct 2026):
 *  1. an archived ITEM photo never reaches the public report link;
 *  2. a completed report keeps showing an item that was retired after it was inspected (public link, PDF,
 *     comparison) — and still leaves out a retired item that inspection never assessed;
 *  3. an interim inspection reads "Interim", not "Routine", on the property's Inspections tab.
 */
final class RentalInspectionReportRecordTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private PropertyRoom $bedroom;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('public');

        $this->agency = Agency::create(['name' => 'Record Agency', 'slug' => 'record-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '3 Beach Road, Uvongo', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->bedroom = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Bedroom',
            'label' => 'Bedroom 1', 'source' => 'manual', 'sort_order' => 0, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function item(string $label, int $sort = 0): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $this->bedroom->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'sort_order' => $sort,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function inspection(string $type = RentalInspection::TYPE_IN, ?RentalInspection $previous = null): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'previous_inspection_id' => $previous?->id, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function grade(RentalInspection $inspection, RentalInspectionItem $item, string $condition = 'good'): void
    {
        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => $condition, 'source' => 'in_inspection',
        ])->assertOk();
    }

    private function itemPhoto(RentalInspection $inspection, RentalInspectionItem $item, string $takenAt): RentalInspectionPhoto
    {
        $this->postJson(route('corex.rental-inspections.photos.store', $inspection), [
            'photos' => [UploadedFile::fake()->image('p.jpg')],
            'rental_inspection_item_id' => $item->id,
            'captured_at' => [$takenAt],
        ])->assertStatus(201);

        return RentalInspectionPhoto::orderByDesc('id')->firstOrFail();
    }

    private function publicPage(RentalInspection $inspection): string
    {
        $inspection->generatePublicLink();

        return $this->get(route('rental-inspections.public.show', $inspection->fresh()->public_token))->assertOk()->getContent();
    }

    private function pdfHtml(RentalInspection $inspection): string
    {
        return app(RentalInspectionReportPdfService::class)->generate($inspection->fresh())->getDomPDF()->outputHtml();
    }

    // ═══ 1. Archived item photos stay off the public link ══════════════════════

    public function test_an_archived_item_photo_is_not_shown_on_the_public_report_but_a_live_one_is(): void
    {
        $item = $this->item('Ceiling');
        $inspection = $this->inspection();
        $this->grade($inspection, $item);
        $this->itemPhoto($inspection, $item, '2026-08-12T14:03:00+02:00');
        $wrongProperty = $this->itemPhoto($inspection, $item, '2026-08-12T14:41:00+02:00');

        $before = $this->publicPage($inspection);
        $this->assertStringContainsString('Taken 12 Aug 2026 14:03', $before);
        $this->assertStringContainsString('Taken 12 Aug 2026 14:41', $before, 'control: both photos show while neither is archived');

        $wrongProperty->delete(); // soft delete — the agent archived it ("wrong property")

        $after = $this->publicPage($inspection);
        $this->assertStringContainsString('Taken 12 Aug 2026 14:03', $after, 'the live photo is still there');
        $this->assertStringNotContainsString('Taken 12 Aug 2026 14:41', $after, 'the archived photo must not reach the tenant/landlord link');
        $this->assertNotNull(RentalInspectionPhoto::withTrashed()->find($wrongProperty->id), 'archived, never hard-deleted');
    }

    // ═══ 2. Retired items stay on the reports they were inspected for ══════════

    public function test_a_retired_item_still_appears_on_the_public_report_it_was_inspected_for(): void
    {
        $retired = $this->item('Old geyser');
        $live = $this->item('Ceiling', 1);
        $inspection = $this->inspection();
        $this->grade($inspection, $retired, 'fair');
        $this->grade($inspection, $live);
        $retired->update(['is_retired' => true]); // retired AFTER it was inspected

        $html = $this->publicPage($inspection);

        $this->assertStringContainsString('Old geyser', $html, 'a completed report keeps showing what was inspected at the time');
        $this->assertStringContainsString('Ceiling', $html);
    }

    public function test_a_retired_item_the_inspection_never_assessed_is_not_on_the_report(): void
    {
        $neverAssessed = $this->item('Removed shelf');
        $onlyAnchored = $this->item('Removed rail', 1);
        $live = $this->item('Ceiling', 2);
        $inspection = $this->inspection();
        $this->grade($inspection, $live);
        // A photo-anchor row (no condition yet) is not an assessment.
        RentalInspectionObservation::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $onlyAnchored->id,
            'condition' => RentalInspectionObservation::CONDITION_PENDING, 'source' => 'in_inspection', 'observed_by_user_id' => $this->agent->id,
        ]);
        $neverAssessed->update(['is_retired' => true]);
        $onlyAnchored->update(['is_retired' => true]);

        $html = $this->publicPage($inspection);
        $pdf = $this->pdfHtml($inspection);

        foreach (['Removed shelf', 'Removed rail'] as $label) {
            $this->assertStringNotContainsString($label, $html);
            $this->assertStringNotContainsString($label, $pdf);
        }
        $this->assertStringContainsString('Ceiling', $html);
        $this->assertStringContainsString('Ceiling', $pdf);
    }

    public function test_a_retired_item_still_appears_on_the_pdf_it_was_inspected_for_and_not_on_a_later_inspection(): void
    {
        $retired = $this->item('Old geyser');
        $in = $this->inspection();
        $this->grade($in, $retired, 'fair');
        $in->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $retired->update(['is_retired' => true]);

        $this->assertStringContainsString('Old geyser', $this->pdfHtml($in));

        // A later inspection that never assessed it (it was retired before) lists nothing of it.
        $out = $this->inspection(RentalInspection::TYPE_OUT, $in);
        $this->assertSame(
            [],
            RentalInspectionItem::where('property_id', $this->property->id)->listedOnReportOf($out->id)->pluck('id')->all(),
        );
        $this->assertSame(
            [$retired->id],
            RentalInspectionItem::where('property_id', $this->property->id)->listedOnReportOf($in->id)->pluck('id')->all(),
        );
    }

    public function test_the_comparison_on_a_completed_inspection_page_keeps_a_retired_item(): void
    {
        $retired = $this->item('Old geyser');
        $in = $this->inspection();
        $this->grade($in, $retired, 'good');
        $in->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $out = $this->inspection(RentalInspection::TYPE_OUT, $in);
        $this->grade($out, $retired, 'fair');
        $out->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $retired->update(['is_retired' => true]);

        $this->get(route('corex.rental-inspections.show', $out))->assertOk()->assertSee('Old geyser');
    }

    // ═══ 3. An interim inspection is labelled Interim on the property tab ══════

    public function test_the_property_inspections_tab_labels_an_interim_inspection_interim(): void
    {
        $html = $this->get(route('corex.properties.show', $this->property->id))->assertOk()->getContent();

        // One helper names every type; interim has its own word.
        $this->assertMatchesRegularExpression("/inspectionTypeLabel\(type\)\s*\{\s*return [^;]*'interim' \? 'Interim'/", $html);
        // The old inline "anything else is Routine" ternaries are gone everywhere on the tab.
        $this->assertStringNotContainsString("'In' : 'Routine'", $html);
        // Every header goes through the helper.
        $this->assertStringContainsString('inspectionTypeLabel(chainTail.type)', $html);
        $this->assertStringContainsString("this.inspectionTypeLabel(insp.type)", $html);
        $this->assertStringContainsString("inspectionTypeLabel(chainPredecessor.type) + '-inspection", $html);
        $this->assertStringContainsString("inspectionTypeLabel(tailSection()) + '-inspection", $html);
    }
}
