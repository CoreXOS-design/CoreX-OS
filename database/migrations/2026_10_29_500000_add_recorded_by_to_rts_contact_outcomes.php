<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 - an assistant works the list of the agent they act for (spec road-to-sold.md "Assistants"). The outcome belongs to the AGENT
 * (user_id: their target, hold and report), but the person who actually made the call is kept too: recorded_by_user_id. NULL = the agent
 * recorded it themselves (every row written before this column existed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rts_contact_outcomes', function (Blueprint $table) {
            $table->unsignedBigInteger('recorded_by_user_id')->nullable()->after('user_id');
            $table->index('recorded_by_user_id', 'rts_outcome_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('rts_contact_outcomes', function (Blueprint $table) {
            $table->dropIndex('rts_outcome_recorded_by');
            $table->dropColumn('recorded_by_user_id');
        });
    }
};
