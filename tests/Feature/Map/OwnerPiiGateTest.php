<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Http\Controllers\Map\MapController;
use App\Models\User;
use App\Services\Map\MapBoundsRequest;
use App\Services\Map\MapPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POPIA owner-detail gate — server-side proofs.
 *
 * The Seller/Agent toggle controls whether owner PII reaches the browser.
 * Per POPIA + PPRA Code of Conduct, a seller/buyer must never receive
 * another owner's personal details without lawful basis. The gate MUST
 * be enforced server-side at the network-payload layer — CSS/JS hiding
 * is non-compliant because the bytes are still in page source.
 *
 * These tests prove what is on the wire, not what is rendered. Each test
 * inspects the JSON payload directly for the presence/absence of the
 * specific PII strings inserted into the database fixture.
 *
 * Field set considered "owner PII" by this gate
 * (`.ai/specs/map-owner-pii-egress.md` §1):
 *   owner_name, owner_phone, owner_email, owner_id_number,
 *   purchase_date, purchase_price, bond_holder, bond_amount, bond_date,
 *   buyer_name, seller_name, agent_name (competitor practitioner).
 */
final class OwnerPiiGateTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_NAME  = 'POPIA-Test-Owner-Surname';

    /**
     * `seedRoleWithoutProspecting` flips `PermissionService::$seeded`
     * from "unseeded → grant all" to "seeded → honour table". RefreshDatabase
     * truncates `role_permissions` between tests but does not reset the
     * static; without this teardown later tests in the same suite see an
     * empty `role_permissions` table with `$seeded === true` and treat every
     * user as having zero permissions — surfacing as spurious 403s in
     * unrelated Map tests.
     */
    protected function tearDown(): void
    {
        \App\Services\PermissionService::clearCache();
        parent::tearDown();
    }

    // ── resolveViewMode helper — direct unit assertions ─────────────────

    /** No flag from an authorised user → Seller (default-safe). */
    public function test_resolve_view_mode_defaults_to_seller_when_flag_absent(): void
    {
        [$agencyId] = $this->makeAgencies();
        $user = $this->makeUserInAgency($agencyId);  // unseeded perms → always granted

        $req = Request::create('/corex/map/pins');
        $req->setUserResolver(fn () => $user);

        $this->assertSame('seller', MapController::resolveViewMode($req));
    }

    /** Explicit ?viewMode=seller stays seller. */
    public function test_resolve_view_mode_explicit_seller_stays_seller(): void
    {
        [$agencyId] = $this->makeAgencies();
        $user = $this->makeUserInAgency($agencyId);

        $req = Request::create('/corex/map/pins?viewMode=seller');
        $req->setUserResolver(fn () => $user);

        $this->assertSame('seller', MapController::resolveViewMode($req));
    }

    /** Agent flag + permission held → Agent. */
    public function test_resolve_view_mode_agent_granted_when_permission_held(): void
    {
        [$agencyId] = $this->makeAgencies();
        $user = $this->makeUserInAgency($agencyId);  // unseeded → all perms

        $req = Request::create('/corex/map/pins?viewMode=agent');
        $req->setUserResolver(fn () => $user);

        $this->assertSame('agent', MapController::resolveViewMode($req));
    }

    /** Agent flag + permission missing → Seller (server-side override). */
    public function test_resolve_view_mode_agent_denied_without_permission(): void
    {
        [$agencyId] = $this->makeAgencies();
        // Seed role_permissions so PermissionService stops the
        // "unseeded = grant-all" fallthrough. We deliberately seed a row
        // that is NOT access_prospecting against the user's role, so
        // hasPermission('access_prospecting') returns false.
        $this->seedRoleWithoutProspecting('pii-blocked-' . Str::random(6));
        $user = User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => $this->lastBlockedRoleName,
        ]);

        $req = Request::create('/corex/map/pins?viewMode=agent');
        $req->setUserResolver(fn () => $user);

        $this->assertSame(
            'seller',
            MapController::resolveViewMode($req),
            'Agent View must be downgraded to Seller when the user lacks access_prospecting',
        );
    }

    /** No authenticated user → Seller. */
    public function test_resolve_view_mode_no_user_is_seller(): void
    {
        $req = Request::create('/corex/map/pins?viewMode=agent');
        $req->setUserResolver(fn () => null);

        $this->assertSame('seller', MapController::resolveViewMode($req));
    }

    // ── MapPinService bounds payload — scheme_owners redaction ─────────

    /** Seller View bounds payload: owner_name must not appear anywhere
     *  in the JSON bytes. Proves redaction at the network-payload layer. */
    public function test_bounds_payload_redacts_scheme_owner_pii_in_seller_view(): void
    {
        [$agencyId] = $this->makeAgencies();
        $this->insertSchemeOwnerWithReport($agencyId);

        $svc = new MapPinService();
        $req = $this->bounds($agencyId, viewMode: 'seller', layers: ['scheme_owners']);
        $resp = $svc->getPinsInBounds($req);

        $encoded = json_encode($resp, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(
            self::OWNER_NAME, $encoded,
            'Seller View must not carry owner_name in the bounds payload',
        );
    }

    /** Agent View bounds payload: owner_name surfaces in subtitle. */
    public function test_bounds_payload_exposes_scheme_owner_name_in_agent_view(): void
    {
        [$agencyId] = $this->makeAgencies();
        $this->insertSchemeOwnerWithReport($agencyId);

        $svc = new MapPinService();
        $req = $this->bounds($agencyId, viewMode: 'agent', layers: ['scheme_owners']);
        $resp = $svc->getPinsInBounds($req);

        $encoded = json_encode($resp, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(
            self::OWNER_NAME, $encoded,
            'Agent View must carry owner_name in the bounds payload',
        );
    }

    /** Pin with no owner data renders in either mode without 500. */
    public function test_bounds_payload_handles_empty_owner_in_both_modes(): void
    {
        [$agencyId] = $this->makeAgencies();
        $this->insertSchemeOwnerWithReport($agencyId, ownerName: null);

        $svc = new MapPinService();
        foreach (['seller', 'agent'] as $mode) {
            $req  = $this->bounds($agencyId, viewMode: $mode, layers: ['scheme_owners']);
            $resp = $svc->getPinsInBounds($req);
            $this->assertIsArray($resp['locations']);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private ?string $lastBlockedRoleName = null;

    private function bounds(int $agencyId, string $viewMode, array $layers): MapBoundsRequest
    {
        return new MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0,
            layers: $layers, viewMode: $viewMode, agencyId: $agencyId,
        );
    }

    private function makeAgencies(): array
    {
        $id = (int) DB::table('agencies')->insertGetId([
            'name'       => 'PII-Test-Agency-' . Str::random(6),
            'slug'       => 'pii-test-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $id, 'agency_id' => $id, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$id];
    }

    private function makeUserInAgency(int $agencyId): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
        ]);
    }

    private function insertSchemeOwnerWithReport(int $agencyId, ?string $ownerName = self::OWNER_NAME): void
    {
        $schemeName = 'POPIA-Test-Scheme-' . Str::random(4);

        DB::table('market_report_types')->insertOrIgnore([
            'key' => 'cma_info_property', 'display_name' => 'CMA Info Property',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $reportTypeId = (int) DB::table('market_report_types')->where('key', 'cma_info_property')->value('id');

        $uploader = $this->makeUserInAgency($agencyId);
        $reportId = (int) DB::table('market_reports')->insertGetId([
            'agency_id'            => $agencyId,
            'uploaded_by_user_id'  => $uploader->id,
            'report_type_id'       => $reportTypeId,
            'file_path'            => 'test/popia.pdf',
            'file_name'            => 'popia.pdf',
            'file_hash'             => hash('sha256', 'popia-' . Str::random(8)),
            'report_date'          => now()->toDateString(),
            'subject_address'      => '1 Sectional Lane, Uvongo',
            'subject_scheme_name'  => $schemeName,
            'subject_latitude'     => -30.84,
            'subject_longitude'    => 30.39,
            'parser_version'       => 'test',
            'is_demo'              => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('scheme_owners')->insert([
            'agency_id'        => $agencyId,
            'market_report_id' => $reportId,
            'scheme_name'      => $schemeName,
            'section_number'   => 1,
            'owner_name'       => $ownerName ?? 'Unknown owner',
            'is_demo'          => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Seed role_permissions with a sentinel role that holds *some* permission
     * but NOT access_prospecting. This both (a) flips the
     * PermissionService::$seeded gate so the "unseeded = grant all" fallback
     * no longer applies, and (b) gives us a known-blocked role to assign to
     * the test user.
     */
    private function seedRoleWithoutProspecting(string $roleName): void
    {
        DB::table('roles')->insertOrIgnore([
            'name' => $roleName, 'label' => 'PII Blocked', 'is_owner' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('nexus_permissions')->insertOrIgnore([
            'key' => 'view_dashboard', 'label' => 'View Dashboard',
            'section' => 'dashboard', 'type' => 'access', 'module' => 'dashboard',
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_permissions')->insert([
            'role' => $roleName, 'permission_key' => 'view_dashboard',
            'scope' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        \App\Services\PermissionService::clearCache();
        $this->lastBlockedRoleName = $roleName;
    }
}
