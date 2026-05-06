<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Pre-check: abort if any NULL branch_id rows remain (including soft-deleted)
        $nullContacts = DB::table('contacts')->whereNull('branch_id')->count();
        $nullProperties = DB::table('properties')->whereNull('branch_id')->count();

        // Backfill any remaining NULLs (soft-deleted records missed by previous migration)
        if ($nullContacts > 0 || $nullProperties > 0) {
            // Backfill from owner user's branch
            DB::statement("
                UPDATE contacts
                INNER JOIN users ON users.id = contacts.created_by_user_id
                SET contacts.branch_id = users.branch_id
                WHERE contacts.branch_id IS NULL AND users.branch_id IS NOT NULL
            ");
            DB::statement("
                UPDATE properties
                INNER JOIN users ON users.id = properties.agent_id
                SET properties.branch_id = users.branch_id
                WHERE properties.branch_id IS NULL AND users.branch_id IS NOT NULL
            ");

            // Fallback: agency's default branch or lowest branch
            DB::statement("
                UPDATE contacts
                INNER JOIN (
                    SELECT agency_id, MIN(id) as default_branch
                    FROM branches WHERE deleted_at IS NULL GROUP BY agency_id
                ) AS ab ON ab.agency_id = contacts.agency_id
                SET contacts.branch_id = ab.default_branch
                WHERE contacts.branch_id IS NULL
            ");
            DB::statement("
                UPDATE properties
                INNER JOIN (
                    SELECT agency_id, MIN(id) as default_branch
                    FROM branches WHERE deleted_at IS NULL GROUP BY agency_id
                ) AS ab ON ab.agency_id = properties.agency_id
                SET properties.branch_id = ab.default_branch
                WHERE properties.branch_id IS NULL
            ");

            // Absolute fallback for records without agency_id
            DB::table('contacts')->whereNull('branch_id')->update(['branch_id' => 1]);
            DB::table('properties')->whereNull('branch_id')->update(['branch_id' => 1]);
        }

        // Drop existing FK (SET NULL conflicts with NOT NULL), then re-add as RESTRICT
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });

        // Now make NOT NULL
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable(false)->change();
        });
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable(false)->change();
        });

        // Re-add FK with RESTRICT (can't delete a branch that has contacts/properties)
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
        });
        Schema::table('properties', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->change();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->change();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }
};
