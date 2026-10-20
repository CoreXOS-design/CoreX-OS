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
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionSignature;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryMoveOutSignOff;
use App\Models\RentalInventoryPhoto;
use App\Models\User;
use App\Services\Rentals\RentalRoomPhotoSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Rental inspections and inventories audit of 20 Oct 2026 - the fixes that need no scan: H1 (private-image throttle), M2 (drawn agent
 * signature), M3 (late evidence out of the body and the fingerprint), M4 (shared photos stay on a locked inventory), M6 (deductions review),
 * L4 (agent move-out sign-off), L7 (archived reference revokes links). Scan fixes are in RentalInspectionScanReviewNotesPhotosTest.
 */
final class RentalInspectionAuditFixes1020Test extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private PropertyRoom $lounge;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('local');
        Storage::fake('public');

        $this->agency = Agency::create(['name' => 'Audit1020 Agency', 'slug' => 'a1020-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['empty_checklist_blocks_signing' => false, 'routine_follows_full_checks' => false, 'record_deduction_amounts' => false]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Audit1020 Property', 'status' => 'let_out', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->lounge = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'lounge', 'label' => 'Lounge',
            'source' => 'manual', 'sort_order' => 1, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function item(string $label = 'Walls'): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $this->lounge->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function inspection(string $type = RentalInspection::TYPE_AD_HOC, array $attrs = []): RentalInspection
    {
        return RentalInspection::create(array_merge([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id, 'type' => $type, 'created_by_user_id' => $this->agent->id,
        ], $attrs));
    }

    private function jpeg(): UploadedFile
    {
        $im = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        $path = tempnam(sys_get_temp_dir(), 'ri') . '.jpg';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'p.jpg', 'image/jpeg', null, true);
    }

    /** A real picture on the private disk and the inspection photo row that points at it. */
    private function inspectionPhoto(RentalInspection $inspection, array $extra = []): RentalInspectionPhoto
    {
        $rel = 'private-images/rental-inspections/' . $this->agency->id . '/' . $inspection->id . '/' . uniqid() . '.jpg';
        $im = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($im);
        Storage::disk('local')->put($rel, (string) ob_get_clean());

        return RentalInspectionPhoto::create(array_merge([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'property_room_id' => $this->lounge->id,
            'storage_path' => 'private:' . $rel, 'uploaded_by_user_id' => $this->agent->id,
        ], $extra));
    }

    private function pathOf(string $signedUrl): string
    {
        return (string) parse_url($signedUrl, PHP_URL_PATH) . '?' . (string) parse_url($signedUrl, PHP_URL_QUERY);
    }

    // ── H1 - the private-image route serves galleries ───────────────────────────────

    public function test_a_gallery_of_hundreds_of_pictures_is_not_throttled(): void
    {
        RateLimiter::clear('x');
        // the old flat limit was 120 per minute per address: the 121st picture of a move-out came back 429
        for ($i = 0; $i < 300; $i++) {
            $status = $this->get('/private-image/AAAA' . $i)->getStatusCode();
            $this->assertNotSame(429, $status, "picture {$i} of the screen was throttled");
        }
    }

    public function test_one_link_asked_for_again_and_again_is_throttled_per_link(): void
    {
        config(['private-images.per_token_per_minute' => 5]);
        $statuses = [];
        for ($i = 0; $i < 7; $i++) {
            $statuses[] = $this->get('/private-image/SameLinkSameLink')->getStatusCode();
        }

        $this->assertNotContains(429, array_slice($statuses, 0, 5));
        $this->assertSame(429, $statuses[5]);
    }

    public function test_the_per_address_ceiling_still_stops_one_address_hammering_with_made_up_links(): void
    {
        config(['private-images.per_address_per_minute' => 4, 'private-images.per_browser_per_minute' => 100]);
        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->get('/private-image/MadeUp' . $i)->getStatusCode();
        }

        $this->assertNotContains(429, array_slice($statuses, 0, 4));
        $this->assertSame(429, $statuses[4]);
    }

    public function test_a_signed_in_browser_has_its_own_bucket_apart_from_the_office_address(): void
    {
        config(['private-images.per_address_per_minute' => 1000, 'private-images.per_browser_per_minute' => 3]);
        $cookie = (string) config('session.cookie');
        $first = [];
        for ($i = 0; $i < 4; $i++) {
            $first[] = $this->call('GET', '/private-image/Browser' . $i, [], [$cookie => 'session-cookie-one'])->getStatusCode();
        }
        $this->assertSame(429, $first[3], 'one browser is limited on its own');

        // a different browser behind the same address is not
        $this->assertNotSame(429, $this->call('GET', '/private-image/Other1', [], [$cookie => 'session-cookie-two'])->getStatusCode());
    }

    // ── M7 - the handwriting AI switch on the settings page ─────────────────────────

    public function test_the_handwriting_ai_switch_is_on_the_settings_page_and_saves_without_being_reset_by_a_step_that_omits_it(): void
    {
        $this->get(route('corex.settings.rental-inspections.edit'))->assertOk()->assertSee('Scanned form: read handwritten notes with AI')->assertSee('name="note_read_enabled"', false);
        $this->assertTrue(\App\Models\RentalInspectionSetting::noteReadEnabledFor($this->agency->id));

        $base = ['fault_report_window_days' => 14, 'out_inspection_signing_window_days' => 7];
        $this->post(route('corex.settings.rental-inspections.update'), $base + ['note_read_enabled' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(\App\Models\RentalInspectionSetting::noteReadEnabledFor($this->agency->id));

        $this->post(route('corex.settings.rental-inspections.update'), $base)->assertSessionHasNoErrors();
        $this->assertFalse(\App\Models\RentalInspectionSetting::noteReadEnabledFor($this->agency->id), 'a post that never rendered the toggle must not turn it back on');

        $keys = [];
        $copy = config('agency-onboarding-copy');
        array_walk_recursive($copy, function ($value, $key) use (&$keys) {
            if ($key === 'key') {
                $keys[] = $value;
            }
        });
        $this->assertContains('note_read_enabled', $keys, 'it is in the Setup Wizard copy');
    }

    // ── M2 - the drawn agent signature is the inspection's own agent only ───────────

    public function test_a_colleague_cannot_sign_as_the_agent_with_a_drawn_signature(): void
    {
        $colleague = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $inspection = $this->inspection(RentalInspection::TYPE_AD_HOC, ['inspector_user_id' => $this->agent->id]);
        $this->actingAs($colleague);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG, 'signing_method' => 'drawn',
        ])->assertStatus(403);
        $this->assertSame(0, RentalInspectionSignature::where('party_role', 'agent')->count());
    }

    public function test_the_inspections_own_agent_can_still_sign_with_a_drawn_signature(): void
    {
        $inspection = $this->inspection(RentalInspection::TYPE_AD_HOC, ['inspector_user_id' => $this->agent->id]);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG, 'signing_method' => 'drawn',
        ])->assertCreated();
        $this->assertSame(1, RentalInspectionSignature::where('party_role', 'agent')->count());
    }

    // ── M3 - late offline evidence stays out of the signed body and the fingerprint ──

    public function test_a_late_item_photo_is_not_in_the_report_body_and_does_not_move_the_fingerprint(): void
    {
        $inspection = $this->inspection();
        $item = $this->item();
        $observation = RentalInspectionObservation::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'ad_hoc',
        ]);
        $bodyPhoto = $this->inspectionPhoto($inspection, ['rental_inspection_observation_id' => $observation->id]);
        $inspection->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $before = $inspection->fresh()->reportFingerprint();

        $this->postJson(route('corex.rental-inspections.photos.store', $inspection), [
            'photos' => [$this->jpeg()], 'rental_inspection_item_id' => $item->id, 'captured_at' => [now()->subHour()->toIso8601String()],
        ])->assertCreated()->assertJsonPath('added_after_signing', true);
        $late = RentalInspectionPhoto::whereNotNull('added_after_signing_at')->firstOrFail();

        $this->assertSame($before, $inspection->fresh()->reportFingerprint(), 'a late photo never changes the signed report\'s fingerprint');

        $data = app(\App\Http\Controllers\RentalInspectionPublicController::class)->reportData($inspection->fresh(), fn () => '');
        $bodyPhotoIds = collect($data['rows'])->flatten(1)->flatMap(fn ($row) => $row->photos->pluck('id'))->all();
        $this->assertSame([$bodyPhoto->id], $bodyPhotoIds, 'only the photo taken before signing is in the body');
        $this->assertContains($late->id, collect($data['addedAfterSent'])->flatMap(fn ($e) => collect($e['photos'])->pluck('id'))->all(), 'it is in the marked "added after signing" block');
    }

    public function test_a_late_room_photo_is_not_in_the_body_either(): void
    {
        $inspection = $this->inspection();
        $inspection->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $before = $inspection->fresh()->reportFingerprint();

        $this->postJson(route('corex.rental-inspections.photos.store', $inspection), [
            'photos' => [$this->jpeg()], 'property_room_id' => $this->lounge->id, 'captured_at' => [now()->subHour()->toIso8601String()],
        ])->assertCreated()->assertJsonPath('added_after_signing', true);

        $this->assertSame($before, $inspection->fresh()->reportFingerprint());
        $data = app(\App\Http\Controllers\RentalInspectionPublicController::class)->reportData($inspection->fresh(), fn () => '');
        $this->assertCount(0, $data['roomPhotos'], 'a late room photo is not a body room photo');
        $this->assertSame(1, collect($data['addedAfterSent'])->where('kind', 'late_photo')->count());
    }

    // ── M4 / L7 - shared inventory photos ───────────────────────────────────────────

    /** @return array{0: RentalInventory, 1: RentalInspectionPhoto, 2: RentalInventoryPhoto} */
    private function inventoryWithSharedPhoto(string $inventoryStatus): array
    {
        $in = $this->inspection(RentalInspection::TYPE_IN, ['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()]);
        $source = $this->inspectionPhoto($in);
        $inventory = RentalInventory::start($this->property, $this->lease, $this->agent);
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id, 'property_room_id' => $this->lounge->id,
            'room_label' => 'Lounge', 'quantity' => 1, 'description' => 'TV', 'created_by_user_id' => $this->agent->id,
        ]);
        $reference = app(RentalRoomPhotoSet::class)->share($inventory, $source, $this->agent);
        if ($inventoryStatus !== RentalInventory::STATUS_DRAFT) {
            $inventory->forceFill(['status' => $inventoryStatus])->save();
        }

        return [$inventory->fresh(), $source, $reference];
    }

    public function test_archiving_the_inspection_photo_removes_it_from_a_draft_inventory(): void
    {
        [$inventory, $source] = $this->inventoryWithSharedPhoto(RentalInventory::STATUS_DRAFT);
        $this->assertCount(1, $inventory->photos()->get());

        $source->archive($this->agent);

        $this->assertCount(0, $inventory->fresh()->photos()->get(), 'a draft inventory has not locked its evidence yet');
    }

    public function test_archiving_the_inspection_photo_does_not_remove_it_from_a_completed_inventory(): void
    {
        [$inventory, $source, $reference] = $this->inventoryWithSharedPhoto(RentalInventory::STATUS_COMPLETED);

        $source->archive($this->agent);

        $kept = $inventory->fresh()->photos()->get();
        $this->assertCount(1, $kept, 'the signed inventory keeps its move-in photo');
        $address = $kept->first()->storage_path;
        $this->assertStringContainsString('/private-image/', (string) $address);
        $this->get($this->pathOf($address))->assertOk();   // and the picture still opens

        // an inventory awaiting signatures is locked the same way
        $inventory->forceFill(['status' => RentalInventory::STATUS_AWAITING_SIGNATURE])->save();
        $this->assertCount(1, $inventory->fresh()->photos()->get());
    }

    public function test_archiving_the_whole_inspection_does_not_remove_it_from_a_completed_inventory(): void
    {
        [$inventory, $source] = $this->inventoryWithSharedPhoto(RentalInventory::STATUS_COMPLETED);

        RentalInspection::withoutGlobalScopes()->whereKey($source->rental_inspection_id)->update(['deleted_at' => now()]);

        $this->assertCount(1, $inventory->fresh()->photos()->get());
        $this->get($this->pathOf($inventory->fresh()->photos()->first()->storage_path))->assertOk();
    }

    public function test_an_archived_reference_stops_its_links_working(): void
    {
        [$inventory, $source, $reference] = $this->inventoryWithSharedPhoto(RentalInventory::STATUS_DRAFT);
        $link = $this->pathOf($inventory->photos()->first()->storage_path);
        $this->get($link)->assertOk();

        $reference->archive($this->agent);

        $this->get($link)->assertNotFound();
        $this->assertNull(RentalInspectionPhoto::find($source->id)?->deleted_at, 'the inspection photo itself is untouched');
    }

    public function test_an_archived_reference_on_a_completed_inventory_also_stops_its_links(): void
    {
        [$inventory, , $reference] = $this->inventoryWithSharedPhoto(RentalInventory::STATUS_COMPLETED);
        $link = $this->pathOf($inventory->photos()->first()->storage_path);
        $this->get($link)->assertOk();

        $reference->archive($this->agent);

        $this->get($link)->assertNotFound();
    }

    // ── M6 - the deductions review is cleared by a later change ─────────────────────

    public function test_classifying_or_repricing_a_charge_after_the_review_clears_the_review(): void
    {
        $out = $this->inspection(RentalInspection::TYPE_OUT, ['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()]);
        $item = $this->item();
        RentalInspectionItemFinding::record($out, $item, RentalInspectionItemFinding::DISPOSITION_CHARGE_TENANT, 'Cracked tile', $this->agent);
        app(\App\Services\Rentals\RentalDepositDeductionsService::class)->markReviewed($out->fresh(), $this->agent);
        $this->assertNotNull($out->fresh()->deductions_reviewed_at);

        // changed after review: classified again -> a review that no longer covers what is on record
        RentalInspectionItemFinding::record($out->fresh(), $item, RentalInspectionItemFinding::DISPOSITION_CHARGE_TENANT, 'Cracked tile, two of them', $this->agent);

        $fresh = $out->fresh();
        $this->assertNull($fresh->deductions_reviewed_at);
        $this->assertNull($fresh->deductions_reviewed_by_user_id);

        // reviewed again, then a NEW item is classified
        app(\App\Services\Rentals\RentalDepositDeductionsService::class)->markReviewed($fresh, $this->agent);
        $this->assertNotNull($out->fresh()->deductions_reviewed_at);
        RentalInspectionItemFinding::record($out->fresh(), $this->item('Window'), RentalInspectionItemFinding::DISPOSITION_CHARGE_TENANT, 'Broken pane', $this->agent);
        $this->assertNull($out->fresh()->deductions_reviewed_at);
    }

    public function test_an_unreviewed_inspection_stays_unreviewed_when_a_finding_is_recorded(): void
    {
        $out = $this->inspection(RentalInspection::TYPE_OUT, ['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()]);
        RentalInspectionItemFinding::record($out, $this->item(), RentalInspectionItemFinding::DISPOSITION_WEAR_AND_TEAR, 'Faded paint', $this->agent);

        $this->assertNull($out->fresh()->deductions_reviewed_at);
    }

    // ── L4 - the agent's move-out sign-off is the logged-in user ────────────────────

    public function test_the_agents_move_out_sign_off_carries_the_logged_in_users_name_whatever_was_typed(): void
    {
        $inventory = RentalInventory::start($this->property, $this->lease, $this->agent);
        $inventory->forceFill(['status' => RentalInventory::STATUS_COMPLETED, 'move_out_completed_at' => now(), 'move_out_completed_by_user_id' => $this->agent->id])->save();

        $row = $inventory->fresh()->recordMoveOutSignOff(RentalInventoryMoveOutSignOff::PARTY_AGENT, null, RentalInventoryMoveOutSignOff::DISPOSITION_SIGNED, 'Somebody Else Entirely', null, $this->agent, '10.0.0.1', 'test');

        $this->assertSame($this->agent->name, $row->signed_name);
        $this->assertSame($this->agent->id, (int) $row->recorded_by_user_id);
    }
}
