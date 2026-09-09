<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design-standard audit, 2026-09-09 — Johan: "search must cover what an RO
 * or CO would actually type: applicant name, property, agent." The
 * authoriser queue (RentalApplicationAuthorisationController::index()) had
 * neither before this — pins both, reusing FiltersRentalApplicationList,
 * the exact logic RentalApplicationCrudStandardTest already pins for
 * index()/returned().
 */
final class RentalApplicationAuthorisationQueueSearchSortTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
    }

    /**
     * role 'admin' matches every real RO/CO user on the actual Home Finders
     * Coastal agency today (confirmed by direct query, 2026-09-09) — RO/CO
     * is a tier bolted onto a user via the agency's id-list columns, not a
     * role itself, but in practice every current holder is an admin. Also
     * sidesteps ContactScope's role-based 'own' fallback (a plain 'agent'
     * only sees contacts THEY created; admin bypasses that scope entirely)
     * — that fallback is test-suite-only and unreachable on a real server
     * (see PermissionService::getDataScope()'s AT-265 docblock), so an
     * 'agent'-role reviewer here would fail this test for a reason that
     * cannot happen in production — not a real scoping gap, just the wrong
     * persona for this test.
     */
    private function ro(): User
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->agency->update(['rental_application_ro_user_ids' => [$user->id]]);

        return $user;
    }

    private function pending(User $creator, string $lastName, string $propertyOverride, \DateTimeInterface $submittedAt): RentalApplication
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Test', 'last_name' => $lastName, 'email' => strtolower($lastName) . '-' . uniqid() . '@example.co.za',
        ]);

        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $creator->id, 'status' => 'under_assessment',
            'submitted_for_approval_at' => $submittedAt, 'property_address_override' => $propertyOverride,
        ]);
    }

    public function test_search_matches_applicant_property_and_agent(): void
    {
        $reviewer = $this->ro();
        $agentSmith = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Agent Smith']);
        $agentJones = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Agent Jones']);

        $this->pending($agentSmith, 'Ndlovu', '19 Windsor Avenue', now());
        $this->pending($agentJones, 'Khumalo', 'Somewhere else entirely', now());

        $this->actingAs($reviewer)->get(route('corex.rental-applications.authorisation.index', ['q' => 'Ndlovu']))
            ->assertOk()->assertSee('Ndlovu')->assertDontSee('Khumalo');

        $this->actingAs($reviewer)->get(route('corex.rental-applications.authorisation.index', ['q' => 'Windsor Avenue']))
            ->assertOk()->assertSee('Windsor Avenue')->assertDontSee('Somewhere else entirely');

        $this->actingAs($reviewer)->get(route('corex.rental-applications.authorisation.index', ['q' => 'Agent Smith']))
            ->assertOk()->assertSee('Ndlovu')->assertDontSee('Khumalo');
    }

    public function test_search_with_no_matches_shows_a_real_empty_state(): void
    {
        $reviewer = $this->ro();
        $this->pending($reviewer, 'Ndlovu', '19 Windsor Avenue', now());

        $this->actingAs($reviewer)->get(route('corex.rental-applications.authorisation.index', ['q' => 'ZZZNoMatch']))
            ->assertOk()->assertSee('No applications awaiting your decision match this search');
    }

    public function test_default_order_is_oldest_submitted_first(): void
    {
        $reviewer = $this->ro();
        $this->pending($reviewer, 'Newer', 'Newer Property', now());
        $this->pending($reviewer, 'Older', 'Older Property', now()->subDays(3));

        $content = $this->actingAs($reviewer)->get(route('corex.rental-applications.authorisation.index'))->getContent();

        $this->assertLessThan(strpos($content, 'Newer Property'), strpos($content, 'Older Property'), 'the oldest pending decision must render first by default');
    }

    public function test_sort_by_agent_changes_row_order(): void
    {
        $reviewer = $this->ro();
        $agentA = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Aaron Agent']);
        $agentZ = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Zola Agent']);

        $this->pending($agentZ, 'RowZ', 'Property Z', now());
        $this->pending($agentA, 'RowA', 'Property A', now());

        $ascending = $this->actingAs($reviewer)->get(route('corex.rental-applications.authorisation.index', ['sort' => 'agent', 'direction' => 'asc']))->getContent();
        $this->assertLessThan(strpos($ascending, 'Property Z'), strpos($ascending, 'Property A'));
    }

    /**
     * cc5 regression pass, 2026-09-10 — Johan: "hardcodes paginate(20) and
     * ignores the per_page parameter the other two screens on your shared
     * trait both honour — confirmed live, ?per_page=5 still returned 20
     * rows." Pins the fix: same clamp, same options, as index()/returned().
     */
    public function test_per_page_can_be_set_and_is_honoured(): void
    {
        $reviewer = $this->ro();
        foreach (range(1, 12) as $i) {
            $this->pending($reviewer, "Row{$i}", "PP Property {$i}", now()->subMinutes($i));
        }

        $ten = $this->actingAs($reviewer)->get(route('corex.rental-applications.authorisation.index', ['per_page' => 10]));
        $ten->assertOk();
        $this->assertSame(10, substr_count($ten->getContent(), 'PP Property'));

        $twentyFiveDefault = $this->actingAs($reviewer)->get(route('corex.rental-applications.authorisation.index'));
        $twentyFiveDefault->assertOk();
        $this->assertSame(12, substr_count($twentyFiveDefault->getContent(), 'PP Property'), 'default per_page (25) must show all 12 on one page');
    }

    public function test_per_page_is_clamped_to_the_ten_to_one_hundred_range(): void
    {
        $reviewer = $this->ro();
        $this->pending($reviewer, 'Clamped', 'Clamp Property', now());

        // Below the floor clamps to 10, not an error and not "0 per page".
        $this->actingAs($reviewer)->get(route('corex.rental-applications.authorisation.index', ['per_page' => 1]))->assertOk();
        // Above the ceiling clamps to 100, not an unbounded query.
        $this->actingAs($reviewer)->get(route('corex.rental-applications.authorisation.index', ['per_page' => 99999]))->assertOk();
    }
}
