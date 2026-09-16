<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Agency;
use App\Models\AgentActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-393 — Outreach & Canvassing board (Activity Feed tab): search over the feed
 * rows, agent picker within scope, 25-per-page pagination carrying the filters.
 * Spec: .ai/specs/outreach-canvassing-board-list.md
 *
 * Search narrows only the VISIBLE rows — the subtotal tiles stay the honest
 * window-wide breakdown (Part 4's "never blended" rule).
 */
final class OutreachCanvassingBoardListTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agentA;
    private User $agentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Board Agency', 'slug' => 'board-' . uniqid()]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agency->id, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mk = fn (string $role, string $name) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branchId, 'role' => $role, 'name' => $name, 'is_active' => true,
        ]);
        $this->admin  = $mk('admin', 'Ada Admin');
        $this->agentA = $mk('agent', 'Amos Adams');
        $this->agentB = $mk('agent', 'Bella Botha');
    }

    /** A claim event (always MIC prospecting) by $agent. */
    private function claim(User $agent, int $listingId = 1): void
    {
        AgentActivityEvent::create([
            'agency_id'    => $this->agency->id,
            'user_id'      => $agent->id,
            'event_type'   => 'claim.created',
            'subject_type' => \App\Models\ProspectingClaim::class,
            'subject_id'   => $listingId,
            'payload'      => ['listing_id' => $listingId, 'status' => 'claimed'],
            'occurred_at'  => now()->subMinutes($listingId),
            'created_at'   => now(),
        ]);
    }

    /** A comms-tile send by $agent. */
    private function commsTile(User $agent, int $contactId = 7): void
    {
        AgentActivityEvent::create([
            'agency_id'    => $this->agency->id,
            'user_id'      => $agent->id,
            'event_type'   => 'comms_tile_message.sent',
            'subject_type' => \App\Models\Contact::class,
            'subject_id'   => $contactId,
            'payload'      => ['channel' => 'whatsapp', 'contact_id' => $contactId],
            'occurred_at'  => now()->subMinutes(200 + $contactId),
            'created_at'   => now(),
        ]);
    }

    private function visit(array $query = [])
    {
        return $this->actingAs($this->admin)
            ->get(route('corex.outreach-canvassing.index', array_merge(['tab' => 'activity', 'days' => 365], $query)));
    }

    public function test_search_narrows_rows_but_never_the_subtotal_tiles(): void
    {
        $this->claim($this->agentA, 1);
        $this->claim($this->agentA, 2);
        $this->commsTile($this->agentB, 7);

        $res = $this->visit(['q' => 'comms-tile']);
        $res->assertOk();
        $html = $res->getContent();

        // Visible rows: only the comms-tile action.
        $this->assertSame(1, substr_count($html, '>Comms-tile message<'), 'one visible row');
        $this->assertStringNotContainsString('>Listing claimed<', $html);
        $this->assertStringContainsString('1 action<', $html, 'live count reflects the visible rows');

        // Tiles: still the honest window-wide breakdown (2 MIC + 0 direct + 1 comms-tile = 3).
        $this->assertStringContainsString('= 2 + 0 + 1', $html, 'subtotals untouched by search');
    }

    public function test_agent_picker_is_offered_to_all_scope_and_narrows_the_feed(): void
    {
        $this->claim($this->agentA, 1);
        $this->claim($this->agentB, 2);

        $all = $this->visit();
        $all->assertOk()->assertSee('name="agent_id"', false)->assertSee('Amos Adams')->assertSee('Bella Botha');

        $onlyB = $this->visit(['agent_id' => $this->agentB->id]);
        $onlyB->assertOk();
        $rows = substr_count($onlyB->getContent(), '>Listing claimed<');
        $this->assertSame(1, $rows, 'drill-down shows only agent B\'s claim');
        // Agent A still appears in the picker (option), but not as a row's agent cell.
        $this->assertStringNotContainsString('>Amos Adams</td>', $onlyB->getContent());
    }

    public function test_feed_is_paged_at_25_and_page_links_carry_the_search(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->claim($this->agentA, $i);
        }
        $this->commsTile($this->agentB, 9);

        $page1 = $this->visit(['q' => 'claimed']);
        $page1->assertOk();
        $this->assertSame(25, substr_count($page1->getContent(), '>Listing claimed<'), 'page 1 shows 25 rows');
        $this->assertStringNotContainsString('>Comms-tile message<', $page1->getContent());
        $this->assertStringContainsString('q=claimed&amp;page=2', $page1->getContent(), 'page-2 link keeps the search');

        $page2 = $this->visit(['q' => 'claimed', 'page' => 2]);
        $page2->assertOk();
        $this->assertSame(1, substr_count($page2->getContent(), '>Listing claimed<'), 'page 2 shows the last row');
    }
}
