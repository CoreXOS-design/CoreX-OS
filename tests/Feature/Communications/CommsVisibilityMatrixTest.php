<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Http\Controllers\Communications\CommunicationTriageController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Communications\Communication;
use App\Models\Communications\CommsAccessRequest;
use App\Models\Contact;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Communications\CommunicationTriageService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AT-274 "1000% locked" verification — the comms-thread visibility MATRIX.
 *
 * One test proving, per role, exactly who sees WhatsApp/email threads:
 *   agent  → OWN only
 *   BM     → OWN only (AT-118 reversal ceiling), UNTIL a request-access grant lands
 *   admin  → ALL
 *   owner  → ALL, and the ingestion surfaces never render blank
 *
 * Proof per cell = an asserted scope + an asserted row-count through the real
 * Communication::scopeVisibleTo query (the exact SQL the archive/thread views run).
 */
final class CommsVisibilityMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private User $agentA;
    private User $agentB;
    private User $bm;
    private User $admin;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency  = Agency::create(['name' => 'HFC ' . uniqid(), 'slug' => 'hfc-' . uniqid()]);
        $this->branch  = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Port Shepstone']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Test', 'last_name' => 'Seller', 'phone' => '0830000000',
        ]);

        // Owner role must exist as an is_owner Role row for isOwnerRole()/getDataScope().
        // is_owner is guarded (not fillable), so set it directly; label is NOT NULL.
        $ownerRole = new Role(['name' => 'super_admin', 'label' => 'System Owner', 'agency_id' => null]);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::clearCache();

        $mk = fn (string $role) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role,
        ]);

        $this->agentA = $mk('agent');
        $this->agentB = $mk('agent');
        $this->bm     = $mk('branch_manager');
        $this->admin  = $mk('admin');
        // Owner carries an agency so AgencyScope resolves to it; is_owner still forces 'all'.
        $this->owner  = $mk('super_admin');

        // Stored scopes exactly as a live agency carries them.
        foreach ([
            ['agent', 'own'], ['branch_manager', 'branch'], ['admin', 'all'],
        ] as [$role, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => 'communications.view', 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        // Deals stays branch for the BM (proves the ceiling is comms-only).
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'deals.view', 'scope' => 'branch', 'agency_id' => $this->agency->id]);

        PermissionService::clearCache();
        PermissionService::forceProductionPosture();
        Cache::flush();

        // Two owned threads, one per agent, same branch.
        $this->thread($this->agentA, 'wa:aaa111');
        $this->thread($this->agentB, 'wa:bbb222');
    }

    private function thread(User $owner, string $threadKey): Communication
    {
        return Communication::create([
            'agency_id'     => $this->agency->id,
            'channel'       => Communication::CHANNEL_WHATSAPP,
            'direction'     => Communication::DIRECTION_INBOUND,
            'external_id'   => $threadKey . ':' . uniqid(),
            'thread_key'    => $threadKey,
            'owner_user_id' => $owner->id,
            'occurred_at'   => now(),
            'captured_at'   => now(),
        ]);
    }

    /** Count threads a user can see through the real visibility scope. */
    private function visibleCount(User $user): int
    {
        $this->actingAs($user);
        $scope = PermissionService::getDataScope($user, 'communications');
        return Communication::visibleTo($user, $scope)->count();
    }

    public function test_scope_matrix_resolves_per_role(): void
    {
        $this->assertSame('own', PermissionService::getDataScope($this->agentA, 'communications'), 'agent → own');
        $this->assertSame('own', PermissionService::getDataScope($this->bm, 'communications'), 'BM → own (AT-118 ceiling)');
        $this->assertSame('all', PermissionService::getDataScope($this->admin, 'communications'), 'admin → all');
        $this->assertSame('all', PermissionService::getDataScope($this->owner, 'communications'), 'owner → all (break-glass)');

        // The ceiling is comms-only — the BM keeps branch oversight everywhere else.
        $this->assertSame('branch', PermissionService::getDataScope($this->bm, 'deals'), 'BM deals stays branch');
    }

    public function test_visibility_counts_match_the_scope_matrix(): void
    {
        $this->assertSame(1, $this->visibleCount($this->agentA), 'agent A sees only their own thread');
        $this->assertSame(0, $this->visibleCount($this->bm), 'BM sees NEITHER agent thread by default (own-only)');
        $this->assertSame(2, $this->visibleCount($this->admin), 'admin sees all threads');
        $this->assertSame(2, $this->visibleCount($this->owner), 'owner sees all threads');
    }

    public function test_bm_request_access_grant_reveals_exactly_the_granted_thread(): void
    {
        $this->assertSame(0, $this->visibleCount($this->bm), 'precondition: BM sees nothing');

        // The end of the request-access flow: an APPROVED, always-on grant to agent A's thread.
        CommsAccessRequest::create([
            'agency_id'                  => $this->agency->id,
            'contact_id'                 => $this->contact->id,
            'thread_key'                 => 'wa:aaa111',
            'requester_user_id'          => $this->bm->id,
            'status'                     => CommsAccessRequest::STATUS_APPROVED,
            'grant_mode'                 => CommsAccessRequest::MODE_ALWAYS,
            'expires_at'                 => now()->addYear(),
        ]);
        PermissionService::clearCache();
        Cache::flush();

        $this->assertSame(1, $this->visibleCount($this->bm), 'BM now sees ONLY the granted thread — request-access works end-to-end');
    }

    public function test_triage_never_blank_for_null_agency_owner(): void
    {
        $noAgencyOwner = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin']);
        $this->actingAs($noAgencyOwner);

        $view = (new CommunicationTriageController(app(CommunicationTriageService::class)))->index();

        $data = $view->getData();
        $this->assertTrue($data['noContext'] ?? false, 'null-agency owner gets the explained no-context panel, never a blank 403');
        $this->assertTrue($data['items']->isEmpty(), 'no personal triage items for an owner');
    }

    public function test_triage_renders_items_for_an_agent_with_context(): void
    {
        $this->actingAs($this->agentA);
        $view = (new CommunicationTriageController(app(CommunicationTriageService::class)))->index();

        $this->assertFalse($view->getData()['noContext'] ?? true, 'agent with agency context renders the real queue, not the no-context panel');
    }
}
