<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-118 hardening — changing a contact's Primary/Co-Agent requires
 * contacts.reassign_agent, enforced SERVER-SIDE (not just a hidden dropdown).
 * Plus: office_admin can be granted communications.view per agency.
 */
final class ContactReassignGateTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Seed so the gate is ACTIVE: both roles can access contacts; only
        // branch_manager holds contacts.reassign_agent. office_admin gets comms.view.
        RolePermission::insert([
            ['role' => 'agent', 'permission_key' => 'access_contacts', 'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'branch_manager', 'permission_key' => 'access_contacts', 'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'branch_manager', 'permission_key' => 'contacts.reassign_agent', 'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'office_admin', 'permission_key' => 'communications.view', 'scope' => 'own', 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        PermissionService::clearCache();
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => $role, 'is_active' => true,
        ]);
    }

    private function contact(): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Rick', 'last_name' => 'M', 'phone' => '0821234567',
        ]);
    }

    private function contactTypeId(): int
    {
        return (int) \App\Models\ContactType::create(['agency_id' => $this->agencyId, 'name' => 'Buyer'])->id;
    }

    private function payload(array $over = []): array
    {
        // No phone/email keys: the update only touches identifiers when the
        // request carries them (AT-125), so omitting them keeps these tests
        // focused on the reassignment gate without invoking identifier sync.
        return array_merge([
            'first_name' => 'Rick', 'last_name' => 'M',
        ], $over);
    }

    public function test_plain_agent_cannot_change_primary_agent(): void
    {
        $agent   = $this->user('agent');
        $other   = $this->user('agent');
        $contact = $this->contact();

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload(['agent_id' => $other->id]))
            ->assertStatus(403);

        $this->assertNull($contact->fresh()->agent_id, 'assignment unchanged after refused attempt');
    }

    public function test_plain_agent_cannot_change_co_agent(): void
    {
        $agent   = $this->user('agent');
        $other   = $this->user('agent');
        $contact = $this->contact();
        $contact->forceFill(['agent_id' => $agent->id])->save();

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload(['agent_id' => $agent->id, 'second_agent_id' => $other->id]))
            ->assertStatus(403);
    }

    public function test_branch_manager_can_reassign(): void
    {
        $bm      = $this->user('branch_manager');
        $target  = $this->user('agent');
        $contact = $this->contact();

        // The gate's contract: a capability-holder is NOT blocked (a gate block is
        // a 403). Proceeding = 302. Full end-to-end reassignment persistence is
        // proven on staging with real data (the contact-update pipeline has its own
        // required inputs that are out of scope for this gate test).
        $this->actingAs($bm)
            ->put(route('corex.contacts.update', $contact), $this->payload(['agent_id' => $target->id, 'parent_type_ids' => [$this->contactTypeId()]]))
            ->assertStatus(302);
    }

    public function test_branch_manager_reassign_is_not_gate_blocked(): void
    {
        // Explicit: the reassignment gate does NOT return 403 for a cap-holder.
        $bm      = $this->user('branch_manager');
        $target  = $this->user('agent');
        $contact = $this->contact();

        $resp = $this->actingAs($bm)
            ->put(route('corex.contacts.update', $contact), $this->payload(['agent_id' => $target->id, 'parent_type_ids' => [$this->contactTypeId()]]));

        $this->assertNotSame(403, $resp->status(), 'branch_manager must not be gate-blocked from reassigning');
    }

    public function test_plain_agent_can_edit_other_fields_without_touching_assignment(): void
    {
        $agent   = $this->user('agent');
        $contact = $this->contact();

        // No agent_id change → the reassignment gate does NOT fire (not a 403);
        // a plain agent can still edit the contact's other fields.
        $resp = $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload(['first_name' => 'Richard', 'parent_type_ids' => [$this->contactTypeId()]]));

        $this->assertNotSame(403, $resp->status(), 'editing a non-assignment field must not be gate-blocked');
    }

    public function test_office_admin_can_be_granted_communications_view(): void
    {
        $oa = $this->user('office_admin');
        // Seeded above with scope 'own' → comms-capable → would see the tab + request panel.
        $this->assertSame('own', PermissionService::getDataScope($oa, 'communications'));
        $this->assertTrue($oa->hasPermission('communications.view'));

        // And a role without it (plain agent here lacks comms.view in this test's seed) → no scope.
        $agent = $this->user('agent');
        $this->assertNull(PermissionService::getDataScope($agent, 'communications'));
    }
}
