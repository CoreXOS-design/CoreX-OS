<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Step 1: Expand duplicate_mode enum (hard_block → hard_block_override)
        // MySQL requires ALTER COLUMN to change enum values
        DB::statement("ALTER TABLE agency_contact_settings MODIFY COLUMN duplicate_mode ENUM('auto_link','soft_warn','hard_block_override','hard_block_request') NOT NULL DEFAULT 'soft_warn'");

        // Migrate existing 'hard_block' rows to 'hard_block_override'
        DB::table('agency_contact_settings')
            ->where('duplicate_mode', 'hard_block')
            ->update(['duplicate_mode' => 'hard_block_override']);

        // Step 2: Create contact_duplicate_log table
        Schema::create('contact_duplicate_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('attempted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('mode_at_attempt', 30);
            $table->string('match_field', 50);
            $table->string('match_value', 255);
            $table->foreignId('existing_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->json('attempted_data')->nullable();
            $table->enum('action_taken', [
                'auto_linked', 'used_existing', 'created_anyway',
                'override_with_reason', 'request_pending', 'rejected',
            ]);
            $table->text('override_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Step 3: Add purged_at + purged_reason to contacts (POPIA soft-purge)
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('purged_at')->nullable()->after('deleted_at');
            $table->string('purged_reason', 255)->nullable()->after('purged_at');
        });

        // Step 4: Create contact_duplicate_clusters table (for cleanup queue)
        Schema::create('contact_duplicate_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->json('contact_ids'); // array of contact IDs in this cluster
            $table->string('match_field', 50);
            $table->string('match_value', 255);
            $table->enum('status', ['pending', 'reviewed', 'merged', 'dismissed'])->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_duplicate_clusters');

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['purged_at', 'purged_reason']);
        });

        Schema::dropIfExists('contact_duplicate_log');

        // Revert enum (can't easily revert to old values if data has new values)
        DB::statement("ALTER TABLE agency_contact_settings MODIFY COLUMN duplicate_mode ENUM('hard_block','soft_warn','auto_link') NOT NULL DEFAULT 'soft_warn'");
    }
};
