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
use App\Models\RentalInspectionPhotoNote;
use App\Models\RentalInspectionRoomNote;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use App\Services\Rentals\RentalInspectionPhotoCaptureTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §45.3 — Build I-1: photo capture time (stored server-side, never
 * rewritten, shown honestly), the every-item-graded completion guard, and report completeness.
 * NOT covered here, by Johan's ruling: any photo-requirement guard (Q5), and the PDF per-flaw photo
 * line (waits on Q8).
 */
final class RentalInspectionI1EvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private PropertyRoom $bedroom;
    private PropertyRoom $kitchen;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('public');

        $this->agency = Agency::create(['name' => 'I1 Agency', 'slug' => 'i1-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        // These fixtures have no checklist; the "empty checklist cannot be signed" and "Routine follows the full checks" rules (spec §52) have their own tests.
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['empty_checklist_blocks_signing' => false, 'routine_follows_full_checks' => false]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '7 Marine Drive, Margate', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->bedroom = $this->room('Bedroom 1', 0);
        $this->kitchen = $this->room('Kitchen', 1);
    }

    private function room(string $label, int $order): PropertyRoom
    {
        return PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => str_contains($label, 'Bed') ? 'Bedroom' : 'Kitchen', 'label' => $label, 'source' => 'manual',
            'sort_order' => $order, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function item(PropertyRoom $room, string $label, int $sort = 0): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'sort_order' => $sort,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function inspection(string $type = RentalInspection::TYPE_IN, array $extra = []): RentalInspection
    {
        return RentalInspection::create(array_merge([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'created_by_user_id' => $this->agent->id,
        ], $extra));
    }

    private function grade(RentalInspection $inspection, RentalInspectionItem $item, string $condition = 'good'): void
    {
        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => $condition, 'source' => 'in_inspection',
        ])->assertOk();
    }

    /** A real JPEG carrying EXIF DateTimeOriginal (and optionally OffsetTimeOriginal), built byte by byte. */
    private function jpegWithExif(string $dateTimeOriginal, ?string $offset = null): UploadedFile
    {
        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($img);
        $jpg = (string) ob_get_clean();

        $dt = $dateTimeOriginal . "\0";                              // 20 bytes
        $entries = pack('nnNN', 0x9003, 2, strlen($dt), 0);          // offset patched below
        $count = 1;
        $off = $offset !== null ? $offset . "\0" : null;             // 7 bytes
        $exifIfdAt = 26;
        $dataAt = $exifIfdAt + 2 + 12 * ($offset !== null ? 2 : 1) + 4;
        $entries = pack('nnNN', 0x9003, 2, strlen($dt), $dataAt);
        $blob = $dt;
        if ($off !== null) {
            $entries .= pack('nnNN', 0x9011, 2, strlen($off), $dataAt + strlen($dt));
            $blob .= $off;
            $count = 2;
        }
        $tiff = 'MM' . pack('nN', 42, 8)
            . pack('n', 1) . pack('nnNN', 0x8769, 4, 1, $exifIfdAt) . pack('N', 0)
            . pack('n', $count) . $entries . pack('N', 0)
            . $blob;
        $app1 = "Exif\0\0" . $tiff;
        $jpg = substr($jpg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpg, 2);

        $path = tempnam(sys_get_temp_dir(), 'exif') . '.jpg';
        file_put_contents($path, $jpg);

        return new UploadedFile($path, 'camera.jpg', 'image/jpeg', null, true);
    }

    private function upload(RentalInspection $inspection, UploadedFile $file, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('corex.rental-inspections.photos.store', $inspection), array_merge(['photos' => [$file]], $extra));
    }

    // ═══ Capture time ══════════════════════════════════════════════════════════

    public function test_a_client_capture_time_is_stored_as_client_and_converted_to_the_app_timezone(): void
    {
        $inspection = $this->inspection();

        $this->upload($inspection, UploadedFile::fake()->image('a.jpg'), ['captured_at' => ['2026-08-12T14:03:00+02:00']])->assertStatus(201);

        $photo = RentalInspectionPhoto::firstOrFail();
        $this->assertSame('client', $photo->taken_at_source);
        $this->assertSame('2026-08-12 14:03:00', $photo->taken_at->format('Y-m-d H:i:s'), 'Africa/Johannesburg is +02:00, so wall-clock time is unchanged');

        // A UTC client time lands in app-timezone wall-clock time.
        $this->upload($inspection, UploadedFile::fake()->image('b.jpg'), ['captured_at' => ['2026-08-12T12:03:00Z']])->assertStatus(201);
        $this->assertSame('2026-08-12 14:03:00', RentalInspectionPhoto::orderByDesc('id')->first()->taken_at->format('Y-m-d H:i:s'));
    }

    public function test_exif_time_is_used_when_the_client_sent_none(): void
    {
        $inspection = $this->inspection();

        $this->upload($inspection, $this->jpegWithExif('2026:08:12 09:15:30'))->assertStatus(201);
        $photo = RentalInspectionPhoto::firstOrFail();
        $this->assertSame('exif', $photo->taken_at_source);
        $this->assertSame('2026-08-12 09:15:30', $photo->taken_at->format('Y-m-d H:i:s'), 'no offset in the file = the app timezone');

        $this->upload($inspection, $this->jpegWithExif('2026:08:12 09:15:30', '+00:00'))->assertStatus(201);
        $this->assertSame('2026-08-12 11:15:30', RentalInspectionPhoto::orderByDesc('id')->first()->taken_at->format('Y-m-d H:i:s'), 'an explicit offset in the file wins');
    }

    public function test_client_time_beats_exif_when_both_are_present(): void
    {
        $inspection = $this->inspection();

        $this->upload($inspection, $this->jpegWithExif('2026:08:12 09:15:30'), ['captured_at' => ['2026-08-13T10:00:00+02:00']])->assertStatus(201);

        $photo = RentalInspectionPhoto::firstOrFail();
        $this->assertSame('client', $photo->taken_at_source);
        $this->assertSame('2026-08-13 10:00:00', $photo->taken_at->format('Y-m-d H:i:s'));
    }

    public function test_no_client_time_and_no_exif_falls_back_to_the_server_receive_time(): void
    {
        $inspection = $this->inspection();

        $this->upload($inspection, UploadedFile::fake()->image('plain.jpg'))->assertStatus(201);

        $photo = RentalInspectionPhoto::firstOrFail();
        $this->assertSame('server', $photo->taken_at_source);
        $this->assertEqualsWithDelta(now()->timestamp, $photo->taken_at->timestamp, 5);
    }

    public function test_malformed_and_ancient_client_times_are_absorbed_never_a_failed_upload(): void
    {
        $inspection = $this->inspection();

        foreach (['not a date', '31/31/2026', '0000-00-00T00:00:00Z', '1960-01-01T00:00:00Z', ''] as $bad) {
            $this->upload($inspection, UploadedFile::fake()->image('x.jpg'), ['captured_at' => [$bad]])->assertStatus(201);
        }
        // A non-string (array) claim is also just "no claim".
        $this->upload($inspection, UploadedFile::fake()->image('y.jpg'), ['captured_at' => [['nested']]])->assertStatus(201);

        $this->assertSame(6, RentalInspectionPhoto::count());
        $this->assertSame(['server'], RentalInspectionPhoto::pluck('taken_at_source')->unique()->values()->all());
    }

    public function test_a_future_client_or_exif_time_is_rejected_as_a_claim_and_logged(): void
    {
        Log::spy();
        $inspection = $this->inspection();

        $this->upload($inspection, UploadedFile::fake()->image('f.jpg'), ['captured_at' => [now()->addDays(3)->toIso8601String()]])->assertStatus(201);
        $this->upload($inspection, $this->jpegWithExif(now()->addYear()->format('Y:m:d H:i:s')))->assertStatus(201);

        $this->assertSame(['server', 'server'], RentalInspectionPhoto::orderBy('id')->pluck('taken_at_source')->all());
        foreach (RentalInspectionPhoto::all() as $photo) {
            $this->assertLessThan(60, abs(now()->timestamp - $photo->taken_at->timestamp), 'stored as the server time, not the future claim');
        }
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'capture time in the future rejected'))->twice();
    }

    public function test_a_time_inside_the_clock_skew_allowance_is_accepted(): void
    {
        $inspection = $this->inspection();

        $this->upload($inspection, UploadedFile::fake()->image('s.jpg'), ['captured_at' => [now()->addMinutes(3)->toIso8601String()]])->assertStatus(201);

        $this->assertSame('client', RentalInspectionPhoto::firstOrFail()->taken_at_source);
    }

    public function test_a_retry_with_the_same_key_returns_the_existing_photo_and_never_rewrites_its_time(): void
    {
        $inspection = $this->inspection();
        $key = (string) \Illuminate\Support\Str::uuid();

        $this->upload($inspection, UploadedFile::fake()->image('a.jpg'), ['captured_at' => ['2026-08-12T09:00:00+02:00'], 'client_idempotency_keys' => [$key]])->assertStatus(201);
        $this->upload($inspection, UploadedFile::fake()->image('a.jpg'), ['captured_at' => ['2026-09-30T18:00:00+02:00'], 'client_idempotency_keys' => [$key]])->assertStatus(201);

        $this->assertSame(1, RentalInspectionPhoto::count());
        $this->assertSame('2026-08-12 09:00:00', RentalInspectionPhoto::firstOrFail()->taken_at->format('Y-m-d H:i:s'));
    }

    public function test_the_legacy_single_photo_endpoint_records_a_capture_time_too(): void
    {
        $inspection = $this->inspection();
        $item = $this->item($this->bedroom, 'Ceiling');
        $observation = RentalInspectionObservation::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'condition' => 'good', 'observed_by_user_id' => $this->agent->id, 'source' => 'in_inspection',
        ]);

        $this->postJson(route('corex.rental-inspections.observations.photos.store', [$inspection, $observation]), [
            'photo' => $this->jpegWithExif('2026:08:12 09:15:30'),
        ])->assertStatus(201);

        $this->assertSame('exif', RentalInspectionPhoto::firstOrFail()->taken_at_source);
    }

    public function test_retagging_a_photo_never_changes_when_it_was_taken(): void
    {
        $inspection = $this->inspection();
        $this->upload($inspection, UploadedFile::fake()->image('a.jpg'), ['captured_at' => ['2026-08-12T09:00:00+02:00']])->assertStatus(201);
        $photo = RentalInspectionPhoto::firstOrFail();

        $this->postJson(route('corex.rental-inspections.photos.tag', [$inspection, $photo]), ['property_room_id' => $this->kitchen->id])->assertOk();
        $this->postJson(route('corex.rental-inspections.photos.untag', [$inspection, $photo]))->assertOk();

        $fresh = $photo->fresh();
        $this->assertSame('2026-08-12 09:00:00', $fresh->taken_at->format('Y-m-d H:i:s'));
        $this->assertSame('client', $fresh->taken_at_source);

        // And not by a direct write either — evidence, once set, is fixed.
        $fresh->forceFill(['taken_at' => now(), 'taken_at_source' => 'server'])->save();
        $this->assertSame('2026-08-12 09:00:00', $photo->fresh()->taken_at->format('Y-m-d H:i:s'));
        $this->assertSame('client', $photo->fresh()->taken_at_source);
    }

    public function test_every_photo_row_always_has_a_capture_time(): void
    {
        $inspection = $this->inspection();
        $photo = RentalInspectionPhoto::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'storage_path' => '/storage/x.jpg',
            'uploaded_by_user_id' => $this->agent->id,
        ]);

        $this->assertNotNull($photo->fresh()->taken_at);
        $this->assertSame('server', $photo->fresh()->taken_at_source);
    }

    public function test_the_migration_backfilled_rows_read_as_uploaded_not_taken(): void
    {
        $inspection = $this->inspection();
        $photo = RentalInspectionPhoto::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'storage_path' => '/storage/old.jpg',
            'uploaded_by_user_id' => $this->agent->id, 'created_at' => now()->subDays(40),
        ]);

        $this->assertStringStartsWith('Uploaded ', $photo->captionLabel());
        $this->assertStringNotContainsString('Taken', $photo->captionLabel());
    }

    // ═══ Captions ══════════════════════════════════════════════════════════════

    public function test_caption_wording_for_each_source_and_the_upload_note(): void
    {
        $inspection = $this->inspection();
        $make = fn (array $a) => new RentalInspectionPhoto(array_merge(['agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id], $a));

        $taken = \Carbon\Carbon::parse('2026-08-12 14:03:00', 'Africa/Johannesburg');

        // Real capture time, uploaded the same minute: just "Taken".
        $this->assertSame('Taken 12 Aug 2026 14:03', $make(['taken_at' => $taken, 'taken_at_source' => 'client', 'created_at' => $taken->copy()->addSeconds(20)])->captionLabel());
        // Uploaded later the same day: the upload time is added.
        $this->assertSame('Taken 12 Aug 2026 14:03 · uploaded 16:20', $make(['taken_at' => $taken, 'taken_at_source' => 'exif', 'created_at' => $taken->copy()->setTime(16, 20)])->captionLabel());
        // Uploaded another day: the upload date is added too.
        $this->assertSame('Taken 12 Aug 2026 14:03 · uploaded 14 Aug 2026 09:00', $make(['taken_at' => $taken, 'taken_at_source' => 'client', 'created_at' => $taken->copy()->addDays(2)->setTime(9, 0)])->captionLabel());
        // Server time is NEVER presented as a capture time.
        $server = $make(['taken_at' => $taken, 'taken_at_source' => 'server', 'created_at' => $taken]);
        $this->assertSame('Uploaded 12 Aug 2026 14:03', $server->captionLabel());
        $this->assertSame('Uploaded 14:03', $server->taken_caption_short);
        $this->assertSame('12 Aug 14:03', $make(['taken_at' => $taken, 'taken_at_source' => 'client', 'created_at' => $taken])->taken_caption_short);
    }

    public function test_the_captions_travel_with_the_photo_in_the_tab_payload(): void
    {
        $inspection = $this->inspection();
        $this->upload($inspection, UploadedFile::fake()->image('a.jpg'), ['captured_at' => ['2026-08-12T14:03:00+02:00']])->assertStatus(201);

        $json = $this->getJson(route('corex.properties.rental-inspection-tab.data', $this->property))->assertOk()->json();
        $photos = collect([$json['chain_tail'] ?? null, $json['in_inspection'] ?? null])->filter()->flatMap(fn ($i) => $i['photos'] ?? []);

        $this->assertNotEmpty($photos);
        $this->assertStringStartsWith('Taken 12 Aug 2026 14:03', $photos->first()['taken_caption']);
        $this->assertSame('12 Aug 14:03', $photos->first()['taken_caption_short']);
    }

    // ═══ Every-item-graded guard ═══════════════════════════════════════════════

    public function test_start_for_signature_and_complete_are_refused_with_the_full_list_while_an_item_is_ungraded(): void
    {
        $ceiling = $this->item($this->bedroom, 'Ceiling');
        $walls = $this->item($this->bedroom, 'Walls', 1);
        $sink = $this->item($this->kitchen, 'Sink');
        $inspection = $this->inspection();
        $this->grade($inspection, $ceiling);

        foreach (['start-awaiting-signature', 'complete'] as $route) {
            $response = $this->postJson(route('corex.rental-inspections.' . $route, $inspection))->assertStatus(409);

            $this->assertSame(
                [['Bedroom 1', 'Walls'], ['Kitchen', 'Sink']],
                collect($response->json('ungraded_items'))->map(fn ($m) => [$m['room_label'], $m['item_label']])->all(),
                'listed by room in walking order, graded items left out'
            );
            $this->assertStringContainsString('2 checklist items have not been recorded yet', $response->json('message'));
            $this->assertStringContainsString('Bedroom 1 (1), Kitchen (1)', $response->json('message'));
            $this->assertStringContainsString('Not applicable counts', $response->json('message'));
        }
        $this->assertSame(RentalInspection::STATUS_DRAFT, $inspection->fresh()->status);
    }

    public function test_not_applicable_counts_as_graded_and_the_inspection_then_moves_on(): void
    {
        $a = $this->item($this->bedroom, 'Ceiling');
        $b = $this->item($this->kitchen, 'Sink');
        $inspection = $this->inspection();
        $this->grade($inspection, $a, 'good');
        $this->grade($inspection, $b, 'n_a');

        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $inspection))->assertOk();
        $this->assertSame(RentalInspection::STATUS_AWAITING_SIGNATURE, $inspection->fresh()->status);
    }

    public function test_a_bare_photo_anchor_is_not_a_grade(): void
    {
        $item = $this->item($this->bedroom, 'Ceiling');
        $inspection = $this->inspection();

        // A photo filed to the item before anyone rated it creates a CONDITION_PENDING anchor row.
        $this->upload($inspection, UploadedFile::fake()->image('a.jpg'), ['rental_inspection_item_id' => $item->id])->assertStatus(201);
        $this->assertSame(1, $inspection->observations()->count());

        $this->postJson(route('corex.rental-inspections.complete', $inspection))
            ->assertStatus(409)->assertJsonPath('ungraded_items.0.item_label', 'Ceiling');
    }

    public function test_a_retired_item_does_not_count_and_a_room_note_does_not_substitute_for_a_grade(): void
    {
        $retired = $this->item($this->bedroom, 'Old fitting');
        $live = $this->item($this->bedroom, 'Ceiling', 1);
        $retired->update(['is_retired' => true]);
        $inspection = $this->inspection();
        RentalInspectionRoomNote::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'property_room_id' => $this->bedroom->id,
            'note' => 'Fresh paint.', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $inspection))
            ->assertStatus(409)
            ->assertJsonCount(1, 'ungraded_items')
            ->assertJsonPath('ungraded_items.0.item_id', $live->id);
    }

    public function test_the_guard_is_switched_off_by_the_agency_setting_and_ad_hoc_is_never_covered(): void
    {
        $this->item($this->bedroom, 'Ceiling');

        // Ad hoc: never gated, whatever the setting.
        $adHoc = $this->inspection(RentalInspection::TYPE_AD_HOC);
        $this->postJson(route('corex.rental-inspections.complete', $adHoc))->assertOk();
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $adHoc->fresh()->status);

        // Setting off: an in-inspection is no longer stopped for ungraded items (it then meets the next,
        // unrelated rule — signatures — which is a different message).
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['all_items_required_to_complete' => false]);
        $in = $this->inspection(RentalInspection::TYPE_IN);
        $response = $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $in));
        $response->assertOk();

        $out = $this->inspection(RentalInspection::TYPE_OUT);
        $this->assertNull($this->postJson(route('corex.rental-inspections.complete', $out))->json('ungraded_items'));
    }

    public function test_an_inspection_with_no_checklist_at_all_is_not_blocked(): void
    {
        $inspection = $this->inspection();

        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $inspection))->assertOk();
    }

    public function test_the_setting_defaults_on_and_saves_from_the_settings_page_without_wiping_on_a_subset_post(): void
    {
        $this->assertTrue(RentalInspectionSetting::allItemsRequiredToCompleteFor($this->agency->id));
        $this->assertTrue(RentalInspectionSetting::allItemsRequiredToCompleteFor(null));

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $base = ['fault_report_window_days' => 7, 'out_inspection_signing_window_days' => 7];

        $this->actingAs($admin)->post(route('corex.settings.rental-inspections.update'), $base + ['all_items_required_to_complete' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(RentalInspectionSetting::allItemsRequiredToCompleteFor($this->agency->id));

        // A wizard step that does not render this control posts a subset: the stored value must survive.
        $this->actingAs($admin)->post(route('corex.settings.rental-inspections.update'), $base)->assertSessionHasNoErrors();
        $this->assertFalse(RentalInspectionSetting::allItemsRequiredToCompleteFor($this->agency->id));

        $this->actingAs($admin)->post(route('corex.settings.rental-inspections.update'), $base + ['all_items_required_to_complete' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(RentalInspectionSetting::allItemsRequiredToCompleteFor($this->agency->id));
    }

    // ═══ Report completeness ═══════════════════════════════════════════════════

    private function publicPage(RentalInspection $inspection): string
    {
        $inspection->generatePublicLink();

        return $this->get(route('rental-inspections.public.show', $inspection->fresh()->public_token))->assertOk()->getContent();
    }

    public function test_the_public_report_shows_room_photos_photo_times_photo_notes_room_and_overall_notes(): void
    {
        $item = $this->item($this->bedroom, 'Ceiling');
        $inspection = $this->inspection(RentalInspection::TYPE_IN, ['overall_notes' => 'Apartment clean and fair.']);
        $this->grade($inspection, $item, 'good');

        // An item photo, a general bedroom photo, and a kitchen photo — the kitchen has NO graded item.
        $this->upload($inspection, UploadedFile::fake()->image('item.jpg'), ['rental_inspection_item_id' => $item->id, 'captured_at' => ['2026-08-12T14:03:00+02:00']])->assertStatus(201);
        $this->upload($inspection, UploadedFile::fake()->image('room.jpg'), ['property_room_id' => $this->bedroom->id, 'captured_at' => ['2026-08-12T14:05:00+02:00']])->assertStatus(201);
        $this->upload($inspection, UploadedFile::fake()->image('kitchen.jpg'), ['property_room_id' => $this->kitchen->id, 'captured_at' => ['2026-08-12T14:09:00+02:00']])->assertStatus(201);
        $roomPhoto = RentalInspectionPhoto::where('property_room_id', $this->bedroom->id)->whereNull('rental_inspection_observation_id')->firstOrFail();
        $itemPhoto = RentalInspectionPhoto::whereNotNull('rental_inspection_observation_id')->firstOrFail();
        foreach ([[$roomPhoto, 'Water stain above window.'], [$itemPhoto, 'Hairline crack.']] as [$p, $text]) {
            RentalInspectionPhotoNote::create([
                'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_photo_id' => $p->id,
                'classification_key' => 'defect', 'note' => $text, 'created_by_user_id' => $this->agent->id,
            ]);
        }
        RentalInspectionRoomNote::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'property_room_id' => $this->kitchen->id,
            'note' => 'Kitchen smelt of damp.', 'created_by_user_id' => $this->agent->id,
        ]);

        $html = $this->publicPage($inspection);

        $this->assertStringContainsString('Apartment clean and fair.', $html);
        $this->assertStringContainsString('Room photos', $html);
        $this->assertStringContainsString('Water stain above window.', $html);
        $this->assertStringContainsString('Hairline crack.', $html);
        $this->assertStringContainsString('Taken 12 Aug 2026 14:03', $html);
        $this->assertStringContainsString('Taken 12 Aug 2026 14:05', $html);
        $this->assertStringContainsString('Taken 12 Aug 2026 14:09', $html, 'a room with only a photo and a note, no graded item, still appears');
        $this->assertStringContainsString('Kitchen', $html);
        $this->assertStringContainsString('Kitchen smelt of damp.', $html);
    }

    public function test_an_archived_room_photo_is_not_shown_on_the_public_report(): void
    {
        $inspection = $this->inspection();
        $this->upload($inspection, UploadedFile::fake()->image('gone.jpg'), ['property_room_id' => $this->kitchen->id, 'captured_at' => ['2026-08-12T14:09:00+02:00']])->assertStatus(201);
        RentalInspectionPhoto::firstOrFail()->delete();

        $this->assertStringNotContainsString('Taken 12 Aug 2026 14:09', $this->publicPage($inspection));
    }

    public function test_the_public_report_still_renders_for_an_inspection_with_nothing_on_it(): void
    {
        $this->assertStringContainsString('No observations recorded yet', $this->publicPage($this->inspection()));
    }

    public function test_the_pdf_carries_the_overall_notes_and_each_rooms_note(): void
    {
        $item = $this->item($this->bedroom, 'Ceiling');
        $inspection = $this->inspection(RentalInspection::TYPE_IN, ['overall_notes' => 'Apartment clean and fair.']);
        $this->grade($inspection, $item, 'good');
        RentalInspectionRoomNote::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'property_room_id' => $this->bedroom->id,
            'note' => 'Wardrobe door sticks.', 'created_by_user_id' => $this->agent->id,
        ]);

        $pdf = app(\App\Services\Rentals\RentalInspectionReportPdfService::class)->generate($inspection->fresh());
        $html = $pdf->getDomPDF()->outputHtml();

        $this->assertStringContainsString('Overall notes:', $html);
        $this->assertStringContainsString('Apartment clean and fair.', $html);
        $this->assertStringContainsString('Room note:', $html);
        $this->assertStringContainsString('Wardrobe door sticks.', $html);
    }
}
