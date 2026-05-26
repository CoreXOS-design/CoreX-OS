<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('calendar_event_invitations', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->default(null)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_event_invitations', function (Blueprint $table) {
            $table->dropColumn('acknowledged_at');
        });
    }
};
