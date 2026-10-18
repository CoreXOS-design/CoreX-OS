<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * leases.md §3.8 — archive/restore on leases. deleted_at stays the "archived at"; these three record who, why,
 * and what the lease was before it was archived, so Restore can put it back exactly. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            if (!Schema::hasColumn('leases', 'archived_by_user_id')) {
                $table->unsignedBigInteger('archived_by_user_id')->nullable();
            }
            if (!Schema::hasColumn('leases', 'archive_reason')) {
                $table->string('archive_reason', 500)->nullable();
            }
            if (!Schema::hasColumn('leases', 'archived_from_status')) {
                $table->string('archived_from_status', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            foreach (['archived_by_user_id', 'archive_reason', 'archived_from_status'] as $column) {
                if (Schema::hasColumn('leases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
