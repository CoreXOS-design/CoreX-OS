<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA Inspection Pack Phase A — .ai/specs/ppra-inspection-pack.md §4.1
 *
 * Seeds two new compliance document types (BEE Sworn Affidavit — the
 * satisfies_group='bee' alternative to bee_certificate — and Trial Balance,
 * a manual upload since CoreX does not do trust accounting) for every
 * agency that has already initialised the agency_document_type_configs
 * vault (i.e. has at least one row). Never a hardcoded agency id — every
 * agency is discovered from its own existing rows. Idempotent: skips an
 * agency that already has either slug (re-running this migration, or a
 * row an admin created by hand under the same slug, never duplicates).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agency_document_type_configs')) {
            return;
        }

        $agencyIds = DB::table('agency_document_type_configs')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('agency_id');

        foreach ($agencyIds as $agencyId) {
            $existingSlugs = DB::table('agency_document_type_configs')
                ->where('agency_id', $agencyId)
                ->whereIn('slug', ['bee_affidavit', 'trial_balance'])
                ->whereNull('deleted_at')
                ->pluck('slug')
                ->all();

            $maxSortOrder = (int) DB::table('agency_document_type_configs')
                ->where('agency_id', $agencyId)
                ->max('sort_order');

            $rows = [];

            if (! in_array('bee_affidavit', $existingSlugs, true)) {
                $rows[] = [
                    'agency_id'               => $agencyId,
                    'name'                    => 'BEE Sworn Affidavit',
                    'slug'                    => 'bee_affidavit',
                    'satisfies_group'         => 'bee',
                    'description'             => 'Alternative to a BEE certificate — a sworn affidavit is acceptable where the agency holds no formal certificate.',
                    'has_expiry'              => true,
                    'renewal_days'            => 365,
                    'required'                => false,
                    'sort_order'              => ++$maxSortOrder,
                    'is_active'               => true,
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ];
            }

            if (! in_array('trial_balance', $existingSlugs, true)) {
                $rows[] = [
                    'agency_id'               => $agencyId,
                    'name'                    => 'Trial Balance (latest)',
                    'slug'                    => 'trial_balance',
                    'satisfies_group'         => null,
                    'description'             => 'The agency\'s latest trial balance, provided by its accountant. CoreX does not keep trust accounting records — this is a manual upload.',
                    'has_expiry'              => true,
                    'renewal_days'            => 90,
                    'required'                => false,
                    'sort_order'              => ++$maxSortOrder,
                    'is_active'               => true,
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ];
            }

            if (! empty($rows)) {
                DB::table('agency_document_type_configs')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('agency_document_type_configs')) {
            return;
        }

        // Soft-delete rather than hard-delete on rollback — an agency may
        // already have uploaded a provision against one of these types by
        // the time anyone rolls this migration back, and CLAUDE.md
        // Non-negotiable #1 forbids hard deletes everywhere, migrations
        // included. A rollback that then re-runs up() will simply find the
        // row already exists (soft-deleted) — up() only checks
        // whereNull('deleted_at'), so it would re-insert; that's acceptable
        // for a migration rollback path, which is a rare, deliberate action.
        DB::table('agency_document_type_configs')
            ->whereIn('slug', ['bee_affidavit', 'trial_balance'])
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
