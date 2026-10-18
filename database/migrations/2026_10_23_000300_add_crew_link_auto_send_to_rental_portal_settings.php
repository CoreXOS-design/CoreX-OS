<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AT-452 round 2 - send the crew their job link automatically when a job card is created from a fault decision. Nullable: null reads as the model default (on). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_portal_settings') && ! Schema::hasColumn('rental_portal_settings', 'crew_link_auto_send_on_fault_job')) {
            Schema::table('rental_portal_settings', function (Blueprint $table) {
                $table->boolean('crew_link_auto_send_on_fault_job')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rental_portal_settings') && Schema::hasColumn('rental_portal_settings', 'crew_link_auto_send_on_fault_job')) {
            Schema::table('rental_portal_settings', function (Blueprint $table) {
                $table->dropColumn('crew_link_auto_send_on_fault_job');
            });
        }
    }
};
