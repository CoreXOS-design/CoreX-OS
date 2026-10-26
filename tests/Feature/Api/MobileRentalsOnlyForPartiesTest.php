<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AgencyFeature;
use App\Models\ContactType;
use App\Models\RentalPortalSetting;
use App\Services\Features\AgencyFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * Rentals show only to people who are in rentals.
 * .ai/specs/at-508-mobile-rentals.md "Rentals only for lessees and lessors".
 *
 * Client app: GET /client/me -> rentals {tenant, owner}. Staff app: GET /mobile/visibility -> rentals_enabled.
 */
final class MobileRentalsOnlyForPartiesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function sides($login): array
    {
        return $this->asClient($login)->getJson('/api/v1/client/me')->assertOk()->json('rentals');
    }

    private function switchRentalsFeature(bool $on): void
    {
        AgencyFeature::withoutGlobalScopes()->updateOrCreate(
            ['agency_id' => $this->agency->id, 'feature_key' => 'rentals'],
            ['enabled' => $on],
        );
        app(AgencyFeatureService::class)->forget();
    }

    private function type(string $esignRole): ContactType
    {
        return ContactType::firstOrCreate(['esign_role' => $esignRole], ['name' => ucfirst($esignRole), 'sort_order' => 1, 'is_active' => true]);
    }

    // ── clients ──────────────────────────────────────────────────────────────────────────────

    public function test_a_tenant_gets_the_tenant_side_only_and_an_owner_the_owner_side_only(): void
    {
        $this->assertSame(['tenant' => true, 'owner' => false], $this->sides($this->tenantLogin));
        $this->assertSame(['tenant' => false, 'owner' => true], $this->sides($this->ownerLogin));
    }

    public function test_a_buyer_or_seller_with_no_rental_standing_gets_no_rentals_at_all(): void
    {
        $buyer = $this->loginFor($this->contact('Bianca'));

        $this->assertSame(['tenant' => false, 'owner' => false], $this->sides($buyer));
    }

    public function test_a_person_marked_lessee_or_lessor_but_linked_to_no_rental_sees_nothing(): void
    {
        $lessee = $this->contact('Lesley');
        $lessee->parentTypes()->attach($this->type('lessee')->id);
        $lessor = $this->contact('Lorna');
        $lessor->parentTypes()->attach($this->type('lessor')->id);

        $this->assertSame(['tenant' => false, 'owner' => false], $this->sides($this->loginFor($lessee)), 'typed Lessee, on no lease');
        $this->assertSame(['tenant' => false, 'owner' => false], $this->sides($this->loginFor($lessor)), 'typed Lessor, linked to no property');
    }

    public function test_once_a_lessee_or_lessor_is_linked_to_a_rental_they_get_that_side(): void
    {
        $lessee = $this->contact('Lesley');
        $lessee->parentTypes()->attach($this->type('lessee')->id);
        \App\Models\LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $lessee->id, 'is_primary' => false]);
        $lessor = $this->contact('Lorna');
        $lessor->parentTypes()->attach($this->type('lessor')->id);
        $this->property->contacts()->attach($lessor->id, ['role' => 'lessor']);

        $this->assertSame(['tenant' => true, 'owner' => false], $this->sides($this->loginFor($lessee)));
        $this->assertSame(['tenant' => false, 'owner' => true], $this->sides($this->loginFor($lessor)));
    }

    public function test_a_buyer_type_does_not_open_rentals(): void
    {
        $buyer = $this->contact('Brian');
        $buyer->parentTypes()->attach($this->type('buyer')->id);

        $this->assertSame(['tenant' => false, 'owner' => false], $this->sides($this->loginFor($buyer)));
    }

    public function test_a_person_who_is_both_gets_both_sides(): void
    {
        \App\Models\LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->owner->id, 'is_primary' => false]);

        $this->assertSame(['tenant' => true, 'owner' => true], $this->sides($this->ownerLogin));
    }

    public function test_the_agency_switching_a_side_off_or_rentals_off_hides_it(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['tenant_portal_enabled' => false]);
        $this->assertSame(['tenant' => false, 'owner' => false], $this->sides($this->tenantLogin), 'tenant portal off');
        $this->assertSame(['tenant' => false, 'owner' => true], $this->sides($this->ownerLogin), 'the owner side is a separate switch');

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['tenant_portal_enabled' => true]);
        $this->switchRentalsFeature(false);
        $this->assertSame(['tenant' => false, 'owner' => false], $this->sides($this->tenantLogin));
        $this->assertSame(['tenant' => false, 'owner' => false], $this->sides($this->ownerLogin));
    }

    // ── staff ────────────────────────────────────────────────────────────────────────────────

    public function test_an_agent_with_rental_rights_has_rentals_an_agent_without_does_not(): void
    {
        $this->grantScope($this->agency, 'agent', 'own', ['rental_fault_reports.view', 'access_properties']);
        $this->asStaff($this->agent)->getJson('/api/v1/mobile/visibility')->assertOk()->assertJsonPath('rentals_enabled', true);

        // the same role with the rental rights taken away (other rights remain, so the role is still "held to" permissions)
        \App\Models\RolePermission::where('role', 'agent')->where('agency_id', $this->agency->id)->where('permission_key', 'rental_fault_reports.view')->delete();
        \App\Services\PermissionService::clearCache();
        $res = $this->asStaff($this->agent)->getJson('/api/v1/mobile/visibility')->assertOk();
        $res->assertJsonPath('rentals_enabled', false);
        $this->assertFalse($res->json('rentals.fault_reports.can_view'));
    }

    public function test_an_agency_with_rentals_switched_off_hides_every_rental_screen_even_for_an_agent_who_holds_the_rights(): void
    {
        $this->grantScope($this->agency, 'agent', 'own', ['rental_fault_reports.view', 'leases.view']);
        $this->switchRentalsFeature(false);

        $res = $this->asStaff($this->agent)->getJson('/api/v1/mobile/visibility')->assertOk();
        $res->assertJsonPath('rentals_enabled', false);
        foreach (['waiting', 'fault_reports', 'work_orders', 'leases', 'notices', 'inspections', 'inventories'] as $screen) {
            $this->assertFalse($res->json("rentals.$screen.can_view"), $screen);
            $this->assertNull($res->json("rentals.$screen.scope"), $screen);
        }
    }
}
