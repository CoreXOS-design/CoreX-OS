<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * .ai/specs/rental-work-orders.md §17.15 (foundation F10) — five new keys,
 * each default-granted to every role that already holds its SOURCE key (each
 * agency's own copy of the role AND the global template rows new agencies are
 * cloned from), carrying the same scope. Role Manager can then revoke it per role.
 *
 *   rental_job_cards.price                       <- rental_job_cards.send_quote
 *   rental_job_cards.view_costs                  <- rental_job_cards.send_quote
 *   rental_work_orders.record_emergency_approval <- rental_work_orders.record_approval
 *   rental_work_orders.manage_work_terms         <- rental_work_orders.manage_settings
 *   rental_work_orders.manage_completion         <- rental_work_orders.complete
 *
 * Idempotent (withTrashed()->firstOrNew + restore — the (role, permission_key,
 * agency_id) unique index has no deleted_at). Never overwrites a live row.
 * down() removes exactly these keys. Template: 2026_10_10_100500.
 */
return new class extends Migration
{
    private const GRANTS = [
        'rental_job_cards.price' => 'rental_job_cards.send_quote',
        'rental_job_cards.view_costs' => 'rental_job_cards.send_quote',
        'rental_work_orders.record_emergency_approval' => 'rental_work_orders.record_approval',
        'rental_work_orders.manage_work_terms' => 'rental_work_orders.manage_settings',
        'rental_work_orders.manage_completion' => 'rental_work_orders.complete',
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $key => $sourceKey) {
            $sources = RolePermission::query()->where('permission_key', $sourceKey)->get();

            foreach ($sources as $source) {
                $row = RolePermission::withTrashed()->firstOrNew([
                    'agency_id' => $source->agency_id,
                    'role' => $source->role,
                    'permission_key' => $key,
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
    }

    public function down(): void
    {
        RolePermission::withTrashed()->whereIn('permission_key', array_keys(self::GRANTS))->forceDelete();
    }
};
