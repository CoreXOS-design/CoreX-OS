<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AgencyFeedbackOptionsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // Outcomes
            ['category' => 'outcome', 'label' => 'Interested',         'sort_order' => 10],
            ['category' => 'outcome', 'label' => 'Not interested',     'sort_order' => 20],
            ['category' => 'outcome', 'label' => 'Made offer',         'sort_order' => 30],
            ['category' => 'outcome', 'label' => 'No-show',            'sort_order' => 40],
            ['category' => 'outcome', 'label' => 'Cancelled',          'sort_order' => 50],
            ['category' => 'outcome', 'label' => 'Rescheduled',        'sort_order' => 60],

            // Concerns
            ['category' => 'concern', 'label' => 'Price',              'sort_order' => 10],
            ['category' => 'concern', 'label' => 'Location',           'sort_order' => 20],
            ['category' => 'concern', 'label' => 'Condition',          'sort_order' => 30],
            ['category' => 'concern', 'label' => 'Size',               'sort_order' => 40],
            ['category' => 'concern', 'label' => 'Layout',             'sort_order' => 50],
            ['category' => 'concern', 'label' => 'Damp / maintenance', 'sort_order' => 60],
            ['category' => 'concern', 'label' => 'School zone',        'sort_order' => 70],
            ['category' => 'concern', 'label' => 'Parking',            'sort_order' => 80],
            ['category' => 'concern', 'label' => 'Garden / outdoor',   'sort_order' => 90],

            // Lost reasons
            ['category' => 'lost_reason', 'label' => 'Bought elsewhere — bad service',  'sort_order' => 10],
            ['category' => 'lost_reason', 'label' => 'Bought elsewhere — wrong stock',  'sort_order' => 20],
            ['category' => 'lost_reason', 'label' => 'Bought elsewhere — better price', 'sort_order' => 30],
            ['category' => 'lost_reason', 'label' => 'Timing changed',                  'sort_order' => 40],
            ['category' => 'lost_reason', 'label' => 'Personal circumstances changed',  'sort_order' => 50],
            ['category' => 'lost_reason', 'label' => 'Lost contact',                    'sort_order' => 60],
            ['category' => 'lost_reason', 'label' => 'Other',                           'sort_order' => 99],

            // CAL-7 Class 4 — listing-presentation outcomes. Previously
            // these were hardcoded in CalendarController::showFeedback at
            // L624-627 ('Mandate signed', 'Considering', ...), bypassing
            // the seed table. Migrating into agency_feedback_options
            // means: same source of truth for every feedback variant; per-
            // agency override possible (set agency_id=X for a custom list);
            // empty-state banner (CAL-6) catches missing data uniformly
            // across feedback modes.
            ['category' => 'lp_outcome', 'label' => 'Mandate signed',       'sort_order' => 10],
            ['category' => 'lp_outcome', 'label' => 'Considering',          'sort_order' => 20],
            ['category' => 'lp_outcome', 'label' => 'Lost',                 'sort_order' => 30],
            ['category' => 'lp_outcome', 'label' => 'Other agent',          'sort_order' => 40],
            ['category' => 'lp_outcome', 'label' => 'Pricing too far',      'sort_order' => 50],
            ['category' => 'lp_outcome', 'label' => 'Not ready',            'sort_order' => 60],
            ['category' => 'lp_outcome', 'label' => 'Reschedule',           'sort_order' => 70],

            // CAL-7 Class 4 — listing-presentation mandate types. Same
            // story: previously hardcoded as ['Sole','Open','N/A']; now
            // seeded so the listing-presentation modal renders the same
            // way the per-contact mode does (empty table -> empty-state
            // banner; populated -> rendered options).
            ['category' => 'lp_mandate_type', 'label' => 'Sole', 'sort_order' => 10],
            ['category' => 'lp_mandate_type', 'label' => 'Open', 'sort_order' => 20],
            ['category' => 'lp_mandate_type', 'label' => 'N/A',  'sort_order' => 30],
        ];

        foreach ($defaults as $option) {
            DB::table('agency_feedback_options')->updateOrInsert(
                [
                    'agency_id' => null,
                    'category'  => $option['category'],
                    'label'     => $option['label'],
                ],
                array_merge($option, [
                    'agency_id'         => null,
                    'is_active'         => true,
                    'is_system_default' => true,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ])
            );
        }

        $this->command->info('Seeded ' . count($defaults) . ' agency feedback options.');
    }
}
