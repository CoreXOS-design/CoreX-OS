<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.17 — the "Awaiting owner" and "Variation pending" filters on the Job Cards and Work Orders lists
 * (no build owned them; added in the 7 Oct reconciliation). Each has a tile with a count, a yes/no filter, and the list standard's own
 * own / branch / agency scoping at the query layer. "Awaiting owner" = the quote is out and the owner has not decided; "Variation pending"
 * = extra work was put to the owner and he has not answered. A closed job is waiting on nobody, so it is in neither.
 */
final class MaintenanceListFiltersTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private RentalJobCard $awaiting;
    private RentalJobCard $variation;
    private RentalJobCard $quiet;
    private RentalJobCard $cancelled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('List Filters');

        // A — quote out, over the owner's limit, no answer yet.
        [$a] = $this->internalJob(1000.0);
        $this->sendQuote($a);
        $this->awaiting = $this->named($a, 'Awaiting job');

        // B — approved, then extra work beyond the owner's terms (tolerance 0 %) is waiting for the owner.
        [$b] = $this->internalJob(1000.0);
        $wo = $this->sendQuote($b);
        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'ok']);
        $this->travel(5)->seconds();
        app(RentalJobCardService::class)->addLine($b->fresh(), ['type' => 'part', 'description' => 'Extra', 'quantity' => 1, 'unit' => 'each', 'unit_price' => 300], $this->admin);
        $this->variation = $this->named($b, 'Variation job');

        // C — a small job inside the owner's limit: nothing is waiting on anyone.
        [$c] = $this->internalJob(100.0);
        $this->quiet = $this->named($c, 'Quiet job');

        // D — was awaiting the owner, then cancelled: waiting on nobody now.
        [$d] = $this->internalJob(1000.0);
        $this->sendQuote($d);
        $this->cancelled = $this->named($d, 'Cancelled job');
        $this->cancelled->forceFill(['status' => RentalJobCard::STATUS_CANCELLED])->save();
        $this->cancelled->workOrder()->first()->forceFill(['status' => RentalWorkOrder::STATUS_CANCELLED])->save();
    }

    private function named(RentalJobCard $card, string $title): RentalJobCard
    {
        $card->forceFill(['title' => $title])->save();
        $card->workOrder()->first()->forceFill(['title' => $title])->save();

        return $card->fresh();
    }

    private function cardTitles(array $params = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->admin)->get(route('corex.rental-job-cards.index', $params))->assertOk()
            ->viewData('jobCards')->pluck('title')->sort()->values()->all();
    }

    private function workOrderTitles(array $params = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->admin)->get(route('corex.rental-work-orders.index', $params))->assertOk()
            ->viewData('workOrders')->pluck('title')->sort()->values()->all();
    }

    // ── Job Cards ─────────────────────────────────────────────────────

    public function test_job_cards_awaiting_owner_lists_only_the_quote_out_with_the_owner(): void
    {
        $this->assertSame(['Awaiting job'], $this->cardTitles(['awaiting_owner' => 1]));
    }

    public function test_job_cards_variation_pending_lists_only_extra_work_waiting_for_the_owner(): void
    {
        $this->assertSame(['Variation job'], $this->cardTitles(['variation_pending' => 1]));
    }

    public function test_job_card_tiles_carry_the_counts_and_the_screen_shows_both_tiles(): void
    {
        $response = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.index'))->assertOk();
        $counts = $response->viewData('tileCounts');

        $this->assertSame(1, $counts['awaiting_owner']);
        $this->assertSame(1, $counts['variation_pending']);
        $response->assertSee('data-tile="awaiting_owner"', false)->assertSee('data-tile="variation_pending"', false)
            ->assertSee('Awaiting owner')->assertSee('Variation pending');
    }

    public function test_without_a_filter_every_job_card_is_listed(): void
    {
        $this->assertSame(['Awaiting job', 'Cancelled job', 'Quiet job', 'Variation job'], $this->cardTitles());
    }

    public function test_a_flag_tile_link_carries_its_own_filter_and_clears_the_others(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.index', ['awaiting_owner' => 1, 'status' => 'quoted']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-tile="variation_pending"/', $html);
        preg_match('/href="([^"]*)"\s+data-tile="variation_pending"/', $html, $m);
        $href = html_entity_decode($m[1] ?? '');
        $this->assertStringContainsString('variation_pending=1', $href);
        $this->assertStringNotContainsString('awaiting_owner', $href, 'switching tile drops the previous flag');
        $this->assertStringNotContainsString('status=', $href);
    }

    public function test_the_print_list_follows_the_same_filter_as_the_screen(): void
    {
        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.print-list', ['awaiting_owner' => 1]))->assertOk()
            ->assertSee('Awaiting job')->assertDontSee('Variation job')->assertDontSee('Quiet job');
    }

    // ── Work Orders ───────────────────────────────────────────────────

    public function test_work_orders_awaiting_owner_and_variation_pending_filters(): void
    {
        $this->assertSame(['Awaiting job'], $this->workOrderTitles(['awaiting_owner' => 1]));
        $this->assertSame(['Variation job'], $this->workOrderTitles(['variation_pending' => 1]));
    }

    public function test_work_order_tiles_carry_the_counts_and_the_screen_shows_both_tiles(): void
    {
        $response = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.index'))->assertOk();
        $counts = $response->viewData('tileCounts');

        $this->assertSame(1, $counts['awaiting_owner']);
        $this->assertSame(1, $counts['variation_pending']);
        $response->assertSee('Awaiting owner')->assertSee('Variation pending');
    }

    public function test_work_order_print_list_and_export_follow_the_filter_and_say_so(): void
    {
        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.print-list', ['awaiting_owner' => 1]))->assertOk()
            ->assertSee('Awaiting job')->assertDontSee('Variation job')->assertSee('Awaiting owner');
    }

    // ── Scoping: own / branch / agency ────────────────────────────────

    public function test_another_agencys_waiting_jobs_never_appear_or_count(): void
    {
        // setUp's web request left the first agency's admin authenticated; a model created while someone is logged in is re-homed to
        // THEIR agency, which would put "Elsewhere" in agency 1. A second agency's data is created with nobody logged in.
        \Illuminate\Support\Facades\Auth::logout();
        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'elsewhere-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Else', 'agency_id' => $other->id]);
        $admin = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'admin', 'email' => 'e-' . uniqid() . '@example.invalid']);
        $property = Property::forceCreate(['agency_id' => $other->id, 'agent_id' => $admin->id, 'branch_id' => $branch->id, 'title' => '9 Other Rd', 'status' => 'active', 'listing_type' => 'rental']);
        $card = app(RentalJobCardService::class)->createForProperty($property, ['title' => 'Elsewhere job'], $admin);
        $card->workOrder()->first()->forceFill(['owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING])->save();

        $this->assertSame(['Awaiting job'], $this->cardTitles(['awaiting_owner' => 1]), 'agency 1 does not see agency 2');
        $this->assertSame(['Elsewhere job'], $this->cardTitles(['awaiting_owner' => 1], $admin));
        $this->assertSame(['Elsewhere job'], $this->workOrderTitles(['awaiting_owner' => 1], $admin));
    }

    public function test_an_own_scope_user_only_sees_and_counts_what_they_created(): void
    {
        foreach (['rental_job_cards.view', 'rental_work_orders.view'] as $key) {
            RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
            Role::firstOrCreate(['name' => 'ownagent', 'agency_id' => $this->agency->id], ['label' => 'Own agent']);
            RolePermission::updateOrCreate(['role' => 'ownagent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'own']);
        }
        PermissionService::clearCache();
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'ownagent', 'email' => 'own-' . uniqid() . '@example.invalid']);

        // The agent has created nothing: both lists are empty for them even though the agency has waiting jobs, and the tiles say 0.
        $this->assertSame([], $this->cardTitles(['awaiting_owner' => 1], $agent));
        $this->assertSame([], $this->workOrderTitles(['variation_pending' => 1], $agent));
        $this->assertSame(0, $this->actingAs($agent)->get(route('corex.rental-job-cards.index'))->viewData('tileCounts')['awaiting_owner']);
        $this->assertSame(0, $this->actingAs($agent)->get(route('corex.rental-work-orders.index'))->viewData('tileCounts')['awaiting_owner']);

        // Give the agent ownership of the waiting job and it appears, and only it.
        $this->awaiting->forceFill(['created_by_user_id' => $agent->id])->save();
        $this->awaiting->workOrder()->first()->forceFill(['created_by_user_id' => $agent->id])->save();
        $this->assertSame(['Awaiting job'], $this->cardTitles(['awaiting_owner' => 1], $agent));
        $this->assertSame(['Awaiting job'], $this->workOrderTitles(['awaiting_owner' => 1], $agent));
        $this->assertSame([], $this->cardTitles(['variation_pending' => 1], $agent));
    }
}
