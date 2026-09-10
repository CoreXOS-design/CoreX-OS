<?php

namespace Database\Seeders;

use App\Models\CommandCenter\DocumentExpectation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-02 config sweep — Command Center → Document Expectations (Operations
 * settings tab) was a genuine empty state for every agency: no seeder ever
 * existed for `command_document_expectations`, unlike its sibling
 * AutomationRule (CommandCenterAutomationSeeder). A prospective agency sees
 * "no document expectations configured" on first look at this tab.
 *
 * Per-agency data (not global reference data — BelongsToAgency), so this is
 * NOT a SyncableReferenceSeeder; call it explicitly per agency, same as
 * DealStageDocumentRuleSeeder/DocumentDistributionMatrixSeeder.
 *
 * Idempotent: firstOrCreate keyed on (agency_id, property_type, document_type_id).
 */
class DemoDocumentExpectationsSeeder
{
    public function run(int $agencyId): array
    {
        $typeIds = DB::table('document_types')->whereIn('slug', ['mandate', 'marketing_permission', 'fica', 'otp'])
            ->pluck('id', 'slug');

        if ($typeIds->isEmpty()) {
            return ['created' => 0, 'note' => 'Skipped — no matching document types on this agency.'];
        }

        $rows = [
            ['property_type' => 'house',      'slug' => 'mandate',              'label' => 'Signed Mandate',            'required' => true,  'due_offset_hours' => 24,  'sort_order' => 1],
            ['property_type' => 'house',      'slug' => 'marketing_permission', 'label' => 'Marketing Permission',      'required' => true,  'due_offset_hours' => 24,  'sort_order' => 2],
            ['property_type' => 'house',      'slug' => 'fica',                 'label' => 'Seller FICA',                'required' => true,  'due_offset_hours' => 72,  'sort_order' => 3],
            ['property_type' => 'sectional',  'slug' => 'mandate',              'label' => 'Signed Mandate',            'required' => true,  'due_offset_hours' => 24,  'sort_order' => 1],
            ['property_type' => 'sectional',  'slug' => 'marketing_permission', 'label' => 'Marketing Permission',      'required' => true,  'due_offset_hours' => 24,  'sort_order' => 2],
            ['property_type' => 'vacant',     'slug' => 'mandate',              'label' => 'Signed Mandate',            'required' => true,  'due_offset_hours' => 24,  'sort_order' => 1],
            ['property_type' => 'house',      'slug' => 'otp',                  'label' => 'Signed Offer to Purchase',  'required' => false, 'due_offset_hours' => 48,  'sort_order' => 4],
        ];

        $created = 0;
        foreach ($rows as $row) {
            $typeId = $typeIds[$row['slug']] ?? null;
            if (! $typeId) {
                continue;
            }

            $expectation = DocumentExpectation::withoutGlobalScopes()->firstOrCreate(
                [
                    'agency_id'         => $agencyId,
                    'property_type'     => $row['property_type'],
                    'document_type_id'  => $typeId,
                ],
                [
                    'label'            => $row['label'],
                    'required'         => $row['required'],
                    'due_offset_hours' => $row['due_offset_hours'],
                    'sort_order'       => $row['sort_order'],
                ]
            );
            if ($expectation->wasRecentlyCreated) {
                $created++;
            }
        }

        return ['created' => $created];
    }
}
