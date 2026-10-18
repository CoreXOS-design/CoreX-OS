<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the duplicate-deal fix's schema objects (2026-09-30, Johan) — the
 * application code was reverted separately (git revert on
 * remove-dup-deal-fix-2026-09-29); Andre's own fix for the same #1826/#1827
 * bug (41d60405d, origin/Staging) is the one being kept. See
 * .ai/specs/deal-register-v2-spec.md §21 for the full writeup.
 *
 * The five original migrations that CREATED these objects are deliberately
 * NOT deleted or reverted — QA1 has already run them, and migrations that
 * ran after them exist. This is a plain forward migration that drops what
 * they created instead. Every step is existence-guarded, so this is a
 * no-op (not an error) wherever an object doesn't exist — safe to run on
 * an environment that never had the fix, or to re-run if somehow partially
 * applied. down() recreates every object exactly as the originals did, so
 * this migration is a genuine, reversible undo, not a one-way deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (self::indexExists('deals', 'deals_agency_id_deal_no_unique')) {
            Schema::table('deals', function (Blueprint $table) {
                $table->dropUnique(['agency_id', 'deal_no']);
            });
        }

        if (Schema::hasColumn('deals', 'create_token')) {
            if (self::indexExists('deals', 'deals_create_token_unique')) {
                Schema::table('deals', function (Blueprint $table) {
                    $table->dropUnique(['create_token']);
                });
            }
            Schema::table('deals', function (Blueprint $table) {
                $table->dropColumn('create_token');
            });
        }

        if (Schema::hasColumn('deals_v2', 'create_token')) {
            if (self::indexExists('deals_v2', 'deals_v2_create_token_unique')) {
                Schema::table('deals_v2', function (Blueprint $table) {
                    $table->dropUnique(['create_token']);
                });
            }
            Schema::table('deals_v2', function (Blueprint $table) {
                $table->dropColumn('create_token');
            });
        }

        if (Schema::hasTable('sequence_counters')) {
            Schema::dropIfExists('sequence_counters');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sequence_counters')) {
            Schema::create('sequence_counters', function (Blueprint $table) {
                $table->id();
                $table->string('scope')->unique();
                $table->unsignedBigInteger('next_value');
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('deals_v2', 'create_token')) {
            Schema::table('deals_v2', function (Blueprint $table) {
                $table->string('create_token', 64)->nullable()->after('reference');
                $table->unique('create_token');
            });
        }

        if (! Schema::hasColumn('deals', 'create_token')) {
            Schema::table('deals', function (Blueprint $table) {
                $table->string('create_token', 64)->nullable()->after('deal_no');
                $table->unique('create_token');
            });
        }

        if (! self::indexExists('deals', 'deals_agency_id_deal_no_unique')) {
            Schema::table('deals', function (Blueprint $table) {
                $table->unique(['agency_id', 'deal_no']);
            });
        }
    }

    private static function indexExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
