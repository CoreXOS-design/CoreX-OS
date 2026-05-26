<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->foreignId('default_branch_id')->nullable()->after('split_branches_enabled')
                  ->constrained('branches')->nullOnDelete();
        });

        // Seed: set each agency's default_branch_id to its lowest-id active branch
        DB::statement("
            UPDATE agencies
            INNER JOIN (
                SELECT agency_id, MIN(id) as first_branch
                FROM branches
                WHERE deleted_at IS NULL
                GROUP BY agency_id
            ) AS ab ON ab.agency_id = agencies.id
            SET agencies.default_branch_id = ab.first_branch
            WHERE agencies.default_branch_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_branch_id');
        });
    }
};
