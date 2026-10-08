<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionPhotoNote;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-433 Part C, .ai/specs/rental-inspections.md §23 — the photo-level
 * note. Full CRUD (create/update/archive/restore), one live note per
 * photo, agency-configurable classification vocabulary, and locked once
 * the inspection is completed/signed (Johan's ruling).
 */
final class RentalInspectionPhotoNoteControllerTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'RI Photo Note Agency', 'slug' => 'ri-photo-note-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        // These fixtures have no checklist; the "empty checklist cannot be signed" and "Routine follows the full checks" rules (spec §51) have their own tests.
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['empty_checklist_blocks_signing' => false, 'routine_follows_full_checks' => false]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'RI Photo Note Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeInspection(string $type = RentalInspection::TYPE_AD_HOC): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makePhoto(RentalInspection $inspection): RentalInspectionPhoto
    {
        return RentalInspectionPhoto::create([
            'agency_id' => $this->agency->id,
            'rental_inspection_id' => $inspection->id,
            'storage_path' => '/fake/path/photo-' . uniqid() . '.jpg',
            'uploaded_by_user_id' => $this->agent->id,
        ]);
    }

    // ── Setting resolver ────────────────────────────────────────────────

    public function test_default_classification_vocabulary_when_agency_has_not_customized_it(): void
    {
        $classifications = RentalInspectionSetting::photoNoteClassificationsFor($this->agency->id);

        $this->assertEqualsCanonicalizing(
            ['defect', 'wear_and_tear', 'reference'],
            array_column($classifications, 'key'),
        );
    }

    public function test_agency_can_customize_the_classification_vocabulary(): void
    {
        $this->postJson(route('corex.settings.rental-inspections.photo-note-classifications'), [
            'photo_note_classifications_submitted' => '1',
            'photo_note_classifications' => [
                ['key' => 'defect', 'label' => 'Defect'],
                ['key' => 'cosmetic', 'label' => 'Cosmetic only'],
            ],
        ])->assertRedirect();

        $classifications = RentalInspectionSetting::photoNoteClassificationsFor($this->agency->id);
        $this->assertEqualsCanonicalizing(['defect', 'cosmetic'], array_column($classifications, 'key'));
    }

    public function test_saving_an_empty_classification_list_is_rejected(): void
    {
        $this->post(route('corex.settings.rental-inspections.photo-note-classifications'), [
            'photo_note_classifications_submitted' => '1',
            'photo_note_classifications' => [],
        ])->assertSessionHasErrors('photo_note_classifications');

        // Untouched — still resolves to the shipped default.
        $this->assertEqualsCanonicalizing(
            ['defect', 'wear_and_tear', 'reference'],
            array_column(RentalInspectionSetting::photoNoteClassificationsFor($this->agency->id), 'key'),
        );
    }

    // ── Create ──────────────────────────────────────────────────────────

    public function test_agent_can_add_a_note_to_a_photo(): void
    {
        $inspection = $this->makeInspection();
        $photo = $this->makePhoto($inspection);

        $response = $this->postJson(
            route('corex.rental-inspections.photos.notes.store', [$inspection, $photo]),
            ['classification_key' => 'defect', 'note' => 'Scuff mark on the left wall, roughly 10cm.'],
        )->assertCreated();

        $this->assertDatabaseHas('rental_inspection_photo_notes', [
            'rental_inspection_photo_id' => $photo->id,
            'classification_key' => 'defect',
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->assertSame($this->agency->id, $response->json('agency_id'));
    }

    public function test_note_text_is_required(): void
    {
        $inspection = $this->makeInspection();
        $photo = $this->makePhoto($inspection);

        $this->postJson(
            route('corex.rental-inspections.photos.notes.store', [$inspection, $photo]),
            ['classification_key' => 'defect', 'note' => ''],
        )->assertStatus(422);
    }

    public function test_classification_key_is_required(): void
    {
        $inspection = $this->makeInspection();
        $photo = $this->makePhoto($inspection);

        $this->postJson(
            route('corex.rental-inspections.photos.notes.store', [$inspection, $photo]),
            ['note' => 'Missing a classification.'],
        )->assertStatus(422);
    }

    public function test_an_unknown_classification_key_is_rejected(): void
    {
        $inspection = $this->makeInspection();
        $photo = $this->makePhoto($inspection);

        $this->postJson(
            route('corex.rental-inspections.photos.notes.store', [$inspection, $photo]),
            ['classification_key' => 'made_up', 'note' => 'Not a real classification.'],
        )->assertStatus(422);
    }

    public function test_a_second_note_on_the_same_photo_is_rejected(): void
    {
        $inspection = $this->makeInspection();
        $photo = $this->makePhoto($inspection);

        $this->postJson(route('corex.rental-inspections.photos.notes.store', [$inspection, $photo]), [
            'classification_key' => 'defect', 'note' => 'First note.',
        ])->assertCreated();

        $this->postJson(route('corex.rental-inspections.photos.notes.store', [$inspection, $photo]), [
            'classification_key' => 'reference', 'note' => 'Second attempt.',
        ])->assertStatus(422);

        $this->assertSame(1, RentalInspectionPhotoNote::where('rental_inspection_photo_id', $photo->id)->count());
    }

    // ── Update ──────────────────────────────────────────────────────────

    public function test_agent_can_edit_a_note_in_place(): void
    {
        $inspection = $this->makeInspection();
        $photo = $this->makePhoto($inspection);
        $note = RentalInspectionPhotoNote::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_photo_id' => $photo->id, 'classification_key' => 'defect',
            'note' => 'Original wording.', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->patchJson(route('corex.rental-inspections.photos.notes.update', [$inspection, $photo, $note]), [
            'classification_key' => 'wear_and_tear', 'note' => 'Corrected wording.',
        ])->assertOk();

        $note->refresh();
        $this->assertSame('wear_and_tear', $note->classification_key);
        $this->assertSame('Corrected wording.', $note->note);
        $this->assertSame($this->agent->id, $note->updated_by_user_id);
        // Same row, edited in place — not a new one (unlike observations' own
        // append-only supersede convention elsewhere in this module).
        $this->assertSame(1, RentalInspectionPhotoNote::count());
    }

    public function test_a_note_belonging_to_a_different_photo_404s_on_update(): void
    {
        $inspection = $this->makeInspection();
        $photoA = $this->makePhoto($inspection);
        $photoB = $this->makePhoto($inspection);
        $noteOnA = RentalInspectionPhotoNote::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_photo_id' => $photoA->id, 'classification_key' => 'defect',
            'note' => 'Belongs to photo A.', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->patchJson(route('corex.rental-inspections.photos.notes.update', [$inspection, $photoB, $noteOnA]), [
            'classification_key' => 'defect', 'note' => 'Trying to hijack via photo B.',
        ])->assertStatus(404);
    }

    // ── Archive / restore ───────────────────────────────────────────────

    public function test_agent_can_archive_and_restore_a_note(): void
    {
        $inspection = $this->makeInspection();
        $photo = $this->makePhoto($inspection);
        $note = RentalInspectionPhotoNote::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_photo_id' => $photo->id, 'classification_key' => 'defect',
            'note' => 'Will be archived.', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->deleteJson(route('corex.rental-inspections.photos.notes.archive', [$inspection, $photo, $note]))
            ->assertOk();

        $this->assertSoftDeleted('rental_inspection_photo_notes', ['id' => $note->id]);
        $note->refresh();
        $this->assertSame($this->agent->id, $note->archived_by_user_id);
        $this->assertNull(RentalInspectionPhotoNote::liveFor($photo));

        $this->postJson(route('corex.rental-inspections.photos.notes.restore', [$inspection, $photo, $note]))
            ->assertOk();

        $this->assertNotSoftDeleted('rental_inspection_photo_notes', ['id' => $note->id]);
        $this->assertNotNull(RentalInspectionPhotoNote::liveFor($photo));
    }

    public function test_restoring_is_rejected_when_the_photo_already_has_a_different_live_note(): void
    {
        $inspection = $this->makeInspection();
        $photo = $this->makePhoto($inspection);
        $archived = RentalInspectionPhotoNote::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_photo_id' => $photo->id, 'classification_key' => 'defect',
            'note' => 'Old note.', 'created_by_user_id' => $this->agent->id,
        ]);
        $archived->delete();

        RentalInspectionPhotoNote::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_photo_id' => $photo->id, 'classification_key' => 'reference',
            'note' => 'A fresh note added after the old one was archived.', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inspections.photos.notes.restore', [$inspection, $photo, $archived]))
            ->assertStatus(422);
    }

    // ── Photo removed ───────────────────────────────────────────────────

    /**
     * Design call: RentalInspectionPhoto::archive() cascades — the photo's
     * live note is archived in the same call (see that method's own
     * docblock for why a parent's SoftDeletes scope alone doesn't cover
     * this direction). No auto-restore on the reverse: nothing in this
     * module currently restores an archived photo at all, so there is
     * nothing yet to keep in sync on that side.
     */
    public function test_archiving_a_photo_archives_its_live_note_too(): void
    {
        $inspection = $this->makeInspection();
        $photo = $this->makePhoto($inspection);
        $note = RentalInspectionPhotoNote::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_photo_id' => $photo->id, 'classification_key' => 'defect',
            'note' => 'Evidence for this photo.', 'created_by_user_id' => $this->agent->id,
        ]);

        $photo->archive($this->agent);

        $this->assertSoftDeleted('rental_inspection_photo_notes', ['id' => $note->id]);
        $note->refresh();
        $this->assertSame($this->agent->id, $note->archived_by_user_id);
    }

    // ── Locked once signed/cancelled (Johan's ruling) ───────────────────

    public function test_photo_note_mutations_are_blocked_once_the_inspection_is_completed(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_AD_HOC);
        $photo = $this->makePhoto($inspection);
        $inspection->markCompleted();

        $this->postJson(route('corex.rental-inspections.photos.notes.store', [$inspection, $photo]), [
            'classification_key' => 'defect', 'note' => 'Too late.',
        ])->assertStatus(409);

        $note = RentalInspectionPhotoNote::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_photo_id' => $photo->id, 'classification_key' => 'defect',
            'note' => 'Recorded before completion (bypassing the guard directly, as a real pre-completion note would have been).',
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->patchJson(route('corex.rental-inspections.photos.notes.update', [$inspection, $photo, $note]), [
            'classification_key' => 'defect', 'note' => 'Trying to edit a signed report.',
        ])->assertStatus(409);

        $this->deleteJson(route('corex.rental-inspections.photos.notes.archive', [$inspection, $photo, $note]))
            ->assertStatus(409);
    }

    public function test_photo_note_mutations_are_blocked_once_the_inspection_is_cancelled(): void
    {
        $inspection = $this->makeInspection();
        $photo = $this->makePhoto($inspection);
        $inspection->cancel($this->agent, 'Tenant withdrew before the walkthrough.');

        $this->postJson(route('corex.rental-inspections.photos.notes.store', [$inspection, $photo]), [
            'classification_key' => 'defect', 'note' => 'Too late.',
        ])->assertStatus(409);
    }

    // ── Agency scoping ──────────────────────────────────────────────────

    public function test_a_photo_belonging_to_another_agency_404s_via_route_binding(): void
    {
        // Built while LOGGED OUT, same as this class's own setUp() builds
        // $this->agency/$this->branch before $this->agent exists to act as —
        // BelongsToAgency's creating() hook force-stamps agency_id from the
        // CURRENTLY authenticated user's effective agency, overriding any
        // explicit value passed to create()
        // (App\Models\Concerns\BelongsToAgency::bootBelongsToAgency()).
        // Building this fixture while still acting as $this->agent would
        // silently re-stamp every row onto the FIRST agency regardless of the
        // agency_id written here — which is exactly the false alarm this got
        // wrong on the first pass of this investigation.
        \Illuminate\Support\Facades\Auth::logout();
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-agency-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        $otherAgent = User::factory()->create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'agent',
        ]);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAgent->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other Agency Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherLease = Lease::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $otherAgent->id,
        ]);
        $otherInspection = RentalInspection::create([
            'agency_id' => $otherAgency->id, 'lease_id' => $otherLease->id, 'type' => RentalInspection::TYPE_AD_HOC,
            'created_by_user_id' => $otherAgent->id,
        ]);
        $otherPhoto = RentalInspectionPhoto::create([
            'agency_id' => $otherAgency->id, 'rental_inspection_id' => $otherInspection->id,
            'storage_path' => '/fake/other-agency-photo.jpg', 'uploaded_by_user_id' => $otherAgent->id,
        ]);
        $this->assertSame($otherAgency->id, $otherInspection->agency_id, 'fixture sanity check — must genuinely belong to the OTHER agency');
        $this->assertSame($otherAgency->id, $otherPhoto->agency_id, 'fixture sanity check — must genuinely belong to the OTHER agency');

        // Switch back to $this->agent (the FIRST agency) for the actual
        // assertion — a direct URL naming another agency's inspection/photo
        // IDs must 404, not leak or 403 with any identifying detail.
        $this->actingAs($this->agent);
        $this->postJson(
            route('corex.rental-inspections.photos.notes.store', [$otherInspection, $otherPhoto]),
            ['classification_key' => 'defect', 'note' => 'Cross-agency attempt.'],
        )->assertStatus(404);
    }
}
