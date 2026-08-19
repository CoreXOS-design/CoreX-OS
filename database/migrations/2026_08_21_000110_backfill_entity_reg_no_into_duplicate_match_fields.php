<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Entity-type foundation (.ai/specs/contact-entity-type.md §6.7) — appends
 * 'entity_reg_no' to every EXISTING agency's agency_contact_settings.
 * duplicate_match_fields, so entity dedup-on-registration-number actually
 * takes effect agency-wide, not just for agencies provisioned after this
 * change. AgencyContactSettings::forAgency()'s firstOrCreate() default only
 * applies when a row is first CREATED — it never retroactively updates an
 * already-materialised row, which every real agency already has.
 *
 * Additive only: appends the field if missing, never removes or reorders an
 * agency's existing (possibly customised) match-field list.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('agency_contact_settings')
            ->whereNotNull('duplicate_match_fields')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $fields = json_decode($row->duplicate_match_fields ?? '[]', true) ?: [];
                    if (!in_array('entity_reg_no', $fields, true)) {
                        $fields[] = 'entity_reg_no';
                        DB::table('agency_contact_settings')
                            ->where('id', $row->id)
                            ->update(['duplicate_match_fields' => json_encode($fields)]);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('agency_contact_settings')
            ->whereNotNull('duplicate_match_fields')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $fields = json_decode($row->duplicate_match_fields ?? '[]', true) ?: [];
                    $fields = array_values(array_diff($fields, ['entity_reg_no']));
                    DB::table('agency_contact_settings')
                        ->where('id', $row->id)
                        ->update(['duplicate_match_fields' => json_encode($fields)]);
                }
            });
    }
};
