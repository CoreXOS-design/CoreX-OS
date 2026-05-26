<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Leave\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    /**
     * Seed leave types for all existing agencies.
     */
    public function run(): void
    {
        foreach (Agency::all() as $agency) {
            $count = $this->seedForAgency($agency);
            $this->command?->info("Seeded {$count} leave types for {$agency->name} (id={$agency->id})");
        }
    }

    /**
     * Seed default BCEA-compliant leave types for a single agency.
     * Idempotent via firstOrCreate keyed on (agency_id, code).
     *
     * Called by this seeder for bulk, and by future agency creation hook.
     */
    public function seedForAgency(Agency $agency): int
    {
        $count = 0;

        foreach ($this->getDefaults() as $data) {
            LeaveType::firstOrCreate(
                ['agency_id' => $agency->id, 'code' => $data['code']],
                $data
            );
            $count++;
        }

        return $count;
    }

    /**
     * BCEA-compliant default leave types per Public Holidays Act,
     * BCEA ss 20-27, and Oct 2025 ConCourt ruling (Van Wyk v Min E&L).
     */
    private function getDefaults(): array
    {
        return [
            // §4.1 Annual Leave — BCEA s20
            [
                'code'                               => 'annual_leave',
                'label'                              => 'Annual Leave',
                'description'                        => 'BCEA s20 — 21 consecutive days per cycle',
                'category'                           => 'annual',
                'is_paid'                            => true,
                'is_uif_claimable'                   => false,
                'requires_documentation'             => false,
                'documentation_label'                => null,
                'documentation_threshold_days'       => null,
                'entitlement_days_per_cycle'         => 15.00,
                'entitlement_days_per_cycle_six_day' => 18.00,
                'cycle_months'                       => 12,
                'accrual_method'                     => 'accrual_per_day_worked',
                'accrual_rate_per_days'              => 17,
                'accrual_starts_at_employment_date'  => true,
                'requires_pre_approval'              => true,
                'min_advance_notice_days'            => 30,
                'allows_negative_balance'            => false,
                'carries_over_to_next_cycle'         => true,
                'forfeit_after_months'               => null,
                'payout_on_termination'              => true,
                'affects_payroll'                    => false,
                'is_system'                          => true,
                'is_active'                          => true,
                'sort_order'                         => 10,
            ],

            // §4.2 Sick Leave — BCEA s22
            [
                'code'                               => 'sick_leave',
                'label'                              => 'Sick Leave',
                'description'                        => 'BCEA s22 — 30 working days per 36-month cycle (5-day week)',
                'category'                           => 'sick',
                'is_paid'                            => true,
                'is_uif_claimable'                   => false,
                'requires_documentation'             => true,
                'documentation_label'                => 'Medical certificate',
                'documentation_threshold_days'       => 2,
                'entitlement_days_per_cycle'         => 30.00,
                'entitlement_days_per_cycle_six_day' => 36.00,
                'cycle_months'                       => 36,
                'accrual_method'                     => 'accrual_first_six_months',
                'accrual_rate_per_days'              => 26,
                'accrual_starts_at_employment_date'  => true,
                'requires_pre_approval'              => false,
                'min_advance_notice_days'            => 0,
                'allows_negative_balance'            => false,
                'carries_over_to_next_cycle'         => false,
                'forfeit_after_months'               => 36,
                'payout_on_termination'              => false,
                'affects_payroll'                    => false,
                'is_system'                          => true,
                'is_active'                          => true,
                'sort_order'                         => 20,
            ],

            // §4.3 Family Responsibility Leave — BCEA s27
            [
                'code'                               => 'family_responsibility_leave',
                'label'                              => 'Family Responsibility Leave',
                'description'                        => 'BCEA s27 — 3 days per cycle for qualifying events',
                'category'                           => 'family_responsibility',
                'is_paid'                            => true,
                'is_uif_claimable'                   => false,
                'requires_documentation'             => false,
                'documentation_label'                => 'Supporting document (death/birth certificate)',
                'documentation_threshold_days'       => null,
                'entitlement_days_per_cycle'         => 3.00,
                'entitlement_days_per_cycle_six_day' => 3.00,
                'cycle_months'                       => 12,
                'accrual_method'                     => 'full_at_start',
                'accrual_rate_per_days'              => 17,
                'accrual_starts_at_employment_date'  => true,
                'requires_pre_approval'              => true,
                'min_advance_notice_days'            => 0,
                'allows_negative_balance'            => false,
                'carries_over_to_next_cycle'         => false,
                'forfeit_after_months'               => null,
                'payout_on_termination'              => false,
                'affects_payroll'                    => false,
                'is_system'                          => true,
                'is_active'                          => true,
                'sort_order'                         => 30,
            ],

            // §4.4 Parental Leave — BCEA s25 (Oct 2025 ConCourt ruling)
            [
                'code'                               => 'parental_leave',
                'label'                              => 'Parental Leave',
                'description'                        => 'BCEA s25 (as amended Oct 2025 ConCourt ruling Van Wyk v Min E&L) — 4 months + 10 days SHARED parental leave pool',
                'category'                           => 'parental',
                'is_paid'                            => false,
                'is_uif_claimable'                   => true,
                'requires_documentation'             => true,
                'documentation_label'                => 'Birth certificate / adoption order / surrogacy agreement',
                'documentation_threshold_days'       => 0,
                'entitlement_days_per_cycle'         => 130.00,
                'entitlement_days_per_cycle_six_day' => 130.00,
                'cycle_months'                       => 0,
                'accrual_method'                     => 'none',
                'accrual_rate_per_days'              => 17,
                'accrual_starts_at_employment_date'  => true,
                'requires_pre_approval'              => true,
                'min_advance_notice_days'            => 30,
                'allows_negative_balance'            => false,
                'carries_over_to_next_cycle'         => false,
                'forfeit_after_months'               => null,
                'payout_on_termination'              => false,
                'affects_payroll'                    => true,
                'is_system'                          => true,
                'is_active'                          => true,
                'sort_order'                         => 40,
            ],

            // §4.5 Study Leave — agency policy, not BCEA-mandated
            [
                'code'                               => 'study_leave',
                'label'                              => 'Study Leave',
                'description'                        => 'Agency policy — not BCEA-mandated. Default zero days; agency admin grants per case.',
                'category'                           => 'study',
                'is_paid'                            => false,
                'is_uif_claimable'                   => false,
                'requires_documentation'             => true,
                'documentation_label'                => 'Proof of registration / exam timetable',
                'documentation_threshold_days'       => null,
                'entitlement_days_per_cycle'         => 0,
                'entitlement_days_per_cycle_six_day' => 0,
                'cycle_months'                       => 12,
                'accrual_method'                     => 'none',
                'accrual_rate_per_days'              => 17,
                'accrual_starts_at_employment_date'  => true,
                'requires_pre_approval'              => true,
                'min_advance_notice_days'            => 0,
                'allows_negative_balance'            => false,
                'carries_over_to_next_cycle'         => false,
                'forfeit_after_months'               => null,
                'payout_on_termination'              => false,
                'affects_payroll'                    => true,
                'is_system'                          => false,
                'is_active'                          => true,
                'sort_order'                         => 50,
            ],

            // §4.6 Unpaid Leave
            [
                'code'                               => 'unpaid_leave',
                'label'                              => 'Unpaid Leave',
                'description'                        => 'Approved leave without pay. Reduces gross by working_days x daily_rate.',
                'category'                           => 'unpaid',
                'is_paid'                            => false,
                'is_uif_claimable'                   => false,
                'requires_documentation'             => false,
                'documentation_label'                => null,
                'documentation_threshold_days'       => null,
                'entitlement_days_per_cycle'         => 0,
                'entitlement_days_per_cycle_six_day' => 0,
                'cycle_months'                       => 12,
                'accrual_method'                     => 'none',
                'accrual_rate_per_days'              => 17,
                'accrual_starts_at_employment_date'  => true,
                'requires_pre_approval'              => true,
                'min_advance_notice_days'            => 7,
                'allows_negative_balance'            => true,
                'carries_over_to_next_cycle'         => false,
                'forfeit_after_months'               => null,
                'payout_on_termination'              => false,
                'affects_payroll'                    => true,
                'is_system'                          => true,
                'is_active'                          => true,
                'sort_order'                         => 60,
            ],

            // §4.7 Special / Discretionary Leave
            [
                'code'                               => 'special_leave',
                'label'                              => 'Special / Discretionary Leave',
                'description'                        => 'Compassionate, religious, or other discretionary paid leave granted at employer discretion.',
                'category'                           => 'special',
                'is_paid'                            => true,
                'is_uif_claimable'                   => false,
                'requires_documentation'             => false,
                'documentation_label'                => null,
                'documentation_threshold_days'       => null,
                'entitlement_days_per_cycle'         => 0,
                'entitlement_days_per_cycle_six_day' => 0,
                'cycle_months'                       => 12,
                'accrual_method'                     => 'none',
                'accrual_rate_per_days'              => 17,
                'accrual_starts_at_employment_date'  => true,
                'requires_pre_approval'              => true,
                'min_advance_notice_days'            => 0,
                'allows_negative_balance'            => false,
                'carries_over_to_next_cycle'         => false,
                'forfeit_after_months'               => null,
                'payout_on_termination'              => false,
                'affects_payroll'                    => false,
                'is_system'                          => false,
                'is_active'                          => true,
                'sort_order'                         => 70,
            ],
        ];
    }
}
