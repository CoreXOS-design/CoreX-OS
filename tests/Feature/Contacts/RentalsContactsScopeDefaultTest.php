<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactType;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * cc4 walk, finding 8 + tasks A/B, 2026-09-13 — the real Rentals → Contacts
 * screen (corex.rentals.contacts.index, AT-403). Johan: an agency-scoped
 * user landed on a hard empty "Mine" view on first load despite real
 * agency-wide contacts existing; the scope pill offered no explicit Branch
 * option at all. Every behaviour here is gated on the rentals-lens route
 * name — the plain corex.contacts.index screen must be completely
 * unaffected, which the last test in this file pins directly.
 */
final class RentalsContactsScopeDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function makeLesseeContact(Agency $agency, Branch $branch, User $creator, string $lastName): Contact
    {
        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'created_by_user_id' => $creator->id,
            'first_name' => 'Test', 'last_name' => $lastName, 'email' => strtolower($lastName) . '-' . uniqid() . '@example.test',
        ]);
        $lessee = ContactType::where('esign_role', 'lessee')->first();
        if ($lessee) {
            $contact->parentTypes()->syncWithoutDetaching([$lessee->id]);
        }

        return $contact;
    }

    public function test_agency_scoped_user_lands_on_agency_wide_view_by_default(): void
    {
        $agency = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $other = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->makeLesseeContact($agency, $branch, $other, 'NotMine');

        $response = $this->actingAs($admin)->get(route('corex.rentals.contacts.index'));

        $response->assertOk();
        $response->assertSee('NotMine');
    }

    public function test_branch_pill_offered_and_scoped_when_split_branches_enabled(): void
    {
        $agency = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b-' . uniqid(), 'split_branches_enabled' => true]);
        $branch1 = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch 1']);
        $branch2 = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch 2']);
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'contacts.view', 'agency_id' => $agency->id, 'scope' => 'all']);
        $bm = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch1->id, 'role' => 'branch_manager']);
        $sameBranch = $this->makeLesseeContact($agency, $branch1, $bm, 'SameBranch');
        $otherBranch = $this->makeLesseeContact($agency, $branch2, $bm, 'OtherBranch');

        $response = $this->actingAs($bm)->get(route('corex.rentals.contacts.index'));

        $response->assertOk();
        $response->assertSee('>Branch<', false);
        $response->assertDontSee('>Agency<', false);
        $response->assertSee('SameBranch');
        $response->assertDontSee('OtherBranch');

        // Detail view, query layer: cross-branch is a real 404, own-branch is a real 200.
        $this->actingAs($bm)->get(route('corex.contacts.show', $otherBranch))->assertNotFound();
        $this->actingAs($bm)->get(route('corex.contacts.show', $sameBranch))->assertOk();
    }

    public function test_own_scoped_user_sees_only_their_own_contact_and_no_pill(): void
    {
        $agency = Agency::create(['name' => 'Agency C', 'slug' => 'agency-c-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch C']);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'contacts.view', 'agency_id' => $agency->id, 'scope' => 'own']);
        $me = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $colleague = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $mine = $this->makeLesseeContact($agency, $branch, $me, 'MineOnly');
        $theirs = $this->makeLesseeContact($agency, $branch, $colleague, 'TheirsOnly');

        $response = $this->actingAs($me)->get(route('corex.rentals.contacts.index'));

        $response->assertOk();
        $response->assertSee('MineOnly');
        $response->assertDontSee('TheirsOnly');
        $response->assertDontSee('inline-flex rounded-md overflow-hidden', false);

        $this->actingAs($me)->get(route('corex.contacts.show', $theirs))->assertNotFound();
    }

    public function test_main_contacts_screen_default_is_completely_unchanged(): void
    {
        $agency = Agency::create(['name' => 'Agency D', 'slug' => 'agency-d-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch D']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $other = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'created_by_user_id' => $other->id,
            'first_name' => 'Other', 'last_name' => 'AgentsContact', 'email' => 'other-' . uniqid() . '@example.test',
        ]);

        // Unchanged pre-existing behaviour: the plain Contacts screen still
        // defaults to "Mine" on first load, even for an agency-scoped admin.
        $response = $this->actingAs($admin)->get(route('corex.contacts.index'));

        $response->assertOk();
        $response->assertDontSee('AgentsContact');
    }
}
