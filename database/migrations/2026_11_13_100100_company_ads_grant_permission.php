<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * Company Ads (.ai/specs/company-ads.md §9) — the new Role Manager key `ad_manager.company_ads`
 * ("Create company ads").
 *
 * Fresh installs get it from config/corex-permissions.php role_defaults (branch manager, office admin;
 * admin through its all-minus-exclude rule). Existing agencies already have their roles stored in
 * role_permissions, so this grants the key — in every agency's own copy of the role and in the global
 * template rows RoleProvisioningService clones for new agencies — to the manager roles that already hold
 * `access_ad_manager`. Agents and viewers do not get it (Johan 2026-10-18, decision C7); an agency can
 * tick it per role in Role Manager.
 *
 * Idempotent (withTrashed()->firstOrNew + restore), same pattern as 2026_11_09_100100. Never overwrites a live row.
 */
return new class extends Migration
{
    private const KEY = 'ad_manager.company_ads';

    private const ROLES = ['branch_manager', 'admin', 'office_admin'];

    public function up(): void
    {
        $holders = RolePermission::query()
            ->where('permission_key', 'access_ad_manager')
            ->whereIn('role', self::ROLES)
            ->get(['role', 'agency_id']);

        foreach ($holders as $h) {
            $row = RolePermission::withTrashed()->firstOrNew([
                'agency_id'      => $h->agency_id,
                'role'           => $h->role,
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

    public function down(): void
    {
        RolePermission::where('permission_key', self::KEY)->delete();
    }
};
