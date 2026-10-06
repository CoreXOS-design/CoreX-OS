<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * .ai/specs/rental-work-orders.md §14.28 — new key `rental_job_cards.share`
 * ("Share Job Cards with Crew"), default-granted to every role that already
 * holds `rental_job_cards.create` (each agency's own copy of the role AND the
 * global template rows new agencies are cloned from), carrying the same scope.
 * Role Manager can then revoke it per role.
 *
 * Idempotent (withTrashed()->firstOrNew + restore, BUILD_STANDARD §5a — the
 * (role, permission_key, agency_id) unique index has no deleted_at). Never
 * overwrites a live row. down() removes exactly this key.
 */
return new class extends Migration
{
    private const KEY = 'rental_job_cards.share';
    private const SOURCE_KEY = 'rental_job_cards.create';

    public function up(): void
    {
        $sources = RolePermission::query()->where('permission_key', self::SOURCE_KEY)->get();

        foreach ($sources as $source) {
            $row = RolePermission::withTrashed()->firstOrNew([
                'agency_id' => $source->agency_id,
                'role' => $source->role,
                'permission_key' => self::KEY,
            ]);
            if ($row->exists && ! $row->trashed()) {
                continue;
            }
            if ($row->trashed()) {
                $row->restore();
            }
            $row->scope = $source->scope;
            $row->save();
        }
    }

    public function down(): void
    {
        RolePermission::withTrashed()->where('permission_key', self::KEY)->forceDelete();
    }
};
