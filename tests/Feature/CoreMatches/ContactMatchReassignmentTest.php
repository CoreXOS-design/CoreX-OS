<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Exceptions\CoreMatches\ReassignmentNotAuthorizedException;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactMatchReassignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-Core-Matches, Johan's ruling 1 — "only a branch manager or admin can
 * move a buyer between agents. Ever. An agent can never reassign a buyer,
 * including to themselves, by any route." Both halves proven: the model
 * method directly, AND the real HTTP route (an agent hitting it directly,
 * not just the button being hidden).
 */
class ContactMatchReassignmentTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'CM Test Agency', 'slug' => 'cm-test-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        // Real permission resolution, not the unseeded-grants-table
        // allow-all test fallback (PermissionService::allowAllWhenUnseeded())
        // — a permission-boundary test that runs against allow-all proves
        // nothing about the boundary itself. Global default grant (agency_id
        // null), matching config/corex-permissions.php's own role-default
        // shape for core_matches.reassign. 'agent' gets no row — absence is
        // the denial.
        RolePermission::create([
            'role'           => 'branch_manager',
            'permission_key' => 'core_matches.reassign',
            'agency_id'      => null,
        ]);
    }

    private function makeUser(string $roleName): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => $roleName,
        ]);
    }

    private function makeContact(User $createdBy): Contact
    {
        return Contact::create([
            'agency_id'          => $this->agency->id,
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $createdBy->id,
            'first_name'         => 'Buyer',
            'last_name'          => 'Test',
            'phone'              => '083' . random_int(1000000, 9999999),
        ]);
    }

    private function makeMatch(User $createdBy, ?int $agentId = null): ContactMatch
    {
        $contact = $this->makeContact($createdBy);

        return ContactMatch::create([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $contact->id,
            'created_by_user_id' => $createdBy->id,
            'agent_id'           => $agentId ?? $createdBy->id,
            'name'               => 'Test wishlist',
            'listing_type'       => 'sale',
        ]);
    }

    public function test_agent_cannot_reassign_via_the_model_method(): void
    {
        $agent   = $this->makeUser('agent');
        $other   = $this->makeUser('agent');
        $match   = $this->makeMatch($agent);

        $this->expectException(ReassignmentNotAuthorizedException::class);
        $match->reassignTo($other, $agent, 'Trying to move it myself.');
    }

    public function test_branch_manager_can_reassign_via_the_model_method(): void
    {
        $manager = $this->makeUser('branch_manager');
        $agentA  = $this->makeUser('agent');
        $agentB  = $this->makeUser('agent');
        $match   = $this->makeMatch($agentA);

        $record = $match->reassignTo($agentB, $manager, 'Agent A left the agency.');

        $this->assertInstanceOf(ContactMatchReassignment::class, $record);
        $match->refresh();
        $this->assertSame($agentB->id, $match->agent_id);
        $this->assertSame($agentA->id, $record->from_agent_id);
        $this->assertSame($agentB->id, $record->to_agent_id);
        $this->assertSame($manager->id, $record->moved_by_user_id);
        $this->assertSame('Agent A left the agency.', $record->reason);
    }

    public function test_empty_reason_is_rejected(): void
    {
        $manager = $this->makeUser('branch_manager');
        $agentA  = $this->makeUser('agent');
        $agentB  = $this->makeUser('agent');
        $match   = $this->makeMatch($agentA);

        $this->expectException(\InvalidArgumentException::class);
        $match->reassignTo($agentB, $manager, '   ');
    }

    public function test_agent_hitting_the_route_directly_is_refused_not_just_hidden(): void
    {
        $agent  = $this->makeUser('agent');
        $agentB = $this->makeUser('agent');
        $match  = $this->makeMatch($agent);

        $response = $this->actingAs($agent)->post(route('corex.core-matches.reassign', $match), [
            'to_agent_id' => $agentB->id,
            'reason'      => 'Trying anyway, direct URL.',
        ]);

        $response->assertForbidden();
        $match->refresh();
        $this->assertSame($agent->id, $match->agent_id, 'agent_id must not have moved');
        $this->assertSame(0, ContactMatchReassignment::count(), 'no audit row for a refused attempt');
    }

    public function test_branch_manager_hitting_the_route_succeeds(): void
    {
        $manager = $this->makeUser('branch_manager');
        $agentA  = $this->makeUser('agent');
        $agentB  = $this->makeUser('agent');
        $match   = $this->makeMatch($agentA);

        $response = $this->actingAs($manager)->post(route('corex.core-matches.reassign', $match), [
            'to_agent_id' => $agentB->id,
            'reason'      => 'Handover to Agent B.',
        ]);

        $response->assertRedirect();
        $match->refresh();
        $this->assertSame($agentB->id, $match->agent_id);
        $this->assertSame(1, ContactMatchReassignment::count());
    }
}
