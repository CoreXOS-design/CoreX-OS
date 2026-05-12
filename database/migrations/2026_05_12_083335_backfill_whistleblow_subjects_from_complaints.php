<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill: for each existing complaint with subject_agency_name set,
        // create one row in whistleblow_complaint_subjects
        $complaints = DB::table('whistleblow_complaints')
            ->whereNotNull('subject_agency_name')
            ->where('subject_agency_name', '!=', '')
            ->get(['id', 'subject_agency_name', 'subject_practitioner_name',
                    'property_portal_url', 'portal_source', 'portal_listing_ref',
                    'created_at', 'updated_at']);

        foreach ($complaints as $c) {
            DB::table('whistleblow_complaint_subjects')->insert([
                'complaint_id'      => $c->id,
                'agency_name'       => $c->subject_agency_name,
                'practitioner_name' => $c->subject_practitioner_name,
                'portal_url'        => $c->property_portal_url ?? 'https://unknown',
                'portal_source'     => $c->portal_source ?? 'other',
                'portal_listing_ref' => $c->portal_listing_ref,
                'display_order'     => 0,
                'created_at'        => $c->created_at,
                'updated_at'        => $c->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        // No rollback — the create table migration handles cleanup
    }
};
