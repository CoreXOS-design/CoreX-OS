<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalCrew;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrderSetting;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.23 — the Job Cards LIST to the design
 * standard: search fields, every sortable column + default, paging, tiles that
 * equal the list, the quote indicator only where it applies, the empty
 * states, and OWN/BRANCH/AGENCY scoping at the query layer for the list, the
 * tiles and the Print list alike.
 */
final class RentalJobCardListQueryTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private RentalJobCardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RJC List Query Agency', 'slug' => 'rjclq-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true, 'show_prices_on_printed_job_card' => true, 'no_approval_spend_threshold' => 100000]);
        $this->service = app(RentalJobCardService::class);
    }

    private function property(string $title, ?Branch $branch = null, ?Agency $agency = null, ?User $agent = null): Property
    {
        $agency ??= $this->agency;
        $branch ??= $this->branch;

        return Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => ($agent ?? $this->admin)->id, 'branch_id' => $branch->id,
            'title' => $title, 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function card(string $title, Property $property, array $attrs = [], ?User $by = null, float $price = 0): RentalJobCard
    {
        $by ??= $this->admin;
        $card = $this->service->createForProperty($property, ['title' => $title], $by);
        if ($price > 0) {
            $this->service->addLine($card, ['description' => 'Work', 'quantity' => 1, 'unit_price' => $price], $by);
        }
        if ($attrs) {
            $card->forceFill($attrs)->save();
        }

        return $card->fresh();
    }

    private function list(array $params = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->get(route('corex.rental-job-cards.index', $params))->assertOk();
    }

    private function titles(array $params = [], ?User $as = null): array
    {
        return $this->list($params, $as)->viewData('jobCards')->pluck('title')->all();
    }

    private function seedThree(): void
    {
        $zebra = $this->property('Zebra Road');
        $apple = $this->property('Apple Street');
        $mango = $this->property('Mango Lane');
        $crewA = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Crew A', 'created_by_user_id' => $this->admin->id]);
        $crewB = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Crew B', 'created_by_user_id' => $this->admin->id]);

        $this->card('Alpha geyser', $zebra, ['status' => 'draft', 'due_at' => null, 'rental_crew_id' => $crewB->id, 'created_at' => '2026-01-03 09:00:00'], null, 300);
        $this->card('Bravo tap', $apple, ['status' => 'scheduled', 'due_at' => '2026-10-10 09:00:00', 'rental_crew_id' => $crewA->id, 'created_at' => '2026-01-02 09:00:00'], null, 100);
        $this->card('Charlie roof', $mango, ['status' => 'completed', 'due_at' => '2026-10-05 09:00:00', 'rental_crew_id' => null, 'created_at' => '2026-01-01 09:00:00'], null, 200);
    }

    // ── Sort ────────────────────────────────────────────────────────────

    public function test_default_sort_is_due_soonest_first_with_undated_cards_last(): void
    {
        $this->seedThree();

        $this->assertSame(['Charlie roof', 'Bravo tap', 'Alpha geyser'], $this->titles());
    }

    public function test_every_sortable_column_sorts_both_ways(): void
    {
        $this->seedThree();

        $expected = [
            'property' => ['Bravo tap', 'Charlie roof', 'Alpha geyser'],   // Apple, Mango, Zebra
            'title' => ['Alpha geyser', 'Bravo tap', 'Charlie roof'],
            'crew' => ['Bravo tap', 'Alpha geyser', 'Charlie roof'],       // Crew A, Crew B, none last
            'status' => ['Alpha geyser', 'Bravo tap', 'Charlie roof'],     // draft, scheduled, completed (workflow order)
            'due_at' => ['Charlie roof', 'Bravo tap', 'Alpha geyser'],     // undated last
            'created_at' => ['Charlie roof', 'Bravo tap', 'Alpha geyser'],
            'total' => ['Bravo tap', 'Charlie roof', 'Alpha geyser'],      // 100, 200, 300
        ];
        foreach ($expected as $col => $asc) {
            $this->assertSame($asc, $this->titles(['sort' => $col, 'direction' => 'asc']), "{$col} asc");
        }

        // Descending reverses everything with a value; rows with nothing to sort on still go last.
        $this->assertSame(['Alpha geyser', 'Charlie roof', 'Bravo tap'], $this->titles(['sort' => 'property', 'direction' => 'desc']));
        $this->assertSame(['Charlie roof', 'Bravo tap', 'Alpha geyser'], $this->titles(['sort' => 'title', 'direction' => 'desc']));
        $this->assertSame(['Alpha geyser', 'Bravo tap', 'Charlie roof'], $this->titles(['sort' => 'crew', 'direction' => 'desc']));
        $this->assertSame(['Charlie roof', 'Bravo tap', 'Alpha geyser'], $this->titles(['sort' => 'status', 'direction' => 'desc']));
        $this->assertSame(['Bravo tap', 'Charlie roof', 'Alpha geyser'], $this->titles(['sort' => 'due_at', 'direction' => 'desc']));
        $this->assertSame(['Alpha geyser', 'Bravo tap', 'Charlie roof'], $this->titles(['sort' => 'created_at', 'direction' => 'desc']));
        $this->assertSame(['Alpha geyser', 'Charlie roof', 'Bravo tap'], $this->titles(['sort' => 'total', 'direction' => 'desc']));
    }

    public function test_tenant_column_sorts_and_unknown_sort_falls_back_to_default(): void
    {
        $this->seedThree();
        foreach (['Zed Zulu' => 'Alpha geyser', 'Amy Adams' => 'Bravo tap'] as $name => $title) {
            [$first, $last] = explode(' ', $name);
            $card = RentalJobCard::where('title', $title)->firstOrFail();
            $lease = Lease::create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $card->property_id,
                'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subMonths(6)->toDateString(),
                'source' => 'manual', 'created_by_user_id' => $this->admin->id,
            ]);
            $contact = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => $first, 'last_name' => $last]);
            LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);
            $card->forceFill(['lease_id' => $lease->id])->save();
        }

        $this->assertSame(['Bravo tap', 'Alpha geyser', 'Charlie roof'], $this->titles(['sort' => 'tenant', 'direction' => 'asc']));
        $this->assertSame(['Alpha geyser', 'Bravo tap', 'Charlie roof'], $this->titles(['sort' => 'tenant', 'direction' => 'desc']));
        $this->assertSame($this->titles(), $this->titles(['sort' => 'rm -rf', 'direction' => 'sideways']));
    }

    // ── Search ──────────────────────────────────────────────────────────

    public function test_search_covers_address_title_tenant_crew_and_card_number(): void
    {
        $this->seedThree();
        $alpha = RentalJobCard::where('title', 'Alpha geyser')->firstOrFail();

        $this->assertSame(['Bravo tap'], $this->titles(['q' => 'Apple']), 'property address');
        $this->assertSame(['Charlie roof'], $this->titles(['q' => 'roof']), 'title');
        $this->assertSame(['Alpha geyser'], $this->titles(['q' => 'Crew B']), 'crew');
        $this->assertSame(['Alpha geyser'], $this->titles(['q' => (string) $alpha->id]), 'bare number');
        $this->assertSame(['Alpha geyser'], $this->titles(['q' => '#' . $alpha->id]), '#number');
        $this->assertSame(['Alpha geyser'], $this->titles(['q' => 'JC-' . $alpha->id]), 'JC-number');

        $contact = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi']);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $alpha->property_id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subMonths(6)->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->admin->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);
        $alpha->forceFill(['lease_id' => $lease->id])->save();
        $this->assertSame(['Alpha geyser'], $this->titles(['q' => 'Thandi']), 'tenant first name');
        $this->assertSame(['Alpha geyser'], $this->titles(['q' => 'Thandi Nkosi']), 'tenant full name');
    }

    // ── Tiles, paging, filters ──────────────────────────────────────────

    public function test_each_tile_count_equals_the_rows_you_land_on_clicking_it_even_with_filters_applied(): void
    {
        $this->seedThree();
        $extra = $this->property('Apple Crescent');
        $this->card('Delta overdue', $extra, ['status' => 'in_progress', 'due_at' => now()->subDays(3)]);

        foreach ([[], ['q' => 'Apple']] as $base) {
            $response = $this->list($base);
            $tiles = $response->viewData('tileCounts');
            $this->assertSame($response->viewData('jobCards')->total(), $tiles['total'], 'Total tile = unfiltered-by-status list');

            foreach (['draft', 'scheduled', 'completed', 'in_progress', 'quoted', 'approved', 'cancelled'] as $status) {
                $this->assertSame($tiles[$status], $this->list($base + ['status' => $status])->viewData('jobCards')->total(), "tile {$status}");
            }
            $this->assertSame($tiles['overdue'], $this->list($base + ['overdue' => 1])->viewData('jobCards')->total(), 'tile overdue');
        }
        $this->assertSame(1, $this->list(['overdue' => 1])->viewData('tileCounts')['overdue']);
    }

    public function test_pagination_is_25_per_page_and_filters_survive_in_the_page_links(): void
    {
        $property = $this->property('Paged Place');
        for ($i = 1; $i <= 27; $i++) {
            $this->card("Card {$i}", $property);
        }

        $page1 = $this->list(['q' => 'Card', 'sort' => 'title'])->viewData('jobCards');
        $this->assertSame(27, $page1->total());
        $this->assertCount(25, $page1->items());

        $page2 = $this->list(['q' => 'Card', 'sort' => 'title', 'page' => 2]);
        $this->assertCount(2, $page2->viewData('jobCards')->items());
        $page2->assertSee('q=Card', false);
        $page2->assertSee('sort=title', false);
        $page2->assertSee('26–27 of 27', false);

        // The in-memory total sort pages too.
        $this->assertCount(2, $this->list(['sort' => 'total', 'page' => 2])->viewData('jobCards')->items());
    }

    public function test_crew_property_date_range_and_archived_filters(): void
    {
        $this->seedThree();
        $crewA = RentalCrew::where('name', 'Crew A')->firstOrFail();
        $apple = Property::where('title', 'Apple Street')->firstOrFail();

        $this->assertSame(['Bravo tap'], $this->titles(['rental_crew_id' => $crewA->id]));
        $this->assertSame(['Bravo tap'], $this->titles(['property_id' => $apple->id]));
        $this->assertSame(['Charlie roof'], $this->titles(['date_from' => '2026-10-05', 'date_to' => '2026-10-05']));

        RentalJobCard::where('title', 'Alpha geyser')->firstOrFail()->delete();
        $this->assertNotContains('Alpha geyser', $this->titles());
        $this->assertSame(['Alpha geyser'], $this->titles(['archived' => 1]));
    }

    // ── Quote indicator ─────────────────────────────────────────────────

    public function test_quote_indicator_shows_only_where_it_applies(): void
    {
        Mail::fake();
        $landlord = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Jane', 'last_name' => 'Landlord', 'email' => 'owner@example.test']);
        $property = $this->property('Quoted Place');
        \App\Services\Property\ContactPropertyLinker::link($landlord->id, $property->id, 'landlord');

        $this->card('Never quoted', $property, [], null, 100);
        $sent = $this->card('Sent unchanged', $property, [], null, 100);
        $changed = $this->card('Sent then edited', $property, [], null, 100);

        foreach ([$sent, $changed] as $card) {
            $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect();
        }
        $this->service->addLine($changed->fresh(), ['description' => 'Extra', 'quantity' => 1, 'unit_price' => 50], $this->admin);

        $html = $this->list()->getContent();
        $this->assertSame(1, substr_count($html, 'data-quote-changed'), 'Only the edited-after-sending card is flagged.');
        $this->assertSame(0, substr_count($html, 'data-quote-revision'), 'Revision tag appears only from Rev 2.');

        // Re-send: changed flag clears, Rev 2 tag appears on that card only.
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $changed->fresh()))->assertRedirect();
        $html = $this->list()->getContent();
        $this->assertSame(0, substr_count($html, 'data-quote-changed'));
        $this->assertSame(1, substr_count($html, 'data-quote-revision'));
    }

    // ── Empty states ────────────────────────────────────────────────────

    public function test_empty_states_and_no_helper_copy(): void
    {
        $this->list()->assertSee('No job cards', false)->assertSee('New Job Card')->assertDontSee('raise one from');

        $this->seedThree();
        $this->list(['q' => 'nothing-matches-this'])->assertSee('No job cards match', false)->assertSee('Clear filters');
    }

    // ── OWN / BRANCH / AGENCY scoping ───────────────────────────────────

    /** @return array{agent: User, manager: User, other: Agency} */
    private function seedScopedWorld(): array
    {
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'branch_mgr', 'label' => 'Branch manager', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'rental_job_cards.view', 'agency_id' => $this->agency->id], ['scope' => 'own']);
        RolePermission::updateOrCreate(['role' => 'branch_mgr', 'permission_key' => 'rental_job_cards.view', 'agency_id' => $this->agency->id], ['scope' => 'branch']);
        // Seeding any role rows puts the agency into explicit-permissions mode, so the admin needs its own grant too.
        RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => 'rental_job_cards.view', 'agency_id' => $this->agency->id], ['scope' => 'all']);
        PermissionService::clearCache();

        $branch2 = Branch::forceCreate(['name' => 'Second', 'agency_id' => $this->agency->id]);
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $colleague = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $remote = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch2->id, 'role' => 'agent']);
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_mgr']);

        $this->card('Mine', $this->property('P1', $this->branch, null, $agent), [], $agent);
        $this->card('Colleague same branch', $this->property('P2', $this->branch, null, $colleague), [], $colleague);
        $this->card('Other branch', $this->property('P3', $branch2, null, $remote), [], $remote);

        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $this->card('Other agency card', $this->property('P4', $otherBranch, $otherAgency, $otherAdmin), [], $otherAdmin);

        return ['agent' => $agent, 'manager' => $manager, 'other' => $otherAgency];
    }

    public function test_agent_branch_manager_and_admin_each_see_exactly_their_scope_in_list_tiles_and_print(): void
    {
        $w = $this->seedScopedWorld();

        $expect = [
            [$w['agent'], ['Mine']],
            [$w['manager'], ['Colleague same branch', 'Mine']],
            [$this->admin, ['Colleague same branch', 'Mine', 'Other branch']],
        ];
        foreach ($expect as [$user, $titles]) {
            $response = $this->list(['sort' => 'title'], $user);
            $this->assertSame($titles, $response->viewData('jobCards')->pluck('title')->all(), $user->role . ' list');
            $this->assertSame(count($titles), $response->viewData('tileCounts')['total'], $user->role . ' tile total');
            $this->assertSame(count($titles), array_sum(array_slice($response->viewData('scopeCounts'), -1)), $user->role . ' widest scope count');

            $print = $this->actingAs($user)->get(route('corex.rental-job-cards.print-list', ['sort' => 'title']))->assertOk();
            $this->assertSame($titles, $print->viewData('jobCards')->pluck('title')->all(), $user->role . ' print list');
            $print->assertDontSee('Other agency card');
        }
    }

    public function test_a_requested_scope_wider_than_the_role_ceiling_is_clamped_everywhere(): void
    {
        $w = $this->seedScopedWorld();

        $response = $this->list(['scope' => 'all'], $w['agent']);
        $this->assertSame(['Mine'], $response->viewData('jobCards')->pluck('title')->all());
        $this->assertSame('own', $response->viewData('resolvedScope'));
        $this->assertSame(['own'], $response->viewData('scopeOptions'));
        $this->assertSame(1, $response->viewData('tileCounts')['total']);

        $print = $this->actingAs($w['agent'])->get(route('corex.rental-job-cards.print-list', ['scope' => 'all']))->assertOk();
        $this->assertSame(['Mine'], $print->viewData('jobCards')->pluck('title')->all());

        // Branch manager asking for "all" gets branch, with Own/Branch counts that differ.
        $mgr = $this->list(['scope' => 'all'], $w['manager']);
        $this->assertSame('branch', $mgr->viewData('resolvedScope'));
        $this->assertSame(['own' => 0, 'branch' => 2], $mgr->viewData('scopeCounts'));
    }

    public function test_admin_scope_counts_own_branch_all_differ_and_never_include_another_agency(): void
    {
        $this->seedScopedWorld();
        $this->card('Admin own card', $this->property('P5'));

        $counts = $this->list()->viewData('scopeCounts');

        $this->assertSame(['own' => 1, 'branch' => 3, 'all' => 4], $counts);
    }
}
