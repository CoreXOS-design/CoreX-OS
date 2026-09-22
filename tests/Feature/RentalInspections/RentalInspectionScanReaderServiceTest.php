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
use App\Models\RentalInspectionScan;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use App\Services\Rentals\RentalInspectionFormPdfService;
use App\Services\Rentals\RentalInspectionScanReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspection-form.md §13 — proves the OMR reader end to
 * end against a GENUINELY GENERATED form (cc5's own service, never a hand-
 * built fixture manifest): generate a real PDF, rasterize it, programmatically
 * ink specific tick-boxes (simulating an agent's wet-ink marks), feed the
 * result back through the reader, and assert the marks read back match
 * exactly what was inked — including a 180-degree-rotated page and a
 * small-angle skewed one, proving the affine calibration (not a hardcoded
 * "assume no rotation" shortcut) is what's actually doing the work.
 *
 * Deliberately rasterizes at a DIFFERENT dpi (220) than the reader's own
 * internal PDF-rasterization constant (200) — the coordinate contract is
 * explicitly DPI-independent (manifest §"coordinate_space"), and an
 * uploaded IMAGE (as opposed to a PDF) is never re-rasterized by the
 * reader at all, so this is the real, representative path for a phone
 * photo or a scanner set to whatever DPI it happens to use.
 */
final class RentalInspectionScanReaderServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_RASTER_DPI = 220;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'RI Scan Agency', 'slug' => 'ri-scan-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'RI Scan Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function addRoomWithItems(string $label, int $count): PropertyRoom
    {
        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Bedroom', 'label' => $label,
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        for ($i = 1; $i <= $count; $i++) {
            RentalInspectionItem::create([
                'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room->id,
                'kind' => RentalInspectionItem::KIND_SPACE, 'label' => "{$label} item {$i}", 'space_type' => 'Bedroom',
                'source' => 'manual', 'sort_order' => $i, 'created_by_user_id' => $this->agent->id,
            ]);
        }

        return $room;
    }

    /** PropertyRoom has no items() relation — the real FK query, matching RentalInspectionFormPdfServiceTest's own pattern. */
    private function itemsOf(PropertyRoom $room): \Illuminate\Support\Collection
    {
        return RentalInspectionItem::where('property_room_id', $room->id)->orderBy('sort_order')->get();
    }

    private function inspection(): RentalInspection
    {
        return RentalInspection::start($this->property, RentalInspection::TYPE_IN, $this->agent);
    }

    /**
     * Rasterizes page 1 of a generated form at TEST_RASTER_DPI, ink one
     * chosen condition box per item in $itemConditionMap, apply the given
     * transform (identity/180/skew), and return the resulting Imagick page.
     */
    private function inkedPage(array $manifest, array $itemConditionMap, string $pdfAbsolutePath, ?callable $transform = null): Imagick
    {
        $page = new Imagick();
        $page->setResolution(self::TEST_RASTER_DPI, self::TEST_RASTER_DPI);
        $page->readImage($pdfAbsolutePath . '[0]');
        $page->setImageColorspace(Imagick::COLORSPACE_GRAY);

        $scale = self::TEST_RASTER_DPI / 72.0;
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('black'));
        foreach ($manifest['boxes'] as $box) {
            if ((int) $box['page'] !== 1) {
                continue;
            }
            $itemId = (int) $box['rental_inspection_item_id'];
            if (($itemConditionMap[$itemId] ?? null) !== $box['condition_key']) {
                continue;
            }
            $x = (float) $box['x'] * $scale;
            $y = (float) $box['y'] * $scale;
            $w = (float) $box['width'] * $scale;
            $h = (float) $box['height'] * $scale;
            $draw->rectangle($x, $y, $x + $w, $y + $h);
        }
        $page->drawImage($draw);

        if ($transform !== null) {
            $transform($page);
        }

        return $page;
    }

    private function pageToUploadedFile(Imagick $page, string $name = 'scan.png'): \Illuminate\Http\UploadedFile
    {
        $page->setImageFormat('png');
        $tmp = tempnam(sys_get_temp_dir(), 'omrtest') . '.png';
        $page->writeImage($tmp);

        return new \Illuminate\Http\UploadedFile($tmp, $name, 'image/png', null, true);
    }

    private function createScan(RentalInspection $inspection, \Illuminate\Http\UploadedFile $file): RentalInspectionScan
    {
        $path = $file->store("properties/{$inspection->property_id}/rental-inspection-scans", 'local');

        return RentalInspectionScan::create([
            'agency_id' => $inspection->agency_id,
            'branch_id' => $this->property->branch_id,
            'rental_inspection_id' => $inspection->id,
            'original_filename' => $file->getClientOriginalName() ?: 'scan.png',
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'status' => RentalInspectionScan::STATUS_PROCESSING,
            'uploaded_by_user_id' => $this->agent->id,
        ]);
    }

    // ── The core claim: read marks back exactly as inked ────────────────

    public function test_reads_marks_correctly_from_a_straight_scan(): void
    {
        $room = $this->addRoomWithItems('Bedroom 1', 4);
        $items = $this->itemsOf($room);
        $inspection = $this->inspection();

        $pdfService = app(RentalInspectionFormPdfService::class);
        $form = $pdfService->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;
        $conditionKeys = collect($manifest['condition_states'])->pluck('key')->all();

        // Ink a DIFFERENT condition per item so a systematic offset bug can't accidentally pass.
        $expected = [];
        foreach ($items as $i => $item) {
            $expected[$item->id] = $conditionKeys[$i % count($conditionKeys)];
        }

        $absolutePath = Storage::disk('local')->path($form->pdf_storage_path);
        $page = $this->inkedPage($manifest, $expected, $absolutePath);
        $scan = $this->createScan($inspection, $this->pageToUploadedFile($page));

        app(RentalInspectionScanReaderService::class)->process($scan->fresh());
        $scan->refresh();

        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->status, $scan->failure_reason ?? '');
        foreach ($items as $item) {
            $mark = $scan->marks()->where('rental_inspection_item_id', $item->id)->first();
            $this->assertNotNull($mark, "no mark row for item {$item->id}");
            $this->assertFalse($mark->ambiguous, "item {$item->id} unexpectedly ambiguous");
            $this->assertSame($expected[$item->id], $mark->detected_condition_key, "item {$item->id} read wrong condition");
        }
    }

    public function test_reads_marks_correctly_from_a_180_degree_rotated_scan(): void
    {
        $room = $this->addRoomWithItems('Bedroom 1', 3);
        $items = $this->itemsOf($room);
        $inspection = $this->inspection();

        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;
        $conditionKeys = collect($manifest['condition_states'])->pluck('key')->all();

        $expected = [];
        foreach ($items as $i => $item) {
            $expected[$item->id] = $conditionKeys[($i + 1) % count($conditionKeys)];
        }

        $absolutePath = Storage::disk('local')->path($form->pdf_storage_path);
        $page = $this->inkedPage($manifest, $expected, $absolutePath, function (Imagick $p) {
            $p->rotateImage(new ImagickPixel('white'), 180);
        });
        $scan = $this->createScan($inspection, $this->pageToUploadedFile($page, 'rotated.png'));

        app(RentalInspectionScanReaderService::class)->process($scan->fresh());
        $scan->refresh();

        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->status, $scan->failure_reason ?? '');
        foreach ($items as $item) {
            $mark = $scan->marks()->where('rental_inspection_item_id', $item->id)->first();
            $this->assertNotNull($mark, "no mark row for item {$item->id}");
            $this->assertFalse($mark->ambiguous, "item {$item->id} unexpectedly ambiguous (rotated)");
            $this->assertSame($expected[$item->id], $mark->detected_condition_key, "item {$item->id} read wrong condition after 180-degree rotation");
        }
    }

    public function test_reads_marks_correctly_from_a_skewed_scan(): void
    {
        $room = $this->addRoomWithItems('Bedroom 1', 3);
        $items = $this->itemsOf($room);
        $inspection = $this->inspection();

        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;
        $conditionKeys = collect($manifest['condition_states'])->pluck('key')->all();

        $expected = [];
        foreach ($items as $i => $item) {
            $expected[$item->id] = $conditionKeys[($i + 2) % count($conditionKeys)];
        }

        $absolutePath = Storage::disk('local')->path($form->pdf_storage_path);
        // A modest camera-angle tilt — not a 90-degree multiple, so this
        // exercises the general affine fit, not the shape-based
        // rotation-disambiguation path at all.
        $page = $this->inkedPage($manifest, $expected, $absolutePath, function (Imagick $p) {
            $p->rotateImage(new ImagickPixel('white'), 6);
        });
        $scan = $this->createScan($inspection, $this->pageToUploadedFile($page, 'skewed.png'));

        app(RentalInspectionScanReaderService::class)->process($scan->fresh());
        $scan->refresh();

        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->status, $scan->failure_reason ?? '');
        foreach ($items as $item) {
            $mark = $scan->marks()->where('rental_inspection_item_id', $item->id)->first();
            $this->assertNotNull($mark, "no mark row for item {$item->id}");
            $this->assertFalse($mark->ambiguous, "item {$item->id} unexpectedly ambiguous (skewed)");
            $this->assertSame($expected[$item->id], $mark->detected_condition_key, "item {$item->id} read wrong condition after a 6-degree skew");
        }
    }

    // ── Ambiguity is flagged, never guessed ─────────────────────────────

    public function test_two_marks_on_one_row_is_flagged_ambiguous_not_guessed(): void
    {
        $room = $this->addRoomWithItems('Bedroom 1', 1);
        $item = $this->itemsOf($room)->first();
        $inspection = $this->inspection();

        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;
        $conditionKeys = collect($manifest['condition_states'])->pluck('key')->all();
        $this->assertGreaterThanOrEqual(2, count($conditionKeys));

        $absolutePath = Storage::disk('local')->path($form->pdf_storage_path);
        $scale = self::TEST_RASTER_DPI / 72.0;
        $page = new Imagick();
        $page->setResolution(self::TEST_RASTER_DPI, self::TEST_RASTER_DPI);
        $page->readImage($absolutePath . '[0]');
        $page->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('black'));
        // Ink BOTH the first two condition boxes for this one item.
        $inked = 0;
        foreach ($manifest['boxes'] as $box) {
            if ((int) $box['rental_inspection_item_id'] !== $item->id) {
                continue;
            }
            if (! in_array($box['condition_key'], array_slice($conditionKeys, 0, 2), true)) {
                continue;
            }
            $x = (float) $box['x'] * $scale;
            $y = (float) $box['y'] * $scale;
            $w = (float) $box['width'] * $scale;
            $h = (float) $box['height'] * $scale;
            $draw->rectangle($x, $y, $x + $w, $y + $h);
            $inked++;
        }
        $this->assertSame(2, $inked);
        $page->drawImage($draw);

        $scan = $this->createScan($inspection, $this->pageToUploadedFile($page, 'ambiguous.png'));
        app(RentalInspectionScanReaderService::class)->process($scan->fresh());
        $scan->refresh();

        $mark = $scan->marks()->where('rental_inspection_item_id', $item->id)->first();
        $this->assertNotNull($mark);
        $this->assertTrue($mark->ambiguous);
        $this->assertNull($mark->detected_condition_key);
    }

    public function test_no_marks_on_a_row_is_flagged_ambiguous_never_silently_skipped(): void
    {
        $room = $this->addRoomWithItems('Bedroom 1', 1);
        $item = $this->itemsOf($room)->first();
        $inspection = $this->inspection();

        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $absolutePath = Storage::disk('local')->path($form->pdf_storage_path);

        $page = new Imagick();
        $page->setResolution(self::TEST_RASTER_DPI, self::TEST_RASTER_DPI);
        $page->readImage($absolutePath . '[0]');
        $page->setImageColorspace(Imagick::COLORSPACE_GRAY);
        // No ink at all — a blank form page.

        $scan = $this->createScan($inspection, $this->pageToUploadedFile($page, 'blank.png'));
        app(RentalInspectionScanReaderService::class)->process($scan->fresh());
        $scan->refresh();

        $mark = $scan->marks()->where('rental_inspection_item_id', $item->id)->first();
        $this->assertNotNull($mark);
        $this->assertTrue($mark->ambiguous);
        $this->assertNull($mark->detected_condition_key);
    }

    // ── Version mismatch — never apply against a stale layout ───────────

    public function test_scan_of_an_old_form_version_is_flagged_mismatch_not_applied(): void
    {
        $room = $this->addRoomWithItems('Bedroom 1', 2);
        $inspection = $this->inspection();
        $pdfService = app(RentalInspectionFormPdfService::class);

        $formV1 = $pdfService->generate($inspection, $this->agent);
        $manifestV1 = $formV1->manifest_json;
        $item1 = $this->itemsOf($room)->first();
        $absolutePathV1 = Storage::disk('local')->path($formV1->pdf_storage_path);
        $page = $this->inkedPage($manifestV1, [$item1->id => $manifestV1['condition_states'][0]['key']], $absolutePathV1);

        // The item list changes AFTER the v1 form was printed — content_hash
        // changes, generate() produces v2, and v1's row/PDF are retained.
        $this->addRoomWithItems('Bedroom 2', 1);
        $formV2 = $pdfService->generate($inspection, $this->agent);
        $this->assertSame(2, $formV2->version);

        $scan = $this->createScan($inspection, $this->pageToUploadedFile($page, 'stale.png'));
        app(RentalInspectionScanReaderService::class)->process($scan->fresh());
        $scan->refresh();

        $this->assertSame(RentalInspectionScan::STATUS_VERSION_MISMATCH, $scan->status);
        $this->assertSame(1, $scan->decoded_form_version);
        $this->assertNotNull($scan->failure_reason);
        $this->assertSame(0, $scan->marks()->count(), 'a version-mismatched scan must never produce marks to apply');
    }

    // ── Applying — an ordinary observation, full audit trail ────────────

    public function test_applying_a_confirmed_mark_creates_an_ordinary_observation_with_scan_provenance(): void
    {
        $room = $this->addRoomWithItems('Bedroom 1', 1);
        $item = $this->itemsOf($room)->first();
        $inspection = $this->inspection();

        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;
        $key = $manifest['condition_states'][0]['key'];
        $absolutePath = Storage::disk('local')->path($form->pdf_storage_path);
        $page = $this->inkedPage($manifest, [$item->id => $key], $absolutePath);
        $scan = $this->createScan($inspection, $this->pageToUploadedFile($page));

        $reader = app(RentalInspectionScanReaderService::class);
        $reader->process($scan->fresh());
        $mark = $scan->fresh()->marks()->where('rental_inspection_item_id', $item->id)->firstOrFail();

        $observation = $reader->applyMark($mark, $key, $this->agent, \App\Models\RentalInspectionObservation::SOURCE_IN_INSPECTION);

        $this->assertSame($item->id, $observation->rental_inspection_item_id);
        $this->assertSame($key, $observation->condition);
        $this->assertSame($this->agent->id, $observation->observed_by_user_id);

        $mark->refresh();
        $this->assertSame($observation->id, $mark->applied_observation_id);
        $this->assertSame($this->agent->id, $mark->confirmed_by_user_id);
        $this->assertNotNull($mark->confirmed_at);
        // The audit trail: observation <- mark <- scan (which scan, which page, who confirmed).
        $this->assertSame($scan->id, $mark->rental_inspection_scan_id);
        $this->assertSame(1, $mark->page_number);
    }

    // ── Agency-configurable threshold ────────────────────────────────────

    public function test_omr_mark_threshold_defaults_and_is_agency_configurable(): void
    {
        $this->assertSame(RentalInspectionSetting::DEFAULT_OMR_MARK_THRESHOLD, RentalInspectionSetting::omrMarkThresholdFor($this->agency->id));

        RentalInspectionSetting::create(['agency_id' => $this->agency->id, 'omr_mark_threshold' => 0.6]);
        $this->assertSame(0.6, RentalInspectionSetting::omrMarkThresholdFor($this->agency->id));
    }
}
