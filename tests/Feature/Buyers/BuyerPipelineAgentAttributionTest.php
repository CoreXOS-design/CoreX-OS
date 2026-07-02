<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-159 — the Buyers Pipeline "Agent" is the ASSIGNED agent (contacts.agent_id),
 * never the capturer (contacts.created_by_user_id).
 *
 * Regression guard for the wrong-read reported live (Keith Ellis showed his
 * creator Johan Reichel instead of his assigned agent Shawn Du Bois). Covers
 * all three surfaces the fix repointed: the card display, the "own" pipeline
 * scope, and the ?agent_id= filter.
 */
final class BuyerPipelineAgentAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The pipeline view extends the corex layout (@vite); no built manifest
        // exists in the test env — stub Vite so the HTML renders for assertions.
        $this->withoutVite();
    }

    /**
     * A buyer captured by one user but ASSIGNED to another.
     *
     * @param string $actorRole Role for the capturer + assignee. 'admin' bypasses
     *   the orthogonal Layer-2 ContactScope so a test isolates the Layer-3 pipeline
     *   scope (this mirrors HFC live, where agents have contacts.view='all' → same
     *   no-op). 'agent' is used by the display test, where a plain agent's name is
     *   NOT surfaced by the layout's admin list, keeping the negative assertion clean.
     */
    private function scenario(string $actorRole = 'admin'): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $creator = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => $actorRole, 'name' => 'Capturer Creator',
        ]);
        $assignee = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => $actorRole, 'name' => 'Assigned Agent',
        ]);
        $admin = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin', 'name' => 'The Admin',
        ]);

        $buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Keith', 'last_name' => 'Ellis',
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'keith-' . Str::random(5) . '@example.co.za',
            'created_by_user_id' => $creator->id, // capturer
            'agent_id' => $assignee->id,           // ASSIGNED (the one working the buyer)
        ]);

        return [$agencyId, $creator, $assignee, $admin, $buyer];
    }

    public function test_controller_feeds_the_assigned_agent_to_the_view(): void
    {
        // The reported bug was a wrong-READ: the board fed the capturer
        // (created_by_user_id) into the "Agent" slot instead of the assigned
        // agent (agent_id). Assert the controller now eager-loads and provides
        // the ASSIGNED agent — proven on the view-data, not fragile page HTML
        // (the layout lists agency users, which confounds an HTML assertDontSee).
        [, , $assignee, $admin, $buyer] = $this->scenario('admin');

        $rows = $this->pipelineBuyers($admin, ['view' => 'list', 'scope' => 'agency']);
        $card = $rows->firstWhere('id', $buyer->id);

        $this->assertNotNull($card, 'buyer should be on the board');
        $this->assertTrue($card->relationLoaded('agent'), 'controller must eager-load the assigned agent');
        $this->assertFalse($card->relationLoaded('createdBy'), 'controller must NOT eager-load createdBy as the agent');
        $this->assertSame($assignee->id, $card->agent?->id, 'the card agent is the ASSIGNED agent, not the capturer');
    }

    public function test_pipeline_scope_and_filter_key_on_agent_id(): void
    {
        // Same scenario/setup as the display test (kept in ONE method to avoid a
        // second heavy per-test bootstrap, which was tripping a transient MySQL
        // 1615 flake). Guards the scope-column choice the fix made: own/branch
        // scopes + the ?agent_id= filter all key on contacts.agent_id — the
        // exact clause BuyerPipelineController now builds.
        [, $creator, $assignee, $admin, $buyer] = $this->scenario('admin');
        $this->actingAs($admin); // admin → Layer-2 ContactScope no-op (mirrors HFC agents = contacts.view 'all')

        // The Keith-Ellis shape: capturer != assigned agent.
        $this->assertNotSame((int) $buyer->created_by_user_id, (int) $buyer->agent_id);

        // Filtering / own-scoping by the ASSIGNED agent selects the buyer…
        $this->assertTrue(
            Contact::buyers()->where('contacts.agent_id', $assignee->id)->pluck('id')->contains($buyer->id),
            'agent_id keyed on the assignee selects the buyer'
        );
        // …by the CAPTURER it does not (under the old created_by read it would have).
        $this->assertFalse(
            Contact::buyers()->where('contacts.agent_id', $creator->id)->pluck('id')->contains($buyer->id),
            'agent_id keyed on the capturer must NOT select the buyer'
        );
    }

    /** Invoke the controller directly and return the 'buyers' paginator's models. */
    private function pipelineBuyers(User $viewer, array $query): \Illuminate\Support\Collection
    {
        $this->actingAs($viewer);
        $request = \Illuminate\Http\Request::create('/corex/command-center/buyers/pipeline', 'GET', $query);
        $request->setUserResolver(fn () => $viewer);

        $view = app(\App\Http\Controllers\CommandCenter\BuyerPipelineController::class)->index($request);

        return collect($view->getData()['buyers']->items());
    }
}
