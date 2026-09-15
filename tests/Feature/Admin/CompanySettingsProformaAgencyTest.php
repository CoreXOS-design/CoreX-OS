<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\AgencyFeature;
use App\Models\Proforma\AgencyProformaSettings;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Company Settings › Proforma panel must use the agency the page is SHOWING,
 * never the login's effective agency (Rule 17). A system owner who has not
 * switched into an agency has no effective agency; `(int) null` is the
 * agency_id = 0 sentinel that BelongsToAgency refuses — the 500 Andre hit on
 * QA2 on 2026-09-15. Same fix for the save side (ProformaSettingsController).
 */
final class CompanySettingsProformaAgencyTest extends TestCase
{
    use RefreshDatabase;

    private Agency $coastal;
    private Agency $inland;

    protected function setUp(): void
    {
        parent::setUp();

        // Alphabetical so "first agency shown" is deterministic.
        $this->coastal = Agency::create(['name' => 'A Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->inland  = Agency::create(['name' => 'B Inland Homes', 'slug' => 'inland-' . uniqid()]);
        Branch::create(['agency_id' => $this->coastal->id, 'name' => 'Margate']);
        Branch::create(['agency_id' => $this->inland->id, 'name' => 'Hilton']);

        Role::forceCreate(['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => true]); // is_owner is NOT fillable
        Role::clearCache();
    }

    /** A system owner with NO agency and NO switcher selection — the QA2 login. */
    private function globalOwner(): User
    {
        return User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin', 'is_active' => true]);
    }

    private function agencyAdmin(Agency $agency): User
    {
        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $agency->id]);
        foreach (['manage_performance_settings', 'proforma.manage'] as $key) {
            RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $agency->id], []);
        }

        Role::clearCache();
        PermissionService::clearCache();
        AgencyFeature::create(['agency_id' => $agency->id, 'feature_key' => 'proforma-invoices', 'enabled' => true]);

        return User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin', 'is_active' => true]);
    }

    public function test_owner_without_an_agency_context_can_open_company_settings(): void
    {
        $resp = $this->actingAs($this->globalOwner())->get(route('admin.company-settings'));

        $resp->assertOk();
        $resp->assertDontSee('No agency found');
        $resp->assertSee('Proforma Invoice');

        // The panel resolved to the agency the page is showing (the first one) — never agency 0.
        $this->assertDatabaseHas('agency_proforma_settings', ['agency_id' => $this->coastal->id]);
        $this->assertDatabaseMissing('agency_proforma_settings', ['agency_id' => 0]);
    }

    public function test_owner_managing_another_agency_sees_that_agencys_proforma_settings(): void
    {
        AgencyProformaSettings::forAgency($this->inland->id)->update(['number_prefix' => 'INL-']);

        $resp = $this->actingAs($this->globalOwner())->get(route('admin.company-settings', ['agency' => $this->inland->id]));

        $resp->assertOk();
        $resp->assertSee('value="INL-"', false);
        $this->assertDatabaseMissing('agency_proforma_settings', ['agency_id' => $this->coastal->id]);
    }

    public function test_owner_save_lands_on_the_agency_the_page_showed(): void
    {
        $resp = $this->actingAs($this->globalOwner())->put(route('admin.proforma-settings.update'), [
            'agency_id'             => $this->inland->id,
            'from_company_settings' => $this->inland->id,
            'number_prefix'         => 'INL-',
            'number_padding'        => 5,
            'due_date_rule'         => 'days_after',
            'due_days'              => 14,
            'bank_details'          => "FNB\nAcc 62012345678\nBranch 250655",
        ]);

        $resp->assertSessionHasNoErrors();
        $resp->assertRedirect(route('admin.company-settings', ['agency' => $this->inland->id]) . '#company');
        $this->assertDatabaseHas('agency_proforma_settings', [
            'agency_id' => $this->inland->id, 'number_prefix' => 'INL-', 'number_padding' => 5, 'due_days' => 14,
        ]);
        $this->assertDatabaseMissing('agency_proforma_settings', ['agency_id' => $this->coastal->id]);
        $this->assertDatabaseMissing('agency_proforma_settings', ['agency_id' => 0]);
    }

    public function test_owner_save_with_no_resolvable_agency_is_refused_with_a_plain_message(): void
    {
        $resp = $this->actingAs($this->globalOwner())->put(route('admin.proforma-settings.update'), [
            'number_prefix' => 'X-', 'number_padding' => 4, 'due_date_rule' => 'on_receipt', 'due_days' => 0,
        ]);

        $resp->assertRedirect(route('admin.company-settings'));
        $resp->assertSessionHas('error');
        $this->assertDatabaseCount('agency_proforma_settings', 0);
    }

    public function test_agency_admin_cannot_redirect_a_save_to_another_agency(): void
    {
        $admin = $this->agencyAdmin($this->coastal);

        $resp = $this->actingAs($admin)->put(route('admin.proforma-settings.update'), [
            'agency_id'      => $this->inland->id, // forged — must be ignored for a non-owner
            'number_prefix'  => 'CST-',
            'number_padding' => 4,
            'due_date_rule'  => 'end_of_month',
            'due_days'       => 30,
        ]);

        $resp->assertSessionHasNoErrors();
        $this->assertDatabaseHas('agency_proforma_settings', ['agency_id' => $this->coastal->id, 'number_prefix' => 'CST-']);
        $this->assertDatabaseMissing('agency_proforma_settings', ['agency_id' => $this->inland->id]);
    }
}
