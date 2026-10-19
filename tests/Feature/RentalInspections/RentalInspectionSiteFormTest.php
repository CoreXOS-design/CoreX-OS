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
use App\Models\RentalInspectionScan;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use App\Services\Rentals\RentalInspectionFormPdfService;
use App\Services\Rentals\RentalInspectionScanReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Tests\TestCase;

/**
 * AT-463 / .ai/specs/rental-inspections.md §60 — the printed site form and its read-back:
 *  - a QR code on every page identifies the inspection and page and orients the scan (any angle, upside down);
 *  - a form that belongs to another inspection is refused;
 *  - items already recorded on screen print greyed and are never read as ink;
 *  - the agency's paper size drives the page geometry the reader uses.
 * Every test generates a REAL form with the real service, inks it, rasterizes it and reads it back.
 */
final class RentalInspectionSiteFormTest extends TestCase
{
    use RefreshDatabase;

    private const DPI = 220;

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

        $this->agency = Agency::create(['name' => 'Site Form Agency', 'slug' => 'site-form-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->actingAs($this->agent);

        [$this->property, $this->lease] = $this->propertyWithLease('Site Form Property');
    }

    /** @return array{0: Property, 1: Lease} */
    private function propertyWithLease(string $title): array
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => $title, 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now(), 'created_by_user_id' => $this->agent->id,
        ]);

        return [$property, $lease];
    }

    private function addItems(Property $property, string $label, int $count): \Illuminate\Support\Collection
    {
        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id, 'type' => 'Bedroom', 'label' => $label,
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        for ($i = 1; $i <= $count; $i++) {
            RentalInspectionItem::create([
                'agency_id' => $this->agency->id, 'property_id' => $property->id, 'property_room_id' => $room->id,
                'kind' => RentalInspectionItem::KIND_SPACE, 'label' => "{$label} item {$i}", 'space_type' => 'Bedroom',
                'source' => 'manual', 'sort_order' => $i, 'created_by_user_id' => $this->agent->id,
            ]);
        }

        return RentalInspectionItem::where('property_room_id', $room->id)->orderBy('sort_order')->get();
    }

    private function inspectionFor(Property $property): RentalInspection
    {
        return RentalInspection::start($property, RentalInspection::TYPE_IN, $this->agent);
    }

    /** A condition per item, different down the list, so a systematic offset cannot pass by accident. */
    private function expectedMap(\Illuminate\Support\Collection $items, array $manifest, int $shift = 0): array
    {
        $keys = collect($manifest['condition_states'])->pluck('key')->all();
        $map = [];
        foreach ($items as $i => $item) {
            $map[$item->id] = $keys[($i + $shift) % count($keys)];
        }

        return $map;
    }

    /** Rasterize one page of a PDF (a path or raw bytes) and ink the chosen boxes of that form page. */
    private function inkedPage(array $manifest, array $map, string $pdf, int $formPage, ?callable $transform = null): Imagick
    {
        $path = $pdf;
        if (! is_file($pdf)) {
            $path = tempnam(sys_get_temp_dir(), 'sfpdf') . '.pdf';
            file_put_contents($path, $pdf);
        }
        $page = new Imagick();
        $page->setResolution(self::DPI, self::DPI);
        $page->readImage($path . '[' . ($formPage - 1) . ']');
        $page->setImageBackgroundColor(new ImagickPixel('white'));
        $page->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $page->setImageColorspace(Imagick::COLORSPACE_GRAY);

        $scale = self::DPI / 72.0;
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('black'));
        foreach ($manifest['boxes'] as $box) {
            if ((int) $box['page'] !== $formPage || ($map[(int) $box['rental_inspection_item_id']] ?? null) !== $box['condition_key']) {
                continue;
            }
            $x = (float) $box['x'] * $scale;
            $y = (float) $box['y'] * $scale;
            $draw->rectangle($x, $y, $x + (float) $box['width'] * $scale, $y + (float) $box['height'] * $scale);
        }
        $page->drawImage($draw);
        if ($transform !== null) {
            $transform($page);
        }

        return $page;
    }

    private function png(Imagick $page, string $name = 'scan.png'): UploadedFile
    {
        $page->setImageFormat('png');
        $tmp = tempnam(sys_get_temp_dir(), 'sfscan') . '.png';
        $page->writeImage($tmp);

        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    /** @param list<Imagick> $pages */
    private function multiPagePdf(array $pages, string $name = 'scan.pdf'): UploadedFile
    {
        $doc = new Imagick();
        foreach ($pages as $p) {
            $p->setImageFormat('pdf');
            $p->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);
            $p->setImageResolution(self::DPI, self::DPI);
            $doc->addImage($p);
        }
        $doc->setImageFormat('pdf');
        $tmp = tempnam(sys_get_temp_dir(), 'sfdoc') . '.pdf';
        $doc->writeImages($tmp, true);

        return new UploadedFile($tmp, $name, 'application/pdf', null, true);
    }

    private function scanFor(RentalInspection $inspection, UploadedFile $file): RentalInspectionScan
    {
        $path = $file->store("properties/{$inspection->property_id}/rental-inspection-scans", 'local');

        return RentalInspectionScan::create([
            'agency_id' => $inspection->agency_id, 'branch_id' => $this->property->branch_id,
            'rental_inspection_id' => $inspection->id, 'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path, 'mime_type' => $file->getClientMimeType(),
            'status' => RentalInspectionScan::STATUS_PROCESSING, 'uploaded_by_user_id' => $this->agent->id,
        ]);
    }

    private function read(RentalInspectionScan $scan): RentalInspectionScan
    {
        app(RentalInspectionScanReaderService::class)->process($scan->fresh());

        return $scan->refresh();
    }

    private function assertReadsBack(RentalInspectionScan $scan, \Illuminate\Support\Collection $items, array $expected, string $why): void
    {
        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->status, $why . ': ' . ($scan->failure_reason ?? ''));
        foreach ($items as $item) {
            $mark = $scan->marks()->where('rental_inspection_item_id', $item->id)->first();
            $this->assertNotNull($mark, "{$why}: no mark row for item {$item->id}");
            $this->assertFalse((bool) $mark->ambiguous, "{$why}: item {$item->id} unexpectedly ambiguous");
            $this->assertSame($expected[$item->id], $mark->detected_condition_key, "{$why}: item {$item->id} read the wrong column");
        }
    }

    private function usedCornerMarksOnly(RentalInspectionScan $scan): bool
    {
        foreach ($scan->read_notes ?? [] as $note) {
            if (str_contains($note, 'QR code could not be read')) {
                return true;
            }
        }

        return false;
    }

    // ── The QR on every page identifies the inspection and page, and orients the scan ─────────────────

    public function test_every_page_carries_a_qr_with_the_inspection_page_count_and_version(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 70); // enough rows for several pages
        $inspection = $this->inspectionFor($this->property);
        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;

        $this->assertGreaterThan(1, $form->page_count);
        $this->assertCount($form->page_count, $manifest['qr_codes']);
        foreach ($manifest['qr_codes'] as $i => $qr) {
            $parsed = RentalInspectionFormPdfService::parseQrPayload($qr['payload']);
            $this->assertSame($inspection->id, $parsed['rental_inspection_id']);
            $this->assertSame($i + 1, $parsed['page_number']);
            $this->assertSame($form->page_count, $parsed['page_count']);
            $this->assertSame($form->version, $parsed['form_version']);
        }

        // Row index: every printed row has its own running number, and each box carries its row's number.
        $rowNos = collect($manifest['boxes'])->pluck('row_no', 'rental_inspection_item_id')->unique();
        $this->assertSame(range(1, $items->count()), $rowNos->sort()->values()->all());
    }

    public function test_the_qr_payload_round_trips_and_rejects_text_that_is_not_ours(): void
    {
        $this->assertSame(
            ['rental_inspection_id' => 160, 'page_number' => 2, 'page_count' => 5, 'form_version' => 3],
            RentalInspectionFormPdfService::parseQrPayload(RentalInspectionFormPdfService::qrPayload(160, 2, 5, 3))
        );
        foreach (['https://example.com/x', 'CXI1|160|2|5', 'CXI2|160|2|5|3', 'CXI1|abc|2|5|3', ''] as $foreign) {
            $this->assertNull(RentalInspectionFormPdfService::parseQrPayload($foreign), "accepted: {$foreign}");
        }
    }

    public function test_the_qr_identifies_and_orients_scans_at_every_angle(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 4);
        $inspection = $this->inspectionFor($this->property);
        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;
        $expected = $this->expectedMap($items, $manifest, 1);
        $pdf = Storage::disk('local')->path($form->pdf_storage_path);

        foreach ([0 => 'straight', 180 => 'upside down', 90 => 'sideways (90)', 270 => 'sideways (270)', 20 => '20 degree skew', -12 => 'tilted the other way'] as $angle => $label) {
            $page = $this->inkedPage($manifest, $expected, $pdf, 1, $angle === 0 ? null : fn (Imagick $p) => $p->rotateImage(new ImagickPixel('white'), $angle));
            $scan = $this->read($this->scanFor($inspection, $this->png($page, "angle{$angle}.png")));

            $this->assertReadsBack($scan, $items, $expected, $label);
            $this->assertFalse($this->usedCornerMarksOnly($scan), "{$label}: the QR could not be read and the page fell back to the corner marks");
            $this->assertSame($inspection->id, $scan->decoded_inspection_id);
            $this->assertSame($form->version, $scan->decoded_form_version);
        }
    }

    public function test_a_page_whose_qr_is_unreadable_still_reads_from_the_corner_marks_and_says_so(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 4);
        $inspection = $this->inspectionFor($this->property);
        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;
        $expected = $this->expectedMap($items, $manifest);

        $qr = $manifest['qr_codes'][0];
        $page = $this->inkedPage($manifest, $expected, Storage::disk('local')->path($form->pdf_storage_path), 1, function (Imagick $p) use ($qr) {
            $s = self::DPI / 72.0;
            $d = new ImagickDraw();
            $d->setFillColor(new ImagickPixel('white'));
            $d->rectangle(($qr['x'] - 2) * $s, ($qr['y'] - 2) * $s, ($qr['x'] + $qr['size'] + 2) * $s, ($qr['y'] + $qr['size'] + 2) * $s);
            $p->drawImage($d);
        });
        $scan = $this->read($this->scanFor($inspection, $this->png($page)));

        $this->assertReadsBack($scan, $items, $expected, 'QR blanked out');
        $this->assertTrue($this->usedCornerMarksOnly($scan), 'the fallback was not reported');
    }

    // ── Another inspection's form is refused ──────────────────────────────────────────────────────

    public function test_a_form_that_belongs_to_another_inspection_is_refused(): void
    {
        $itemsA = $this->addItems($this->property, 'Bedroom 1', 3);
        $inspectionA = $this->inspectionFor($this->property);
        app(RentalInspectionFormPdfService::class)->generate($inspectionA, $this->agent);

        [$propertyB] = $this->propertyWithLease('Other Property');
        $itemsB = $this->addItems($propertyB, 'Lounge', 3);
        $inspectionB = $this->inspectionFor($propertyB);
        $formB = app(RentalInspectionFormPdfService::class)->generate($inspectionB, $this->agent);

        $page = $this->inkedPage($formB->manifest_json, $this->expectedMap($itemsB, $formB->manifest_json), Storage::disk('local')->path($formB->pdf_storage_path), 1);
        $scan = $this->read($this->scanFor($inspectionA, $this->png($page)));

        $this->assertSame(RentalInspectionScan::STATUS_FAILED, $scan->status);
        $this->assertSame($inspectionB->id, $scan->decoded_inspection_id);
        $this->assertStringContainsString("inspection #{$inspectionB->id}", (string) $scan->failure_reason);
        $this->assertSame(0, $scan->marks()->count(), 'nothing may be proposed from another inspection\'s form');
        $this->assertCount(3, $itemsA);
    }

    public function test_uploading_another_inspections_form_is_refused_and_nothing_is_kept(): void
    {
        $this->addItems($this->property, 'Bedroom 1', 3);
        $inspectionA = $this->inspectionFor($this->property);
        app(RentalInspectionFormPdfService::class)->generate($inspectionA, $this->agent);

        [$propertyB] = $this->propertyWithLease('Other Property');
        $itemsB = $this->addItems($propertyB, 'Lounge', 3);
        $inspectionB = $this->inspectionFor($propertyB);
        $formB = app(RentalInspectionFormPdfService::class)->generate($inspectionB, $this->agent);
        $page = $this->inkedPage($formB->manifest_json, $this->expectedMap($itemsB, $formB->manifest_json), Storage::disk('local')->path($formB->pdf_storage_path), 1);

        $response = $this->actingAs($this->agent)->from('/back')->post(
            route('corex.rental-inspections.scans.store', $inspectionA),
            ['scan' => $this->png($page, 'wrong.png')]
        );

        $response->assertRedirect('/back');
        $response->assertSessionHas('error');
        $this->assertStringContainsString("#{$inspectionB->id}", (string) session('error'));
        $this->assertSame(0, RentalInspectionScan::where('rental_inspection_id', $inspectionA->id)->count(), 'the wrong form was filed against this inspection');
        $this->assertSame([], Storage::disk('local')->allFiles("properties/{$inspectionA->property_id}/rental-inspection-scans"), 'the wrong form\'s file was kept');
    }

    // ── Pages are placed by their own page number; missing pages are said so ─────────────────────

    public function test_pages_are_placed_by_their_own_page_number_even_when_the_file_has_them_in_another_order(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 45); // two pages
        $inspection = $this->inspectionFor($this->property);
        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;
        $this->assertSame(2, $form->page_count);
        $expected = $this->expectedMap($items, $manifest, 2);
        $pdf = Storage::disk('local')->path($form->pdf_storage_path);

        $page1 = $this->inkedPage($manifest, $expected, $pdf, 1);
        $page2 = $this->inkedPage($manifest, $expected, $pdf, 2);
        $scan = $this->read($this->scanFor($inspection, $this->multiPagePdf([$page2, $page1], 'shuffled.pdf')));

        $this->assertReadsBack($scan, $items, $expected, 'pages in reverse order');
        $this->assertSame([], array_values(array_filter($scan->read_notes ?? [], fn ($n) => str_contains($n, 'not in this scan'))));
    }

    public function test_pages_missing_from_the_upload_are_named_and_their_items_get_nothing_proposed(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 45);
        $inspection = $this->inspectionFor($this->property);
        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;
        $expected = $this->expectedMap($items, $manifest);

        $page2 = $this->inkedPage($manifest, $expected, Storage::disk('local')->path($form->pdf_storage_path), 2);
        $scan = $this->read($this->scanFor($inspection, $this->png($page2)));

        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->status);
        $this->assertContains('Pages not read from this scan: 1 of 2. Items on those pages have nothing proposed.', $scan->read_notes);
        $onPage2 = collect($manifest['boxes'])->where('page', 2)->pluck('rental_inspection_item_id')->unique();
        $this->assertSame($onPage2->count(), $scan->marks()->count());
        $this->assertSame(0, $scan->marks()->whereNotIn('rental_inspection_item_id', $onPage2)->count());
    }

    // ── What is already recorded on screen ────────────────────────────────────────────────────────

    public function test_a_row_already_recorded_on_screen_proposes_nothing_unless_it_is_ticked_differently(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 4);
        $inspection = $this->inspectionFor($this->property);
        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;
        $keys = collect($manifest['condition_states'])->pluck('key')->all();

        // item 0: recorded, row left alone · item 1: recorded, ticked the same · item 2: recorded, ticked differently · item 3: not recorded, ticked
        foreach ([0, 1, 2] as $i) {
            RentalInspectionObservation::record([
                'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $items[$i]->id,
                'observed_by_user_id' => $this->agent->id, 'condition' => $keys[0], 'notes' => null, 'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            ]);
        }
        $map = [$items[1]->id => $keys[0], $items[2]->id => $keys[1], $items[3]->id => $keys[2]];
        $page = $this->inkedPage($manifest, $map, Storage::disk('local')->path($form->pdf_storage_path), 1);
        $scan = $this->read($this->scanFor($inspection, $this->png($page)));

        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->status);
        $this->assertNull($scan->marks()->where('rental_inspection_item_id', $items[0]->id)->first(), 'a row left alone on a recorded item proposed something');
        $this->assertNull($scan->marks()->where('rental_inspection_item_id', $items[1]->id)->first(), 'a row ticked the same as the screen proposed something');
        $this->assertSame($keys[1], $scan->marks()->where('rental_inspection_item_id', $items[2]->id)->value('detected_condition_key'));
        $this->assertSame($keys[2], $scan->marks()->where('rental_inspection_item_id', $items[3]->id)->value('detected_condition_key'));
        $this->assertContains('2 rows match what is already recorded on screen, so nothing is proposed for them.', $scan->read_notes);
    }

    public function test_the_site_form_prints_recorded_items_greyed_with_the_header_filled_and_the_reader_never_counts_the_grey(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 4);
        $inspection = $this->inspectionFor($this->property);
        $inspection->forceFill(['electricity_meter_reading' => '12345', 'water_meter_reading' => '678', 'keys_count' => 3, 'keys_description' => 'front door set'])->save();
        $service = app(RentalInspectionFormPdfService::class);
        $form = $service->generate($inspection, $this->agent);
        $keys = collect($form->manifest_json['condition_states'])->pluck('key')->all();

        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $items[0]->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => $keys[1], 'notes' => null, 'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ]);

        $versionBefore = $form->version;
        $form = $service->generate($inspection->fresh(), $this->agent);
        $this->assertSame($versionBefore, $form->version, 'recording an item must not create a new form version');

        $bytes = $service->render($form, $inspection->fresh());
        $text = (new \Smalot\PdfParser\Parser())->parseContent($bytes)->getText();
        $this->assertStringContainsString('12345', $text);
        $this->assertStringContainsString('front door set', $text);
        $this->assertStringContainsString('Grey boxes are already recorded on screen (1 items)', $text);

        // The recorded item's matching box is grey; every other box is plain paper.
        $tmp = tempnam(sys_get_temp_dir(), 'sfgrey') . '.pdf';
        file_put_contents($tmp, $bytes);
        $img = new Imagick();
        $img->setResolution(100, 100);
        $img->readImage($tmp . '[0]');
        $s = 100 / 72.0;
        $lum = function (array $box) use ($img, $s): float {
            $px = $img->getImagePixelColor((int) round(($box['x'] + $box['width'] / 2) * $s), (int) round(($box['y'] + $box['height'] / 2) * $s));

            return $px->getColorValue(Imagick::COLOR_RED);
        };
        $boxes = collect($form->manifest_json['boxes'])->where('rental_inspection_item_id', $items[0]->id)->values();
        foreach ($boxes as $box) {
            $v = $lum($box);
            if ($box['condition_key'] === $keys[1]) {
                $this->assertGreaterThan(0.70, $v);
                $this->assertLessThan(0.90, $v, 'the recorded state is not shown greyed');
            } else {
                $this->assertGreaterThan(0.95, $v, 'an unrecorded box is not plain paper');
            }
        }

        // Scanning that very print with nothing inked reads no ink in the grey: the recorded item proposes nothing.
        $page = $this->inkedPage($form->manifest_json, [], $tmp, 1);
        $scan = $this->read($this->scanFor($inspection, $this->png($page)));
        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->status, (string) $scan->failure_reason);
        $this->assertNull($scan->marks()->where('rental_inspection_item_id', $items[0]->id)->first());
    }

    // ── Columns follow the agency's states; the paper follows the agency's setting ────────────────

    public function test_the_columns_are_the_agencys_condition_states_in_their_order(): void
    {
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['condition_states' => [
            ['key' => 'ok', 'label' => 'OK', 'requires_notes' => false],
            ['key' => 'worn', 'label' => 'Worn', 'requires_notes' => false],
            ['key' => 'broken', 'label' => 'Broken', 'requires_notes' => true],
        ]]);
        $items = $this->addItems($this->property, 'Kitchen', 3);
        $inspection = $this->inspectionFor($this->property);
        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;

        $this->assertSame(['ok', 'worn', 'broken'], array_column($manifest['condition_states'], 'key'));
        foreach ($items as $item) {
            $row = collect($manifest['boxes'])->where('rental_inspection_item_id', $item->id)->values();
            $this->assertSame(['ok', 'worn', 'broken'], $row->pluck('condition_key')->all());
            $this->assertTrue($row[0]['x'] < $row[1]['x'] && $row[1]['x'] < $row[2]['x'], 'columns are not left to right in the agency\'s order');
        }
    }

    public function test_the_paper_size_setting_drives_the_page_geometry_and_the_reader_follows_it(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 4);
        $service = app(RentalInspectionFormPdfService::class);

        $a4 = $service->generate($this->inspectionFor($this->property), $this->agent);
        $this->assertSame('a4', $a4->manifest_json['page_size']['key']);
        $this->assertEqualsWithDelta(595.28, $a4->manifest_json['page_size']['width_pt'], 0.01);

        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['form_paper_size' => 'letter']);
        $inspection = RentalInspection::where('agency_id', $this->agency->id)->first();
        $letter = $service->generate($inspection, $this->agent);

        $this->assertGreaterThan($a4->version, $letter->version, 'a paper change must be a new form version (the old print no longer reads)');
        $this->assertSame('letter', $letter->manifest_json['page_size']['key']);
        $this->assertEqualsWithDelta(612.0, $letter->manifest_json['page_size']['width_pt'], 0.01);
        $this->assertEqualsWithDelta(792.0, $letter->manifest_json['page_size']['height_pt'], 0.01);

        $expected = $this->expectedMap($items, $letter->manifest_json, 1);
        $page = $this->inkedPage($letter->manifest_json, $expected, $service->render($letter, $inspection), 1);
        $this->assertEqualsWithDelta(612 * self::DPI / 72, $page->getImageWidth(), 3, 'the PDF is not US Letter');
        $scan = $this->read($this->scanFor($inspection, $this->png($page)));

        $this->assertReadsBack($scan, $items, $expected, 'US Letter');
        $this->assertFalse($this->usedCornerMarksOnly($scan), 'the QR was not read on the Letter form');
    }

    public function test_the_paper_size_resolves_to_a4_when_never_chosen_or_unrecognised(): void
    {
        $this->assertSame('a4', RentalInspectionSetting::formPaperSizeFor($this->agency->id));
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['form_paper_size' => 'tabloid']);
        $this->assertSame('a4', RentalInspectionSetting::formPaperSizeFor($this->agency->id));
        $this->assertSame('a4', RentalInspectionSetting::formPaperSizeFor(null));
    }

    // ── The screen: both buttons, the retained scan, and the review wording ───────────────────────

    public function test_the_panel_offers_print_site_form_and_upload_completed_form(): void
    {
        $panel = (string) file_get_contents(resource_path('views/corex/properties/partials/rental-inspection-recording.blade.php'));
        $this->assertStringContainsString('data-qa="print-site-form"', $panel);
        $this->assertStringContainsString('Print site form', $panel);
        $this->assertStringContainsString('data-qa="upload-site-form"', $panel);
        $this->assertStringContainsString('Upload completed form', $panel);
        $this->assertStringContainsString("'/form'", $panel);
        $this->assertStringContainsString("'/scans'", $panel);

        $show = (string) file_get_contents(resource_path('views/corex/rental-inspections/show.blade.php'));
        $this->assertStringContainsString('Print site form', $show);
        $this->assertStringContainsString('Upload completed form', $show);
    }

    public function test_the_tab_payload_lists_the_scans_kept_against_the_inspection_and_the_original_opens(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 3);
        $inspection = $this->inspectionFor($this->property);
        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $page = $this->inkedPage($form->manifest_json, $this->expectedMap($items, $form->manifest_json), Storage::disk('local')->path($form->pdf_storage_path), 1);
        $scan = $this->read($this->scanFor($inspection, $this->png($page, 'site-form.png')));

        $payload = RentalInspection::tabPayloadFor($this->property->fresh());
        $listed = $payload['chain_tail']->site_form_scans;
        $this->assertCount(1, $listed);
        $this->assertSame($scan->id, $listed[0]['id']);
        $this->assertSame('Needs review', $listed[0]['status_label']);
        $this->assertNotNull($listed[0]['review_url']);

        $open = $this->actingAs($this->agent)->get($listed[0]['view_url']);
        $open->assertOk();
        $this->assertStringContainsString('inline', (string) $open->headers->get('Content-Disposition'));
    }

    public function test_the_review_screen_says_apply_proposed_marks_and_leaves_doubtful_rows_blank(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 3);
        $inspection = $this->inspectionFor($this->property);
        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $manifest = $form->manifest_json;
        $keys = collect($manifest['condition_states'])->pluck('key')->all();
        $page = $this->inkedPage($manifest, [$items[0]->id => $keys[0], $items[1]->id => $keys[1]], Storage::disk('local')->path($form->pdf_storage_path), 1);
        $scan = $this->read($this->scanFor($inspection, $this->png($page)));

        // Make one read look doubtful (only just over the tick-box sensitivity).
        $scan->marks()->where('rental_inspection_item_id', $items[1]->id)->update(['detected_confidence' => 0.40]);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-inspections.scans.review', [$inspection, $scan]))->assertOk()->getContent();
        $this->assertStringContainsString('Apply proposed marks', $html);
        $this->assertStringContainsString('data-qa="row-low-confidence"', $html);
        $this->assertStringContainsString('data-qa="row-unclear"', $html); // the third row was left blank
        $this->assertStringContainsString('Open original', $html);
        $this->assertStringContainsString('Nothing changes on the inspection until you press', $html);
    }

    // ── AT-463 / §62: what an office scanner and a hand actually produce ──────────────────────────

    /** Rasterize a form page and tick the chosen boxes BY HAND: a check-mark stroke that covers 10-20 % of a box, not a solid fill. */
    private function tickedPage(array $manifest, array $map, string $pdf, int $formPage): Imagick
    {
        $page = new Imagick();
        $page->setResolution(self::DPI, self::DPI);
        $page->readImage($pdf . '[' . ($formPage - 1) . ']');
        $page->setImageBackgroundColor(new ImagickPixel('white'));
        $page->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $page->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $page->setImagePage(0, 0, 0, 0);

        $s = self::DPI / 72.0;
        $d = new ImagickDraw();
        $d->setStrokeColor(new ImagickPixel('black'));
        $d->setFillOpacity(0);
        $d->setStrokeLineCap(Imagick::LINECAP_ROUND);
        $d->setStrokeLineJoin(Imagick::LINEJOIN_ROUND);
        $d->setStrokeWidth(1.3 * $s);
        foreach ($manifest['boxes'] as $box) {
            if ((int) $box['page'] !== $formPage || ($map[(int) $box['rental_inspection_item_id']] ?? null) !== $box['condition_key']) {
                continue;
            }
            $x = (float) $box['x'] * $s;
            $y = (float) $box['y'] * $s;
            $w = (float) $box['width'] * $s;
            $h = (float) $box['height'] * $s;
            $d->polyline([
                ['x' => $x + $w * 0.18, 'y' => $y + $h * 0.52],
                ['x' => $x + $w * 0.40, 'y' => $y + $h * 0.80],
                ['x' => $x + $w * 0.86, 'y' => $y + $h * 0.16],
            ]);
        }
        $page->drawImage($d);

        return $page;
    }

    /**
     * What a Ricoh-style office scanner does to a page (measured on Johan's real scans): the sheet comes out slightly rotated and
     * stretched down its length, the optics blur it, toner dust speckles it, and a solid black square is rendered as a 1-bit
     * HALFTONE patch (30-65 % dark) rather than solid black.
     */
    private function officeScan(Imagick $im, float $degrees, float $stretch): Imagick
    {
        mt_srand(11);
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $im->rotateImage(new ImagickPixel('white'), $degrees);
        $im->cropImage($w, $h, (int) (($im->getImageWidth() - $w) / 2), (int) (($im->getImageHeight() - $h) / 2));
        $im->setImagePage(0, 0, 0, 0);
        $im->resizeImage($w, (int) round($h * $stretch), Imagick::FILTER_LANCZOS, 1);
        $im->extentImage($w, $h, 0, 0);
        $im->setImagePage(0, 0, 0, 0);
        $im->blurImage(0, 0.7);
        $dust = new ImagickDraw();
        $dust->setFillColor(new ImagickPixel('rgb(90,90,90)'));
        for ($i = 0; $i < 450; $i++) {
            $x = mt_rand(0, $w - 3);
            $y = mt_rand(0, $h - 3);
            $dust->circle($x, $y, $x + mt_rand(1, 2), $y + mt_rand(1, 2));
        }
        $im->drawImage($dust);
        $im->evaluateImage(Imagick::EVALUATE_ADD, 0.28 * $im->getQuantumRange()['quantumRangeLong'], Imagick::CHANNEL_ALL);
        $im->orderedPosterizeImage('h6x6a');
        $im->setImageColorspace(Imagick::COLORSPACE_GRAY);

        return $im;
    }

    private function formFor(RentalInspection $inspection): \App\Models\RentalInspectionForm
    {
        return app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
    }

    public function test_hand_ticks_on_a_halftoned_office_scan_stretched_and_rotated_read_back_exactly(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 20);
        $inspection = $this->inspectionFor($this->property);
        $form = $this->formFor($inspection);
        $manifest = $form->manifest_json;
        $expected = $this->expectedMap($items, $manifest, 1);

        $page = $this->officeScan($this->tickedPage($manifest, $expected, Storage::disk('local')->path($form->pdf_storage_path), 1), 1.5, 1.012);
        $scan = $this->read($this->scanFor($inspection, $this->png($page, 'ricoh.png')));

        $this->assertReadsBack($scan, $items, $expected, 'hand ticks, halftone, 1.2% stretch, 1.5 degrees');
        $result = $scan->page_results[0];
        $this->assertSame('read', $result['status']);
        $this->assertSame(1, $result['form_page']);
        $this->assertSame(20, $result['rows']);
        $this->assertSame(20, $result['rows_located'], 'every row is located from its own printed boxes');
        $this->assertSame(20, $result['proposed']);
        $this->assertSame(0, $result['flagged']);
    }

    public function test_a_picture_that_carries_a_canvas_offset_still_reads(): void
    {
        // A rotated or cropped picture saved by a scanner or phone app can carry a virtual-canvas offset; every crop the
        // reader makes must be on the pixels, not on that canvas.
        $items = $this->addItems($this->property, 'Bedroom 1', 6);
        $inspection = $this->inspectionFor($this->property);
        $form = $this->formFor($inspection);
        $expected = $this->expectedMap($items, $form->manifest_json);

        $page = $this->tickedPage($form->manifest_json, $expected, Storage::disk('local')->path($form->pdf_storage_path), 1);
        $page->setImagePage($page->getImageWidth() + 300, $page->getImageHeight() + 200, 120, 80);
        $scan = $this->read($this->scanFor($inspection, $this->png($page, 'offset.png')));

        $this->assertReadsBack($scan, $items, $expected, 'picture with a canvas offset');
    }

    public function test_an_upload_with_the_pages_out_of_order_and_one_missing_reports_each_page_and_reads_the_rest(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 110);
        $inspection = $this->inspectionFor($this->property);
        $form = $this->formFor($inspection);
        $manifest = $form->manifest_json;
        $last = (int) $manifest['page_count'];
        $this->assertGreaterThanOrEqual(3, $last);
        $expected = $this->expectedMap($items, $manifest);
        $pdf = Storage::disk('local')->path($form->pdf_storage_path);

        // File page 1 is the form's LAST page, file page 2 is form page 1; the pages between were never scanned.
        $file = $this->multiPagePdf([
            $this->officeScan($this->tickedPage($manifest, $expected, $pdf, $last), -1.2, 1.01),
            $this->officeScan($this->tickedPage($manifest, $expected, $pdf, 1), 0.8, 1.01),
        ], 'two-of-three.pdf');
        $scan = $this->read($this->scanFor($inspection, $file));

        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->status, $scan->failure_reason ?? '');
        $this->assertCount(2, $scan->page_results);
        $this->assertSame([1, $last, 'read'], [$scan->page_results[0]['page'], $scan->page_results[0]['form_page'], $scan->page_results[0]['status']]);
        $this->assertSame([2, 1, 'read'], [$scan->page_results[1]['page'], $scan->page_results[1]['form_page'], $scan->page_results[1]['status']]);
        $this->assertContains('Pages not read from this scan: ' . implode(', ', range(2, $last - 1)) . " of {$last}. Items on those pages have nothing proposed.", $scan->read_notes);

        $onPage = fn (int $n) => collect($manifest['boxes'])->where('page', $n)->pluck('rental_inspection_item_id')->unique();
        $this->assertSame($onPage(1)->count() + $onPage($last)->count(), $scan->marks()->count());
        $this->assertSame(0, $scan->marks()->whereIn('rental_inspection_item_id', $onPage(2))->count());
        foreach ($scan->marks as $mark) {
            $this->assertFalse((bool) $mark->ambiguous, "item {$mark->rental_inspection_item_id} ambiguous");
            $this->assertSame($expected[$mark->rental_inspection_item_id], $mark->detected_condition_key, "item {$mark->rental_inspection_item_id} read the wrong column");
            $this->assertContains($mark->page_number, [1, $last]);
        }
    }

    public function test_a_page_that_cannot_be_read_says_why_and_the_page_beside_it_still_reads(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 45);
        $inspection = $this->inspectionFor($this->property);
        $form = $this->formFor($inspection);
        $manifest = $form->manifest_json;
        $expected = $this->expectedMap($items, $manifest);

        $blank = new Imagick();
        $blank->newImage((int) (595.28 * self::DPI / 72), (int) (841.89 * self::DPI / 72), new ImagickPixel('white'));
        $blank->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $file = $this->multiPagePdf([$this->tickedPage($manifest, $expected, Storage::disk('local')->path($form->pdf_storage_path), 1), $blank], 'page-two-blank.pdf');
        $scan = $this->read($this->scanFor($inspection, $file));

        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->status, $scan->failure_reason ?? '');
        $this->assertSame('read', $scan->page_results[0]['status']);
        $this->assertSame('not_read', $scan->page_results[1]['status']);
        $this->assertNull($scan->page_results[1]['form_page']);
        $this->assertNotEmpty($scan->page_results[1]['reason'], 'a page that cannot be read is never silent');
        $this->assertContains('Pages not read from this scan: 2 of 2. Items on those pages have nothing proposed.', $scan->read_notes);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-inspections.scans.review', [$inspection, $scan]))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="scan-page-1-read"', $html);
        $this->assertStringContainsString('data-qa="scan-page-2-not-read"', $html);
        $this->assertStringContainsString('What was read from each page', $html);
    }

    public function test_reread_replaces_proposals_not_yet_applied_and_never_touches_one_that_was(): void
    {
        $items = $this->addItems($this->property, 'Bedroom 1', 4);
        $inspection = $this->inspectionFor($this->property);
        $form = $this->formFor($inspection);
        $expected = $this->expectedMap($items, $form->manifest_json, 2);
        $scan = $this->read($this->scanFor($inspection, $this->png($this->tickedPage($form->manifest_json, $expected, Storage::disk('local')->path($form->pdf_storage_path), 1))));
        $this->assertSame(4, $scan->marks()->count());

        // The agent applies the first row; the second proposal is left alone.
        $applied = $scan->marks()->where('rental_inspection_item_id', $items[0]->id)->firstOrFail();
        app(RentalInspectionScanReaderService::class)->applyMark($applied, $expected[$items[0]->id], $this->agent, RentalInspectionObservation::SOURCE_IN_INSPECTION);
        $observationsBefore = RentalInspectionObservation::where('rental_inspection_id', $inspection->id)->count();

        $this->artisan('rental-inspections:reread-scan', ['scan' => $scan->id])->assertSuccessful();

        $scan->refresh();
        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->status);
        $this->assertNotEmpty($scan->page_results);
        // Three unapplied proposals were brought back (not duplicated - the table holds one row per scan and item) ...
        $this->assertSame(3, $scan->marks()->whereNull('applied_observation_id')->count());
        foreach ($scan->marks()->whereNull('applied_observation_id')->get() as $mark) {
            $this->assertSame($expected[$mark->rental_inspection_item_id], $mark->detected_condition_key);
        }
        // ... and the applied one is exactly as the agent left it, with no second observation made.
        $applied->refresh();
        $this->assertNotNull($applied->applied_observation_id);
        $this->assertNotNull($applied->confirmed_at);
        $this->assertSame($observationsBefore, RentalInspectionObservation::where('rental_inspection_id', $inspection->id)->count());
        $this->assertSame(1, $scan->marks()->whereNotNull('applied_observation_id')->count());
    }

    public function test_the_migration_resets_a_stored_sensitivity_that_was_calibrated_for_solid_fills(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        RentalInspectionSetting::create(['agency_id' => $this->agency->id, 'omr_mark_threshold' => 0.35]);
        RentalInspectionSetting::withoutAgencyStamping(fn () => RentalInspectionSetting::create(['agency_id' => $other->id, 'omr_mark_threshold' => 0.08]));

        $migration = require base_path('database/migrations/2026_11_10_100100_reset_old_omr_mark_threshold_values.php');
        $migration->up();

        // 0.35 was the old default every agency stored by pressing Save; it reads no hand tick, so it goes back to "unset".
        $this->assertSame(RentalInspectionSetting::DEFAULT_OMR_MARK_THRESHOLD, RentalInspectionSetting::omrMarkThresholdFor($this->agency->id));
        // A value an agency chose on purpose for its own scanner is left alone.
        $this->assertSame(0.08, RentalInspectionSetting::omrMarkThresholdFor($other->id));
    }
}
