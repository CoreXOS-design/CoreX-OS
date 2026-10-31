<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\LeaseBreach;
use App\Models\RentalCrew;
use App\Models\RentalInspection;
use App\Models\RentalNoticeTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsMobileInspectionExtras;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * Mobile parity, the web lists that are pages (breaches, sent notices, applications, the move-out deposit comparison), the form pickers, and
 * the user's own permission keys. The lists RUN the web action, so this proves the shape and that scope/permission hold, not the query itself.
 */
final class MobileListsAndPickersTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;
    use BuildsMobileInspectionExtras;

    private const M = '/api/v1/mobile';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->buildInspectionExtras();
    }

    private function viewer(): User
    {
        $this->grantScope($this->agency, 'viewer', 'all', ['leases.view', 'properties.view', 'access_properties']);

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);
    }

    public function test_the_breach_register_answers_the_web_list_as_data(): void
    {
        LeaseBreach::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'lease_id' => $this->lease->id, 'kind' => LeaseBreach::KIND_PAYMENT,
            'breach_party' => 'tenant', 'amount' => 4500, 'clause' => '5.2', 'reason' => 'Rent unpaid', 'status' => LeaseBreach::STATUS_ISSUED,
            'issued_on' => now()->toDateString(), 'remedy_deadline' => now()->addDays(20)->toDateString(), 'issued_by_user_id' => $this->admin->id,
        ]);

        $res = $this->asStaff($this->admin)->getJson(self::M . '/breaches')->assertOk();
        $res->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.kind', LeaseBreach::KIND_PAYMENT)->assertJsonPath('data.0.clause', '5.2')
            ->assertJsonStructure(['data' => [['id', 'lease_id', 'tenant', 'property', 'status', 'issued_on', 'remedy_deadline']], 'tile_counts', 'meta' => ['page', 'per_page', 'total', 'last_page']]);

        $this->asStaff($this->admin)->getJson(self::M . '/breaches?q=nomatchatall')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_sent_notices_and_applications_lists_answer_in_the_apps_shape(): void
    {
        $this->asStaff($this->admin)->getJson(self::M . '/rental-notices-sent')->assertOk()->assertJsonStructure(['data', 'meta' => ['page', 'total', 'last_page', 'empty_reason']]);
        $this->asStaff($this->admin)->getJson(self::M . '/rental-applications')->assertOk()->assertJsonStructure(['data', 'counts', 'meta' => ['page', 'total']]);
    }

    public function test_the_lists_need_their_web_permission(): void
    {
        $viewer = $this->viewer();
        foreach (['/breaches', '/rental-notices-sent', '/rental-applications', '/rental-notice-templates', '/rental-crews'] as $path) {
            $this->asStaff($viewer)->getJson(self::M . $path)->assertForbidden();
        }
    }

    public function test_the_move_out_deposit_comparison_is_read_as_data(): void
    {
        $out = $this->inspectionRow(RentalInspection::TYPE_OUT);

        $this->asStaff($this->admin)->getJson(self::M . "/rental-inspections/{$out->id}/deposit-comparison")
            ->assertOk()->assertJsonPath('inspection_id', $out->id)->assertJsonStructure(['comparison', 'difference_labels', 'disposition_labels', 'room_options']);
    }

    public function test_the_pickers_return_this_agencys_people_templates_and_crews(): void
    {
        // created before any request logs a user in: the model stamps the acting user's agency on create
        RentalNoticeTemplate::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Payment breach', 'notice_type' => RentalNoticeTemplate::TYPE_BREACH_PAYMENT, 'body_html' => '<p>x</p>', 'is_active' => true]);
        RentalNoticeTemplate::withoutGlobalScopes()->create(['agency_id' => $this->other->id, 'name' => 'Theirs', 'notice_type' => RentalNoticeTemplate::TYPE_BREACH_PAYMENT, 'body_html' => '<p>x</p>', 'is_active' => true]);
        RentalCrew::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Blue crew', 'is_active' => true]);

        $res = $this->asStaff($this->admin)->getJson(self::M . '/rental-inspectors')->assertOk();
        $this->assertContains($this->agent->id, array_column($res->json('data'), 'id'));
        $this->assertNotContains($this->outsider->id, array_column($res->json('data'), 'id'));
        $res->assertJsonPath('default_inspector_id', $this->admin->id);

        $this->asStaff($this->admin)->getJson(self::M . "/leases/{$this->lease->id}/selectable-agents")->assertOk()->assertJsonStructure(['data' => [['id', 'name', 'branch_id', 'in_branch']]]);

        $tpl = $this->asStaff($this->admin)->getJson(self::M . '/rental-notice-templates')->assertOk();
        $this->assertSame(['Payment breach'], array_column($tpl->json('data'), 'name'));

        $this->asStaff($this->admin)->getJson(self::M . '/rental-crews')->assertOk()->assertJsonPath('data.0.name', 'Blue crew');
    }

    public function test_the_json_property_pickers_the_web_has_are_reachable_and_not_swallowed_by_an_id_route(): void
    {
        $this->asStaff($this->admin)->getJson(self::M . '/leases/party-check')->assertOk(); // the web's own answer, not a 404 from the {lease} route
        $this->asStaff($this->admin)->getJson(self::M . '/leases/search-properties?q=Sea')->assertOk();
        $this->asStaff($this->admin)->getJson(self::M . '/rental-job-cards/search-properties?q=Sea')->assertOk();
        // and the id routes still work for a real id
        $this->asStaff($this->admin)->getJson(self::M . "/leases/{$this->lease->id}")->assertOk();
    }

    public function test_the_permission_echo_lists_the_users_own_rentals_keys(): void
    {
        $this->grantEveryone();
        $viewer = $this->viewer();

        $mine = $this->asStaff($viewer)->getJson(self::M . '/rentals/permissions')->assertOk()->json('permissions');
        $this->assertTrue($mine['leases.view']);
        $this->assertFalse($mine['leases.create'] ?? false);
        $this->assertArrayHasKey('rental_inspections.cancel', $mine);
        $this->assertFalse($mine['rental_inspections.cancel']);
        $this->assertArrayNotHasKey('view_dashboard', $mine, 'only the rentals areas');

        $this->asStaff($this->agent)->getJson(self::M . '/rentals/permissions')->assertOk()->assertJsonPath('permissions.rental_inspections.create', fn ($v) => true);
    }
}
