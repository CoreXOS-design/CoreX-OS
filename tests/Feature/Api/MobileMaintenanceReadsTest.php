<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\RentalJobCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsMobileInspectionExtras;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/** Mobile parity: the job-card list and the contractor list. Scoped to the agency, permissioned, searchable, paged in the app's list shape. */
final class MobileMaintenanceReadsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;
    use BuildsMobileInspectionExtras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->buildInspectionExtras();
    }

    private function card(string $title, ?int $agencyId = null): RentalJobCard
    {
        return RentalJobCard::withoutGlobalScopes()->create([
            'agency_id' => $agencyId ?? $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'title' => $title, 'status' => RentalJobCard::STATUS_DRAFT, 'created_by_user_id' => $this->admin->id,
        ]);
    }

    private function supplier(string $company, array $over = []): AgencyServiceProvider
    {
        return AgencyServiceProvider::withoutGlobalScopes()->create($over + ['agency_id' => $this->agency->id, 'company' => $company, 'name' => 'Contact ' . $company, 'is_active' => true]);
    }

    public function test_the_job_card_list_is_the_web_list_in_the_apps_shape(): void
    {
        $this->card('Paint the gate');
        $this->card('Fix the geyser');

        $res = $this->asStaff($this->admin)->getJson('/api/v1/mobile/rental-job-cards')->assertOk();
        $res->assertJsonPath('meta.total', 2)->assertJsonStructure(['data' => [['id', 'title', 'status', 'property_address', 'crew', 'scheduled_at']], 'tiles', 'scope', 'meta' => ['page', 'per_page', 'last_page', 'empty_reason']]);

        $this->asStaff($this->admin)->getJson('/api/v1/mobile/rental-job-cards?q=geyser')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Fix the geyser');
        $this->asStaff($this->admin)->getJson('/api/v1/mobile/rental-job-cards?q=zzzz')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('meta.empty_reason', 'No job cards match those filters.');
    }

    public function test_the_job_card_list_never_shows_another_agencys_cards_and_needs_the_view_permission(): void
    {
        $this->card('Mine');
        $this->card('Theirs', $this->other->id);

        $this->asStaff($this->admin)->getJson('/api/v1/mobile/rental-job-cards')->assertJsonPath('meta.total', 1);

        $this->grantScope($this->agency, 'viewer', 'all', ['rental_work_orders.view', 'properties.view', 'access_properties']);
        $viewer = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);
        $this->asStaff($viewer)->getJson('/api/v1/mobile/rental-job-cards')->assertForbidden();
    }

    public function test_the_contractor_list_is_this_agencys_active_contractors_with_search(): void
    {
        $this->supplier('Cape Plumbing', ['is_preferred' => true]);
        $this->supplier('Sparky Electrical');
        $this->supplier('Old Roofing', ['is_active' => false]);
        $this->supplier('Other Agency Plumbers', ['agency_id' => $this->other->id]);

        $res = $this->asStaff($this->admin)->getJson('/api/v1/mobile/rental-suppliers')->assertOk();
        $res->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.name', 'Cape Plumbing - Contact Cape Plumbing');
        $this->assertSame(['Cape Plumbing', 'Sparky Electrical'], array_column($res->json('data'), 'company'));

        $this->asStaff($this->admin)->getJson('/api/v1/mobile/rental-suppliers?q=spark')->assertJsonPath('meta.total', 1);
        $this->asStaff($this->admin)->getJson('/api/v1/mobile/rental-suppliers?q=nobody')->assertJsonPath('meta.total', 0)->assertJsonPath('meta.empty_reason', fn ($v) => is_string($v) && $v !== '');
    }
}
