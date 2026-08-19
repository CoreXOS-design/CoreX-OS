<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_property_owners', function (Blueprint $table) {
            $table->timestamp('conflict_resolved_at')->nullable()->after('conflict_flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_property_owners', function (Blueprint $table) {
            $table->dropColumn('conflict_resolved_at');
        });
    }
};
