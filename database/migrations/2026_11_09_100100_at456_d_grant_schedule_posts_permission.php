<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * AT-456 Part D — the new Role Manager key `ad_manager.schedule_posts` ("Schedule social posts").
 *
 * Fresh installs get it from config/corex-permissions.php role_defaults. Existing agencies already
 * have their roles stored in role_permissions, so this grants the key — in every agency's own copy of
 * the role and in the global template rows RoleProvisioningService clones for new agencies — to the
 * roles that already hold `access_ad_manager` AND are the hands-on practitioner roles (the same
 * roles that hold it in role_defaults). Nobody who cannot open the Ad Manager gains anything, viewers
 * stay read-only, and an agency can untick it per role in Role Manager.
 *
 * Idempotent (withTrashed()->firstOrNew + restore; the (role, permission_key, agency_id) unique index
 * has no deleted_at). Never overwrites a live row.
 */
return new class extends Migration
{
    private const KEY = 'ad_manager.schedule_posts';

    private const ROLES = ['agent', 'branch_manager', 'admin', 'office_admin'];

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
