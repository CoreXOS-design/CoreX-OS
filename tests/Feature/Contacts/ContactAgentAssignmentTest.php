<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Two concerns proven here:
 *
 *  1. Duplicate detection (the contact-create warning box) must run agency-wide
 *     for EVERY role. An agent has 'own' ContactScope, so a duplicate captured
 *     by another agent was invisible to the client-side check while the
 *     server-side store() check (which bypasses the scope) still blocked the
 *     submit — a green light then a hard wall. checkDuplicate() now bypasses
 *     ContactScope and matches on agency_id, mirroring ContactDuplicateService.
 *
 *  2. Contact agent assignment — primary (reassignable) + optional co-agent,
 *     mirroring Property.agent_id / pp_second_agent_id. created_by stays the
 *     immutable capture audit.
 */
final class ContactAgentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    // ── Task 1: duplicate box works for the agent role ───────────────────

    public function test_duplicate_check_finds_agency_contact_created_by_another_agent(): void
    {
        [$agencyId, $agent, $other] = $this->seedFixture();

        $dup = $this->makeContact($agencyId, $other->id, 'Andre', 'Roets', '0825550101', 'andre@example.com');

        $resp = $this->actingAs($agent)->postJson(route('corex.contacts.check-duplicate'), [
            'phone' => '0825550101',
        ]);

        $resp->assertOk();
        $resp->assertJson(['found' => true, 'name' => 'Andre Roets']);
    }

    public function test_duplicate_check_excludes_other_agency_contact(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $otherAgencyId = $this->makeAgency();

        $this->makeContact($otherAgencyId, null, 'Foreign', 'Person', '0825550101', 'foreign@example.com');

        $resp = $this->actingAs($agent)->postJson(route('corex.contacts.check-duplicate'), [
            'phone' => '0825550101',
        ]);

        $resp->assertOk();
        $resp->assertJson(['found' => false]);
    }

    // ── Task 2: agent assignment on a contact ────────────────────────────

    public function test_update_assigns_primary_and_co_agent(): void
    {
        [$agencyId, $agent, $other] = $this->seedFixture();
        $coAgent = $this->makeUser($agencyId, 'agent');
        $contact = $this->makeContact($agencyId, $agent->id, 'Sam', 'Buyer', '0825551111', 'sam@example.com');

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload([
                'agent_id'        => $other->id,
                'second_agent_id' => $coAgent->id,
                '_from_show'      => 1,
            ]))
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertSame($other->id, $fresh->agent_id);
        $this->assertSame($coAgent->id, $fresh->second_agent_id);
    }

    public function test_co_agent_without_primary_is_collapsed(): void
    {
        [$agencyId, $agent, $other] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id, 'Sam', 'Buyer', '0825551111', 'sam@example.com');

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload([
                'agent_id'        => '',
                'second_agent_id' => $other->id,
                '_from_show'      => 1,
            ]))
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertNull($fresh->agent_id);
        $this->assertNull($fresh->second_agent_id, 'co-agent collapses when no primary');
    }

    public function test_second_agent_must_differ_from_primary(): void
    {
        [$agencyId, $agent, $other] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id, 'Sam', 'Buyer', '0825551111', 'sam@example.com');

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload([
                'agent_id'        => $other->id,
                'second_agent_id' => $other->id,
                '_from_show'      => 1,
            ]))
            ->assertSessionHasErrors('second_agent_id');
    }

    public function test_cannot_assign_agent_from_another_agency(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $foreignAgent = $this->makeUser($this->makeAgency(), 'agent');
        $contact = $this->makeContact($agencyId, $agent->id, 'Sam', 'Buyer', '0825551111', 'sam@example.com');

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload([
                'agent_id'   => $foreignAgent->id,
                '_from_show' => 1,
            ]))
            ->assertSessionHasErrors('agent_id');
    }

    public function test_new_contact_defaults_primary_agent_to_creator(): void
    {
        [$agencyId, $agent] = $this->seedFixture();

        $this->actingAs($agent)->post(route('corex.contacts.store'), [
            'first_name' => 'Fresh',
            'last_name'  => 'Lead',
            'phone'      => '0825559999',
        ])->assertSessionHasNoErrors();

        $contact = Contact::withoutGlobalScopes()->where('phone', '0825559999')->firstOrFail();
        $this->assertSame($agent->id, $contact->agent_id);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User,2:User} [agencyId, agent(own scope), other] */
    private function seedFixture(): array
    {
        $agencyId = $this->makeAgency();
        $agent = $this->makeUser($agencyId, 'agent');   // 'own' ContactScope
        $other = $this->makeUser($agencyId, 'admin');

        return [$agencyId, $agent, $other];
    }

    private function makeAgency(): int
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
        return $agencyId;
    }

    private function makeUser(int $agencyId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => $role,
        ]);
    }

    private function makeContact(int $agencyId, ?int $createdBy, string $first, string $last, string $phone, string $email): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'created_by_user_id' => $createdBy,
            'agent_id'  => $createdBy,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $phone,
            'email' => $email,
        ]);
    }

    /** Update requires the core fields; merge in the bits under test. */
    private function payload(array $extra): array
    {
        return array_merge([
            'first_name' => 'Sam',
            'last_name'  => 'Buyer',
            'phone'      => '0825551111',
        ], $extra);
    }
}
