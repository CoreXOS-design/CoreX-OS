<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original migration (2026_03_22_184212) included authorised_by + authorised_at
     * but the columns were not created on the database. This migration adds them safely.
     */
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('signature_requests', 'authorised_by')) {
                $table->unsignedBigInteger('authorised_by')->nullable()->after('reviewed_at');
            }
            if (!Schema::hasColumn('signature_requests', 'authorised_at')) {
                $table->timestamp('authorised_at')->nullable()->after('authorised_by');
            }
        });

        // Add foreign key separately to avoid issues if column already existed
        if (Schema::hasColumn('signature_requests', 'authorised_by')) {
            try {
                Schema::table('signature_requests', function (Blueprint $table) {
                    $table->foreign('authorised_by')->references('id')->on('users')->onDelete('set null');
                });
            } catch (\Throwable $e) {
                // Foreign key may already exist from original migration
            }
        }
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            try {
                $table->dropForeign(['authorised_by']);
            } catch (\Throwable $e) {
                // Ignore if FK doesn't exist
            }
            if (Schema::hasColumn('signature_requests', 'authorised_at')) {
                $table->dropColumn('authorised_at');
            }
            if (Schema::hasColumn('signature_requests', 'authorised_by')) {
                $table->dropColumn('authorised_by');
            }
        });
    }
};
