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
 * AT-273 — the Street & Complex (property) search must run at the caller's FULL
 * contact-visibility scope, NOT the contacts-list "My Contacts" per-agent
 * narrowing.
 *
 * The bug: the search inherited the list's default agent filter (the caller's
 * own id). Property matches are almost always owned by OTHER agents, so a user
 * who ran a property search without first flipping the list to "All Contacts"
 * got ~0 results. The fix pins the search to full visibility scope and ignores
 * any inherited ?agent_id.
 */
final class StreetComplexSearchScopeTest extends TestCase
{
    use RefreshDatabase;

    /** A full-scope (agency-wide) user's property search returns a match owned by another agent. */
    public function test_full_scope_search_returns_other_agents_matching_contact(): void
    {
        [$agencyId, $viewer, $otherAgent] = $this->seedFixture();

        $this->makeAddressedContact($agencyId, $otherAgent->id, 'Owned', 'ByOther', 'Seaview Estate');

        $this->actingAs($viewer)
            ->get(route('corex.contacts.street-complex-search', ['q' => 'Seaview']))
            ->assertOk()
            ->assertSee('ByOther');
    }

    /**
     * The regression lock: even when the OLD inherited "My Contacts" agent_id is
     * still present on the URL, the property search must ignore it and return the
     * other agent's match. Before the fix this narrowed to `where agent_id = me`
     * and returned nothing.
     */
    public function test_inherited_agent_id_is_ignored_and_does_not_narrow_the_search(): void
    {
        [$agencyId, $viewer, $otherAgent] = $this->seedFixture();

        $this->makeAddressedContact($agencyId, $otherAgent->id, 'Owned', 'ByOther', 'Seaview Estate');

        $this->actingAs($viewer)
            ->get(route('corex.contacts.street-complex-search', ['q' => 'Seaview', 'agent_id' => $viewer->id]))
            ->assertOk()
            ->assertSee('ByOther');
    }

    /** An 'own'-scope agent stays isolated: they see their own match, not another agent's. */
    public function test_own_scope_agent_remains_isolated(): void
    {
        [$agencyId, , $otherAgent] = $this->seedFixture();
        $agent = $this->makeUser($agencyId, 'agent'); // 'own' ContactScope

        $this->makeAddressedContact($agencyId, $agent->id, 'Mine', 'OwnMatch', 'Seaview Estate');
        $this->makeAddressedContact($agencyId, $otherAgent->id, 'Theirs', 'HiddenMatch', 'Seaview Estate');

        $this->actingAs($agent)
            ->get(route('corex.contacts.street-complex-search', ['q' => 'Seaview']))
            ->assertOk()
            ->assertSee('OwnMatch')
            ->assertDontSee('HiddenMatch');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User,2:User} [agencyId, viewer(all scope), otherAgent(own)] */
    private function seedFixture(): array
    {
        $agencyId    = $this->makeAgency();
        $viewer      = $this->makeUser($agencyId, 'admin'); // resolves to 'all' contacts scope
        $otherAgent  = $this->makeUser($agencyId, 'agent');

        return [$agencyId, $viewer, $otherAgent];
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

    private function makeAddressedContact(int $agencyId, int $ownerId, string $first, string $last, string $complex): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'created_by_user_id' => $ownerId,
            'agent_id'           => $ownerId,
            'first_name'         => $first,
            'last_name'          => $last,
            'phone'              => '082' . random_int(1000000, 9999999),
            'complex_name'       => $complex,
        ]);
    }
}
