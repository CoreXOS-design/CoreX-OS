<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Images\PortalImageService;
use App\Services\Images\PrivateImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Security audit D14 - tenant / owner pictures are never at a permanent public address. They are streamed through
 * `GET /private-image/{token}`: a signed, expiring, encrypted capability that dies the moment the picture's fault / work order is
 * archived. New portal uploads live on the private disk (`private:` refs); older public files keep working but are only ever handed
 * to the portal as signed URLs; `rentals:privatise-fault-photos` moves the older ones.
 */
final class PrivateImageTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private RentalFaultType $faultType;
    private RentalFaultReport $fault;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Private Pics Rentals', 'slug' => 'pp-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '5 Beach Road', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000, 'start_date' => now()->subMonth(),
            'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Test', 'email' => 'tina+' . uniqid() . '@example.test',
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Geyser', 'category' => 'Plumbing', 'urgency' => 'urgent',
            'first_aid_steps' => 'Switch it off.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
        $this->fault = $this->makeFault();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function makeFault(): RentalFaultReport
    {
        return RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'title' => 'Tap leaks', 'description' => 'Under the sink.',
            'created_by_user_id' => $this->agent->id, 'reported_at' => now(), 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
        ]);
    }

    private function workOrder(): RentalWorkOrder
    {
        return RentalWorkOrder::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'title' => 'Tap leaks', 'description' => 'Under the sink.',
            'assignment_type' => 'outside_supplier', 'status' => 'reported', 'owner_approval_status' => 'not_required',
            'reported_by_type' => 'agent_noticed', 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function jpegBytes(int $w = 900, int $h = 600): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
        ob_start();
        imagejpeg($im, null, 85);

        return (string) ob_get_clean();
    }

    /** A real picture on the PUBLIC disk with a fault photo row pointing at it by its old permanent address. */
    private function legacyPhoto(?RentalFaultReport $fault = null, string $name = 'old.jpg', ?int $uploadedBy = null): RentalFaultReportPhoto
    {
        Storage::disk('public')->put("properties/{$this->property->id}/{$name}", $this->jpegBytes());

        return RentalFaultReportPhoto::create([
            'agency_id' => $this->agency->id, 'rental_fault_report_id' => ($fault ?? $this->fault)->id,
            'storage_path' => "/storage/properties/{$this->property->id}/{$name}", 'uploaded_by_user_id' => $uploadedBy, 'file_size_bytes' => 1000,
        ]);
    }

    /** A new-style upload: on the PRIVATE disk, stored as a `private:` ref. */
    private function privatePhoto(?RentalFaultReport $fault = null): RentalFaultReportPhoto
    {
        $ref = app(PrivateImageService::class)->storeUpload(UploadedFile::fake()->image('new.jpg', 800, 600), $this->property->id);

        return RentalFaultReportPhoto::create([
            'agency_id' => $this->agency->id, 'rental_fault_report_id' => ($fault ?? $this->fault)->id, 'storage_path' => $ref, 'file_size_bytes' => 1000,
        ]);
    }

    private function signed(RentalFaultReportPhoto $photo, string $key = 'url'): string
    {
        return app(PortalImageService::class)->urls($photo->storage_path, $photo->imageGuards(), $this->agency->id)[$key];
    }

    private function login(Contact $c): void
    {
        $cu = ClientUser::create(['email' => $c->email, 'current_agency_id' => $c->agency_id]);
        $c->forceFill(['client_user_id' => $cu->id])->saveQuietly();
        Sanctum::actingAs($cu, ['client']);
    }

    // ── the door: signed, expiring, streams ──────────────────────────────────

    public function test_a_signed_url_streams_a_legacy_public_picture_with_safe_headers(): void
    {
        $photo = $this->legacyPhoto();

        $url = $this->signed($photo);
        $res = $this->get($url);

        $res->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', (string) $res->headers->get('Content-Disposition'));
        $cache = (string) $res->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringNotContainsString('public', $cache);
        $this->assertMatchesRegularExpression('/max-age=(\d+)/', $cache);
        preg_match('/max-age=(\d+)/', $cache, $m);
        $this->assertLessThanOrEqual(PrivateImageService::MAX_BROWSER_CACHE_SECONDS, (int) $m[1], 'a browser never keeps it longer than the cap');
        $this->assertStringContainsString('/private-image/', $url);
    }

    public function test_a_private_upload_lives_only_on_the_private_disk_and_streams_through_a_signed_url(): void
    {
        $photo = $this->privatePhoto();

        $this->assertStringStartsWith('private:private-images/properties/' . $this->property->id . '/', $photo->storage_path);
        $rel = app(PrivateImageService::class)->privateRel($photo->storage_path);
        Storage::disk('local')->assertExists($rel);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing was written where the web server serves files from');

        $this->get($this->signed($photo))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get($this->signed($photo, 'thumb_url'))->assertOk();
        $this->assertSame($photo->absolutePath(), Storage::disk('local')->path($rel));
    }

    public function test_the_portal_never_gets_a_permanent_storage_address_for_a_picture_we_hold(): void
    {
        $legacy = $this->legacyPhoto();
        $private = $this->privatePhoto();

        foreach ([$legacy, $private] as $photo) {
            $urls = app(PortalImageService::class)->urls($photo->storage_path, $photo->imageGuards(), $this->agency->id);
            foreach ($urls as $u) {
                $this->assertStringContainsString('/private-image/', $u);
                $this->assertStringNotContainsString('/storage/', $u);
                $this->assertStringNotContainsString('old.jpg', $u);
            }
        }

        // not ours / not there: unchanged, as before
        foreach (['', null, 'https://example.com/pic.jpg', '/storage/properties/9/missing.jpg', '/storage/../.env.jpg'] as $in) {
            $this->assertSame($in, app(PortalImageService::class)->urls($in)['url']);
        }
    }

    public function test_an_expired_link_is_a_404(): void
    {
        $url = $this->signed($this->legacyPhoto());
        $this->get($url)->assertOk();

        $this->travel(RentalPortalSetting::portalPhotoLinkMinutesFor($this->agency->id) + 1)->minutes();

        $this->get($url)->assertNotFound();
    }

    public function test_a_tampered_or_forged_link_is_a_404(): void
    {
        $url = $this->signed($this->legacyPhoto());

        // 1. the token changed under a valid-looking signature
        $this->get(preg_replace('#/private-image/([^?]+)#', '/private-image/$1x', $url))->assertNotFound();
        // 2. the signature changed
        $this->get(preg_replace('#signature=[0-9a-f]+#', 'signature=' . str_repeat('0', 64), $url))->assertNotFound();
        // 3. no signature at all
        $this->get(strtok($url, '?'))->assertNotFound();
        // 4. a genuine signature over a garbage token
        $this->get(URL::temporarySignedRoute('private-image.show', now()->addMinutes(5), ['token' => 'AAAAAAAAAAAAAAAA']))->assertNotFound();
        // 5. a genuine signature over an encrypted token that points at a path walk
        $evil = rtrim(strtr(\Illuminate\Support\Facades\Crypt::encryptString(json_encode(['r' => '/storage/../../.env', 'v' => 'full', 'g' => [], 'e' => time() + 300])), '+/', '-_'), '=');
        $this->get(URL::temporarySignedRoute('private-image.show', now()->addMinutes(5), ['token' => $evil]))->assertNotFound();
    }

    public function test_every_refusal_is_the_same_empty_404(): void
    {
        $photo = $this->legacyPhoto();
        $good = $this->signed($photo);
        $other = $this->legacyPhoto($this->makeFault(), 'two.jpg');
        $expired = $this->signed($other);   // its own fault stays live: only the clock will kill this one
        $this->fault->delete();   // revokes $good
        $bodies = [
            'revoked' => $this->get($good),
            'forged' => $this->get(URL::temporarySignedRoute('private-image.show', now()->addMinutes(5), ['token' => 'AAAA'])),
            'unsigned' => $this->get('/private-image/AAAA'),
        ];
        $this->get($expired)->assertOk();
        $this->travel(500)->minutes();
        $bodies['expired'] = $this->get($expired);

        foreach ($bodies as $why => $res) {
            $res->assertNotFound();
            $this->assertSame('', $res->getContent(), $why . ' reveals nothing');
        }
        $this->assertSame(1, count(array_unique(array_map(fn ($r) => $r->getContent() . '|' . $r->getStatusCode(), $bodies))), 'all four are indistinguishable');
    }

    // ── revocation ───────────────────────────────────────────────────────────

    public function test_archiving_the_fault_kills_an_unexpired_link_and_restoring_it_brings_the_picture_back(): void
    {
        $photo = $this->legacyPhoto();
        $url = $this->signed($photo);
        $this->get($url)->assertOk();

        $this->fault->delete();   // archive (soft delete)
        $this->get($url)->assertNotFound();

        $this->fault->restore();
        $this->get($url)->assertOk();
    }

    public function test_archiving_the_work_order_kills_its_photo_links(): void
    {
        $wo = $this->workOrder();
        Storage::disk('public')->put('properties/1/crew.jpg', $this->jpegBytes());
        $p = RentalWorkOrderPhoto::create(['agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'photo_type' => 'completed', 'storage_path' => '/storage/properties/1/crew.jpg']);
        $url = app(PortalImageService::class)->urls($p->storage_path, $p->imageGuards(), $this->agency->id)['thumb_url'];
        $this->get($url)->assertOk();

        $wo->delete();

        $this->get($url)->assertNotFound();
    }

    public function test_removing_the_photo_row_kills_its_link(): void
    {
        $photo = $this->legacyPhoto();
        $url = $this->signed($photo);
        $this->get($url)->assertOk();

        DB::table('rental_fault_report_photos')->where('id', $photo->id)->delete();

        $this->get($url)->assertNotFound();
    }

    public function test_a_missing_or_archived_guard_is_revoked_and_an_empty_list_never_is(): void
    {
        $svc = app(PrivateImageService::class);
        $this->assertFalse($svc->revoked([]));
        $this->assertFalse($svc->revoked([['rental_fault_reports', $this->fault->id]]));
        $this->assertTrue($svc->revoked([['rental_fault_reports', 999999]]));
        $this->assertTrue($svc->revoked([['rental_fault_reports', $this->fault->id], ['rental_fault_report_photos', 999999]]));
        $this->assertTrue($svc->revoked([['no such; table', 1]]), 'a malformed guard fails closed');
        $this->fault->delete();
        $this->assertTrue($svc->revoked([['rental_fault_reports', $this->fault->id]]));
    }

    // ── expiry follows the agency setting ────────────────────────────────────

    public function test_the_expiry_defaults_to_120_minutes_and_follows_the_agencys_own_setting(): void
    {
        $photo = $this->legacyPhoto();
        $expiryOf = function (string $url): int {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

            return (int) $q['expires'];
        };

        $this->assertSame(120, RentalPortalSetting::portalPhotoLinkMinutesFor($this->agency->id));
        $this->assertEqualsWithDelta(now()->addMinutes(120)->getTimestamp(), $expiryOf($this->signed($photo)), 5);

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['portal_photo_link_minutes' => 15]);

        $this->assertEqualsWithDelta(now()->addMinutes(15)->getTimestamp(), $expiryOf($this->signed($photo)), 5);
        $url = $this->signed($photo);
        $this->travel(14)->minutes();
        $this->get($url)->assertOk();
        $this->travel(2)->minutes();
        $this->get($url)->assertNotFound();
    }

    // ── portal upload -> private ref -> every reader still works ─────────────

    public function test_a_tenant_upload_through_the_portal_is_stored_private_and_comes_back_as_a_signed_url(): void
    {
        $this->login($this->tenant);

        $res = $this->post('/api/v1/client/rentals/properties/' . $this->property->id . '/fault-reports', [
            'rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem', 'title' => 'Geyser dripping', 'description' => 'Water on the floor',
            'photos' => [UploadedFile::fake()->image('a.jpg', 300, 300), UploadedFile::fake()->image('b.jpg', 300, 300)],
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $rows = DB::table('rental_fault_report_photos')->where('rental_fault_report_id', $res->json('fault_report.id'))->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertStringStartsWith('private:private-images/properties/' . $this->property->id . '/', $row->storage_path);
            Storage::disk('local')->assertExists(app(PrivateImageService::class)->privateRel($row->storage_path));
        }
        $this->assertSame([], Storage::disk('public')->allFiles(), 'no public file and no public thumbnail of a tenant picture');

        $detail = $this->getJson('/api/v1/client/rentals/fault-reports/' . $res->json('fault_report.id'))->assertOk();
        $photos = $detail->json('fault_report.reported_photos');
        $this->assertCount(2, $photos);
        foreach ($photos as $p) {
            $this->assertStringContainsString('/private-image/', $p['url']);
            $this->assertStringContainsString('/private-image/', $p['thumb_url']);
            // a picture request carries no login at all: drop the test's acting-as client so the web stack sees a guest, as a browser's <img> does
            \Illuminate\Support\Facades\Auth::forgetGuards();
            \Illuminate\Support\Facades\Auth::shouldUse('web');
            $this->get($p['thumb_url'])->assertOk();
        }
    }

    public function test_the_staff_fault_screen_shows_private_and_legacy_photos_through_signed_urls(): void
    {
        $private = $this->privatePhoto();
        $legacy = $this->legacyPhoto(null, 'old.jpg', $this->agent->id);

        $html = html_entity_decode($this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $this->fault))->assertOk()->getContent());

        $this->assertStringNotContainsString('private:private-images', $html, 'the raw reference never reaches the page');
        $this->assertSame(1, preg_match('#src="([^"]*/private-image/[^"]+)"#', $html, $m), 'the private photo is a signed URL');
        $this->get($m[1])->assertOk();
        $this->assertStringContainsString('data-open-photo="' . $private->id . '"', $html);
        $this->assertStringContainsString('data-open-photo="' . $legacy->id . '"', $html);
    }

    public function test_a_fault_photo_that_travelled_onto_a_work_order_still_resolves(): void
    {
        $photo = $this->privatePhoto();
        $wo = $this->workOrder();
        $copy = $wo->photos()->create(['agency_id' => $this->agency->id, 'photo_type' => RentalWorkOrder::PHOTO_REPORTED, 'storage_path' => $photo->storage_path]);

        $this->assertStringContainsString('/private-image/', (string) $copy->displayUrl());
        $this->get($copy->displayUrl())->assertOk();
        $this->assertSame($photo->absolutePath(), $copy->absolutePath());
        $this->assertSame('/storage/properties/1/x.jpg', (new RentalWorkOrderPhoto(['storage_path' => '/storage/properties/1/x.jpg']))->displayUrl(), 'a public one is shown as before');
    }

    public function test_the_pdf_photo_reader_resolves_a_private_ref(): void
    {
        $photo = $this->privatePhoto();
        $svc = app(\App\Services\Rentals\RentalDocumentPdfService::class);
        $m = new \ReflectionMethod($svc, 'photoDataUri');
        $m->setAccessible(true);

        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $m->invoke($svc, $photo->storage_path));
        $this->assertNull($m->invoke($svc, 'private:private-images/../../.env'), 'a path walk is refused');
        $this->assertNull($m->invoke($svc, 'private:somewhere/else.jpg'));
    }

    public function test_legacy_value_shapes_resolve_and_walks_do_not(): void
    {
        $svc = app(PrivateImageService::class);
        Storage::disk('public')->put('properties/3/a.jpg', $this->jpegBytes());

        foreach (['/storage/properties/3/a.jpg', 'https://corex.example/storage/properties/3/a.jpg', 'storage/properties/3/a.jpg'] as $stored) {
            $this->assertSame(Storage::disk('public')->path('properties/3/a.jpg'), $svc->absolutePath($stored), $stored);
            $this->assertTrue($svc->exists($stored));
            $this->assertFalse($svc->isPrivate($stored));
        }
        foreach (['/storage/../.env', 'private:../.env', 'private:private-images/../x.jpg', 'https://example.com/a.jpg', '', null] as $bad) {
            $this->assertNull($svc->absolutePath($bad), var_export($bad, true));
        }
    }

    // ── the move command ─────────────────────────────────────────────────────

    public function test_the_move_command_previews_by_default_and_with_dry_run_and_moves_nothing(): void
    {
        $photo = $this->legacyPhoto();

        Artisan::call('rentals:privatise-fault-photos');
        Artisan::call('rentals:privatise-fault-photos', ['--dry-run' => true, '--force' => true]);

        $this->assertSame('/storage/properties/' . $this->property->id . '/old.jpg', $photo->fresh()->storage_path);
        Storage::disk('public')->assertExists('properties/' . $this->property->id . '/old.jpg');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertStringContainsString('would move', Artisan::output());
    }

    public function test_the_move_command_with_force_moves_the_file_repoints_the_rows_and_is_idempotent(): void
    {
        $photo = $this->legacyPhoto();
        $rel = 'properties/' . $this->property->id . '/old.jpg';
        $wo = $this->workOrder();
        $copy = $wo->photos()->create(['agency_id' => $this->agency->id, 'photo_type' => RentalWorkOrder::PHOTO_REPORTED, 'storage_path' => $photo->storage_path]);
        $bytes = Storage::disk('public')->get($rel);
        Storage::disk('public')->put('properties/' . $this->property->id . '/thumbs/old.jpg', 'thumb');

        Artisan::call('rentals:privatise-fault-photos', ['--force' => true]);

        $ref = 'private:private-images/' . $rel;
        $this->assertSame($ref, $photo->fresh()->storage_path);
        $this->assertSame($ref, $copy->fresh()->storage_path, 'the work order copy of the same picture follows it');
        $this->assertSame($bytes, Storage::disk('local')->get('private-images/' . $rel), 'the bytes are identical');
        Storage::disk('public')->assertMissing($rel);
        Storage::disk('public')->assertMissing('properties/' . $this->property->id . '/thumbs/old.jpg');
        $this->assertSame(1, DB::table('rental_fault_report_photos')->count(), 'no record was removed');

        // it still opens, through a signed URL
        $this->get($this->signed($photo->fresh()))->assertOk();

        // run again: nothing left to do, nothing changes
        Artisan::call('rentals:privatise-fault-photos', ['--force' => true]);
        $this->assertSame($ref, $photo->fresh()->storage_path);
        $this->assertStringContainsString('0 moved', Artisan::output());
    }

    public function test_the_move_command_leaves_a_shared_file_alone_and_reports_it(): void
    {
        $photo = $this->legacyPhoto();
        $rel = 'properties/' . $this->property->id . '/old.jpg';
        // a second fault photo, uploaded by staff, pointing at the same file: not a pure portal picture
        $other = $this->makeFault();
        RentalFaultReportPhoto::create(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $other->id, 'storage_path' => $photo->storage_path, 'uploaded_by_user_id' => $this->agent->id]);

        Artisan::call('rentals:privatise-fault-photos', ['--force' => true]);

        $this->assertSame('/storage/' . $rel, $photo->fresh()->storage_path);
        Storage::disk('public')->assertExists($rel);
        $this->assertStringContainsString('skip (shared', Artisan::output());
    }

    public function test_the_move_command_skips_a_work_order_photo_that_is_not_the_reported_copy_and_respects_agency_and_limit(): void
    {
        $a = $this->legacyPhoto(null, 'a.jpg');
        $b = $this->legacyPhoto($this->makeFault(), 'b.jpg');
        $wo = $this->workOrder();
        $wo->photos()->create(['agency_id' => $this->agency->id, 'photo_type' => 'completed', 'storage_path' => $b->storage_path]);

        Artisan::call('rentals:privatise-fault-photos', ['--force' => true, '--agency' => $this->agency->id + 1000]);
        $this->assertStringStartsWith('/storage/', $a->fresh()->storage_path, 'another agency id selects nothing');

        Artisan::call('rentals:privatise-fault-photos', ['--force' => true, '--limit' => 5]);
        $this->assertStringStartsWith('private:', $a->fresh()->storage_path);
        $this->assertStringStartsWith('/storage/', $b->fresh()->storage_path, 'b is shared with a completed-photo row, so it stays');
    }
}
