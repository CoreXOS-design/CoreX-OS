<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Johan, 2026-10-07 — buyer-notes visibility becomes a Role Manager data scope (`buyer_notes.view`,
 * Own / Branch / Agency). So that NOBODY GAINS OR LOSES ACCESS until an agency changes it, every
 * agency/role gets the row its CURRENT effective access implies:
 *
 *   today a buyer's notes were readable by whoever could see the contact (ContactScope), which needed
 *   `access_contacts` and read the role's `contacts.view` scope; admin / super_admin always saw every
 *   contact in the agency; the Data-Isolation setting (agencies.split_branches_enabled) turned an
 *   "all" contacts scope into branch-only.
 *
 * So, for each (agency, role) that holds BOTH `access_contacts` and `contacts.view`:
 *   admin / super_admin            -> all
 *   contacts.view scope own        -> own
 *   contacts.view scope branch     -> branch
 *   contacts.view scope all / null -> branch if the agency has split_branches_enabled, else all
 * Rows the agency already has are never touched (idempotent; a soft-deleted row is restored, never
 * duplicated — BUILD_STANDARD §5a, role_permissions' unique index ignores deleted_at).
 *
 * Assistants: an assignment's matrix is a copy of its agent's permissions, so every assignment that
 * granted `contacts.view` gets the same grant + scope for `buyer_notes.view` (the live agent ceiling
 * still applies on every check).
 */
return new class extends Migration
{
    private const KEY = 'buyer_notes.view';

    public function up(): void
    {
        $now = now();
        $split = DB::table('agencies')->pluck('split_branches_enabled', 'id');

        $withAccess = DB::table('role_permissions')->whereNull('deleted_at')->where('permission_key', 'access_contacts')
            ->get(['agency_id', 'role'])
            ->mapWithKeys(fn ($r) => [($r->agency_id ?? 'null') . '|' . $r->role => true]);

        $sources = DB::table('role_permissions')->whereNull('deleted_at')->where('permission_key', 'contacts.view')
            ->get(['agency_id', 'role', 'scope']);

        foreach ($sources as $src) {
            if (! isset($withAccess[($src->agency_id ?? 'null') . '|' . $src->role])) {
                continue;
            }

            $scope = match (true) {
                in_array($src->role, ['admin', 'super_admin'], true) => 'all',
                $src->scope === 'own' => 'own',
                $src->scope === 'branch' => 'branch',
                default => ($src->agency_id !== null && (bool) ($split[$src->agency_id] ?? false)) ? 'branch' : 'all',
            };

            $existing = DB::table('role_permissions')
                ->where('permission_key', self::KEY)->where('role', $src->role)
                ->when($src->agency_id === null, fn ($q) => $q->whereNull('agency_id'), fn ($q) => $q->where('agency_id', $src->agency_id))
                ->first();

            if ($existing) {
                if ($existing->deleted_at !== null) {
                    DB::table('role_permissions')->where('id', $existing->id)
                        ->update(['deleted_at' => null, 'scope' => $scope, 'updated_at' => $now]);
                }
                continue;
            }

            DB::table('role_permissions')->insert([
                'role' => $src->role, 'permission_key' => self::KEY, 'agency_id' => $src->agency_id,
                'scope' => $scope, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Assistants — mirror the contacts.view grant already on each assignment's matrix.
        $matrix = DB::table('assistant_assignment_permissions')->whereNull('deleted_at')->where('permission_key', 'contacts.view')
            ->get(['agency_id', 'assistant_assignment_id', 'granted', 'scope']);

        foreach ($matrix as $row) {
            $exists = DB::table('assistant_assignment_permissions')
                ->where('assistant_assignment_id', $row->assistant_assignment_id)->where('permission_key', self::KEY)->exists();
            if ($exists) {
                continue;
            }
            DB::table('assistant_assignment_permissions')->insert([
                'agency_id' => $row->agency_id, 'assistant_assignment_id' => $row->assistant_assignment_id,
                'permission_key' => self::KEY, 'granted' => $row->granted, 'scope' => $row->scope,
                'is_locked' => 0, 'is_new' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission_key', self::KEY)->delete();
        DB::table('assistant_assignment_permissions')->where('permission_key', self::KEY)->delete();
    }
};
