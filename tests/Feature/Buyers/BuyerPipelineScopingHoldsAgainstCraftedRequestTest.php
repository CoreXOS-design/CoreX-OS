<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * cc6, 2026-09-14 — Rental Pipeline screen audit. Proves the pipeline's
 * own/branch/agency scoping (BuyerPipelineController::index(), Layer 3 via
 * BuyerPipelineScope, backstopped by ContactScope/AgencyScope, Layers 1+2)
 * holds against a DIRECTLY CRAFTED request from a low-privilege user — not
 * just "the UI never shows them the toggle". A low-priv agent whose
 * `contacts.view` role-permission scope is 'own' can still type
 * `?scope=agency` into the URL bar by hand; this proves that does nothing
 * for them, and that the identical crafted URL genuinely works for a
 * permitted 'agency'-scope user, so the lock isn't just blocking everyone.
 *
 * Method note: uses real Laravel-routed HTTP test requests
 * ($this->actingAs()->get(route(...))) — full router + middleware +
 * Eloquent global-scope stack, the same rigor this controller's own
 * mixed-wishlist test already used and that this session has already
 * proven catches real bugs (the primary-vs-any-match fix). Standard -1f's
 * warning is about JS/Alpine control interactivity (a disabled button a
 * PHPUnit POST can't see); this claim is about backend query-layer data
 * scoping, where a routed request already exercises the real code path
 * end to end — no JS involved, nothing that method is blind to here.
 */
final class BuyerPipelineScopingHoldsAgainstCraftedRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * One agency, two branches, one low-priv 'agent' (role_permissions
     * contacts.view scope explicitly set to 'own' — not relying on
     * whatever an agency's default happens to resolve to, so the test's
     * intent is unambiguous), one admin, and a rival contact belonging to
     * a DIFFERENT agent in the SAME agency (never created by, never
     * assigned to, the low-priv user).
     */
    private function scenario(): array
    {
        $agency = Agency::create([
            'name' => 'THROWAWAY Scoping Test ' . Str::random(6),
            'slug' => 'throwaway-scoping-' . Str::random(8),
        ]);

        $branchA = DB::table('branches')->insertGetId([
            'agency_id' => $agency->id, 'name' => 'THROWAWAY Branch A',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'agency_id' => $agency->id, 'name' => 'THROWAWAY Branch B',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lowPrivAgent = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branchA,
            'role' => 'agent', 'name' => 'THROWAWAY Low-Priv Agent',
        ]);
        $rivalAgent = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branchB,
            'role' => 'agent', 'name' => 'THROWAWAY Rival Agent',
        ]);
        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branchA,
            'role' => 'admin', 'name' => 'THROWAWAY Admin',
        ]);

        // Deterministic 'own' scope for the low-priv agent's role, this
        // agency only — explicit, not inferred from PermissionService's
        // unseeded-agency fallback.
        DB::table('role_permissions')->insert([
            'role' => 'agent', 'permission_key' => 'contacts.view',
            'agency_id' => $agency->id, 'scope' => 'own',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The route itself is gated on `permission:buyer_pipeline.view`
        // (routes/web.php) BEFORE any of the above data-scope logic is ever
        // reached — a '.view' key checks role_permissions.scope existence
        // (PermissionService::userHasPermission), never simple presence.
        // Both roles need this just to get past the gate; 'admin' in a
        // brand-new agency with no `roles` row is NOT an is_owner bypass —
        // it's an ordinary granted role like any other here.
        foreach (['agent', 'admin'] as $roleName) {
            DB::table('role_permissions')->insert([
                'role' => $roleName, 'permission_key' => 'buyer_pipeline.view',
                'agency_id' => $agency->id, 'scope' => 'all',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Admin's own contacts.view — 'all', matching real-world admin grants,
        // so the positive-case test proves the lock genuinely opens for a
        // permitted role rather than everyone being denied.
        DB::table('role_permissions')->insert([
            'role' => 'admin', 'permission_key' => 'contacts.view',
            'agency_id' => $agency->id, 'scope' => 'all',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The rival's own rental-primary buyer — created by AND assigned
        // to the rival, in a DIFFERENT branch, never touched by the
        // low-priv agent. This is the record that must never leak.
        $rivalContact = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => $branchB,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'THROWAWAY', 'last_name' => 'RivalTenant',
            'phone' => '083' . random_int(1000000, 9999999),
            'email' => 'throwaway-rival-' . Str::random(5) . '@example.co.za',
            'agent_id' => $rivalAgent->id, 'created_by_user_id' => $rivalAgent->id,
        ]);
        // agency_id explicit — BelongsToAgency's creating hook otherwise
        // derives it from Auth::user() (null here, before actingAs()) via a
        // "single agency in the DB" fallback, which silently breaks the
        // moment a test creates more than one throwaway agency (found via
        // query-log debugging: this row landed under the WRONG agency and
        // its contact read back with zero matches).
        \App\Models\ContactMatch::create([
            'contact_id' => $rivalContact->id, 'agency_id' => $agency->id, 'listing_type' => 'rental', 'is_primary' => true,
        ]);

        return [$agency, $lowPrivAgent, $rivalAgent, $admin, $rivalContact];
    }

    /**
     * User deletion is deliberately NOT done here: User::delete() refuses to
     * remove an agency's only Admin (LastAdminException), and these fixture
     * agencies only ever have one. RefreshDatabase already wraps every test
     * in a transaction it rolls back, so the fixture users vanish either
     * way — this just proves the one thing that matters, that the CONTACT
     * (the actual business record) was soft- not hard-deleted.
     */
    private function cleanup(Agency $agency, Contact $rivalContact, User ...$users): void
    {
        $rivalContact->delete();
        $agency->delete();
        $this->assertTrue($rivalContact->fresh()->trashed());
    }

    public function test_low_priv_agent_hand_crafting_scope_agency_never_sees_a_rivals_contact(): void
    {
        [$agency, $lowPrivAgent, $rivalAgent, $admin, $rivalContact] = $this->scenario();

        // The crafted request: this user's own UI never renders an "All"
        // pill wide enough to reach this (Layer 3 default would give them
        // 'own'), but nothing stops them typing ?scope=agency by hand.
        $response = $this->actingAs($lowPrivAgent)
            ->get(route('corex.rentals.pipeline.index', ['view' => 'kanban', 'scope' => 'agency']));

        $response->assertOk();
        $rawBody = $response->getContent();

        // Standard -1n: check the raw HTML, not a parsed view-data
        // convenience — proves the rival's name never reaches the byte
        // stream at all, not just that it's absent from some assertion
        // helper's idea of "the list".
        $this->assertStringNotContainsString('RivalTenant', $rawBody);

        $ids = collect($response->viewData('columns'))->flatMap(fn ($col) => $col->pluck('id'));
        $this->assertFalse($ids->contains($rivalContact->id));

        $this->cleanup($agency, $rivalContact, $lowPrivAgent, $rivalAgent, $admin);
    }

    public function test_low_priv_agent_agent_id_filter_cannot_be_pointed_at_someone_outside_their_own_scope(): void
    {
        [$agency, $lowPrivAgent, $rivalAgent, $admin, $rivalContact] = $this->scenario();

        // Narrowest scope (their own default) PLUS a hand-added agent_id
        // pointing at the rival — proves the filter param can't widen what
        // Layer 2 already restricted them to.
        $response = $this->actingAs($lowPrivAgent)
            ->get(route('corex.rentals.pipeline.index', [
                'view' => 'kanban', 'scope' => 'own', 'agent_id' => $rivalAgent->id,
            ]));

        $response->assertOk();
        $this->assertStringNotContainsString('RivalTenant', $response->getContent());

        $this->cleanup($agency, $rivalContact, $lowPrivAgent, $rivalAgent, $admin);
    }

    public function test_the_identical_crafted_url_genuinely_works_for_a_permitted_agency_scope_user(): void
    {
        [$agency, $lowPrivAgent, $rivalAgent, $admin, $rivalContact] = $this->scenario();

        // Positive case (per BUILD_STANDARD.md 5a's own spirit — prove the
        // lock isn't just blocking everyone): an admin (Layer 2 bypass,
        // sees all in agency) hitting the SAME URL DOES see the rival's
        // card. If this failed, the two tests above would be meaningless —
        // they could be passing because the whole board is broken, not
        // because scoping specifically works.
        $response = $this->actingAs($admin)
            ->get(route('corex.rentals.pipeline.index', ['view' => 'kanban', 'scope' => 'agency']));

        $response->assertOk();
        $this->assertStringContainsString('RivalTenant', $response->getContent());

        $ids = collect($response->viewData('columns'))->flatMap(fn ($col) => $col->pluck('id'));
        $this->assertTrue($ids->contains($rivalContact->id));

        $this->cleanup($agency, $rivalContact, $lowPrivAgent, $rivalAgent, $admin);
    }

    public function test_cross_agency_isolation_holds_even_when_the_low_priv_users_own_role_scope_is_agency_wide(): void
    {
        // A second, completely separate agency with its OWN rental buyer.
        // Even a same-agency 'agency'-wide scope role must never see across
        // the Layer 1 tenant boundary — this is AgencyScope, not Layer 3.
        $otherAgency = Agency::create([
            'name' => 'THROWAWAY Other Agency ' . Str::random(6),
            'slug' => 'throwaway-other-' . Str::random(8),
        ]);
        $otherBranch = DB::table('branches')->insertGetId([
            'agency_id' => $otherAgency->id, 'name' => 'THROWAWAY Other Branch',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherAgent = User::factory()->create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch,
            'role' => 'agent', 'name' => 'THROWAWAY Other-Agency Agent',
        ]);
        $otherContact = Contact::withoutGlobalScopes()->create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'THROWAWAY', 'last_name' => 'ForeignTenant',
            'phone' => '084' . random_int(1000000, 9999999),
            'email' => 'throwaway-foreign-' . Str::random(5) . '@example.co.za',
            'agent_id' => $otherAgent->id, 'created_by_user_id' => $otherAgent->id,
        ]);
        \App\Models\ContactMatch::create([
            'contact_id' => $otherContact->id, 'agency_id' => $otherAgency->id, 'listing_type' => 'rental', 'is_primary' => true,
        ]);

        [$agency, $lowPrivAgent, $rivalAgent, $admin, $rivalContact] = $this->scenario();
        // Give the low-priv agent agency-wide contacts scope this time —
        // the strongest possible role grant WITHIN their own tenant.
        DB::table('role_permissions')
            ->where('agency_id', $agency->id)->where('role', 'agent')->where('permission_key', 'contacts.view')
            ->update(['scope' => 'all']);
        // PermissionService statically caches getScopesForRole() per
        // (agencyId, role) for the life of the process — scenario() may
        // already have triggered a read under the OLD 'own' value before
        // this raw update ran. Must invalidate explicitly; TestCase's own
        // setUp() clear only covers the START of the test, not a mid-test
        // permission change.
        \App\Services\PermissionService::clearCache();

        $response = $this->actingAs($lowPrivAgent)
            ->get(route('corex.rentals.pipeline.index', ['view' => 'kanban', 'scope' => 'agency']));

        $response->assertOk();
        // Sees their own agency's rival fine now (agency-wide grant)...
        $this->assertStringContainsString('RivalTenant', $response->getContent());
        // ...but the OTHER agency's contact must never appear, no matter what.
        $this->assertStringNotContainsString('ForeignTenant', $response->getContent());

        $otherContact->delete();
        $otherAgent->delete();
        $otherAgency->delete();
        $this->cleanup($agency, $rivalContact, $lowPrivAgent, $rivalAgent, $admin);
    }
}
