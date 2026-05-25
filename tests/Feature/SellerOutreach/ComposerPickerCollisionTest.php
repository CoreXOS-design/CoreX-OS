<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase A.3.4 — composer property-picker collision visibility (M97-M99).
 *
 * The picker is a native <select> of the contact's linked properties (≤ 2
 * per contact in practice). Each option gets a status-derived suffix; the
 * currently-selected property also shows a coloured badge below the picker.
 * Selecting an `other_draft` property requires a confirm gesture.
 */
final class ComposerPickerCollisionTest extends TestCase
{
    use RefreshDatabase;

    /** M97 — composer view receives propertyStatuses keyed by property id. */
    public function test_m97_composer_view_receives_property_statuses(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $user = User::find($userId);

        $contact = $this->seedContact($agencyId);
        $heldPropertyId = $this->seedProperty($agencyId, $userId, status: 'active',
            address: '18 Golf Course Road', lat: -30.84, lng: 30.39);
        $this->seedTrackedProperty($agencyId, promotedTo: $heldPropertyId,
            lat: -30.84, lng: 30.39, streetNumber: '18', streetName: 'Golf Course Road', suburb: 'Uvongo');
        $this->linkContactToProperty($contact->id, $heldPropertyId, $agencyId);

        $resp = $this->actingAs($user)
            ->get(route('seller-outreach.composer.show', $contact));

        $resp->assertOk();
        $statuses = $resp->viewData('propertyStatuses');
        $this->assertIsArray($statuses);
        $this->assertArrayHasKey($heldPropertyId, $statuses);
        $this->assertSame('held', $statuses[$heldPropertyId]['status']);
    }

    /** M98 — picker dropdown rendering carries the badge label per option,
     *        and the selected-property banner reflects the status. */
    public function test_m98_picker_renders_status_badges(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $user = User::find($userId);

        $contact = $this->seedContact($agencyId);

        // Property 1: held — shows "On HFC books" badge.
        $heldId = $this->seedProperty($agencyId, $userId, status: 'active',
            address: '18 Golf Course Road', lat: -30.84, lng: 30.39);
        $this->seedTrackedProperty($agencyId, promotedTo: $heldId,
            lat: -30.84, lng: 30.39, streetNumber: '18', streetName: 'Golf Course Road', suburb: 'Uvongo');
        $this->linkContactToProperty($contact->id, $heldId, $agencyId);

        // Property 2: sold — shows "Previously sold" badge.
        $soldId = $this->seedProperty($agencyId, $userId, status: 'sold',
            address: '7 Sold Street', lat: -30.85, lng: 30.40);
        $this->seedTrackedProperty($agencyId, promotedTo: $soldId,
            lat: -30.85, lng: 30.40, streetNumber: '7', streetName: 'Sold Street', suburb: 'Uvongo');
        $this->linkContactToProperty($contact->id, $soldId, $agencyId);

        $resp = $this->actingAs($user)
            ->get(route('seller-outreach.composer.show', $contact) . '?property_id=' . $heldId);

        $resp->assertOk();
        // Both options labelled with badge suffix in the dropdown.
        $resp->assertSee('On HFC books', false);
        $resp->assertSee('Previously sold', false);
        // The selected (held) property surfaces the inline badge container
        // with its data attribute for JS hooks / e2e tests.
        $resp->assertSee('data-prospect-status-badge="held"', false);
    }

    /** M99 — the Alpine handler exists and the picker is wired to it so
     *        an `other_draft` selection triggers the confirm gesture.
     *        (E2E-level click simulation needs a browser; here we assert
     *        the wiring is in place — the handler name + data-payload.) */
    public function test_m99_picker_wires_collision_handler_for_other_draft(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $user = User::find($userId);
        $bob  = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
            'name'      => 'Bob Builder',
        ]);

        $contact = $this->seedContact($agencyId);
        $otherDraftId = $this->seedProperty($agencyId, $bob->id, status: 'draft',
            address: '99 Drafty Lane', lat: -30.86, lng: 30.41);
        $this->seedTrackedProperty($agencyId, promotedTo: $otherDraftId,
            lat: -30.86, lng: 30.41, streetNumber: '99', streetName: 'Drafty Lane', suburb: 'Uvongo');
        $this->linkContactToProperty($contact->id, $otherDraftId, $agencyId);

        $resp = $this->actingAs($user)
            ->get(route('seller-outreach.composer.show', $contact) . '?property_id=' . $otherDraftId);

        $resp->assertOk();
        // Alpine component is referenced from the picker root.
        $resp->assertSee('propertyPickerCollision', false);
        $resp->assertSee('onPickerChange', false);
        // The other_draft option carries the data attribute the JS reads
        // before deciding to confirm.
        $resp->assertSee('data-prospect-status="other_draft"', false);
        // Inline badge under the picker shows the colleague's name.
        $resp->assertSee('Bob Builder', false);
        $resp->assertSee('Draft by colleague', false);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
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

    private function seedContact(int $agencyId): Contact
    {
        return Contact::create([
            'agency_id'  => $agencyId,
            'branch_id'  => $agencyId,
            'first_name' => 'Test',
            'last_name'  => 'Buyer',
            'phone'      => '+27821234567',
            'email'      => 'test-' . Str::random(6) . '@example.test',
        ]);
    }

    private function seedProperty(
        int $agencyId, int $userId, string $status,
        string $address, float $lat, float $lng,
    ): int {
        return (int) DB::table('properties')->insertGetId([
            'external_id'   => 'TEST-' . Str::random(8),
            'title'         => $address,
            'address'       => $address,
            'street_number' => preg_replace('/\D+/', '', explode(' ', $address)[0] ?? '0') ?: '0',
            'street_name'   => trim(preg_replace('/^\S+\s*/', '', $address)),
            'suburb'        => 'Uvongo',
            'latitude'      => $lat,
            'longitude'     => $lng,
            'price'         => 1_200_000,
            'property_type' => 'house',
            'status'        => $status,
            'is_demo'       => false,
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $userId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function seedTrackedProperty(
        int $agencyId, int $promotedTo, float $lat, float $lng,
        string $streetNumber, string $streetName, string $suburb,
    ): int {
        return (int) DB::table('tracked_properties')->insertGetId([
            'agency_id'              => $agencyId,
            'external_id'            => 'TP-' . Str::random(8),
            'street_number'          => $streetNumber,
            'street_name'            => $streetName,
            'suburb'                 => $suburb,
            'latitude'               => $lat,
            'longitude'              => $lng,
            'promoted_to_property_id'=> $promotedTo,
            'promoted_at'            => now(),
            'status'                 => 'promoted',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    private function linkContactToProperty(int $contactId, int $propertyId, int $agencyId): void
    {
        DB::table('contact_property')->insert([
            'contact_id'  => $contactId,
            'property_id' => $propertyId,
            'role'        => 'buyer',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
}
