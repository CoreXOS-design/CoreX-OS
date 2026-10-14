<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Models\Agency;
use App\Models\AgentCapPeriod;
use App\Models\Branch;
use App\Models\CommissionSetting;
use App\Models\User;
use App\Services\CommissionCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan, 14 Oct 2026: an annual cap of 0 / blank means NO CAP - never "capped from the first deal" (which skipped the agency's split and
 * mentor fee, so an agency that entered a split but left the cap blank paid its agents 100%). A real cap is a positive amount, and an
 * agency with one (HFC's R160 000) behaves exactly as before.
 */
final class CapZeroMeansNoCapTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Cap Agency', 'slug' => 'cap-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
    }

    private function settings(float $cap): CommissionSetting
    {
        $s = CommissionSetting::forAgency((int) $this->agency->id);
        $s->update([
            'commission_split_agent' => 80, 'commission_split_agency' => 20, 'annual_cap' => $cap,
            'post_cap_transaction_fee' => 2500, 'post_cap_fee_cap' => 50000, 'post_cap_reduced_fee' => 750,
            'risk_management_fee' => 400, 'risk_management_cap' => 5000,
            'mentor_program_enabled' => false, 'revenue_share_enabled' => false,
        ]);

        return $s;
    }

    /** R115 000 gross incl. VAT = R100 000 excl. */
    private function deal(string $label): \App\Models\CommissionLedger
    {
        return CommissionCalculationService::calculateDealCommission((int) $this->agent->id, '115000.00', '15000.00', 'sale', $label);
    }

    public function test_with_no_cap_the_split_applies_on_every_deal_and_the_agent_is_never_capped(): void
    {
        $this->settings(0);

        foreach (range(1, 12) as $n) {
            $entry = $this->deal("deal {$n}");
            self::assertFalse((bool) $entry->is_post_cap, "deal {$n} is not post-cap");
            self::assertEquals(80, $entry->agent_split_percent, "deal {$n}: the agency's 80% split applies");
            self::assertEquals('0.00', (string) $entry->transaction_fee, "deal {$n}: no post-cap fee");
        }

        $period = AgentCapPeriod::currentForUser((int) $this->agent->id, (int) $this->agency->id)->fresh();
        self::assertFalse($period->hasCap());
        self::assertFalse($period->checkCap());
        self::assertFalse((bool) $period->is_capped, 'the period is never flagged capped');
        self::assertFalse($this->agent->fresh()->isCapped());
        self::assertSame(0.0, $period->getCapProgressPercent(), 'no cap: nothing to be a percentage of');
        self::assertSame('0.00', $period->getRemainingToCap());
    }

    public function test_a_period_wrongly_flagged_capped_by_the_old_rule_is_not_capped_when_there_is_no_cap(): void
    {
        $this->settings(0);
        $period = AgentCapPeriod::currentForUser((int) $this->agent->id, (int) $this->agency->id);
        $period->forceFill(['is_capped' => true, 'capped_at' => now(), 'company_dollar_paid' => 5000])->save();

        self::assertFalse($period->fresh()->checkCap());
        self::assertEquals(80, $this->deal('after the old flag')->agent_split_percent);
    }

    public function test_with_a_real_cap_the_post_cap_path_starts_after_the_cap_and_not_before(): void
    {
        $this->settings(160000);

        // each deal: agency's 20% (R20 000) + the R400 risk fee = R20 400 of company dollar; R160 000 is reached on the 8th deal
        foreach (range(1, 8) as $n) {
            $entry = $this->deal("deal {$n}");
            self::assertFalse((bool) $entry->is_post_cap, "deal {$n} is still pre-cap");
            self::assertEquals(80, $entry->agent_split_percent);
        }

        $period = AgentCapPeriod::currentForUser((int) $this->agent->id, (int) $this->agency->id)->fresh();
        self::assertTrue($period->hasCap());
        self::assertTrue($period->checkCap(), 'R163 200 of company dollar has passed the R160 000 cap');
        self::assertTrue((bool) $period->is_capped);

        $ninth = $this->deal('deal 9');
        self::assertTrue((bool) $ninth->is_post_cap);
        self::assertEquals(100, $ninth->agent_split_percent, 'post-cap: the agent keeps 100% less the fees');
        self::assertEquals('2500.00', (string) $ninth->transaction_fee);
    }

    public function test_a_blank_cap_in_settings_is_saved_as_no_cap_and_an_open_no_cap_period_adopts_a_cap_entered_later(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin', 'is_active' => true]);
        $payload = [
            'commission_split_agent' => 80, 'annual_cap' => '', 'post_cap_transaction_fee' => 2500, 'post_cap_fee_cap' => 50000,
            'post_cap_reduced_fee' => 750, 'monthly_platform_fee' => 0, 'risk_management_fee' => 400, 'risk_management_cap' => 5000,
            'mentor_extra_split' => 20, 'mentor_transactions' => 3, 'revenue_share_pool_percent' => 50,
            'tier_1_percent' => 0, 'tier_2_percent' => 0, 'tier_3_percent' => 0, 'tier_4_percent' => 0, 'tier_5_percent' => 0,
            'tier_6_percent' => 0, 'tier_7_percent' => 0,
            'tier_4_flqa_requirement' => 0, 'tier_5_flqa_requirement' => 0, 'tier_6_flqa_requirement' => 0, 'tier_7_flqa_requirement' => 0,
        ];

        $this->actingAs($admin)->post(route('corex.settings.commission.update'), $payload)->assertRedirect();
        self::assertEquals(0, CommissionSetting::forAgency((int) $this->agency->id)->fresh()->annual_cap, 'blank is saved as no cap');

        // the agent's period opens while there is no cap
        $this->deal('first deal');
        $period = AgentCapPeriod::currentForUser((int) $this->agent->id, (int) $this->agency->id);
        self::assertEquals(0, $period->fresh()->cap_amount);

        // the agency now enters its own cap: the open no-cap period takes it up
        $this->actingAs($admin)->post(route('corex.settings.commission.update'), ['annual_cap' => 160000] + $payload)->assertRedirect();
        self::assertEquals(160000, $period->fresh()->cap_amount);

        // a period that already has a positive cap keeps it when the agency changes the setting
        $this->actingAs($admin)->post(route('corex.settings.commission.update'), ['annual_cap' => 90000] + $payload)->assertRedirect();
        self::assertEquals(160000, $period->fresh()->cap_amount, 'a positive cap in force is not rewritten');
    }
}
