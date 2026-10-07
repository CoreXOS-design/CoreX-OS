<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactAuditLog;
use App\Models\ContactMatch;
use App\Models\ContactMatchReassignment;
use App\Models\PortalLead;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan, 2026-10-07 — "the primary agent is the one working with the client,
 * so when a manager reassigns a buyer to another agent the contact's primary
 * agent MUST change." Plus the false "moved to Barbara" flag: "Reassigned from
 * X to Y" is shown only when a real reassignment record exists, and a search
 * simply created by another agent says "Search created by <name>" instead.
 */
final class ContactMatchReassignPrimaryAgentTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $manager;
    private User $kym;
    private User $barbara;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Primary Agent Co', 'slug' => 'pa-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        // Real permission resolution (not the unseeded allow-all fallback): only
        // branch_manager holds core_matches.reassign, agents hold nothing.
        foreach (['core_matches.reassign', 'core_matches.view', 'core_matches.all_view', 'access_contacts'] as $key) {
            RolePermission::create(['role' => 'branch_manager', 'permission_key' => $key, 'agency_id' => null]);
        }

        $this->manager = $this->makeUser('branch_manager', 'Manny Manager');
        $this->kym     = $this->makeUser('agent', 'Kym Pollard');
        $this->barbara = $this->makeUser('agent', 'Barbara Jackson');
    }

    private function makeUser(string $role, string $name): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => $role,
            'name'      => $name,
            'is_active' => true,
        ]);
    }

    /** A buyer whose PRIMARY agent is $primary; the search is created by $creator. */
    private function makeMatch(User $primary, ?User $creator = null, array $contactOverrides = []): ContactMatch
    {
        $creator ??= $primary;
        $contact = Contact::create(array_merge([
            'agency_id'          => $this->agency->id,
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $primary->id,
            'agent_id'           => $primary->id,
            'first_name'         => 'Buyer' . random_int(100, 999),
            'last_name'          => 'Test',
            'is_buyer'           => true,
            'buyer_state'        => 'warm',
            'phone'              => '083' . random_int(1000000, 9999999),
        ], $contactOverrides));

        return ContactMatch::create([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $contact->id,
            'created_by_user_id' => $creator->id,
            'name'               => 'Test wishlist',
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
        ]);
    }

    public function test_reassigning_a_buyer_also_moves_the_contacts_primary_agent(): void
    {
        $match = $this->makeMatch($this->kym);

        $match->reassignTo($this->barbara, $this->manager, 'Kym is on leave.');

        $this->assertSame($this->barbara->id, $match->fresh()->agent_id, 'search owner moved');
        $this->assertSame($this->barbara->id, $match->contact->fresh()->agent_id, 'primary agent moved too');
        $this->assertSame(1, ContactMatchReassignment::count());
    }

    public function test_the_move_is_written_to_the_contact_history_with_who_and_from_to(): void
    {
        $match = $this->makeMatch($this->kym);

        // No logged-in user (console/queue style): the manager must still be named.
        $match->reassignTo($this->barbara, $this->manager, 'Handover.');

        $row = ContactAuditLog::where('contact_id', $match->contact_id)
            ->where('event_type', 'agent_assigned')->latest('id')->first();

        $this->assertNotNull($row, 'agent_assigned row written to the contact history');
        $this->assertSame($this->manager->id, (int) $row->user_id, 'who did it');
        $this->assertSame($this->kym->id, (int) ($row->old_values['agent_id'] ?? 0), 'from');
        $this->assertSame($this->barbara->id, (int) ($row->new_values['agent_id'] ?? 0), 'to');
    }

    public function test_the_real_route_moves_the_primary_agent_for_a_manager(): void
    {
        $match = $this->makeMatch($this->kym);

        $this->actingAs($this->manager)->post(route('corex.core-matches.reassign', $match), [
            'to_agent_id' => $this->barbara->id,
            'reason'      => 'Handover to Barbara.',
        ])->assertRedirect();

        $this->assertSame($this->barbara->id, $match->contact->fresh()->agent_id);
        $this->assertSame(
            $this->manager->id,
            (int) ContactAuditLog::where('contact_id', $match->contact_id)->where('event_type', 'agent_assigned')->latest('id')->value('user_id'),
        );
    }

    public function test_an_agent_is_still_refused_and_the_primary_agent_does_not_move(): void
    {
        $match = $this->makeMatch($this->kym);

        $this->actingAs($this->kym)->post(route('corex.core-matches.reassign', $match), [
            'to_agent_id' => $this->barbara->id,
            'reason'      => 'Trying anyway.',
        ])->assertForbidden();

        $this->assertSame($this->kym->id, $match->contact->fresh()->agent_id);
        $this->assertSame(0, ContactMatchReassignment::count());
    }

    public function test_a_co_agent_who_becomes_primary_is_collapsed_not_duplicated(): void
    {
        $match = $this->makeMatch($this->kym, null, ['second_agent_id' => $this->barbara->id]);

        $match->reassignTo($this->barbara, $this->manager, 'Barbara takes over.');

        $contact = $match->contact->fresh();
        $this->assertSame($this->barbara->id, $contact->agent_id);
        $this->assertNull($contact->second_agent_id);
    }

    public function test_the_whole_move_rolls_back_if_the_primary_agent_cannot_be_saved(): void
    {
        $match = $this->makeMatch($this->kym);

        Contact::saving(function (Contact $c) {
            if ($c->isDirty('agent_id')) {
                throw new \RuntimeException('simulated contact save failure');
            }
        });

        try {
            $match->reassignTo($this->barbara, $this->manager, 'Should not stick.');
            $this->fail('expected the simulated failure to surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated contact save failure', $e->getMessage());
        }

        $this->assertSame($this->kym->id, $match->fresh()->agent_id, 'search owner rolled back');
        $this->assertSame($this->kym->id, $match->contact->fresh()->agent_id, 'primary agent unchanged');
        $this->assertSame(0, ContactMatchReassignment::count(), 'no reassignment record without the full move');
    }

    // ── The flag on the board ────────────────────────────────────────────

    private function board(User $viewer)
    {
        return $this->actingAs($viewer)->get(route('corex.core-matches.index', ['scope' => 'agency']));
    }

    public function test_a_real_reassignment_shows_reassigned_from_x_to_y(): void
    {
        $match = $this->makeMatch($this->kym);
        $match->reassignTo($this->barbara, $this->manager, 'Handover.');

        $this->board($this->manager)->assertOk()
            ->assertSee('Reassigned', false)
            ->assertSee('from Kym Pollard', false)
            ->assertSee('to Barbara Jackson', false);
    }

    public function test_a_search_created_by_another_agent_shows_no_moved_or_created_by_flag(): void
    {
        // Primary agent Kym, but Barbara created the search — NO reassignment record.
        // Ruling A/C (2026-10-07): no inferred flag of any kind.
        $this->makeMatch($this->kym, $this->barbara);

        $this->board($this->manager)->assertOk()
            ->assertDontSee('Search created by', false)
            ->assertDontSee('Reassigned', false);
    }

    public function test_a_different_portal_lead_receiver_never_produces_a_reassigned_flag(): void
    {
        $match = $this->makeMatch($this->kym);
        PortalLead::create([
            'agency_id'           => $this->agency->id,
            'portal'              => PortalLead::PORTAL_P24,
            'lead_type'           => 'enquiry',
            'contact_id'          => $match->contact_id,
            'received_by_user_id' => $this->barbara->id,
            'name'                => 'Lead',
            'lead_source_raw'     => [],
            'received_at'         => now()->subDay(),
        ]);

        $this->board($this->manager)->assertOk()
            ->assertDontSee('Reassigned', false)
            ->assertDontSee('Search created by', false);
    }

    public function test_no_flag_at_all_when_creator_and_primary_agent_are_the_same(): void
    {
        $this->makeMatch($this->kym);

        $this->board($this->manager)->assertOk()
            ->assertDontSee('Reassigned', false)
            ->assertDontSee('Search created by', false);
    }
}
