<?php

namespace Database\Seeders;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

class DealPipelineTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // Dev: force-delete and recreate to pick up new columns
        DealPipelineStep::query()->forceDelete();
        DealPipelineTemplate::query()->forceDelete();

        $admin = User::where('is_admin', true)->first() ?? User::first();
        $adminId = $admin?->id ?? 1;
        $agencyId = $admin?->agency_id ?? (int) (\DB::table('agencies')->value('id') ?? 1);

        $this->seedBondSale($adminId, $agencyId);
        $this->seedCashSale($adminId, $agencyId);
        $this->seedSaleOf2nd($adminId, $agencyId);
    }

    private function seedBondSale(int $adminId, int $agencyId): void
    {
        $template = DealPipelineTemplate::create([
            'name' => 'Standard Bond Sale',
            'deal_type' => 'bond',
            'agency_id' => $agencyId,
            'branch_id' => null,
            'is_default' => true,
            'is_active' => true,
            'created_by_id' => $adminId,
        ]);

        // [position, name, is_locked, is_milestone, completion_type, trigger_type, trigger_step_name, days_offset, rag_green, rag_amber, rag_red, status_trigger, negative_status_trigger, negative_outcome_label, requires_bm_approval]
        $steps = [
            [1,  'OTP Signed',                true,  true,  'date_input',       'on_creation', null,                         0,  14, 7,  3,  null,        'cancelled', 'OTP Rejected',   false],
            [2,  'Bond Application Submitted', false, false, 'date_input',       'after_step',  'OTP Signed',                 3,  10, 5,  2,  null,        null,        null,             false],
            [3,  'Bond Approved',              true,  true,  'date_input',       'after_step',  'Bond Application Submitted',  30, 15, 7,  3,  'granted',   'cancelled', 'Bond Declined',  true],
            [4,  'Deposit Paid',               true,  false, 'amount_input',     'after_step',  'Bond Approved',               7,  10, 5,  2,  null,        null,        null,             false],
            [5,  'Attorney Instructed',        false, false, 'text_input',       'after_step',  'Bond Approved',               5,  10, 5,  2,  null,        null,        null,             false],
            [6,  'FICA Completed (Buyer)',     true,  false, 'document_upload',  'after_step',  'Attorney Instructed',         14, 10, 5,  2,  null,        null,        null,             false],
            [7,  'FICA Completed (Seller)',    true,  false, 'document_upload',  'after_step',  'Attorney Instructed',         14, 10, 5,  2,  null,        null,        null,             false],
            [8,  'Electrical COC',             true,  false, 'document_upload',  'after_step',  'Bond Approved',               30, 14, 7,  3,  null,        null,        null,             false],
            [9,  'Gas COC',                    false, false, 'document_upload',  'after_step',  'Bond Approved',               30, 14, 7,  3,  null,        null,        null,             false],
            [10, 'Electric Fence COC',         false, false, 'document_upload',  'after_step',  'Bond Approved',               30, 14, 7,  3,  null,        null,        null,             false],
            [11, 'Beetle Certificate',         false, false, 'document_upload',  'after_step',  'Bond Approved',               30, 14, 7,  3,  null,        null,        null,             false],
            [12, 'Water Installation COC',     false, false, 'document_upload',  'after_step',  'Bond Approved',               30, 14, 7,  3,  null,        null,        null,             false],
            [13, 'Rates Clearance',            true,  false, 'document_upload',  'after_step',  'Attorney Instructed',         42, 21, 10, 5,  null,        null,        null,             false],
            [14, 'Deeds Office Lodgement',     true,  true,  'date_input',       'after_step',  'Rates Clearance',             7,  10, 5,  2,  null,        null,        null,             false],
            [15, 'Registration',               true,  true,  'date_input',       'after_step',  'Deeds Office Lodgement',      15, 10, 5,  2,  'completed', null,        null,             false],
        ];

        $this->createSteps($template, $steps);
    }

    private function seedCashSale(int $adminId, int $agencyId): void
    {
        $template = DealPipelineTemplate::create([
            'name' => 'Cash Sale',
            'deal_type' => 'cash',
            'agency_id' => $agencyId,
            'branch_id' => null,
            'is_default' => false,
            'is_active' => true,
            'created_by_id' => $adminId,
        ]);

        $steps = [
            [1, 'OTP Signed',               true,  true,  'date_input',       'on_creation', null,                     0,  14, 7, 3,  null,        'cancelled', 'OTP Rejected', false],
            [2, 'Deposit Paid',              true,  false, 'amount_input',     'after_step',  'OTP Signed',             7,  10, 5, 2,  'granted',   null,        null,           true],
            [3, 'Attorney Instructed',       false, false, 'text_input',       'after_step',  'OTP Signed',             5,  10, 5, 2,  null,        null,        null,           false],
            [4, 'FICA Completed (Buyer)',    true,  false, 'document_upload',  'after_step',  'Attorney Instructed',    14, 10, 5, 2,  null,        null,        null,           false],
            [5, 'FICA Completed (Seller)',   true,  false, 'document_upload',  'after_step',  'Attorney Instructed',    14, 10, 5, 2,  null,        null,        null,           false],
            [6, 'Electrical COC',            true,  false, 'document_upload',  'after_step',  'OTP Signed',             21, 14, 7, 3,  null,        null,        null,           false],
            [7, 'Rates Clearance',           true,  false, 'document_upload',  'after_step',  'Attorney Instructed',    28, 14, 7, 3,  null,        null,        null,           false],
            [8, 'Deeds Office Lodgement',    true,  true,  'date_input',       'after_step',  'Rates Clearance',        7,  10, 5, 2,  null,        null,        null,           false],
            [9, 'Registration',              true,  true,  'date_input',       'after_step',  'Deeds Office Lodgement', 15, 10, 5, 2,  'completed', null,        null,           false],
        ];

        $this->createSteps($template, $steps);
    }

    private function seedSaleOf2nd(int $adminId, int $agencyId): void
    {
        $template = DealPipelineTemplate::create([
            'name' => 'Sale of Second Property',
            'deal_type' => 'sale_of_2nd',
            'agency_id' => $agencyId,
            'branch_id' => null,
            'is_default' => false,
            'is_active' => true,
            'created_by_id' => $adminId,
        ]);

        $steps = [
            [1,  'OTP Signed',                true,  true,  'date_input',              'on_creation', null,                         0,  14, 7,  3,  null,        'cancelled', 'OTP Rejected',       false],
            [2,  'Linked Property Sold',       true,  true,  'auto_from_linked_deal',   'manual',      null,                         0,  14, 7,  3,  null,        'cancelled', 'Linked Sale Failed', false],
            [3,  'Bond Application Submitted', false, false, 'date_input',              'after_step',  'Linked Property Sold',        3,  10, 5,  2,  null,        null,        null,                 false],
            [4,  'Bond Approved',              true,  true,  'date_input',              'after_step',  'Bond Application Submitted',  30, 15, 7,  3,  'granted',   'cancelled', 'Bond Declined',      true],
            [5,  'Deposit Paid',               true,  false, 'amount_input',            'after_step',  'Bond Approved',               7,  10, 5,  2,  null,        null,        null,                 false],
            [6,  'Attorney Instructed',        false, false, 'text_input',              'after_step',  'Bond Approved',               5,  10, 5,  2,  null,        null,        null,                 false],
            [7,  'FICA Completed (Buyer)',     true,  false, 'document_upload',         'after_step',  'Attorney Instructed',         14, 10, 5,  2,  null,        null,        null,                 false],
            [8,  'FICA Completed (Seller)',    true,  false, 'document_upload',         'after_step',  'Attorney Instructed',         14, 10, 5,  2,  null,        null,        null,                 false],
            [9,  'Electrical COC',             true,  false, 'document_upload',         'after_step',  'Bond Approved',               30, 14, 7,  3,  null,        null,        null,                 false],
            [10, 'Gas COC',                    false, false, 'document_upload',         'after_step',  'Bond Approved',               30, 14, 7,  3,  null,        null,        null,                 false],
            [11, 'Electric Fence COC',         false, false, 'document_upload',         'after_step',  'Bond Approved',               30, 14, 7,  3,  null,        null,        null,                 false],
            [12, 'Beetle Certificate',         false, false, 'document_upload',         'after_step',  'Bond Approved',               30, 14, 7,  3,  null,        null,        null,                 false],
            [13, 'Water Installation COC',     false, false, 'document_upload',         'after_step',  'Bond Approved',               30, 14, 7,  3,  null,        null,        null,                 false],
            [14, 'Rates Clearance',            true,  false, 'document_upload',         'after_step',  'Attorney Instructed',         42, 21, 10, 5,  null,        null,        null,                 false],
            [15, 'Deeds Office Lodgement',     true,  true,  'date_input',              'after_step',  'Rates Clearance',             7,  10, 5,  2,  null,        null,        null,                 false],
            [16, 'Registration',               true,  true,  'date_input',              'after_step',  'Deeds Office Lodgement',      15, 10, 5,  2,  'completed', null,        null,                 false],
        ];

        $this->createSteps($template, $steps);
    }

    private function createSteps(DealPipelineTemplate $template, array $steps): void
    {
        $stepMap = [];

        foreach ($steps as [$position, $name, $isLocked, $isMilestone, $completionType, $triggerType, $triggerStepName, $daysOffset, $ragGreen, $ragAmber, $ragRed, $statusTrigger, $negativeStatusTrigger, $negativeOutcomeLabel, $requiresBmApproval]) {
            $step = $template->steps()->create([
                'agency_id' => $template->agency_id,
                'name' => $name,
                'position' => $position,
                'is_locked' => $isLocked,
                'is_milestone' => $isMilestone,
                'completion_type' => $completionType,
                'trigger_type' => $triggerType,
                'trigger_step_id' => null,
                'days_offset' => $daysOffset,
                // AT-158 WS7 — two-threshold RAG: green is derived ("not yet amber"),
                // no seeded green threshold. rag_green_days keeps its column default
                // (the calendar tile resolver still reads it until AT-164 reconciles
                // it to derived-green). $ragGreen in the tuple is retained but unused.
                'rag_amber_days' => $ragAmber,
                'rag_red_days' => $ragRed,
                'status_trigger' => $statusTrigger,
                'negative_status_trigger' => $negativeStatusTrigger,
                'negative_outcome_label' => $negativeOutcomeLabel,
                'requires_bm_approval' => $requiresBmApproval,
            ]);

            $stepMap[$name] = $step;
        }

        foreach ($steps as [$position, $name, $isLocked, $isMilestone, $completionType, $triggerType, $triggerStepName, $daysOffset, $ragGreen, $ragAmber, $ragRed, $statusTrigger, $negativeStatusTrigger, $negativeOutcomeLabel, $requiresBmApproval]) {
            if ($triggerStepName && isset($stepMap[$triggerStepName])) {
                $stepMap[$name]->update([
                    'trigger_step_id' => $stepMap[$triggerStepName]->id,
                ]);
            }
        }
    }
}
