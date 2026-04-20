<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Migrate rmcp_compliance_officers → appointments as PRIMARY ──
        $rmcpOfficers = DB::table('rmcp_compliance_officers')
            ->whereNull('deleted_at')
            ->get();

        foreach ($rmcpOfficers as $row) {
            DB::table('fica_officer_appointments')->insert([
                'agency_id'              => $row->agency_id,
                'branch_id'              => null,
                'user_id'                => $row->user_id,
                'role'                   => 'primary_compliance_officer',
                'full_name'              => $row->full_name,
                'id_number'              => $row->id_number,
                'cell'                   => $row->cell,
                'email'                  => $row->email,
                'title'                  => $row->title ?? 'FICA Compliance Officer',
                'appointed_on'           => $row->appointed_on,
                'ended_on'               => $row->ended_on,
                'appointed_by'           => $row->appointed_by,
                'appointment_letter_path' => null,
                'notes'                  => $row->appointment_notes,
                'created_at'             => $row->created_at,
                'updated_at'             => $row->updated_at,
            ]);
        }

        // ── 2. Migrate fica_compliance_officers → appointments as MLRO ──
        // Skip users already migrated as primary
        $primaryUserIds = DB::table('fica_officer_appointments')
            ->whereNotNull('user_id')
            ->where('role', 'primary_compliance_officer')
            ->whereNull('ended_on')
            ->pluck('user_id')
            ->toArray();

        $ficaOfficers = DB::table('fica_compliance_officers')->get();

        foreach ($ficaOfficers as $row) {
            if (in_array($row->user_id, $primaryUserIds)) {
                continue;
            }

            $user = DB::table('users')->find($row->user_id);
            if (!$user) {
                continue;
            }

            // Super-admin users may have null agency_id — resolve from HFC or skip
            $agencyId = $user->agency_id;
            if (!$agencyId) {
                $agencyId = DB::table('agencies')->where('slug', 'hfc-coastal')->value('id');
            }
            if (!$agencyId) {
                continue; // cannot determine agency
            }

            DB::table('fica_officer_appointments')->insert([
                'agency_id'              => $agencyId,
                'branch_id'              => $user->branch_id ?? null,
                'user_id'                => $row->user_id,
                'role'                   => 'mlro',
                'full_name'              => $user->name,
                'id_number'              => null,
                'cell'                   => null,
                'email'                  => $user->email,
                'title'                  => 'Money Laundering Reporting Officer',
                'appointed_on'           => $row->assigned_at
                    ? date('Y-m-d', strtotime($row->assigned_at))
                    : now()->toDateString(),
                'ended_on'               => null,
                'appointed_by'           => $row->assigned_by,
                'appointment_letter_path' => null,
                'notes'                  => null,
                'created_at'             => $row->created_at ?? now(),
                'updated_at'             => $row->updated_at ?? now(),
            ]);
        }

        // ── 3. Rename old tables with _deprecated suffix ──
        Schema::rename('fica_compliance_officers', 'fica_compliance_officers_deprecated_20260421');
        Schema::rename('rmcp_compliance_officers', 'rmcp_compliance_officers_deprecated_20260421');
    }

    public function down(): void
    {
        // Restore old table names
        if (Schema::hasTable('fica_compliance_officers_deprecated_20260421')) {
            Schema::rename('fica_compliance_officers_deprecated_20260421', 'fica_compliance_officers');
        }
        if (Schema::hasTable('rmcp_compliance_officers_deprecated_20260421')) {
            Schema::rename('rmcp_compliance_officers_deprecated_20260421', 'rmcp_compliance_officers');
        }

        DB::table('fica_officer_appointments')->truncate();
    }
};
