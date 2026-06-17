<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact agent assignment (primary + one co-agent), mirroring the Property
 * agent_id / pp_second_agent_id pattern. `created_by_user_id` remains the
 * immutable audit of who first captured the contact; `agent_id` is the
 * operational primary agent (reassignable), `second_agent_id` an optional
 * co-agent. Both nullOnDelete so deactivating/removing a user never deletes
 * the contact (non-negotiable #1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('created_by_user_id')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('second_agent_id')->nullable()->after('agent_id')
                ->constrained('users')->nullOnDelete();
        });

        // Backfill: every existing contact already "sits under" the agent who
        // captured it — seed that as the primary agent so the new assignment is
        // populated for the whole back-catalogue, not just contacts created from
        // here on. Only where a creator exists (imports with no creator stay
        // unassigned rather than pointing at a phantom user).
        \DB::table('contacts')
            ->whereNull('agent_id')
            ->whereNotNull('created_by_user_id')
            ->update(['agent_id' => \DB::raw('created_by_user_id')]);
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
            $table->dropConstrainedForeignId('second_agent_id');
        });
    }
};
