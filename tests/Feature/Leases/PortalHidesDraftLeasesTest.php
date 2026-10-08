<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Services\Rentals\RentalPortalScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rentals front-half walk (QA1, 8 Oct 2026): a lease that is still a DRAFT (the agent's working copy - terms in flux,
 * not signed) must not show up in the tenant or owner portal; it appears once it is live. Also the sign-in code mail
 * must not call the portal "the CoreX mobile app" (the same mail signs people into the web portal).
 */
final class PortalHidesDraftLeasesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLeaseAgreementFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgreementFixture();
    }

    private function lease(string $status): Lease
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => $status, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id, 'signing_status' => Lease::SIGNING_NOT_SENT,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        return $lease;
    }

    public function test_a_draft_lease_is_invisible_to_the_tenant_and_the_owner_until_it_is_live(): void
    {
        $scope = app(RentalPortalScopeService::class);
        $draft = $this->lease(Lease::STATUS_DRAFT);

        $this->assertSame([], $scope->tenantLeaseIds($this->tenant));
        $this->assertNull($scope->tenantLease($this->tenant, $draft->id));
        $this->assertCount(0, $scope->tenantLeases($this->tenant));
        $this->assertCount(0, $scope->landlordLeases($this->landlord));
        $this->assertCount(0, $scope->landlordPropertyLeases($this->landlord, $this->property->id));

        $draft->forceFill(['status' => Lease::STATUS_ACTIVE])->save();

        $this->assertSame([$draft->id], $scope->tenantLeaseIds($this->tenant));
        $this->assertCount(1, $scope->tenantLeases($this->tenant));
        $this->assertCount(1, $scope->landlordLeases($this->landlord));
        $this->assertCount(1, $scope->landlordPropertyLeases($this->landlord, $this->property->id));
    }

    public function test_the_sign_in_code_mail_does_not_claim_the_portal_is_a_mobile_app(): void
    {
        $html = view('emails.client-auth.otp', ['code' => '123456', 'expiresMinutes' => 10])->render();

        $this->assertStringContainsString('123456', $html);
        $this->assertStringNotContainsString('mobile app', $html);
    }
}
