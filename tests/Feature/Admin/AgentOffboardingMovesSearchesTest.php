<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactMatchReassignment;
use App\Models\User;
use App\Services\Admin\AgentDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan, 2026-10-07 — when an agent is deleted and their contacts move to the successor,
 * those buyers' saved searches move to the new primary agent too: same transaction, each
 * move logged (contact_match_reassignments, with who / from / to / reason).
 *
 * Spec: .ai/specs/contacts.md, .ai/specs/core-matches.md (agent offboarding).
 */
final class AgentOffboardingMovesSearchesTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agent(string $name): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true, 'name' => $name,
        ]);
    }

    private function buyer(User $primary, string $last): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'B', 'last_name' => $last,
            'phone' => '082' . random_int(1000000, 9999999), 'agent_id' => $primary->id,
        ]);
    }

    private function search(Contact $contact, User $owner): ContactMatch
    {
        return ContactMatch::create([
            'agency_id' => $this->agencyId, 'contact_id' => $contact->id, 'created_by_user_id' => $owner->id,
            'agent_id' => $owner->id, 'name' => 'S ' . Str::random(3), 'listing_type' => 'sale',
            'status' => ContactMatch::STATUS_ACTIVE,
        ]);
    }

    public function test_every_search_of_a_moved_buyer_follows_the_successor_and_is_logged(): void
    {
        $admin = $this->agent('Ada Admin');
        $departing = $this->agent('Dan Departing');
        $successor = $this->agent('Sue Successor');
        $buyer = $this->buyer($departing, 'Mover');
        $s1 = $this->search($buyer, $departing);
        $s2 = $this->search($buyer, $departing);

        $counts = app(AgentDeletionService::class)->transferForOffboarding($departing, $successor, 'promote', $admin->id);

        $this->assertSame($successor->id, (int) $buyer->fresh()->agent_id);
        $this->assertSame($successor->id, (int) $s1->fresh()->agent_id);
        $this->assertSame($successor->id, (int) $s2->fresh()->agent_id);
        $this->assertSame(2, $counts['contact_searches']);

        $this->assertSame(2, ContactMatchReassignment::query()->count());
        $this->assertDatabaseHas('contact_match_reassignments', [
            'contact_match_id' => $s1->id, 'from_agent_id' => $departing->id, 'to_agent_id' => $successor->id,
            'moved_by_user_id' => $admin->id, 'reason' => 'Agent offboarded: Dan Departing → Sue Successor.',
        ]);
    }

    public function test_searches_of_buyers_who_stay_with_other_agents_are_untouched(): void
    {
        $admin = $this->agent('Ada Admin');
        $departing = $this->agent('Dan Departing');
        $successor = $this->agent('Sue Successor');
        $bystander = $this->agent('Bob Bystander');
        $theirs = $this->buyer($bystander, 'Stays');
        $stays = $this->search($theirs, $bystander);
        // A buyer whose co-agent (not primary) is the departing agent keeps their searches where they are.
        $coBuyer = $this->buyer($bystander, 'CoAgent');
        $coBuyer->forceFill(['second_agent_id' => $departing->id])->save();
        $coSearch = $this->search($coBuyer, $bystander);

        $counts = app(AgentDeletionService::class)->transferForOffboarding($departing, $successor, 'promote', $admin->id);

        $this->assertSame($bystander->id, (int) $stays->fresh()->agent_id);
        $this->assertSame($bystander->id, (int) $coSearch->fresh()->agent_id);
        $this->assertSame(0, $counts['contact_searches']);
        $this->assertSame(0, ContactMatchReassignment::query()->count());
    }

    public function test_a_search_already_owned_by_the_successor_is_not_logged_again(): void
    {
        $admin = $this->agent('Ada Admin');
        $departing = $this->agent('Dan Departing');
        $successor = $this->agent('Sue Successor');
        $buyer = $this->buyer($departing, 'Mixed');
        $already = $this->search($buyer, $successor);
        $moving = $this->search($buyer, $departing);

        $counts = app(AgentDeletionService::class)->transferForOffboarding($departing, $successor, 'promote', $admin->id);

        $this->assertSame(1, $counts['contact_searches']);
        $this->assertSame($successor->id, (int) $already->fresh()->agent_id);
        $this->assertSame($successor->id, (int) $moving->fresh()->agent_id);
        $this->assertSame(1, ContactMatchReassignment::query()->count());
    }

    public function test_the_whole_transfer_rolls_back_if_a_search_cannot_be_saved(): void
    {
        $admin = $this->agent('Ada Admin');
        $departing = $this->agent('Dan Departing');
        $successor = $this->agent('Sue Successor');
        $buyer = $this->buyer($departing, 'Rollback');
        $this->search($buyer, $departing);

        ContactMatch::saving(function (ContactMatch $m) {
            if ($m->isDirty('agent_id')) {
                throw new \RuntimeException('simulated failure');
            }
        });

        try {
            app(AgentDeletionService::class)->transferForOffboarding($departing, $successor, 'promote', $admin->id);
            $this->fail('expected the simulated failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $this->assertSame($departing->id, (int) $buyer->fresh()->agent_id, 'contact did not move');
        $this->assertSame(0, ContactMatchReassignment::query()->count(), 'no reassignment record survives');
    }
}
