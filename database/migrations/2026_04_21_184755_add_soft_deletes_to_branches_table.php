<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Branch model declares `use SoftDeletes` but no migration in the
 * repo ever added `deleted_at`. The column was added ad-hoc on the live
 * DB(s) at some point, which would leave `migrate:fresh` clones broken.
 * This migration is idempotent so it is safe on environments that
 * already have the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('branches', 'deleted_at')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('branches', 'deleted_at')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
