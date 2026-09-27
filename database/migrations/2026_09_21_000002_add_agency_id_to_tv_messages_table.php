<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-424 — `tv_messages` never carried an `agency_id`. Any agency admin with
 * manage_tv_messages listed, edited, archived and restored every agency's TV
 * messages, and one agency's "all branches" (branch_id NULL) messages played
 * on every other agency's TV screens.
 *
 * Backfill, most specific first:
 *   1. branch message       -> its branch's agency
 *   2. all-branches message -> the agency of the user who created it
 *   3. anything left        -> the oldest agency. These rows have no branch
 *      and no creator: they are the original defaults written before CoreX
 *      held more than one agency, so they belong to that first agency.
 * Then NOT NULL.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('tv_messages', 'agency_id')) {
            Schema::table('tv_messages', function (Blueprint $table) {
                $table->unsignedBigInteger('agency_id')->nullable()->after('id');
            });
        }

        DB::update(
            'UPDATE tv_messages m INNER JOIN branches b ON b.id = m.branch_id '
            . 'SET m.agency_id = b.agency_id WHERE m.agency_id IS NULL AND b.agency_id IS NOT NULL'
        );
        DB::update(
            'UPDATE tv_messages m INNER JOIN users u ON u.id = m.created_by_user_id '
            . 'SET m.agency_id = u.agency_id WHERE m.agency_id IS NULL AND u.agency_id IS NOT NULL'
        );

        if (DB::table('tv_messages')->whereNull('agency_id')->exists()) {
            $firstAgencyId = DB::table('agencies')->orderBy('id')->value('id');
            if ($firstAgencyId) {
                DB::table('tv_messages')->whereNull('agency_id')->update(['agency_id' => $firstAgencyId]);
            }
        }

        $stillNull = DB::table('tv_messages')->whereNull('agency_id')->count();
        if ($stillNull > 0) {
            throw new \RuntimeException("tv_messages still has {$stillNull} row(s) with NULL agency_id after backfill.");
        }

        Schema::table('tv_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable(false)->change();
        });

        $hasIndex = !empty(DB::select("SHOW INDEX FROM tv_messages WHERE Key_name = 'idx_tv_messages_agency_id'"));
        if (!$hasIndex) {
            Schema::table('tv_messages', function (Blueprint $table) {
                $table->index('agency_id', 'idx_tv_messages_agency_id');
            });
        }

        $hasFk = !empty(DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tv_messages' "
            . "AND REFERENCED_TABLE_NAME = 'agencies'"
        ));
        if (!$hasFk) {
            Schema::table('tv_messages', function (Blueprint $table) {
                $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tv_messages', 'agency_id')) {
            Schema::table('tv_messages', function (Blueprint $table) {
                try { $table->dropForeign(['agency_id']); } catch (\Throwable $e) {}
                try { $table->dropIndex('idx_tv_messages_agency_id'); } catch (\Throwable $e) {}
                $table->dropColumn('agency_id');
            });
        }
    }
};
