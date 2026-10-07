<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactNote;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Buyers\BuyerNotesAccess;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Buyer-notes visibility as a Role Manager data scope (Johan, 2026-10-07): `buyer_notes.view`, Own / Branch /
 * Agency, read through the standard PermissionService path (BuyerNotesAccess). Surfaces: the Intelligence tab's
 * Buyer Interest Signals and Core Matches — view only, never the seller's public link.
 *
 *   own     buyers whose PRIMARY agent is the viewer
 *   branch  buyers whose primary agent is in the viewer's branch (no agent → the contact's branch)
 *   all     any buyer in the agency
 *
 * The permission system is seeded for real (see RoleManagerFunctionalTest): the schema snapshot is schema-only.
 * Spec: .ai/specs/core-matches.md ("Buyer notes — Role Manager scope").
 */
final class BuyerNotesScopeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch1;
    private Branch $branch2;
    private User $admin;
    private User $viewer;   // agent, branch 1 — the one we vary the scope for
    private User $peer;     // agent, branch 1
    private User $far;      // agent, branch 2
    private Property $property;
    /** @var array<string,Contact> */
    private array $buyer = [];

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
        Bus::fake();
        $this->withoutVite();
        $this->seedPermissionSystem();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch1 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'North']);
        $this->branch2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'South']);

        $this->admin  = $this->user('Ada Admin', 'admin', $this->branch1);
        $this->viewer = $this->user('Vera Viewer', 'agent', $this->branch1);
        $this->peer   = $this->user('Pia Peer', 'agent', $this->branch1);
        $this->far    = $this->user('Fay Far', 'agent', $this->branch2);

        $suburbId = $this->seedP24Suburb();
        $this->property = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->viewer->id, 'branch_id' => $this->branch1->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active',
            'price' => 1_800_000, 'beds' => 3, 'published_at' => now(), 'p24_suburb_id' => $suburbId,
        ]);

        // Captured by the admin every time, so the CONTACT's own scope (created_by) never explains an outcome.
        $this->buyer['mine']    = $this->makeBuyer('Mine', $this->viewer->id, $this->branch1, $suburbId);
        $this->buyer['peer']    = $this->makeBuyer('Peer', $this->peer->id, $this->branch1, $suburbId);
        $this->buyer['far']     = $this->makeBuyer('Far', $this->far->id, $this->branch2, $suburbId);
        $this->buyer['nobody']  = $this->makeBuyer('Nobody', null, $this->branch1, $suburbId);
        $this->buyer['nobody2'] = $this->makeBuyer('Nobody2', null, $this->branch2, $suburbId);

        foreach ($this->buyer as $key => $contact) {
            ContactNote::withoutGlobalScopes()->create([
                'agency_id' => $this->agency->id, 'contact_id' => $contact->id, 'user_id' => $this->admin->id,
                'body' => "Note about {$key}",
            ]);
        }
        PermissionService::clearCache();
    }

    // ── per level, Intelligence tab + the notes address ──────────────────

    public function test_own_level_only_buyers_whose_primary_agent_is_the_viewer(): void
    {
        $this->scope('agent', 'own');

        $this->assertVisible(['mine'], $this->viewer);
    }

    public function test_branch_level_buyers_of_agents_in_the_viewers_branch_and_unassigned_buyers_of_that_branch(): void
    {
        $this->scope('agent', 'branch');

        $this->assertVisible(['mine', 'peer', 'nobody'], $this->viewer);
    }

    public function test_agency_level_any_buyer_in_the_agency(): void
    {
        $this->scope('agent', 'all');

        $this->assertVisible(['mine', 'peer', 'far', 'nobody', 'nobody2'], $this->viewer);
    }

    public function test_without_the_permission_nothing_is_offered_and_the_address_is_refused(): void
    {
        RolePermission::where('agency_id', $this->agency->id)->where('role', 'agent')->where('permission_key', 'buyer_notes.view')->delete();
        PermissionService::clearCache();

        $html = $this->actingAs($this->viewer)->get(route('corex.properties.show', $this->property))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-buyer-notes', $html);
        $this->actingAs($this->viewer)->get(route('corex.buyer-notes.show', $this->buyer['mine']->id))->assertForbidden();
    }

    public function test_the_setting_is_independent_of_the_contacts_own_scope_and_the_link_to_the_contact_follows_that_scope(): void
    {
        // The viewer may open only contacts they captured (none of these), yet the agency lets them read all buyers' notes.
        $this->scope('agent', 'all');
        RolePermission::where('agency_id', $this->agency->id)->where('role', 'agent')->where('permission_key', 'contacts.view')->update(['scope' => 'own']);
        PermissionService::clearCache();

        $frag = $this->actingAs($this->viewer)->get(route('corex.buyer-notes.show', $this->buyer['peer']->id))->assertOk()->getContent();

        $this->assertStringContainsString('Note about peer', $frag);
        $this->assertStringNotContainsString('Open full contact record', $frag, 'no link the viewer could not follow');
        $this->assertStringNotContainsString('<form', $frag);
    }

    public function test_a_buyer_in_another_agency_is_never_reachable_even_at_agency_level(): void
    {
        $this->scope('agent', 'all');
        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'else-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Else']);
        $otherBuyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'first_name' => 'Foreign', 'last_name' => 'Buyer', 'is_buyer' => true,
            'phone' => '082' . random_int(1000000, 9999999),
        ]);

        $this->actingAs($this->viewer)->get(route('corex.buyer-notes.show', $otherBuyer->id))->assertNotFound();
    }

    // ── Core Matches ─────────────────────────────────────────────────────

    public function test_core_matches_offers_the_notes_pill_only_for_buyers_inside_the_scope(): void
    {
        $this->scope('admin', 'own');
        // The admin is the primary agent of one extra buyer; "own" must show only that one's pill.
        $adminBuyer = $this->makeBuyer('Admins', $this->admin->id, $this->branch1, $this->property->p24_suburb_id);
        ContactNote::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'contact_id' => $adminBuyer->id, 'user_id' => $this->admin->id, 'body' => 'x']);
        PermissionService::clearCache();

        $html = $this->actingAs($this->admin)->get(route('corex.core-matches.index', ['scope' => 'agency']))->assertOk()->getContent();

        $this->assertStringContainsString(route('corex.buyer-notes.show', $adminBuyer->id), $html);
        $this->assertStringNotContainsString(route('corex.buyer-notes.show', $this->buyer['mine']->id), $html);
        $this->assertStringNotContainsString(route('corex.buyer-notes.show', $this->buyer['far']->id), $html);

        $this->scope('admin', 'all');
        $html = $this->actingAs($this->admin)->get(route('corex.core-matches.index', ['scope' => 'agency']))->assertOk()->getContent();
        $this->assertStringContainsString(route('corex.buyer-notes.show', $this->buyer['far']->id), $html);
        $this->assertStringContainsString(route('corex.buyer-notes.show', $this->buyer['mine']->id), $html);
    }

    // ── assistants ───────────────────────────────────────────────────────

    public function test_an_assistant_reads_at_most_what_their_agent_may(): void
    {
        $this->scope('agent', 'own');
        Role::firstOrCreate(['name' => 'assistant', 'agency_id' => $this->agency->id], ['label' => 'Assistant']);
        $this->agency->forceFill(['assistants_enabled' => true])->save();
        $assistant = $this->user('Thandi Assist', 'assistant', $this->branch1, true);
        $assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch1->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $this->viewer->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);
        // Even if the matrix hands "agency" over, the agent's own breadth is the ceiling.
        AssistantAssignmentPermission::create([
            'agency_id' => $this->agency->id, 'assistant_assignment_id' => $assignment->id,
            'permission_key' => 'buyer_notes.view', 'granted' => true, 'scope' => 'all',
        ]);
        User::flushAssistantsEnabledCache();
        PermissionService::clearCache();

        $this->actingAs($assistant)->get(route('corex.buyer-notes.show', $this->buyer['mine']->id))->assertOk();
        $this->actingAs($assistant)->get(route('corex.buyer-notes.show', $this->buyer['peer']->id))->assertNotFound();
    }

    // ── Role Manager: same tables, same UI pattern ───────────────────────

    public function test_role_manager_offers_the_scope_selector_and_saves_it_where_the_service_reads_it(): void
    {
        $page = $this->actingAs($this->admin)->get(route('corex.role-manager'))->assertOk()->getContent();
        $this->assertStringContainsString('buyer_notes.view', $page);
        $this->assertStringContainsString("scopes[buyer_notes.view]", $page, 'the standard Own / Branch / Agency selector is wired for it');

        $this->actingAs($this->admin)->post(route('corex.role-manager.save'), [
            'role' => 'agent',
            'permissions' => ['access_properties' => '1', 'properties.view' => '1', 'buyer_notes.view' => '1'],
            'scopes' => ['properties.view' => 'own', 'buyer_notes.view' => 'branch'],
        ])->assertRedirect();

        $this->assertSame('branch', RolePermission::where('agency_id', $this->agency->id)->where('role', 'agent')
            ->where('permission_key', 'buyer_notes.view')->value('scope'));
        PermissionService::clearCache();
        $this->assertSame('branch', BuyerNotesAccess::scopeFor($this->viewer->fresh()));
    }

    public function test_a_fresh_agency_gets_the_standard_role_defaults(): void
    {
        $scope = fn (string $role) => RolePermission::where('agency_id', $this->agency->id)->where('role', $role)
            ->where('permission_key', 'buyer_notes.view')->value('scope');

        $this->assertSame('all', $scope('admin'));
        $this->assertSame('branch', $scope('branch_manager'));
        $this->assertSame('own', $scope('agent'));
    }

    // ── Defaults for existing agencies: nobody gains or loses ────────────

    public function test_the_migration_reproduces_todays_access_per_role_and_is_idempotent(): void
    {
        $plain = Agency::create(['name' => 'Plain', 'slug' => 'plain-' . uniqid(), 'split_branches_enabled' => false]);
        $split = Agency::create(['name' => 'Split', 'slug' => 'split-' . uniqid(), 'split_branches_enabled' => true]);

        // Wipe whatever provisioning gave these two, then set up "today": contacts.view + access_contacts per role.
        RolePermission::withTrashed()->whereIn('agency_id', [$plain->id, $split->id])->forceDelete();
        $grant = function (Agency $a, string $role, ?string $contactsScope, bool $access = true) {
            RolePermission::create(['agency_id' => $a->id, 'role' => $role, 'permission_key' => 'contacts.view', 'scope' => $contactsScope]);
            if ($access) {
                RolePermission::create(['agency_id' => $a->id, 'role' => $role, 'permission_key' => 'access_contacts', 'scope' => null]);
            }
        };
        foreach (['admin' => 'all', 'agent' => 'all', 'branch_manager' => 'all', 'viewer' => 'branch'] as $role => $s) {
            $grant($plain, $role, $s);
        }
        $grant($plain, 'office_admin', 'own');
        $grant($plain, 'locked_out', 'all', access: false);          // no access_contacts today → no notes today
        foreach (['admin' => 'all', 'agent' => 'all', 'viewer' => 'branch'] as $role => $s) {
            $grant($split, $role, $s);
        }
        // An agency that already chose a value is never overridden.
        RolePermission::create(['agency_id' => $split->id, 'role' => 'agent', 'permission_key' => 'buyer_notes.view', 'scope' => 'own']);

        $migration = require base_path('database/migrations/2026_10_14_000200_seed_buyer_notes_view_permission.php');
        $migration->up();
        $migration->up(); // idempotent

        $get = fn (Agency $a, string $role) => RolePermission::where('agency_id', $a->id)->where('role', $role)
            ->where('permission_key', 'buyer_notes.view')->first()?->scope;

        $this->assertSame('all', $get($plain, 'admin'));
        $this->assertSame('all', $get($plain, 'agent'), 'contacts scope all, no data isolation → agency');
        $this->assertSame('all', $get($plain, 'branch_manager'));
        $this->assertSame('branch', $get($plain, 'viewer'));
        $this->assertSame('own', $get($plain, 'office_admin'));
        $this->assertNull($get($plain, 'locked_out'), 'a role that could not open contacts today gets no row');

        $this->assertSame('all', $get($split, 'admin'), 'admin always saw every contact');
        $this->assertSame('own', $get($split, 'agent'), 'an existing choice is never overridden');
        $this->assertSame('branch', $get($split, 'viewer'));
        $this->assertSame(1, RolePermission::where('agency_id', $plain->id)->where('role', 'admin')->where('permission_key', 'buyer_notes.view')->count());
    }

    public function test_the_migration_gives_a_split_branches_agency_branch_where_contacts_were_agency_wide(): void
    {
        $split = Agency::create(['name' => 'Split2', 'slug' => 'split2-' . uniqid(), 'split_branches_enabled' => true]);
        RolePermission::withTrashed()->where('agency_id', $split->id)->forceDelete();
        foreach (['agent', 'branch_manager'] as $role) {
            RolePermission::create(['agency_id' => $split->id, 'role' => $role, 'permission_key' => 'contacts.view', 'scope' => 'all']);
            RolePermission::create(['agency_id' => $split->id, 'role' => $role, 'permission_key' => 'access_contacts', 'scope' => null]);
        }

        (require base_path('database/migrations/2026_10_14_000200_seed_buyer_notes_view_permission.php'))->up();

        foreach (['agent', 'branch_manager'] as $role) {
            $this->assertSame('branch', RolePermission::where('agency_id', $split->id)->where('role', $role)
                ->where('permission_key', 'buyer_notes.view')->value('scope'));
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @param array<int,string> $expected buyer keys whose notes the viewer should reach */
    private function assertVisible(array $expected, User $viewer): void
    {
        $html = $this->actingAs($viewer)->get(route('corex.properties.show', $this->property))->assertOk()->getContent();

        foreach ($this->buyer as $key => $contact) {
            $url = route('corex.buyer-notes.show', $contact->id);
            if (in_array($key, $expected, true)) {
                $this->assertStringContainsString($url, str_replace('\\/', '/', $html), "Intelligence tab offers notes for {$key}");
                $this->actingAs($viewer)->get($url)->assertOk()->assertSee("Note about {$key}");
            } else {
                $this->assertStringNotContainsString($url, str_replace('\\/', '/', $html), "Intelligence tab hides notes for {$key}");
                $this->actingAs($viewer)->get($url)->assertNotFound();
            }
        }
    }

    private function scope(string $role, string $scope): void
    {
        RolePermission::updateOrCreate(
            ['agency_id' => $this->agency->id, 'role' => $role, 'permission_key' => 'buyer_notes.view'],
            ['scope' => $scope],
        );
        PermissionService::clearCache();
    }

    private function user(string $name, string $role, Branch $branch, bool $assistant = false): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'role' => $role, 'is_active' => true, 'is_assistant' => $assistant,
        ]);
    }

    private function makeBuyer(string $name, ?int $primaryAgentId, Branch $branch, int $suburbId): Contact
    {
        $buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'created_by_user_id' => $this->admin->id, 'agent_id' => $primaryAgentId,
            'is_buyer' => true, 'buyer_state' => 'new', 'first_name' => $name, 'last_name' => 'Buyer',
            'phone' => '082' . random_int(1000000, 9999999), 'email' => strtolower($name) . '-' . Str::random(4) . '@example.co.za',
        ]);
        // ContactObserver defaults a blank agent to the capturing user — clear it for a genuinely unassigned buyer.
        if ($primaryAgentId === null) {
            DB::table('contacts')->where('id', $buyer->id)->update(['agent_id' => null]);
        }
        ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'contact_id' => $buyer->id, 'status' => ContactMatch::STATUS_ACTIVE,
            'listing_type' => 'sale', 'price_min' => 1_500_000, 'price_max' => 2_000_000, 'beds_min' => 3,
            'p24_suburb_ids' => [$suburbId],
        ]);

        return $buyer->fresh();
    }

    private function seedPermissionSystem(): void
    {
        $now = now();
        $roles = [
            ['name' => 'super_admin',    'label' => 'System Owner',   'is_owner' => 1, 'can_be_deleted' => 0, 'sort_order' => 1],
            ['name' => 'admin',          'label' => 'Administrator',  'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 2],
            ['name' => 'branch_manager', 'label' => 'Branch Manager', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 3],
            ['name' => 'agent',          'label' => 'Agent',          'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 4],
            ['name' => 'viewer',         'label' => 'Viewer',         'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 5],
            ['name' => 'office_admin',   'label' => 'Office Staff',   'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 6],
        ];
        foreach ($roles as &$r) {
            $r['agency_id'] = null;
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
        }
        DB::table('roles')->insert($roles);
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();
    }

    private function seedP24Suburb(): int
    {
        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => random_int(1, 999999), 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_province_id' => $provinceId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('p24_suburbs')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_city_id' => $cityId, 'name' => 'Uvongo',
            'slug' => 'uvongo-' . Str::random(5), 'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
