<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommissionSetting;
use App\Models\CommissionSettingAuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Go-live audit (2026-08-21) — commission settings could be changed with no
 * record of who changed them or when. Real money, no accountability. This
 * covers the fix: every save that actually changes a value writes an entry
 * to commission_setting_audit_log recording the old value, the new value,
 * who did it, and when.
 */
final class CommissionSettingAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function agencyAndAdmin(): array
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);

        return [$agency, $user];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'commission_split_agent' => 80,
            'annual_cap' => 160000,
            'post_cap_transaction_fee' => 2500,
            'post_cap_fee_cap' => 50000,
            'post_cap_reduced_fee' => 750,
            'monthly_platform_fee' => 850,
            'risk_management_fee' => 400,
            'risk_management_cap' => 5000,
            'mentor_extra_split' => 20,
            'mentor_transactions' => 3,
            'revenue_share_pool_percent' => 50,
            'tier_1_percent' => 3.5,
            'tier_2_percent' => 4,
            'tier_3_percent' => 2.5,
            'tier_4_percent' => 1.5,
            'tier_5_percent' => 1,
            'tier_6_percent' => 0.5,
            'tier_7_percent' => 0.25,
            'tier_4_flqa_requirement' => 5,
            'tier_5_flqa_requirement' => 10,
            'tier_6_flqa_requirement' => 15,
            'tier_7_flqa_requirement' => 20,
        ], $overrides);
    }

    public function test_changing_the_agent_split_writes_an_audit_entry_with_old_and_new_value(): void
    {
        [$agency, $admin] = $this->agencyAndAdmin();
        CommissionSetting::forAgency($agency->id)->update(['commission_split_agent' => 80]);

        $this->actingAs($admin)
            ->post('/settings/commission', $this->payload(['commission_split_agent' => 70]))
            ->assertRedirect();

        $entry = CommissionSettingAuditEntry::where('agency_id', $agency->id)->latest('performed_at')->first();

        $this->assertNotNull($entry, 'expected an audit entry to be written');
        $this->assertSame($admin->id, $entry->performed_by_user_id);
        $this->assertSame(80, (int) $entry->old_values['commission_split_agent']);
        $this->assertSame(70, (int) $entry->new_values['commission_split_agent']);
        // the derived agency-side split must be captured too, since it moved as a side effect
        $this->assertArrayHasKey('commission_split_agency', $entry->new_values);
    }

    public function test_saving_with_no_actual_changes_writes_no_audit_entry(): void
    {
        [$agency, $admin] = $this->agencyAndAdmin();
        CommissionSetting::forAgency($agency->id)->update($this->payload());

        $before = CommissionSettingAuditEntry::where('agency_id', $agency->id)->count();

        $this->actingAs($admin)
            ->post('/settings/commission', $this->payload())
            ->assertRedirect();

        $after = CommissionSettingAuditEntry::where('agency_id', $agency->id)->count();
        $this->assertSame($before, $after, 'an unchanged save must not add audit noise');
    }

    public function test_audit_entries_are_isolated_per_agency(): void
    {
        [$agencyA, $adminA] = $this->agencyAndAdmin();
        [$agencyB, $adminB] = $this->agencyAndAdmin();

        CommissionSetting::forAgency($agencyA->id)->update(['commission_split_agent' => 80]);
        CommissionSetting::forAgency($agencyB->id)->update(['commission_split_agent' => 80]);

        $this->actingAs($adminA)
            ->post('/settings/commission', $this->payload(['commission_split_agent' => 65]))
            ->assertRedirect();

        $this->assertSame(1, CommissionSettingAuditEntry::where('agency_id', $agencyA->id)->count());
        $this->assertSame(0, CommissionSettingAuditEntry::where('agency_id', $agencyB->id)->count());
    }
}
