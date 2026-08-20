<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyOwner;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deeds Capture data scope (Johan, 2026-08-20): "lots of data now flowing in and staff is
 * getting lost as they are all seeing everything that was scraped." Proves, end to end, the
 * four pieces built together: None/Own/Branch/All scope (own = deeds_captured_by_user_id, the
 * user who scraped it — Johan's own definition), the Agent column, the scope-clamped agent
 * picker, and the address-or-contact search — all through the REAL
 * DeedsCaptureController::index() route, not a unit-level scope call, so list and count can
 * never silently disagree (the exact bug class Johan named twice today: MIC and the buyers
 * pipeline shipped list-filters-but-count-doesn't).
 */
final class DeedsCaptureDataScopeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branchA;
    private Branch $branchB;
    private User $agentOwn;          // branch A — the acting "own" user in most tests
    private User $agentSameBranch;   // branch A — a colleague
    private User $agentOtherBranch;  // branch B
    private User $branchManager;     // branch A
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency  = Agency::create(['name' => 'HFC ' . uniqid(), 'slug' => 'hfc-' . uniqid()]);
        $this->branchA = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Shelly Beach']);
        $this->branchB = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);

        $mk = fn (string $role, Branch $branch) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => $role,
        ]);

        $this->agentOwn         = $mk('agent', $this->branchA);
        $this->agentSameBranch  = $mk('agent', $this->branchA);
        $this->agentOtherBranch = $mk('agent', $this->branchB);
        $this->branchManager    = $mk('branch_manager', $this->branchA);
        $this->admin            = $mk('admin', $this->branchA);

        // Real Role Manager grants, forced to PRODUCTION posture (not the test-suite unseeded
        // allow-all fallback) — proving the actual mechanism, same convention as
        // CommsVisibilityMatrixTest. deeds_capture.access gates the route at all; .view's
        // scope is what this build adds.
        foreach (['agent', 'branch_manager', 'admin'] as $role) {
            RolePermission::create(['role' => $role, 'permission_key' => 'deeds_capture.access', 'scope' => null, 'agency_id' => $this->agency->id]);
        }
        foreach ([['agent', 'own'], ['branch_manager', 'branch'], ['admin', 'all']] as [$role, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => 'deeds_capture.view', 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();
    }

    private function deedsCapture(array $overrides = []): TrackedProperty
    {
        return TrackedProperty::create(array_merge([
            'agency_id'    => $this->agency->id,
            'capture_kind' => 'deeds_capture',
            'street_number' => '1',
            'street_name'   => 'Test Road',
            'suburb'        => 'Shelly Beach',
            'erf_number'    => (string) random_int(1000, 999999),
            'source_chain'  => [],
        ], $overrides));
    }

    // ──────────────────────── own ────────────────────────

    public function test_own_returns_only_that_users_scrapes_and_is_non_zero(): void
    {
        $mine  = $this->deedsCapture(['deeds_captured_by_user_id' => $this->agentOwn->id]);
        $other = $this->deedsCapture(['deeds_captured_by_user_id' => $this->agentSameBranch->id]);

        $resp = $this->actingAs($this->agentOwn)->get(route('corex.deeds-capture.index'));
        $resp->assertOk();

        $captures = $resp->viewData('captures');
        $ids = $captures->pluck('id');
        $this->assertGreaterThan(0, $captures->total(), 'own scope must be non-zero for a real agent with a real scrape');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($other->id));
        // Count == list, proven directly against the same query the controller renders from.
        $this->assertSame($captures->total(), $captures->count());
        $this->assertFalse($resp->viewData('canPickAgent'), 'own has nobody else to pick — no picker at all');
    }

    // ──────────────────────── branch ────────────────────────

    public function test_branch_returns_the_branch_not_other_branches(): void
    {
        $mine        = $this->deedsCapture(['deeds_captured_by_user_id' => $this->agentOwn->id]);
        $sameBranch  = $this->deedsCapture(['deeds_captured_by_user_id' => $this->agentSameBranch->id]);
        $otherBranch = $this->deedsCapture(['deeds_captured_by_user_id' => $this->agentOtherBranch->id]);

        $resp = $this->actingAs($this->branchManager)->get(route('corex.deeds-capture.index'));
        $resp->assertOk();

        $ids = $resp->viewData('captures')->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertTrue($ids->contains($sameBranch->id));
        $this->assertFalse($ids->contains($otherBranch->id));
        $this->assertSame($resp->viewData('captures')->total(), $resp->viewData('captures')->count());
    }

    // ──────────────────────── all ────────────────────────

    public function test_all_returns_everything_including_null_scraper(): void
    {
        $mine        = $this->deedsCapture(['deeds_captured_by_user_id' => $this->agentOwn->id]);
        $otherBranch = $this->deedsCapture(['deeds_captured_by_user_id' => $this->agentOtherBranch->id]);
        $unattributed = $this->deedsCapture(['deeds_captured_by_user_id' => null]);

        $resp = $this->actingAs($this->admin)->get(route('corex.deeds-capture.index'));
        $resp->assertOk();

        $ids = $resp->viewData('captures')->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertTrue($ids->contains($otherBranch->id));
        $this->assertTrue($ids->contains($unattributed->id), 'a NULL-scraper row must not vanish under all scope');
        $this->assertSame($resp->viewData('captures')->total(), $resp->viewData('captures')->count());
        // Honest "Unknown" label, never a blank line an agent could misread.
        $resp->assertSee('Unknown');
    }

    public function test_null_scraper_row_excluded_from_own_and_branch(): void
    {
        $unattributed = $this->deedsCapture(['deeds_captured_by_user_id' => null]);

        $own = $this->actingAs($this->agentOwn)->get(route('corex.deeds-capture.index'));
        $this->assertFalse($own->viewData('captures')->pluck('id')->contains($unattributed->id));

        $branch = $this->actingAs($this->branchManager)->get(route('corex.deeds-capture.index'));
        $this->assertFalse($branch->viewData('captures')->pluck('id')->contains($unattributed->id));
    }

    // ──────────────────────── agent picker ────────────────────────

    public function test_agent_picker_offers_only_in_scope_agents(): void
    {
        // Branch scope: the picker's candidate list must be branchA only.
        $resp = $this->actingAs($this->branchManager)->get(route('corex.deeds-capture.index'));
        $resp->assertOk();
        $this->assertTrue($resp->viewData('canPickAgent'));
        $agentIds = $resp->viewData('agentList')->pluck('id');
        $this->assertTrue($agentIds->contains($this->agentOwn->id));
        $this->assertTrue($agentIds->contains($this->agentSameBranch->id));
        $this->assertTrue($agentIds->contains($this->branchManager->id));
        $this->assertFalse($agentIds->contains($this->agentOtherBranch->id), 'branch scope must never OFFER an agent outside the branch — the backend would refuse them anyway');

        // All scope: everyone in the agency, including the other branch.
        $respAll = $this->actingAs($this->admin)->get(route('corex.deeds-capture.index'));
        $agentIdsAll = $respAll->viewData('agentList')->pluck('id');
        $this->assertTrue($agentIdsAll->contains($this->agentOtherBranch->id));
    }

    /**
     * A branch-scoped agent picking (via ?agent_id=) another agent's id who is OUTSIDE the
     * branch must not silently widen their view — the backend re-derives the candidate set
     * itself; a hand-crafted out-of-scope agent_id must resolve to nobody's records, not to
     * that agent's records.
     */
    public function test_picking_an_out_of_scope_agent_id_by_hand_returns_nothing_for_them(): void
    {
        $otherBranchCapture = $this->deedsCapture(['deeds_captured_by_user_id' => $this->agentOtherBranch->id]);

        $resp = $this->actingAs($this->branchManager)
            ->get(route('corex.deeds-capture.index', ['agent_id' => $this->agentOtherBranch->id]));
        $resp->assertOk();

        $this->assertFalse($resp->viewData('captures')->pluck('id')->contains($otherBranchCapture->id));
    }

    // ──────────────────────── search ────────────────────────

    public function test_search_by_partial_address_finds_it(): void
    {
        $target = $this->deedsCapture([
            'deeds_captured_by_user_id' => $this->agentOwn->id,
            'street_name' => 'Bauhinia Close', 'suburb' => 'Shelly Beach',
        ]);
        $other = $this->deedsCapture([
            'deeds_captured_by_user_id' => $this->agentOwn->id,
            'street_name' => 'Palm Avenue', 'suburb' => 'Shelly Beach',
        ]);

        $resp = $this->actingAs($this->agentOwn)->get(route('corex.deeds-capture.index', ['search' => 'Bauhinia']));
        $resp->assertOk();

        $ids = $resp->viewData('captures')->pluck('id');
        $this->assertTrue($ids->contains($target->id));
        $this->assertFalse($ids->contains($other->id));
    }

    public function test_search_by_contact_name_finds_it(): void
    {
        $tp = $this->deedsCapture(['deeds_captured_by_user_id' => $this->agentOwn->id]);
        $owner = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Zandile', 'last_name' => 'Mthembu', 'phone' => '0821112222',
        ]);
        $tp->owner_contact_id = $owner->id;
        $tp->save();
        TrackedPropertyOwner::create([
            'tracked_property_id' => $tp->id, 'contact_id' => $owner->id,
            'name' => 'Zandile Mthembu', 'ownership_status' => 'current', 'is_primary' => true,
        ]);

        $resp = $this->actingAs($this->agentOwn)->get(route('corex.deeds-capture.index', ['search' => 'Mthembu']));
        $resp->assertOk();

        $this->assertTrue($resp->viewData('captures')->pluck('id')->contains($tp->id));
        $resp->assertSee('Zandile Mthembu');
    }

    /**
     * The single most likely way to leak, per Johan: search must never surface a record the
     * user could not otherwise see, even when the search term matches perfectly.
     */
    public function test_search_cannot_surface_an_out_of_scope_record(): void
    {
        $outOfScope = $this->deedsCapture([
            'deeds_captured_by_user_id' => $this->agentOtherBranch->id,
            'street_name' => 'Uniquely Named Crescent', 'suburb' => 'Margate',
        ]);

        // 'own' scope, searching for the exact street name of a record scraped by someone else.
        $resp = $this->actingAs($this->agentOwn)->get(route('corex.deeds-capture.index', ['search' => 'Uniquely Named']));
        $resp->assertOk();
        $this->assertFalse($resp->viewData('captures')->pluck('id')->contains($outOfScope->id));

        // 'branch' scope — the other branch's record must still stay hidden.
        $respBranch = $this->actingAs($this->branchManager)->get(route('corex.deeds-capture.index', ['search' => 'Uniquely Named']));
        $this->assertFalse($respBranch->viewData('captures')->pluck('id')->contains($outOfScope->id));

        // 'all' scope — the same search DOES surface it; proves the search mechanism itself
        // works and the two prior misses were genuinely scope, not a broken query.
        $respAll = $this->actingAs($this->admin)->get(route('corex.deeds-capture.index', ['search' => 'Uniquely Named']));
        $this->assertTrue($respAll->viewData('captures')->pluck('id')->contains($outOfScope->id));
    }

    // ──────────────────────── direct URL access ────────────────────────

    public function test_direct_url_to_out_of_scope_record_is_refused(): void
    {
        $outOfScope = $this->deedsCapture(['deeds_captured_by_user_id' => $this->agentOtherBranch->id]);

        $this->actingAs($this->agentOwn)
            ->post(route('corex.deeds-capture.dismiss', $outOfScope->id))
            ->assertNotFound();

        $this->actingAs($this->agentOwn)
            ->post(route('corex.deeds-capture.promote', $outOfScope->id))
            ->assertNotFound();

        // Same record, branch scope, still outside the branch — still refused.
        $this->actingAs($this->branchManager)
            ->post(route('corex.deeds-capture.dismiss', $outOfScope->id))
            ->assertNotFound();

        // 'all' scope — the same URL now succeeds (proves the 404 above was scope, not a
        // routing/agency bug).
        $this->actingAs($this->admin)
            ->post(route('corex.deeds-capture.dismiss', $outOfScope->id))
            ->assertRedirect(route('corex.deeds-capture.index'));
    }
}
