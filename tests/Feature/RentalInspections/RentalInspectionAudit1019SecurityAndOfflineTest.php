<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionRoomNote;
use App\Models\RentalInspectionSignature;
use App\Models\User;
use App\Services\AgentSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit 2026-10-19 - security (B20-B23, B25), hard deletes (B26-B28) and the offline / retry behaviour (B8, B9, B11, B12, B15-B17, B33).
 */
final class RentalInspectionAudit1019SecurityAndOfflineTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

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
        Storage::fake('public');

        $this->agency = Agency::create(['name' => 'Audit1019 Agency', 'slug' => 'a1019-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['empty_checklist_blocks_signing' => false, 'routine_follows_full_checks' => false]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Audit1019 Property', 'status' => 'let_out', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function item(string $label = 'Bedroom 1'): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function inspection(string $type = RentalInspection::TYPE_AD_HOC, array $attrs = []): RentalInspection
    {
        return RentalInspection::create(array_merge([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type, 'created_by_user_id' => $this->agent->id,
        ], $attrs));
    }

    private function jpeg(bool $withExif = false, string $name = 'p.jpg'): UploadedFile
    {
        $im = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        if ($withExif) {
            // a minimal EXIF APP1 segment (one ASCII tag): stands in for the GPS block a phone writes
            $tiff = "II*\x00\x08\x00\x00\x00" . "\x01\x00" . "\x0e\x01\x02\x00\x04\x00\x00\x00GPS\x00" . "\x00\x00\x00\x00";
            $payload = "Exif\x00\x00" . $tiff;
            $bytes = substr($bytes, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($bytes, 2);
        }
        $path = tempnam(sys_get_temp_dir(), 'ri') . '.jpg';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    // ── B20 ─────────────────────────────────────────────────────────────

    public function test_print_for_signature_get_never_mints_a_public_link(): void
    {
        $inspection = $this->inspection();
        $this->assertNull($inspection->public_token);

        $this->get(route('corex.rental-inspections.print-for-signature', $inspection))->assertOk();

        $this->assertNull($inspection->fresh()->public_token, 'a GET must not change state');
    }

    public function test_print_for_signature_post_mints_only_for_a_user_with_the_public_link_permission(): void
    {
        $inspection = $this->inspection();
        // a non-empty grant set for the agent role that does NOT include public_link
        DB::table('role_permissions')->insert([
            ['role' => 'agent', 'permission_key' => 'rental_inspections.view', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'agent', 'permission_key' => 'rental_inspections.create', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->post(route('corex.rental-inspections.print-for-signature.with-link', $inspection))->assertForbidden();
        $this->assertNull($inspection->fresh()->public_token);
    }

    public function test_print_for_signature_post_mints_for_a_user_with_the_public_link_permission(): void
    {
        $inspection = $this->inspection();
        DB::table('role_permissions')->insert([
            ['role' => 'agent', 'permission_key' => 'rental_inspections.view', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'agent', 'permission_key' => 'rental_inspections.public_link', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->post(route('corex.rental-inspections.print-for-signature.with-link', $inspection))->assertOk();
        $this->assertNotNull($inspection->fresh()->public_token);
    }

    // ── B22 ─────────────────────────────────────────────────────────────

    public function test_the_public_token_is_never_stored_in_plain_text(): void
    {
        $inspection = $this->inspection();
        $token = $inspection->generatePublicLink();

        $row = DB::table('rental_inspections')->where('id', $inspection->id)->first();
        $this->assertNull($row->public_token, 'no plain token in the database');
        $this->assertSame(hash('sha256', $token), $row->public_token_hash);
        $this->assertStringNotContainsString($token, (string) $row->public_token_encrypted);
        $this->assertSame($token, $inspection->fresh()->public_token, 'the agent screen can still show the same link');
        $this->assertSame($inspection->id, RentalInspection::findByPublicToken($token)?->id);
        $this->assertNull(RentalInspection::findByPublicToken(substr($token, 0, 20)));
        $this->assertArrayNotHasKey('public_token_hash', $inspection->fresh()->toArray());
    }

    public function test_the_migration_converts_an_existing_plain_token_in_place(): void
    {
        $inspection = $this->inspection();
        DB::table('rental_inspections')->where('id', $inspection->id)->update(['public_token' => 'legacyplaintoken0000000000000000000000000000000', 'public_token_hash' => null, 'public_token_encrypted' => null, 'public_token_expires_at' => now()->addDay()]);

        (include base_path('database/migrations/2026_11_19_100000_hash_inspection_public_tokens.php'))->up();

        $this->assertNull(DB::table('rental_inspections')->where('id', $inspection->id)->value('public_token'));
        $this->assertSame($inspection->id, RentalInspection::findByPublicToken('legacyplaintoken0000000000000000000000000000000')?->id, 'an already-issued link keeps working');
    }

    // ── B21 ─────────────────────────────────────────────────────────────

    public function test_an_uploaded_photo_is_private_signed_expiring_and_has_no_exif(): void
    {
        $inspection = $this->inspection();
        $item = $this->item();

        $response = $this->postJson(route('corex.rental-inspections.photos.store', $inspection), [
            'photos' => [$this->jpeg(true)], 'rental_inspection_item_id' => $item->id,
        ])->assertCreated();

        $photo = RentalInspectionPhoto::firstOrFail();
        $raw = $photo->rawStoragePath();
        $this->assertStringStartsWith('private:private-images/rental-inspections/' . $this->agency->id . '/' . $inspection->id . '/', $raw);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing is written to the public disk');

        $stored = Storage::disk('local')->get(substr($raw, strlen('private:')));
        $this->assertStringNotContainsString("Exif\x00\x00", $stored, 'embedded metadata (EXIF / GPS) is stripped');
        $this->assertNotFalse(@imagecreatefromstring($stored), 'and it is still a valid image');

        $url = $response->json('photos.0.storage_path');
        $this->assertStringContainsString('/private-image/', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        // the link dies the moment the photo's inspection is archived (the shared service's guard rows), long before it would expire
        $inspection->delete();
        $this->get($url)->assertNotFound();
        $inspection->restore();
        $this->get($url)->assertOk();

        // tampered
        $this->get(preg_replace('/signature=[a-f0-9]{6}/', 'signature=000000', $url))->assertNotFound();

        // expired (the agency default is 480 minutes)
        Carbon::setTestNow(now()->addMinutes(481));
        $this->get($url)->assertNotFound();
        Carbon::setTestNow();
    }

    public function test_the_signed_link_window_is_the_agency_setting(): void
    {
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['photo_link_minutes' => 10]);
        \Illuminate\Support\Facades\Cache::store('array')->flush();
        $inspection = $this->inspection();
        $this->postJson(route('corex.rental-inspections.photos.store', $inspection), ['photos' => [$this->jpeg()]])->assertCreated();
        $url = RentalInspectionPhoto::firstOrFail()->storage_path;

        Carbon::setTestNow(now()->addMinutes(9));
        $this->get($url)->assertOk();
        Carbon::setTestNow(now()->addMinutes(2));
        $this->get($url)->assertNotFound();
        Carbon::setTestNow();
    }

    public function test_a_legacy_public_photo_still_reads_as_before_and_the_privatise_command_moves_it(): void
    {
        $inspection = $this->inspection();
        Storage::disk('public')->put('properties/' . $this->property->id . '/legacy.jpg', file_get_contents($this->jpeg()->getRealPath()));
        $legacy = '/storage/properties/' . $this->property->id . '/legacy.jpg';
        $photo = RentalInspectionPhoto::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'storage_path' => $legacy, 'uploaded_by_user_id' => $this->agent->id,
        ]);
        $this->assertSame($legacy, $photo->fresh()->storage_path, 'a legacy row reads exactly as it did');

        $this->artisan('rental-inspections:privatise-photos', ['--remove-public' => true])->assertSuccessful();

        $moved = $photo->fresh();
        $this->assertStringStartsWith('private:', $moved->rawStoragePath());
        $this->assertStringContainsString('/private-image/', $moved->storage_path);
        $this->assertFalse(Storage::disk('public')->exists('properties/' . $this->property->id . '/legacy.jpg'), 'the public original is gone once moved and unused');
    }

    public function test_the_privatise_command_keeps_a_public_file_another_record_still_uses(): void
    {
        $inspection = $this->inspection();
        Storage::disk('public')->put('properties/' . $this->property->id . '/shared.jpg', file_get_contents($this->jpeg()->getRealPath()));
        $legacy = '/storage/properties/' . $this->property->id . '/shared.jpg';
        RentalInspectionPhoto::create(['agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'storage_path' => $legacy, 'uploaded_by_user_id' => $this->agent->id]);
        // a fault report raised from the photo points at the same public file
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('rental_fault_report_photos')->insert([
            'agency_id' => $this->agency->id, 'rental_fault_report_id' => 0, 'storage_path' => $legacy,
            'client_idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'created_at' => now(),
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->artisan('rental-inspections:privatise-photos', ['--remove-public' => true])->assertSuccessful();

        $this->assertTrue(Storage::disk('public')->exists('properties/' . $this->property->id . '/shared.jpg'));
    }

    // ── B23 ─────────────────────────────────────────────────────────────

    private function unlockedFor(RentalInspection $inspection): void
    {
        app(AgentSignatureService::class)->save($this->agent, self::PNG, self::PNG, '4321');
        $this->withSession(['sig_unlock.' . $this->agent->id . '.' . sha1('rental-inspection:' . $inspection->id) => now()->timestamp]);
    }

    public function test_the_agent_pin_signature_is_refused_when_locked_or_when_the_image_is_not_the_saved_one(): void
    {
        $inspection = $this->inspection();
        app(AgentSignatureService::class)->save($this->agent, self::PNG, self::PNG, '4321');
        $body = ['party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG];

        // locked: the PIN was never entered
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), $body)->assertStatus(403);

        // unlocked, but a different picture
        $this->unlockedFor($inspection);
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), ['signature_image' => self::PNG . 'AAAA'] + $body)->assertStatus(422);
        $this->assertSame(0, RentalInspectionSignature::count());
    }

    public function test_the_agent_pin_signature_is_accepted_when_unlocked_and_the_saved_image(): void
    {
        $inspection = $this->inspection();
        $this->unlockedFor($inspection);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), ['party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG])->assertCreated();
        $this->assertSame(1, RentalInspectionSignature::where('party_role', 'agent')->count());
    }

    public function test_only_the_inspections_own_agent_can_use_the_saved_signature_route(): void
    {
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $inspection = $this->inspection(RentalInspection::TYPE_AD_HOC, ['inspector_user_id' => $other->id]);
        $this->unlockedFor($inspection);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), ['party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG])->assertStatus(403);
    }

    public function test_a_hand_drawn_sign_now_signature_is_not_held_to_the_pin_rule(): void
    {
        $inspection = $this->inspection();

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), ['party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG, 'signing_method' => 'drawn'])->assertCreated();
    }

    // ── B25 ─────────────────────────────────────────────────────────────

    public function test_the_tab_payload_carries_only_the_observations_of_inspections_it_shows(): void
    {
        $item = $this->item();
        $shown = $this->inspection();
        $cancelled = $this->inspection(RentalInspection::TYPE_INTERIM);
        foreach ([$shown, $cancelled] as $insp) {
            RentalInspectionObservation::create([
                'agency_id' => $this->agency->id, 'rental_inspection_id' => $insp->id, 'rental_inspection_item_id' => $item->id,
                'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'ad_hoc',
            ]);
        }

        $cancelled->cancel($this->agent, 'Not wanted');
        $payload = RentalInspection::tabPayloadFor($this->property);
        $observations = collect($payload['items'])->flatMap(fn ($i) => $i->observations);

        $this->assertSame([$shown->id], $observations->pluck('rental_inspection_id')->unique()->values()->all());
    }

    // ── B26 / B27 / B28 ─────────────────────────────────────────────────

    public function test_a_gallery_image_removed_from_a_rental_gallery_is_archived_not_deleted(): void
    {
        Storage::disk('public')->put('properties/1/a.jpg', 'x');
        $structure = $this->property->rentalImagesStructure();
        $structure['in_inspection']['images'] = ['/storage/properties/1/a.jpg', '/storage/properties/1/b.jpg'];

        $this->assertTrue(Property::archiveRentalImage($structure, 'in_inspection', '/storage/properties/1/a.jpg', $this->agent->id));

        $this->assertSame(['/storage/properties/1/b.jpg'], $structure['in_inspection']['images']);
        $this->assertSame('/storage/properties/1/a.jpg', $structure['archived'][0]['url']);
        $this->assertTrue(Storage::disk('public')->exists('properties/1/a.jpg'), 'the file stays');
        $this->assertFalse(Property::archiveRentalImage($structure, 'in_inspection', '/nope.jpg', null));
    }

    public function test_the_web_delete_rental_image_endpoint_archives_and_keeps_the_file(): void
    {
        Storage::disk('public')->put('properties/' . $this->property->id . '/a.jpg', 'x');
        $this->property->forceFill(['rental_images_json' => ['in_inspection' => ['date' => null, 'images' => ['/storage/properties/' . $this->property->id . '/a.jpg']], 'out_inspection' => ['date' => null, 'images' => []], 'custom' => []]])->save();

        $this->postJson(route('corex.properties.rental-images.delete', $this->property), ['section' => 'in_inspection', 'index' => 0])->assertOk();

        $this->assertSame([], $this->property->fresh()->rentalImagesStructure()['in_inspection']['images']);
        $this->assertCount(1, $this->property->fresh()->rentalImagesStructure()['archived']);
        $this->assertTrue(Storage::disk('public')->exists('properties/' . $this->property->id . '/a.jpg'));
    }

    public function test_an_unmarked_inventory_room_is_archived_and_marking_again_restores_it(): void
    {
        $inventory = \App\Models\RentalInventory::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'status' => \App\Models\RentalInventory::STATUS_DRAFT, 'created_by_user_id' => $this->agent->id,
        ]);
        $room = \App\Models\PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Bedroom', 'label' => 'Bedroom 1',
            'source' => 'manual', 'sort_order' => 0, 'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inventories.rooms.mark-empty', [$inventory, $room]))->assertCreated();
        $this->deleteJson(route('corex.rental-inventories.rooms.unmark-empty', [$inventory, $room]))->assertOk();

        $this->assertSame(0, \App\Models\RentalInventoryRoomMark::count());
        $this->assertSame(1, \App\Models\RentalInventoryRoomMark::withTrashed()->count(), 'archived, not deleted');

        $this->postJson(route('corex.rental-inventories.rooms.mark-empty', [$inventory, $room]))->assertCreated();
        $this->assertSame(1, \App\Models\RentalInventoryRoomMark::count());
        $this->assertSame(1, \App\Models\RentalInventoryRoomMark::withTrashed()->count(), 'the same row came back');
    }

    // ── B15 / B8 ────────────────────────────────────────────────────────

    public function test_a_replayed_observation_returns_the_stored_row_not_a_500(): void
    {
        $inspection = $this->inspection();
        $item = $this->item();
        $key = (string) \Illuminate\Support\Str::uuid();
        $body = ['rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'ad_hoc', 'client_idempotency_key' => $key];

        $first = $this->postJson(route('corex.rental-inspections.observations.store', $inspection), $body)->assertOk();
        $second = $this->postJson(route('corex.rental-inspections.observations.store', $inspection), $body)->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, RentalInspectionObservation::where('client_idempotency_key', $key)->count());
    }

    public function test_a_retry_key_from_another_inspection_is_a_422_not_a_500(): void
    {
        $item = $this->item();
        $a = $this->inspection(RentalInspection::TYPE_AD_HOC);
        $key = (string) \Illuminate\Support\Str::uuid();
        $body = ['rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'ad_hoc', 'client_idempotency_key' => $key];
        $this->postJson(route('corex.rental-inspections.observations.store', $a), $body)->assertOk();
        $a->cancel($this->agent, 'x');
        $b = $this->inspection(RentalInspection::TYPE_AD_HOC);

        $this->postJson(route('corex.rental-inspections.observations.store', $b), $body)->assertStatus(422);
    }

    public function test_an_observation_carries_the_apps_captured_time(): void
    {
        $inspection = $this->inspection();
        $item = $this->item();
        $captured = now()->subHours(3)->startOfSecond();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'ad_hoc', 'captured_at' => $captured->toIso8601String(),
        ])->assertOk();

        $observation = RentalInspectionObservation::firstOrFail();
        $this->assertTrue($observation->created_at->equalTo($captured), 'created_at is the captured time');
        $this->assertNotNull($observation->received_at, 'and the arrival time is kept');
    }

    public function test_a_future_captured_time_is_ignored_not_trusted(): void
    {
        $inspection = $this->inspection();
        $item = $this->item();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'ad_hoc', 'captured_at' => now()->addDays(2)->toIso8601String(),
        ])->assertOk();

        $this->assertTrue(RentalInspectionObservation::firstOrFail()->created_at->lte(now()->addMinute()));
        $this->assertNull(RentalInspectionObservation::firstOrFail()->received_at);
    }

    // ── B9 ──────────────────────────────────────────────────────────────

    public function test_late_offline_evidence_on_a_completed_inspection_is_stored_and_flagged(): void
    {
        $inspection = $this->inspection();
        $item = $this->item();
        $captured = now()->subMinutes(30);
        $inspection->markCompleted();

        $response = $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'damaged', 'notes' => 'Crack', 'source' => 'ad_hoc', 'captured_at' => $captured->toIso8601String(),
        ])->assertCreated()->assertJsonPath('added_after_signing', true);

        $this->assertStringContainsString('already completed', (string) $response->json('message'));
        $row = RentalInspectionObservation::firstOrFail();
        $this->assertNotNull($row->added_after_signing_at);
        $this->assertSame(0, \App\Models\RentalInspectionDiscrepancy::count(), 'no discrepancy is raised against a locked report');
        $this->assertNotContains($row->id, $inspection->fresh()->load('observations')->bodyObservations()->pluck('id')->all(), 'never part of the report body');
        $this->assertSame(0, RentalInspectionObservation::recorded()->count(), 'and never the item\'s current condition');

        // it is listed in the clearly-marked block
        $entries = app(\App\Services\Rentals\RentalInspectionAddedAfterSentService::class)->entriesFor($inspection->fresh());
        $this->assertSame('late_observation', $entries->first()['kind']);
    }

    public function test_a_live_write_with_no_captured_time_to_a_completed_inspection_is_still_refused(): void
    {
        $inspection = $this->inspection();
        $item = $this->item();
        $inspection->markCompleted();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'ad_hoc',
        ])->assertStatus(409);
        $this->assertSame(0, RentalInspectionObservation::count());
    }

    public function test_evidence_captured_after_the_report_was_completed_is_refused(): void
    {
        $inspection = $this->inspection();
        $item = $this->item();
        $inspection->markCompleted();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'ad_hoc', 'captured_at' => now()->addMinutes(2)->toIso8601String(),
        ])->assertStatus(409);
    }

    public function test_late_evidence_for_a_cancelled_inspection_is_still_refused(): void
    {
        $inspection = $this->inspection();
        $item = $this->item();
        $inspection->cancel($this->agent, 'Wrong property');

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'ad_hoc', 'captured_at' => now()->subHour()->toIso8601String(),
        ])->assertStatus(409);
    }

    public function test_late_offline_photos_are_stored_flagged_and_captioned(): void
    {
        $inspection = $this->inspection();
        $inspection->markCompleted();

        $response = $this->postJson(route('corex.rental-inspections.photos.store', $inspection), [
            'photos' => [$this->jpeg()], 'captured_at' => [now()->subHour()->toIso8601String()],
        ])->assertCreated()->assertJsonPath('added_after_signing', true);

        $photo = RentalInspectionPhoto::firstOrFail();
        $this->assertNotNull($photo->added_after_signing_at);
        $this->assertStringContainsString('added after signing', $photo->taken_caption);
        $this->assertSame('late_photo', app(\App\Services\Rentals\RentalInspectionAddedAfterSentService::class)->entriesFor($inspection->fresh())->first()['kind']);
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_an_upload_to_a_completed_inspection_with_no_captured_time_is_refused(): void
    {
        $inspection = $this->inspection();
        $inspection->markCompleted();

        $this->postJson(route('corex.rental-inspections.photos.store', $inspection), ['photos' => [$this->jpeg()]])->assertStatus(409);
        $this->assertSame(0, RentalInspectionPhoto::count());
    }

    // ── B11 ─────────────────────────────────────────────────────────────

    public function test_a_retired_item_takes_no_new_observation_or_photo(): void
    {
        $inspection = $this->inspection();
        $item = $this->item();
        $item->update(['is_retired' => true]);

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'ad_hoc',
        ])->assertStatus(422);
        $this->postJson(route('corex.rental-inspections.photos.store', $inspection), ['photos' => [$this->jpeg()], 'rental_inspection_item_id' => $item->id])->assertStatus(422);
        $this->assertSame(0, RentalInspectionObservation::count());
    }

    // ── B12 ─────────────────────────────────────────────────────────────

    public function test_attendance_is_asked_before_signing_not_only_at_complete(): void
    {
        $contact = \App\Models\Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Zulu' . uniqid(), 'email' => 't-' . uniqid() . '@example.co.za',
        ]);
        \App\Models\LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $contact->id]);
        $inspection = $this->inspection(RentalInspection::TYPE_IN);

        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $inspection))
            ->assertStatus(409)->assertJsonStructure(['missing_attendance']);
        $this->assertNotSame(RentalInspection::STATUS_AWAITING_SIGNATURE, $inspection->fresh()->status);
    }

    // ── B17 ─────────────────────────────────────────────────────────────

    public function test_a_retried_room_note_is_one_note(): void
    {
        $inspection = $this->inspection();
        $room = \App\Models\PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Bedroom', 'label' => 'Bedroom 1',
            'source' => 'manual', 'sort_order' => 0, 'created_by_user_id' => $this->agent->id,
        ]);
        $key = (string) \Illuminate\Support\Str::uuid();

        $this->postJson(route('corex.rental-inspections.rooms.notes.store', [$inspection, $room]), ['note' => 'Damp smell', 'client_idempotency_key' => $key])->assertCreated();
        $this->postJson(route('corex.rental-inspections.rooms.notes.store', [$inspection, $room]), ['note' => 'Damp smell', 'client_idempotency_key' => $key])->assertOk();

        $this->assertSame(1, RentalInspectionRoomNote::count());
    }

    public function test_pressing_room_not_applicable_twice_does_not_stack_a_second_set(): void
    {
        $inspection = $this->inspection();
        $room = \App\Models\PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Bedroom', 'label' => 'Bedroom 3',
            'source' => 'manual', 'sort_order' => 0, 'created_by_user_id' => $this->agent->id,
        ]);
        RentalInspectionItem::create(['agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room->id, 'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Ceiling', 'created_by_user_id' => $this->agent->id]);
        RentalInspectionItem::create(['agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room->id, 'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Floor', 'created_by_user_id' => $this->agent->id]);

        $this->postJson(route('corex.rental-inspections.rooms.mark-na', [$inspection, $room]))->assertOk();
        $this->postJson(route('corex.rental-inspections.rooms.mark-na', [$inspection, $room]))->assertOk();

        $this->assertSame(2, RentalInspectionObservation::where('condition', RentalInspectionObservation::CONDITION_NA)->count());
    }

    public function test_a_retried_signature_returns_the_stored_row(): void
    {
        $inspection = $this->inspection();
        $key = (string) \Illuminate\Support\Str::uuid();
        $body = ['party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG, 'signing_method' => 'drawn', 'client_idempotency_key' => $key];

        $first = $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), $body)->assertCreated();
        $second = $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), $body)->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, RentalInspectionSignature::count());
    }

    // ── B16 ─────────────────────────────────────────────────────────────

    public function test_the_uploader_keeps_one_set_of_uuid_keys_per_batch_across_retries(): void
    {
        $js = file_get_contents(public_path('js/corex-photo-batch-uploader.js'));

        $this->assertStringContainsString('entry.keys = entry.files.map(() => window.cpuNewUuid())', $js, 'keys are made once and kept on the batch entry');
        $this->assertStringNotContainsString('Date.now() +', $js, 'no non-UUID fallback key');
        $this->assertStringContainsString('getRandomValues', $js, 'a real v4 UUID even without crypto.randomUUID');
    }

    // ── B33 ─────────────────────────────────────────────────────────────

    public function test_scheduling_lands_on_the_inspections_tab_not_the_read_only_page(): void
    {
        $this->post(route('corex.rental-inspections.store'), [
            'property_id' => $this->property->id, 'type' => 'interim', 'intent' => 'schedule', 'scheduled_for' => now()->addDays(10)->toDateString(),
        ])->assertRedirect(route('corex.properties.show', ['property' => $this->property->id, 'tab' => 'inspections']));
    }

    // ── B7 ──────────────────────────────────────────────────────────────

    public function test_a_let_property_is_eligible_for_rental_inspections_on_the_mobile_endpoints(): void
    {
        $this->assertTrue($this->property->fresh()->isLetOrRented());
        $this->assertTrue($this->property->fresh()->rentalInspectionsAvailable());
        $this->property->forceFill(['status' => 'draft'])->save();
        $this->assertFalse($this->property->fresh()->rentalInspectionsAvailable());
    }
}
