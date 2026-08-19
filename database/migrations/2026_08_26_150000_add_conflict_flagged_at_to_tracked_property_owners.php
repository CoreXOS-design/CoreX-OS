<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_property_owners', function (Blueprint $table) {
            $table->timestamp('conflict_flagged_at')->nullable()->after('is_primary');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_property_owners', function (Blueprint $table) {
            $table->dropColumn('conflict_flagged_at');
        });
    }
};
