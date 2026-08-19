<?php

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyOwner;
use App\Models\Prospecting\TvaContactCapture;
use App\Models\Prospecting\TvaContactCaptureItem;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deeds Capture dismiss (soft-delete) — 2026-08-14, per Johan's explicit spec:
 * a captured property or TVA block that shouldn't be there gets DISMISSED
 * (soft-deleted, reversible, never a hard delete — CoreX Non-Negotiable #1),
 * drops off the screen, but the row survives in the DB for audit. Critically,
 * a dismissed row must never re-surface via the matcher (a fresh capture of
 * the same physical property/person must NOT revive it) or via promote.
 *
 * Covers: DeedsCaptureController::dismissProperty()/dismissTva() (soft
 * delete, not hard delete, tenant-isolated, blocks a promoted row), the
 * index() screen dropping dismissed rows, and — the part that wasn't
 * previously locked in by a test — TrackedPropertyMatchOrCreateService
 * (both matchOrCreate()'s TP-level strategies and promoteToStock()'s
 * property-pillar resolution) correctly excluding dismissed rows.
 */
class DeedsCaptureDismissTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private TrackedPropertyMatchOrCreateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user   = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'admin',
        ]);
        $this->service = new TrackedPropertyMatchOrCreateService();
    }

    private function deedsCapture(array $overrides = []): TrackedProperty
    {
        return TrackedProperty::create(array_merge([
            'agency_id'     => $this->agency->id,
            'capture_kind'  => 'deeds_capture',
            'street_number' => '1',
            'street_name'   => 'Dismiss Test Rd',
            'suburb'        => 'Margate',
            'erf_number'    => '5001',
            'source_chain'  => [],
        ], $overrides));
    }

    // ──────────────────────── dismissProperty() — HTTP layer ────────────────────

    public function test_dismiss_property_soft_deletes_not_hard_deletes(): void
    {
        $tp = $this->deedsCapture();

        $response = $this->actingAs($this->user)
            ->post(route('corex.deeds-capture.dismiss', $tp->id));

        $response->assertRedirect(route('corex.deeds-capture.index'));
        $response->assertSessionHas('success');

        // Hard-delete would leave NOTHING in the table at all. A soft-delete
        // leaves the row present with deleted_at set — assertSoftDeleted()
        // checks this directly against the DB, independent of any Eloquent
        // scope subtlety (queryWithoutAgencyScope() strips only AgencyScope;
        // withoutGlobalScopes() strips SoftDeletingScope too — easy to
        // conflate the two, so the raw DB assertion is the unambiguous one).
        $this->assertSoftDeleted('tracked_properties', ['id' => $tp->id]);
        $this->assertNull(
            TrackedProperty::find($tp->id),
            'the default (non-trashed) query must no longer find it'
        );
    }

    public function test_dismiss_property_removed_from_index_screen(): void
    {
        $tp = $this->deedsCapture();

        $before = $this->actingAs($this->user)->get(route('corex.deeds-capture.index'));
        $before->assertSee('Dismiss Test Rd');

        $this->actingAs($this->user)->post(route('corex.deeds-capture.dismiss', $tp->id));

        $after = $this->actingAs($this->user)->get(route('corex.deeds-capture.index'));
        $after->assertDontSee('Dismiss Test Rd');
    }

    public function test_dismiss_property_blocked_when_already_promoted(): void
    {
        $tp = $this->deedsCapture();
        $property = $this->service->promoteToStock((int) $tp->id, (int) $this->user->id);

        $response = $this->actingAs($this->user)
            ->post(route('corex.deeds-capture.dismiss', $tp->id));

        $response->assertRedirect(route('corex.deeds-capture.index'));
        $response->assertSessionHas('info');
        $this->assertNull(
            TrackedProperty::withoutGlobalScopes()->find($tp->id)->deleted_at,
            'an already-promoted capture must NOT be dismissed — nothing to remove, per the existing guard'
        );

        $property->forceDelete();
    }

    public function test_dismiss_property_blocked_for_other_agency(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other']);
        $tp = $this->deedsCapture(['agency_id' => $otherAgency->id]);

        $response = $this->actingAs($this->user)
            ->post(route('corex.deeds-capture.dismiss', $tp->id));

        $response->assertNotFound();
        $this->assertNull(
            TrackedProperty::withoutGlobalScopes()->find($tp->id)->deleted_at,
            'a capture belonging to a DIFFERENT agency must never be dismissible'
        );
    }

    public function test_dismiss_property_blocked_for_non_deeds_capture(): void
    {
        $tp = $this->deedsCapture(['capture_kind' => 'prospecting']);

        $response = $this->actingAs($this->user)
            ->post(route('corex.deeds-capture.dismiss', $tp->id));

        $response->assertNotFound();
    }

    // ──────────────────────── dismissTva() — HTTP layer ──────────────────────────

    private function tvaCapture(array $overrides = []): TvaContactCapture
    {
        $capture = TvaContactCapture::create(array_merge([
            'agency_id'           => $this->agency->id,
            'captured_by_user_id' => $this->user->id,
            'id_number'           => '9001010000080',
            'first_name'          => 'Test',
            'surname'             => 'Person',
            'source'              => 'tva',
        ], $overrides));
        TvaContactCaptureItem::create([
            'tva_contact_capture_id' => $capture->id,
            'type'                   => 'cell',
            'value'                  => '0821112222',
        ]);

        return $capture;
    }

    public function test_dismiss_tva_soft_deletes_not_hard_deletes(): void
    {
        $capture = $this->tvaCapture();

        $response = $this->actingAs($this->user)
            ->post(route('corex.deeds-capture.tva.dismiss', $capture->id));

        $response->assertRedirect(route('corex.deeds-capture.index'));
        $response->assertSessionHas('success');

        $this->assertSoftDeleted('tva_contact_captures', ['id' => $capture->id]);
        $this->assertNull(TvaContactCapture::find($capture->id), 'the default (non-trashed) query must no longer find it');

        // The scraped phone/email data itself is untouched — that's the R3
        // captured content the dismiss action must never destroy.
        $this->assertSame(1, TvaContactCaptureItem::where('tva_contact_capture_id', $capture->id)->count());
    }

    public function test_dismiss_tva_removed_from_index_screen(): void
    {
        $capture = $this->tvaCapture();

        $before = $this->actingAs($this->user)->get(route('corex.deeds-capture.index'));
        $before->assertSee('Test Person');

        $this->actingAs($this->user)->post(route('corex.deeds-capture.tva.dismiss', $capture->id));

        $after = $this->actingAs($this->user)->get(route('corex.deeds-capture.index'));
        $after->assertDontSee('Test Person');
    }

    public function test_dismiss_tva_blocked_for_other_agency(): void
    {
        $otherAgency = Agency::create(['name' => 'Other TVA', 'slug' => 'other-tva']);
        $capture = $this->tvaCapture(['agency_id' => $otherAgency->id]);

        $response = $this->actingAs($this->user)
            ->post(route('corex.deeds-capture.tva.dismiss', $capture->id));

        $response->assertNotFound();
        $this->assertNull(TvaContactCapture::withoutGlobalScopes()->find($capture->id)->deleted_at);
    }

    // ──────────────────────── exclusion: matcher can't revive a dismissed TP ─────

    public function test_dismissed_tracked_property_excluded_from_source_ref_strategy(): void
    {
        $tp = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '1', 'street_name' => 'Ref Rd', 'suburb' => 'Margate'],
            ['type' => 'deeds', 'ref' => 'DISMISS-REF-1']
        );
        $tp->update(['capture_kind' => 'deeds_capture']);
        $this->actingAs($this->user)->post(route('corex.deeds-capture.dismiss', $tp->id));

        // A fresh capture with the SAME source_ref — strategy=1 would
        // normally find the ExternalRef -> this TP and revive it. Must NOT.
        $revived = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '1', 'street_name' => 'Ref Rd', 'suburb' => 'Margate'],
            ['type' => 'deeds', 'ref' => 'DISMISS-REF-1']
        );

        $this->assertNotSame((int) $tp->id, (int) $revived->id, 'a dismissed TP must never be re-matched via source-ref');
        $this->assertNull($revived->deleted_at, 'the freshly created replacement must be a live row');
    }

    public function test_dismissed_tracked_property_excluded_from_erf_suburb_strategy(): void
    {
        $tp = $this->service->matchOrCreate(
            $this->agency->id,
            ['erf_number' => '7777', 'suburb' => 'Uvongo'],
            ['type' => 'deeds', 'ref' => 'DISMISS-ERF-1']
        );
        $tp->update(['capture_kind' => 'deeds_capture']);
        $this->actingAs($this->user)->post(route('corex.deeds-capture.dismiss', $tp->id));

        $revived = $this->service->matchOrCreate(
            $this->agency->id,
            ['erf_number' => '7777', 'suburb' => 'Uvongo'],
            ['type' => 'deeds', 'ref' => 'DISMISS-ERF-1-different']
        );

        $this->assertNotSame((int) $tp->id, (int) $revived->id, 'a dismissed TP must never be re-matched via erf+suburb');
    }

    public function test_dismissed_tracked_property_excluded_from_normalised_address_strategy(): void
    {
        $tp = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '99', 'street_name' => 'Address Match Rd', 'suburb' => 'Ramsgate'],
            ['type' => 'deeds', 'ref' => 'DISMISS-ADDR-1']
        );
        $tp->update(['capture_kind' => 'deeds_capture']);
        $this->actingAs($this->user)->post(route('corex.deeds-capture.dismiss', $tp->id));

        $revived = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '99', 'street_name' => 'address match rd', 'suburb' => 'RAMSGATE'],
            ['type' => 'deeds', 'ref' => 'DISMISS-ADDR-2']
        );

        $this->assertNotSame((int) $tp->id, (int) $revived->id, 'a dismissed TP must never be re-matched via normalised address');
    }

    // ──────────────────────── exclusion: promote can't touch a dismissed TP ──────

    public function test_dismissed_tracked_property_cannot_be_promoted(): void
    {
        $tp = $this->deedsCapture();
        $this->actingAs($this->user)->post(route('corex.deeds-capture.dismiss', $tp->id));

        $response = $this->actingAs($this->user)
            ->post(route('corex.deeds-capture.promote', $tp->id));

        // The TrackedProperty implicit-binding route param must not resolve
        // a trashed row at all (Laravel implicit binding queries without
        // trashed by default), so the whole request 404s before ever
        // reaching promote()'s own logic.
        $response->assertNotFound();
        $this->assertNull(TrackedProperty::withoutGlobalScopes()->find($tp->id)->promoted_to_property_id);
    }

    public function test_promote_to_stock_throws_for_a_dismissed_tracked_property_called_directly(): void
    {
        $tp = $this->deedsCapture();
        $tp->delete();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->promoteToStock((int) $tp->id, (int) $this->user->id);
    }
}
