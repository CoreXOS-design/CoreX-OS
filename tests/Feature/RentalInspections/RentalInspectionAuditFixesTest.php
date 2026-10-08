<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Exceptions\RentalInspectionNotRecordableException;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit 2026-09-30 fixes (H1-H3, M1-M6, L1-L3) for the rental inspections
 * module. Written without the ability to execute them in the fixing session —
 * run this file on its own before relying on it.
 */
final class RentalInspectionAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'RI Audit Agency', 'slug' => 'ri-audit-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        // These fixtures have no checklist; the "empty checklist cannot be signed" and "Routine follows the full checks" rules (spec §52) have their own tests.
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['empty_checklist_blocks_signing' => false, 'routine_follows_full_checks' => false]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'RI Audit Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeItem(?Property $property = null): RentalInspectionItem
    {
        $property ??= $this->property;

        return RentalInspectionItem::create([
            'agency_id' => $property->agency_id, 'property_id' => $property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Bedroom 1', 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeInspection(string $type = RentalInspection::TYPE_AD_HOC, array $attrs = []): RentalInspection
    {
        return RentalInspection::create(array_merge([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'created_by_user_id' => $this->agent->id,
        ], $attrs));
    }

    // ── H1 / H2 ─────────────────────────────────────────────────────────

    public function test_a_completed_inspection_rejects_writes_with_409(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $inspection->markCompleted();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'ad_hoc',
        ])->assertStatus(409);

        $this->postJson(route('corex.rental-inspections.overall-notes.update', $inspection), ['overall_notes' => 'edited'])
            ->assertStatus(409);

        $this->postJson(route('corex.rental-inspections.mark-all-good', $inspection))->assertStatus(409);

        $this->assertSame(0, RentalInspectionObservation::where('rental_inspection_id', $inspection->id)->count());
    }

    public function test_a_cancelled_inspection_rejects_writes_with_409(): void
    {
        $inspection = $this->makeInspection();
        $inspection->cancel($this->agent, 'Wrong property.');

        $this->postJson(route('corex.rental-inspections.overall-notes.update', $inspection), ['overall_notes' => 'x'])
            ->assertStatus(409);
    }

    public function test_a_completed_inspection_cannot_be_completed_again_or_cancelled(): void
    {
        $inspection = $this->makeInspection();
        $inspection->markCompleted();
        $completedAt = $inspection->fresh()->completed_at;

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertStatus(409);
        $this->assertEquals($completedAt, $inspection->fresh()->completed_at);

        try {
            $inspection->fresh()->cancel($this->agent, 'Too late');
            $this->fail('cancel() must refuse a completed inspection.');
        } catch (RentalInspectionNotRecordableException) {
            $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
        }
    }

    public function test_cancelling_twice_does_not_overwrite_the_first_reason(): void
    {
        $inspection = $this->makeInspection();
        $inspection->cancel($this->agent, 'First reason');

        $this->post(route('corex.rental-inspections.cancel', $inspection), ['cancel_reason' => 'Second reason'])
            ->assertSessionHasErrors('rental_inspection');

        $this->assertSame('First reason', $inspection->fresh()->cancel_reason);
    }

    // ── H3 / M5 ─────────────────────────────────────────────────────────

    public function test_wet_ink_upload_never_uses_the_client_filename_or_extension(): void
    {
        Storage::fake('local');
        $tenant = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Tenant', 'email' => uniqid() . '@example.test',
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_WET_INK,
            'party_contact_id' => $tenant->id,
            'wet_ink_file' => UploadedFile::fake()->image('evil.html.jpg', 20, 20),
        ])->assertStatus(201);

        $stored = RentalInspectionSignature::where('rental_inspection_id', $inspection->id)->firstOrFail()->wet_ink_upload_path;
        $this->assertStringStartsWith(RentalInspectionSignature::PRIVATE_PREFIX, $stored);
        $this->assertStringNotContainsString('evil', $stored);
        $this->assertMatchesRegularExpression('/\.(pdf|jpg|png|heic)$/', $stored);
        Storage::disk('local')->assertExists(substr($stored, strlen(RentalInspectionSignature::PRIVATE_PREFIX)));
    }

    public function test_a_blank_or_garbage_canvas_signature_is_rejected(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);

        foreach (['', 'data:image/png;base64,', 'data:image/png;base64,@@@not-base64@@@', 'data:image/png;base64,' . base64_encode('<html>not a png</html>')] as $bad) {
            $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
                'party_role' => RentalInspectionSignature::PARTY_AGENT,
                'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
                'signature_image' => $bad,
            ])->assertStatus(422);
        }
        $this->assertSame(0, RentalInspectionSignature::where('rental_inspection_id', $inspection->id)->count());
    }

    public function test_a_valid_canvas_signature_is_stored_privately(): void
    {
        Storage::fake('local');
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_AGENT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'signature_image' => self::PNG,
        ])->assertStatus(201);

        $signature = RentalInspectionSignature::where('rental_inspection_id', $inspection->id)->firstOrFail();
        $this->assertStringStartsWith(RentalInspectionSignature::PRIVATE_PREFIX, $signature->party_signature_path);
        $this->get(route('corex.rental-inspections.signatures.file', [$inspection, $signature, 'signature']))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ── M6 ──────────────────────────────────────────────────────────────

    public function test_an_observation_cannot_reference_another_propertys_item(): void
    {
        $other = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Other', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $foreignItem = $this->makeItem($other);
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $foreignItem->id, 'condition' => 'good', 'source' => 'ad_hoc',
        ])->assertStatus(422);
    }

    // ── M1 ──────────────────────────────────────────────────────────────

    public function test_an_own_scope_agent_cannot_open_another_agents_inspection_by_id(): void
    {
        $colleague = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $theirs = $this->makeInspection(RentalInspection::TYPE_AD_HOC, ['created_by_user_id' => $colleague->id]);
        $mine = $this->makeInspection();

        $this->get(route('corex.rental-inspections.show', $mine))->assertOk();
        $this->get(route('corex.rental-inspections.show', $theirs))->assertNotFound();
        $this->get(route('corex.rental-inspections.report', $theirs))->assertNotFound();
        $this->post(route('corex.rental-inspections.cancel', $theirs), ['cancel_reason' => 'x'])->assertNotFound();
    }

    // ── M3 ──────────────────────────────────────────────────────────────

    public function test_downloading_the_report_does_not_create_a_public_link(): void
    {
        $inspection = $this->makeInspection();

        $this->get(route('corex.rental-inspections.report', $inspection))->assertOk();

        $this->assertNull($inspection->fresh()->public_token);
    }

    // ── L2 ──────────────────────────────────────────────────────────────

    public function test_the_public_page_sends_privacy_headers(): void
    {
        $inspection = $this->makeInspection();
        $token = $inspection->generatePublicLink();

        $this->get(route('rental-inspections.public.show', $token))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    // ── L3 ──────────────────────────────────────────────────────────────

    public function test_a_photo_idempotency_key_from_another_inspection_is_not_replayed(): void
    {
        Storage::fake('public');
        $first = $this->makeInspection();
        $second = $this->makeInspection();
        $key = (string) \Illuminate\Support\Str::uuid();

        $this->postJson(route('corex.rental-inspections.photos.store', $first), [
            'photos' => [UploadedFile::fake()->image('a.jpg')], 'client_idempotency_keys' => [$key],
        ])->assertCreated();

        $this->assertNull(
            \App\Models\RentalInspectionPhoto::where('rental_inspection_id', $second->id)->where('client_idempotency_key', $key)->first()
        );
    }
}
