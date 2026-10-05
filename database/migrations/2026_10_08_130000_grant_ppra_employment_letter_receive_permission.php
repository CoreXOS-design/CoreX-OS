<?php

use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Johan, 2026-10-05 — who can receive a PPRA Employment Letter (the admin New-letter picker AND the
 * My Portal → Documents → PPRA Employment Letter tab) is a Role Manager setting per role
 * (`ppra_employment_letters.receive`, "Can receive PPRA employment letter"), replacing the hardcoded
 * agent/branch_manager/admin list and the "has an FFC number" rule.
 *
 * Nobody who sees the letter today may lose it, so each agency is granted:
 *   1. agent, branch_manager and admin (every agency's own copy of those roles, plus the global template
 *      rows RoleProvisioningService clones for new agencies — templates get ONLY these three, a neutral
 *      default for an agency that does not exist yet);
 *   2. every role that has at least one active, non-assistant member in that agency who appears in
 *      today's picker (a practitioner role OR an FFC number on file) — e.g. office_admin;
 *   3. every role held by a user who already has a letter on record, so existing letters stay reachable.
 *
 * Idempotent (withTrashed()->firstOrNew + restore, BUILD_STANDARD §5a — the (role, permission_key,
 * agency_id) unique index has no deleted_at). Never overwrites a live row. down() removes exactly this key.
 */
return new class extends Migration
{
    private const KEY = 'ppra_employment_letters.receive';

    /** The roles the old hardcoded picker list contained. */
    private const BASE_ROLES = ['agent', 'branch_manager', 'admin'];

    public function up(): void
    {
        // (agency_id => [role names]) — null agency_id = the global template rows.
        $grants = [];

        $roles = Role::query()
            ->whereIn('name', self::BASE_ROLES)
            ->where('is_owner', false)
            ->get(['name', 'agency_id']);
        foreach ($roles as $role) {
            $grants[$role->agency_id ?? 0][$role->name] = true;
        }

        // Roles of everyone who appears in today's picker.
        $pickerUsers = DB::table('users')
            ->whereNotNull('agency_id')
            ->where('is_active', true)
            ->where('is_assistant', false)
            ->where('role', '!=', 'assistant')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereIn('role', self::BASE_ROLES)
                    ->orWhere(fn ($has) => $has->whereNotNull('ffc_number')->where('ffc_number', '!=', ''));
            })
            ->distinct()
            ->get(['agency_id', 'role']);
        foreach ($pickerUsers as $u) {
            $grants[$u->agency_id][$u->role] = true;
        }

        // Roles of everyone who already has a letter on record.
        $letterUsers = DB::table('ppra_employment_letters')
            ->join('users', 'users.id', '=', 'ppra_employment_letters.user_id')
            ->whereNotNull('users.agency_id')
            ->distinct()
            ->get(['users.agency_id as agency_id', 'users.role as role']);
        foreach ($letterUsers as $u) {
            $grants[$u->agency_id][$u->role] = true;
        }

        foreach ($grants as $agencyKey => $roleNames) {
            $agencyId = $agencyKey === 0 ? null : $agencyKey;
            foreach (array_keys($roleNames) as $roleName) {
                if ($roleName === null || $roleName === '') {
                    continue;
                }
                $row = RolePermission::withTrashed()->firstOrNew([
                    'agency_id'      => $agencyId,
                    'role'           => $roleName,
                    'permission_key' => self::KEY,
                ]);
                if ($row->exists && ! $row->trashed()) {
                    continue;
                }
                if ($row->trashed()) {
                    $row->restore();
                }
                $row->scope = null;
                $row->save();
            }
        }
    }

    public function down(): void
    {
        RolePermission::withTrashed()->where('permission_key', self::KEY)->forceDelete();
    }
};
