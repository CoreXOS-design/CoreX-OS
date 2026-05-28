<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\Prospecting\ProspectingPitchLock;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Map Workspace Phase B (Fix 2+3) — T-pin "WhatsApp / Pitch" entry-point tests.
 *
 * Mirrors the MIC Work-tab fromProspecting flow but the source is a
 * TrackedProperty rather than a portal listing. These tests guard the
 * contact-capture flow that gives map T-pins parity with portal P-pins:
 *
 *   click → temp-lock → contact-capture modal → store → composer
 *
 * The composer redirect itself is covered by ComposerController tests;
 * here we only verify the pre-composer plumbing.
 */
final class EntryPointFromTrackedPropertyTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_renders_contact_capture_view_with_tracked_property_context(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $tp = $this->seedTrackedProperty($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->get(route('seller-outreach.entry.from-tracked-property', ['trackedProperty' => $tp->id]));

        $resp->assertOk();
        $resp->assertViewIs('seller-outreach.entry.prospecting-create-contact');
        $resp->assertViewHas('trackedProperty', fn ($v) => (int) $v->id === (int) $tp->id);
        $resp->assertViewHas('listing', null);
    }

    public function test_get_creates_temp_lock_keyed_to_tracked_property(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $tp = $this->seedTrackedProperty($agencyId);

        $this->actingAs(User::find($userId))
            ->get(route('seller-outreach.entry.from-tracked-property', ['trackedProperty' => $tp->id]))
            ->assertOk();

        $lock = ProspectingPitchLock::where('tracked_property_id', $tp->id)->first();
        $this->assertNotNull($lock, 'A temp lock must be created keyed to tracked_property_id');
        $this->assertNull($lock->prospecting_listing_id, 'TP-only locks must not reference a listing');
        $this->assertSame($userId, (int) $lock->user_id);
        $this->assertNull($lock->released_at);
        $this->assertTrue($lock->expires_at->isFuture());
    }

    public function test_get_blocks_when_another_agent_holds_active_lock(): void
    {
        [$agencyId, $aliceId] = $this->seedAgency();
        // super_admin role mirrors seedAgency() — needed so the
        // permission:outreach.compose middleware lets the request through.
        $bob = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'role'      => 'super_admin', 'name'   => 'Bob Builder',
        ]);
        $tp = $this->seedTrackedProperty($agencyId);

        // Bob holds the lock first.
        $this->actingAs($bob)
            ->get(route('seller-outreach.entry.from-tracked-property', ['trackedProperty' => $tp->id]))
            ->assertOk();

        // Alice tries — gets redirected back to the map with an error.
        $resp = $this->actingAs(User::find($aliceId))
            ->get(route('seller-outreach.entry.from-tracked-property', ['trackedProperty' => $tp->id]));

        $resp->assertStatus(302);
        $this->assertStringContainsString('Bob Builder', (string) session('error'));
    }

    public function test_get_404_when_tracked_property_in_different_agency(): void
    {
        [, $userId] = $this->seedAgency();
        [$otherAgencyId] = $this->seedAgency();
        $tp = $this->seedTrackedProperty($otherAgencyId);

        // The BelongsToAgency global scope on TrackedProperty makes the
        // implicit binding return 404 before our explicit check fires.
        $this->actingAs(User::find($userId))
            ->get(route('seller-outreach.entry.from-tracked-property', ['trackedProperty' => $tp->id]))
            ->assertNotFound();
    }

    public function test_post_creates_contact_and_links_to_tracked_property(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $tp = $this->seedTrackedProperty($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.entry.store-from-tracked-property', ['trackedProperty' => $tp->id]), [
                'first_name' => 'Alice',
                'last_name'  => 'Owner',
                'phone'      => '0821234567',
            ]);

        $resp->assertRedirect();
        $contact = Contact::where('first_name', 'Alice')->first();
        $this->assertNotNull($contact, 'Contact must be created from the form');
        $this->assertSame($agencyId, (int) $contact->agency_id);

        $tp->refresh();
        $this->assertSame((int) $contact->id, (int) $tp->owner_contact_id,
            'TP→Contact link must be set via tracked_properties.owner_contact_id');
    }

    public function test_post_redirects_to_composer_with_whatsapp_channel(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $tp = $this->seedTrackedProperty($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.entry.store-from-tracked-property', ['trackedProperty' => $tp->id]), [
                'first_name' => 'Alice',
                'phone'      => '0821234567',
            ]);

        $contact = Contact::where('first_name', 'Alice')->firstOrFail();
        $resp->assertRedirect(route('seller-outreach.composer.show', [
            'contact' => $contact->id,
            'channel' => 'whatsapp',
        ]));
    }

    public function test_post_dedupes_against_existing_contact_by_phone(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $tp = $this->seedTrackedProperty($agencyId);

        $existing = Contact::create([
            'agency_id'  => $agencyId,
            'branch_id'  => $agencyId,
            'first_name' => 'Pre-existing',
            'last_name'  => 'Lead',
            'phone'      => '0820001111',
            'created_by_user_id' => $userId,
        ]);

        $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.entry.store-from-tracked-property', ['trackedProperty' => $tp->id]), [
                'first_name' => 'Alice',  // different name
                'phone'      => '082 000 1111',  // same digits, different formatting
            ])
            ->assertRedirect();

        // No new contact created — the existing one is reused.
        $this->assertSame(1, Contact::where('agency_id', $agencyId)->count());

        $tp->refresh();
        $this->assertSame((int) $existing->id, (int) $tp->owner_contact_id);
    }

    public function test_post_requires_phone_or_email(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $tp = $this->seedTrackedProperty($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.entry.store-from-tracked-property', ['trackedProperty' => $tp->id]), [
                'first_name' => 'Alice',
                // No phone, no email.
            ]);

        $resp->assertSessionHasErrors('contact_required');
        $this->assertSame(0, Contact::where('agency_id', $agencyId)->count());

        $tp->refresh();
        $this->assertNull($tp->owner_contact_id);
    }

    public function test_post_does_not_overwrite_previously_captured_owner(): void
    {
        [$agencyId, $userId] = $this->seedAgency();

        $firstOwner = Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'first_name' => 'First', 'last_name' => 'Owner',
            'phone' => '0820000001', 'created_by_user_id' => $userId,
        ]);
        $tp = $this->seedTrackedProperty($agencyId);
        $tp->update(['owner_contact_id' => $firstOwner->id]);

        $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.entry.store-from-tracked-property', ['trackedProperty' => $tp->id]), [
                'first_name' => 'Second',
                'last_name'  => 'Owner',
                'phone'      => '0820000002',
            ])
            ->assertRedirect();

        $tp->refresh();
        $this->assertSame((int) $firstOwner->id, (int) $tp->owner_contact_id,
            'Once owner_contact_id is set, the entry point must not silently overwrite it');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        return [$agencyId, $user->id];
    }

    private function seedTrackedProperty(int $agencyId): TrackedProperty
    {
        return TrackedProperty::create([
            'agency_id'     => $agencyId,
            'street_number' => '42',
            'street_name'   => 'Test Street',
            'suburb'        => 'Margate',
            'latitude'      => -30.88,
            'longitude'     => 30.38,
            'status'        => TrackedProperty::STATUS_ACTIVE,
        ]);
    }
}
