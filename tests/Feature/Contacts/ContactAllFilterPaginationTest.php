<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\PerformanceSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression for the reported "All Contacts" pagination reset: an admin
 * picks the "All" agent filter (submitted as ?agent_id=), pages to page 2,
 * and the list silently reverted to their own "My Contacts" default.
 *
 * Root cause: Laravel's global ConvertEmptyStringsToNull middleware turns
 * the explicitly-blank ?agent_id= into null in $request->query(). The
 * paginator's page=2 link is built from that raw array via
 * ->withQueryString(); PHP's http_build_query() silently OMITS any
 * null-valued key, so the generated link dropped agent_id entirely. The
 * next request then saw agent_id as ABSENT rather than empty, and
 * ContactController::index()'s `$request->has('agent_id')` check re-applied
 * the "my contacts" default. Fixed via Controller::paginationQuery(), which
 * restores '' for anything the middleware nulled before the paginator's
 * ->appends() call.
 */
final class ContactAllFilterPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_contacts_filter_survives_pagination_to_page_two(): void
    {
        $agencyId = $this->makeAgency();
        $admin  = $this->makeUser($agencyId, 'admin');   // 'all' data scope -> canPickAgent
        $agentA = $this->makeUser($agencyId, 'agent');
        $agentB = $this->makeUser($agencyId, 'agent');

        // Force pagination to kick in with just two contacts.
        PerformanceSetting::set('contacts_per_page', 1, $agencyId);

        $this->makeContact($agencyId, $agentA->id, 'Alpha', 'One', '0825550001');
        $this->makeContact($agencyId, $agentB->id, 'Bravo', 'Two', '0825550002');

        // Neither contact belongs to the admin, so "My Contacts" would show NEITHER.
        $page1 = $this->actingAs($admin)
            ->get(route('corex.contacts.index', ['agent_id' => '']))
            ->assertOk();

        // Follow the exact page=2 link the server rendered, exactly as a browser
        // click would — this is what actually exercises the paginator's link
        // generation, not just the controller's read-side logic.
        preg_match('/href="([^"]*page=2[^"]*)"/', $page1->getContent(), $m);
        $this->assertNotEmpty($m, 'page 2 link not found in rendered pagination');
        $page2Url = html_entity_decode($m[1]);

        $this->assertStringContainsString(
            'agent_id=',
            $page2Url,
            'the "All Contacts" filter was dropped from the page 2 pagination link'
        );

        $page2 = $this->get($page2Url)->assertOk();

        // Whichever contact didn't fit on page 1 must still show on page 2 —
        // proving the list is still "All Contacts", not "My Contacts" (which
        // would show neither, since both belong to other agents).
        $this->assertTrue(
            str_contains($page2->getContent(), 'Alpha') || str_contains($page2->getContent(), 'Bravo'),
            'page 2 reverted to "My Contacts" instead of staying on "All Contacts"'
        );
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
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'created_by_user_id' => $agentId,
            'agent_id' => $agentId,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $phone,
        ]);
    }
}
