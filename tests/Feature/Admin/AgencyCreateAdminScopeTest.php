<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG FIX (2026-08-14) — AgencyController::store() creating a new agency's
 * first admin: BelongsToAgency::creating() force-overrides agency_id to the
 * ACTING owner's own effective agency whenever they hold an active
 * agency-switcher session (isUnscopedOwner() returns false in that state),
 * clobbering the explicit `agency_id => $agency->id` passed to User::create()
 * and landing the new admin in the CREATOR's agency instead. Root cause of a
 * real production incident ("Jaco Human" landed in production agency 1
 * instead of test agency 17).
 *
 * Fix: AgencyController::store() unconditionally re-asserts the new admin's
 * agency_id after creation via a raw column update (bypassing only the
 * BelongsToAgency hook, not the user-creation events that already fired) —
 * scoped entirely to this owner_only-gated action, no change to the shared
 * trait. The second test here proves the trait's anti-spoof guard is
 * untouched for ordinary authenticated users.
 */
final class AgencyCreateAdminScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Matches the real seed migration exactly (2026_03_06_000002_seed_existing_roles):
        // super_admin is_owner=true is the actual "System Owner" role OwnerOnly checks.
        //
        // is_owner is deliberately NOT in Role::$fillable (a privileged flag,
        // correctly guarded against ordinary mass-assignment) — so a plain
        // updateOrCreate()/firstOrCreate() silently drops it and every role
        // lands with whatever the column defaults to. forceFill()+save()
        // bypasses that guard, which is exactly appropriate here: this is
        // test fixture setup, not user input.
        foreach ([
            ['super_admin', 'System Owner', true],
            ['admin', 'Admin', false],
            ['agent', 'Agent', false],
        ] as [$name, $label, $isOwner]) {
            $role = Role::firstOrNew(['name' => $name, 'agency_id' => null]);
            $role->forceFill([
                'label' => $label, 'is_owner' => $isOwner, 'can_be_deleted' => false, 'sort_order' => 1,
            ])->save();
        }
        Role::clearCache();
    }

    public function test_new_agencys_first_admin_lands_in_the_new_agency(): void
    {
        $ownerAgency = Agency::create(['name' => 'Owner Home Agency', 'slug' => 'owner-home']);
        $owner = User::factory()->create(['agency_id' => $ownerAgency->id, 'role' => 'super_admin']);

        $resp = $this->actingAs($owner)->post(route('agencies.store'), [
            'name'             => 'Brand New Test Agency',
            'is_demo'          => '0',
            'admin_name'       => 'Jaco Human',
            'admin_email'      => 'jaco.human@example.test',
            'admin_password'   => 'password123',
        ]);

        $resp->assertRedirect();
        $newAgency = Agency::where('name', 'Brand New Test Agency')->firstOrFail();
        $admin = User::where('email', 'jaco.human@example.test')->firstOrFail();

        $this->assertSame($newAgency->id, $admin->agency_id, 'the new agency\'s first admin must land in the NEW agency');
        $this->assertNotSame($ownerAgency->id, $admin->agency_id);
    }

    public function test_new_agencys_first_admin_lands_in_new_agency_even_with_active_switcher_on_creators_own_agency(): void
    {
        // Exact repro of the reported production bug: the owner has an ACTIVE
        // agency-switcher session bound to their OWN agency (e.g. left over
        // from browsing that agency's data moments earlier) when they go to
        // create a brand new one. isUnscopedOwner() sees the override and
        // returns false, so BelongsToAgency::creating() force-stamps the
        // switched-into agency onto every model created in this request
        // UNLESS the controller explicitly re-asserts it afterward.
        $ownerAgency = Agency::create(['name' => 'Production Agency', 'slug' => 'production-agency']);
        $owner = User::factory()->create(['agency_id' => $ownerAgency->id, 'role' => 'super_admin']);

        $resp = $this->actingAs($owner)
            ->withSession(['active_agency_id' => $ownerAgency->id])
            ->post(route('agencies.store'), [
                'name'           => 'Test Agency Seventeen',
                'is_demo'        => '0',
                'admin_name'     => 'Jaco Human',
                'admin_email'    => 'jaco.human.regression@example.test',
                'admin_password' => 'password123',
            ]);

        $resp->assertRedirect();
        $newAgency = Agency::where('name', 'Test Agency Seventeen')->firstOrFail();
        $admin = User::where('email', 'jaco.human.regression@example.test')->firstOrFail();

        $this->assertSame(
            $newAgency->id,
            $admin->agency_id,
            'regression: the new admin must land in the newly-created agency, not the creator\'s switched-into agency'
        );
        $this->assertNotSame($ownerAgency->id, $admin->agency_id);
    }

    public function test_normal_authenticated_request_still_cannot_spoof_agency_id(): void
    {
        // The shared BelongsToAgency anti-spoof guard, tested directly and
        // unaffected by the fix above: an ordinary (non-owner) authenticated
        // user creating ANY agency-scoped model can never make it land in a
        // different agency, no matter what agency_id is supplied.
        $ownAgency = Agency::create(['name' => 'Agent Own Agency', 'slug' => 'agent-own']);
        $otherAgency = Agency::create(['name' => 'Someone Elses Agency', 'slug' => 'someone-elses']);
        $ownBranch = Branch::create(['agency_id' => $ownAgency->id, 'name' => 'Main']);

        $agent = User::factory()->create([
            'agency_id' => $ownAgency->id, 'branch_id' => $ownBranch->id, 'role' => 'agent',
        ]);

        $this->actingAs($agent);
        $contact = Contact::create([
            'agency_id'  => $otherAgency->id, // attempted spoof
            'branch_id'  => $ownBranch->id,
            'first_name' => 'Spoof',
            'last_name'  => 'Attempt',
            'phone'      => '0821234567',
        ]);

        $this->assertSame(
            $ownAgency->id,
            $contact->agency_id,
            'an ordinary authenticated user must never be able to spoof agency_id via mass assignment'
        );
        $this->assertNotSame($otherAgency->id, $contact->agency_id);
    }
}
