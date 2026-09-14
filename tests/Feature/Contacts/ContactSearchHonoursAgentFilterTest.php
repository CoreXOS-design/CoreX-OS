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
 * Contacts index — a typed search honours the active agent filter.
 *
 * Reported 2026-09-13 (alongside the same defect on Properties): with the
 * list filtered to one agent, typing a name into the search box returned
 * contacts held by every agent. The second cut of AT-394 had made a search
 * discard any agent filter and widen to the whole agency. Ruling
 * (2026-09-13): a chosen filter — the "My Contacts" default included — is
 * honoured until the user removes it; search narrows WITHIN it. "All
 * Contacts" is the only thing that widens.
 *
 * The original AT-394 case is preserved: a plain agent (no agent picker, so
 * no filter to remove) still finds a colleague's contact by search, rendered
 * read-only, so they don't re-create a duplicate.
 */
final class ContactSearchHonoursAgentFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_stays_within_an_explicitly_picked_agent(): void
    {
        $agency = $this->makeAgency();
        $admin  = $this->makeUser($agency, 'admin');
        $agentA = $this->makeUser($agency, 'agent');
        $agentB = $this->makeUser($agency, 'agent');

        $this->makeContact($agency, $agentA->id, 'Alphaone',   'Zzzsearchable', '0825550001');
        $this->makeContact($agency, $agentB->id, 'Bravotwo',   'Zzzsearchable', '0825550002');
        $this->makeContact($agency, $admin->id,  'Adminthree', 'Zzzsearchable', '0825550003');

        $this->actingAs($admin)
            ->get(route('corex.contacts.index', [
                'agent_id' => (string) $agentA->id,
                'search'   => 'Zzzsearchable',
            ]))
            ->assertOk()
            ->assertSee('Alphaone')
            ->assertDontSee('Bravotwo')
            ->assertDontSee('Adminthree');
    }

    public function test_search_stays_within_the_my_contacts_default(): void
    {
        $agency = $this->makeAgency();
        $admin  = $this->makeUser($agency, 'admin');
        $agentA = $this->makeUser($agency, 'agent');

        $this->makeContact($agency, $agentA->id, 'Alphaone',   'Zzzsearchable', '0825550001');
        $this->makeContact($agency, $admin->id,  'Adminthree', 'Zzzsearchable', '0825550003');

        // No agent signal at all: the page lands on "My Contacts", and a search
        // from there stays on "My Contacts".
        $this->actingAs($admin)
            ->get(route('corex.contacts.index', ['search' => 'Zzzsearchable']))
            ->assertOk()
            ->assertSee('Adminthree')
            ->assertDontSee('Alphaone');
    }

    public function test_search_covers_the_agency_once_the_agent_filter_is_removed(): void
    {
        $agency = $this->makeAgency();
        $admin  = $this->makeUser($agency, 'admin');
        $agentA = $this->makeUser($agency, 'agent');
        $agentB = $this->makeUser($agency, 'agent');

        $this->makeContact($agency, $agentA->id, 'Alphaone',   'Zzzsearchable', '0825550001');
        $this->makeContact($agency, $agentB->id, 'Bravotwo',   'Zzzsearchable', '0825550002');
        $this->makeContact($agency, $admin->id,  'Adminthree', 'Zzzsearchable', '0825550003');

        // "All Contacts" submits an explicitly blank agent_id.
        $this->actingAs($admin)
            ->get(route('corex.contacts.index', [
                'agent_id' => '',
                'search'   => 'Zzzsearchable',
            ]))
            ->assertOk()
            ->assertSee('Alphaone')
            ->assertSee('Bravotwo')
            ->assertSee('Adminthree');
    }

    public function test_search_stays_within_the_unassigned_filter(): void
    {
        $agency = $this->makeAgency();
        $admin  = $this->makeUser($agency, 'admin');
        $agentA = $this->makeUser($agency, 'agent');

        $this->makeContact($agency, $agentA->id, 'Alphaone', 'Zzzsearchable', '0825550001');
        $orphan = $this->makeContact($agency, $admin->id, 'Nobodyfour', 'Zzzsearchable', '0825550004');
        Contact::withoutGlobalScopes()->whereKey($orphan->id)->update(['agent_id' => null]);

        $this->actingAs($admin)
            ->get(route('corex.contacts.index', [
                'agent_id' => 'unassigned',
                'search'   => 'Zzzsearchable',
            ]))
            ->assertOk()
            ->assertSee('Nobodyfour')
            ->assertDontSee('Alphaone');
    }

    public function test_plain_agent_search_still_finds_a_colleagues_contact_read_only(): void
    {
        $agency    = $this->makeAgency();
        $me        = $this->makeUser($agency, 'agent');
        $colleague = $this->makeUser($agency, 'agent');

        $mine   = $this->makeContact($agency, $me->id,        'Minefive',  'Zzzsearchable', '0825550005');
        $theirs = $this->makeContact($agency, $colleague->id, 'Theirssix', 'Zzzsearchable', '0825550006');

        // AT-394 — no agent picker for a plain agent, so nothing to remove: a
        // search widens to the agency, the colleague's row flagged read-only.
        $response = $this->actingAs($me)
            ->get(route('corex.contacts.index', ['search' => 'Zzzsearchable']))
            ->assertOk()
            ->assertSee('Minefive')
            ->assertSee('Theirssix');

        $restricted = $response->viewData('restrictedContactIds');
        $this->assertNotContains($mine->id,   $restricted, 'own contact must not be read-only');
        $this->assertContains($theirs->id,    $restricted, 'colleague contact must be read-only');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

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

    private function makeContact(int $agencyId, int $agentId, string $first, string $last, string $phone): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'created_by_user_id' => $agentId,
            'agent_id'           => $agentId,
            'first_name'         => $first,
            'last_name'          => $last,
            'phone'              => $phone,
        ]);
    }
}
