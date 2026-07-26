<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-split coverage (LEAK-2, audit 2026-07-18 §B): DealV2 child records carried
 * no branch_id, so under Split ON a direct query (activity feeds, step lists,
 * settlement lists) leaked across branches — isolated only when reached THROUGH the
 * scoped parent deal.
 *
 * Each child's branch is its PARENT's branch (spec §7a) — so the column is nullable,
 * inherited at write time by InheritsBranchFromParent, and backfilled here from the
 * parent row. Two-level children (comments/documents → step instance, access log →
 * distribution) are backfilled AFTER their immediate parent so the chain resolves.
 */
return new class extends Migration
{
    /** child table => [parent table, foreign key on child] */
    private array $directDealChildren = [
        'deal_activity_log'          => ['deals_v2', 'deal_id'],
        'deal_document_distributions'=> ['deals_v2', 'deal_id'],
        'deal_v2_remarks'            => ['deals_v2', 'deal_id'],
        'deal_stage_moves'           => ['deals_v2', 'deal_id'],
        'deal_step_escalations'      => ['deals_v2', 'deal_id'],
        'deal_step_instances'        => ['deals_v2', 'deal_id'],
        'deal_v2_settlements'        => ['deals_v2', 'deal_id'],
    ];

    /**
     * Two-level children, backfilled after the direct children above.
     *
     * deal_document_access_log is deliberately EXCLUDED: it is an append-only,
     * immutable POPIA evidence log (update()/delete() throw) that is documented as
     * "not multi-tenant-scoped on read — reads are always via a distribution the
     * caller already owns." Forcing a global read scope onto that audit table would
     * fight its design; it stays agency-stamped-for-provenance only.
     */
    private array $subChildren = [
        'deal_step_comments'        => ['deal_step_instances', 'deal_step_instance_id'],
        'deal_step_documents'       => ['deal_step_instances', 'deal_step_instance_id'],
    ];

    public function up(): void
    {
        foreach (array_merge(array_keys($this->directDealChildren), array_keys($this->subChildren)) as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                });
            }
        }

        // Backfill direct children first, then sub-children (parent branch now present).
        foreach ($this->directDealChildren as $child => [$parent, $fk]) {
            $this->backfill($child, $parent, $fk);
        }
        foreach ($this->subChildren as $child => [$parent, $fk]) {
            $this->backfill($child, $parent, $fk);
        }
    }

    private function backfill(string $child, string $parent, string $fk): void
    {
        if (!Schema::hasTable($child) || !Schema::hasTable($parent)) {
            return;
        }
        DB::statement(
            "UPDATE `{$child}` c
             JOIN `{$parent}` p ON c.`{$fk}` = p.`id`
             SET c.`branch_id` = p.`branch_id`
             WHERE c.`branch_id` IS NULL AND p.`branch_id` IS NOT NULL"
        );
    }

    public function down(): void
    {
        foreach (array_merge(array_keys($this->subChildren), array_keys($this->directDealChildren)) as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('branch_id');
                });
            }
        }
    }
};
