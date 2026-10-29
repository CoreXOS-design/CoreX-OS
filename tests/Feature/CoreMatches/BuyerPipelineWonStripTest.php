<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Buyer Pipeline, Kanban fits the screen (Johan, 2026-10-29): Won / Success is a collapsed strip at the bottom
 * (header + count) that opens in place into a dense one-line-per-buyer list — name · agent · last activity ·
 * property · status — never cards; the open/closed choice is remembered per user; the cards and their actions
 * in the four working columns behave exactly as before. How the four columns own the screen height at
 * 1366x768 / 1920x1080 is proved in a real browser by scripts/buyer-pipeline-fit-check.mjs.
 *
 * Spec: .ai/specs/buyer-pipeline.md ("Kanban fits the screen").
 */
final class BuyerPipelineWonStripTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Won Strip Agency', 'slug' => 'ws-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'name' => 'Sarah Nkosi', 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => 'agent', 'is_active' => true,
        ]);

        foreach (['access_contacts' => null, 'contacts.view' => 'all', 'access_core_matches' => null,
                  'core_matches.view' => 'all', 'core_matches.all_view' => null, 'core_matches.reassign' => null] as $key => $scope) {
            RolePermission::updateOrCreate(
                ['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id],
                ['scope' => $scope],
            );
        }
        PermissionService::clearCache();
        Role::clearCache();
        PermissionService::forceProductionPosture();
    }

    public function test_won_is_a_collapsed_strip_with_the_count_and_not_a_card_grid(): void
    {
        $this->buyer('Warmie', 'warm');
        $this->buyer('Winner', 'won');
        $this->buyer('Another', 'won');

        $html = $this->actingAs($this->agent)->get(route('command-center.buyers.pipeline', ['scope' => 'agency']))->assertOk()->getContent();

        $this->assertStringContainsString('data-bp-won-toggle', $html, 'the strip header is the toggle');
        $this->assertStringContainsString(':aria-expanded="open', $html);
        $this->assertMatchesRegularExpression('/Won \/ Success<\/span>\s*<span class="ds-badge ds-badge-pill ds-badge-success">2<\/span>/', $html, 'header + count');
        $this->assertMatchesRegularExpression('/id="bp-won-body"[^>]*style="display: none;"/', $html, 'closed until the user opens it');
        $this->assertStringContainsString('<table class="bp-won-table">', $html, 'a list, not cards');
        $this->assertStringNotContainsString('xl:grid-cols-3', $html, 'the old 3-wide Won card grid is gone');
        $this->assertStringNotContainsString('max-h-[30vh]', $html, 'and so is the 30vh block that squeezed the columns');
    }

    public function test_the_open_or_closed_choice_is_remembered_per_user(): void
    {
        $html = $this->actingAs($this->agent)->get(route('command-center.buyers.pipeline'))->assertOk()->getContent();

        $this->assertStringContainsString('x-data="bpWon(' . $this->agent->id . ')"', $html, 'the strip is keyed by the signed-in user');
        $this->assertStringContainsString("'corex.buyerPipeline.wonOpen.' + userId", $html, 'one key per user, so two people on one browser never share a choice');
        $this->assertStringContainsString('window.localStorage.setItem(key', $html, 'every toggle is saved');
        $this->assertStringContainsString('window.localStorage.getItem(key', $html, 'and read back on load');
        $this->assertStringContainsString('open: false', $html, 'collapsed is the default');
    }

    public function test_the_won_list_is_one_line_per_buyer_with_name_agent_date_property_and_status(): void
    {
        $a = $this->buyer('Winner', 'won');
        $b = $this->buyer('Handmade', 'won');         // moved to Won by hand: no deal
        $c = $this->buyer('FellThrough', 'won');       // only a declined deal: no live property
        $this->deal($a, '12 Marine Drive, Uvongo', 'P');
        $this->deal($a, '99 Newer Street, Margate', 'G');   // newest live deal wins
        $this->deal($c, '5 Declined Road, Shelly Beach', 'D');

        $html = $this->actingAs($this->agent)->get(route('command-center.buyers.pipeline', ['scope' => 'agency']))->assertOk()->getContent();
        $won = substr($html, strpos($html, 'data-bp-won'));

        $this->assertStringContainsString('<th>Name</th><th>Agent</th><th>Last activity</th><th>Property</th><th>Status</th>', $won);
        $this->assertSame(3, substr_count($won, '<tr>') - 1, 'one row per Won buyer (minus the header row)');
        $this->assertStringContainsString('Winner Pipe', $won);
        $this->assertStringContainsString('Sarah Nkosi', $won);
        $this->assertStringContainsString('99 Newer Street, Margate', $won);
        $this->assertStringNotContainsString('12 Marine Drive, Uvongo', $won, 'the newest live deal is the one shown');
        $this->assertStringNotContainsString('5 Declined Road, Shelly Beach', $won, 'a declined deal is not a live property');
        $this->assertSame(3, substr_count($won, 'ds-badge ds-badge-success text-[10px]'), 'a Won status on every line');
        $this->assertStringContainsString(route('command-center.buyers.show', $b), $won, 'the name opens the buyer');
    }

    public function test_an_empty_won_strip_still_shows_with_zero_and_the_plain_message(): void
    {
        $this->buyer('Warmie', 'warm');

        $html = $this->actingAs($this->agent)->get(route('command-center.buyers.pipeline'))->assertOk()->getContent();

        $this->assertStringContainsString('data-bp-won', $html);
        $this->assertMatchesRegularExpression('/Won \/ Success<\/span>\s*<span class="ds-badge ds-badge-pill ds-badge-success">0<\/span>/', $html);
        $this->assertStringContainsString('No won buyers yet. When a buyer is linked to a deal in the Deal Register, they move here automatically.', $html);
        $this->assertStringNotContainsString('bp-won-table"', $html, 'no empty table');
    }

    public function test_another_agencys_won_buyer_is_never_listed(): void
    {
        $this->buyer('Mine', 'won');
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oa-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        Contact::create([
            'agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'first_name' => 'Foreign', 'last_name' => 'Winner',
            'is_buyer' => true, 'buyer_state' => 'won',
        ]);
        Contact::withoutGlobalScopes()->where('first_name', 'Foreign')->update(['buyer_state' => 'won', 'agency_id' => $other->id]);

        $html = $this->actingAs($this->agent)->get(route('command-center.buyers.pipeline', ['scope' => 'agency']))->assertOk()->getContent();

        $this->assertStringContainsString('Mine Pipe', $html);
        $this->assertStringNotContainsString('Foreign Winner', $html);
    }

    public function test_the_cards_and_their_actions_in_the_four_working_columns_are_unchanged(): void
    {
        $warm = $this->buyer('Warmie', 'warm');
        $this->buyer('Newbie', 'new');
        $this->buyer('Chilly', 'cold');
        $this->buyer('Gone', 'lost');

        $html = str_replace('\\/', '/', $this->actingAs($this->agent)->get(route('command-center.buyers.pipeline', ['scope' => 'agency']))->assertOk()->getContent());

        foreach (['New', 'Warm', 'Cold', 'Lost'] as $label) {
            $this->assertStringContainsString('>' . $label . '</span>', $html, "$label column");
        }
        foreach (['Warmie Pipe', 'Newbie Pipe', 'Chilly Pipe', 'Gone Pipe'] as $name) {
            $this->assertStringContainsString($name, $html);
        }
        $this->assertSame(4, substr_count($html, 'draggable="true"'), 'every card can still be dragged');
        $this->assertSame(4, substr_count($html, 'Schedule Viewing'));
        $this->assertSame(4, preg_match_all('/>\s*View Matches\s*</', $html), 'the buyer has a wishlist, so every card offers View Matches');
        $this->assertSame(4, substr_count($html, 'Move to another agent</button>'), 'the manager move button is on every card');
        $this->assertStringContainsString(route('command-center.buyers.show', $warm), $html);
        $this->assertStringContainsString('data-tour="buyers-board"', $html);
        $this->assertStringContainsString('class="w-full flex flex-col gap-5 bp-kanban md:h-full md:min-h-0"', $html, 'the Kanban page is the fixed-height block');
    }

    public function test_the_list_view_is_untouched_and_the_page_still_flows_normally(): void
    {
        $this->buyer('Warmie', 'warm');

        $html = $this->actingAs($this->agent)->get(route('command-center.buyers.pipeline', ['view' => 'list', 'scope' => 'agency']))->assertOk()->getContent();

        $this->assertStringContainsString('Warmie Pipe', $html);
        $this->assertStringNotContainsString('gap-5 bp-kanban', $html, 'list view is not the fixed-height block');
        $this->assertStringNotContainsString('data-tour="buyers-board"', $html);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function buyer(string $first, string $state): Contact
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'first_name' => $first, 'last_name' => 'Pipe', 'is_buyer' => true, 'buyer_state' => $state,
        ]);
        ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'name' => 'Wishlist', 'listing_type' => 'sale', 'status' => ContactMatch::STATUS_ACTIVE,
            'price_min' => 1_000_000, 'price_max' => 2_000_000, 'beds_min' => 2, 'property_types' => ['House'],
        ]);
        Contact::withoutGlobalScopes()->whereKey($contact->id)->update(['buyer_state' => $state, 'last_activity_at' => now()]);

        return $contact->fresh();
    }

    private function deal(Contact $buyer, string $address, string $status): void
    {
        $dealId = DB::table('deals')->insertGetId([
            'agency_id' => $this->agency->id, 'period' => '2026-10', 'deal_date' => '2026-10-20',
            'property_value' => 1_500_000, 'total_commission' => 0, 'property_address' => $address,
            'accepted_status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_contacts')->insert([
            'deal_id' => $dealId, 'contact_id' => $buyer->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
