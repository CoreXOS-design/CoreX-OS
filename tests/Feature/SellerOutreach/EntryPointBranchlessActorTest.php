<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for EntryPointController's contact-creation branch_id
 * resolution.
 *
 * Backstory (live 500, 2026-08-18): contacts.branch_id is NOT NULL with no
 * default. storeFromProperty()/storeFromProspecting()/storeFromTrackedProperty()
 * all built the Contact::create() payload from a bare $request->user()->branch_id
 * — for an all-branches admin/super_admin (branch_id NULL by design, the same
 * population CalendarEvent::scopeVisibleTo()'s branches.view_all carve-out
 * exists for), that's null, array_filter() strips the key entirely, and the
 * INSERT hits SQLSTATE 1364. Reproduced live: a super_admin with agency_id
 * and branch_id both NULL composing outreach from a property page.
 *
 * A second, sharper edge came out of the same investigation: the target
 * agency's properties can carry a branch_id belonging to a DIFFERENT agency
 * (stale pre-branch-enforcement data) — resolveBranchId() must never trust
 * that blindly, or a new contact gets stamped with a cross-agency branch
 * reference.
 */
final class EntryPointBranchlessActorTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_from_property_succeeds_when_actor_has_no_branch(): void
    {
        [$agencyId, $userId] = $this->seedAgencyWithBranchlessAdmin();
        // A property whose branch_id belongs to a DIFFERENT agency entirely —
        // the exact stale-data shape found live. Must never be trusted.
        [$otherAgencyId, ] = $this->seedAgencyWithBranchlessAdmin();
        $otherAgencyBranchId = (int) DB::table('branches')->where('agency_id', $otherAgencyId)->value('id');

        $propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => 'TEST-' . Str::random(8),
            'title' => 'Test Property', 'address' => '250 Sunset Boulevard', 'suburb' => 'Uvongo',
            'price' => 1_200_000, 'status' => 'active', 'is_demo' => false,
            'agency_id' => $agencyId, 'branch_id' => $otherAgencyBranchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.entry.store-from-property', ['property' => $propertyId]), [
                'first_name' => 'Andre',
                'last_name'  => 'Roets',
                'phone'      => '0813230105',
                'email'      => 'a.roets12@example.test',
            ]);

        $resp->assertStatus(302);

        $contact = Contact::withoutGlobalScopes()->where('email', 'a.roets12@example.test')->firstOrFail();
        $this->assertSame($agencyId, (int) $contact->agency_id);
        $this->assertNotNull($contact->branch_id, 'branch_id must never be left null on a NOT-NULL column');
        $this->assertNotSame(
            $otherAgencyBranchId,
            (int) $contact->branch_id,
            'must never adopt a branch belonging to a different agency'
        );

        $this->assertSame(
            (int) DB::table('agencies')->where('id', $agencyId)->value('default_branch_id'),
            (int) $contact->branch_id,
            'falls back to the agency default branch when the actor and the property both give nothing usable'
        );
    }

    public function test_store_from_tracked_property_succeeds_when_actor_has_no_branch(): void
    {
        [$agencyId, $userId] = $this->seedAgencyWithBranchlessAdmin();
        $tp = TrackedProperty::create([
            'agency_id' => $agencyId, 'street_number' => '42', 'street_name' => 'Test Street',
            'suburb' => 'Margate', 'latitude' => -30.88, 'longitude' => 30.38,
            'status' => TrackedProperty::STATUS_ACTIVE,
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.entry.store-from-tracked-property', ['trackedProperty' => $tp->id]), [
                'first_name' => 'Nobranch',
                'last_name'  => 'Tester',
                'phone'      => '0829998888',
            ]);

        $resp->assertStatus(302);

        $contact = Contact::withoutGlobalScopes()->where('first_name', 'Nobranch')->firstOrFail();
        $this->assertNotNull($contact->branch_id);
        $this->assertSame(
            (int) DB::table('agencies')->where('id', $agencyId)->value('default_branch_id'),
            (int) $contact->branch_id
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} [agencyId, branchless super_admin userId] */
    private function seedAgencyWithBranchlessAdmin(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Head Office',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('agencies')->where('id', $agencyId)->update(['default_branch_id' => $branchId]);

        // The actual failure population: an admin with NO branch_id of their
        // own (mirrors the live user — super_admin, agency_id AND branch_id
        // both null; simplified here to agency_id set but branch_id null,
        // which is enough to exercise effectiveBranchId() returning null).
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => null, 'role' => 'super_admin',
        ]);
        User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent', 'name' => 'Agency Agent',
        ]);

        return [$agencyId, $user->id];
    }
}
