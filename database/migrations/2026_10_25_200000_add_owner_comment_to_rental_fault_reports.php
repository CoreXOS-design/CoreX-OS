<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AT-452 (§19.12) - the owner's own comment to their agent on a fault: who, when, what. Separate from the tenant's report and the agent's note. Idempotent. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_fault_reports', 'owner_comment')) {
            Schema::table('rental_fault_reports', function (Blueprint $table) {
                $table->text('owner_comment')->nullable();
                $table->unsignedBigInteger('owner_comment_by_contact_id')->nullable();
                $table->timestamp('owner_commented_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_fault_reports', 'owner_comment')) {
            Schema::table('rental_fault_reports', fn (Blueprint $t) => $t->dropColumn(['owner_comment', 'owner_comment_by_contact_id', 'owner_commented_at']));
        }
    }
};
