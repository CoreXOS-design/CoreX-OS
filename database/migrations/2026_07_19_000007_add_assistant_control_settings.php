<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 — Assistant Control Page V2 (.ai/specs/assistant-control-page.md).
 *
 * Per-assignment behaviour settings the AGENT controls on their assistant's control page (styled
 * like the feature switchboard). Ownership ("filed as the agent") is NOT a setting — it is always
 * on for an assistant — so only these three toggles are stored:
 *   - can_manage_my_records : may the assistant EDIT/DELETE the agent's records, or only add+view
 *   - show_attribution      : show "added by <assistant>" on the agent's records
 *   - notify_on_action      : notify the agent when the assistant acts on their behalf (quiet default)
 *
 * Also adds on_behalf_of_user_id to daily_activity_entries so the audit names the assistant behind
 * each daily number (the row is OWNED by the agent; this records who actually logged it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_assignments', function (Blueprint $t) {
            if (!Schema::hasColumn('assistant_assignments', 'can_manage_my_records')) {
                $t->boolean('can_manage_my_records')->default(true)->after('status');
            }
            if (!Schema::hasColumn('assistant_assignments', 'show_attribution')) {
                $t->boolean('show_attribution')->default(true)->after('can_manage_my_records');
            }
            if (!Schema::hasColumn('assistant_assignments', 'notify_on_action')) {
                $t->boolean('notify_on_action')->default(false)->after('show_attribution');
            }
        });

        if (Schema::hasTable('daily_activity_entries') && !Schema::hasColumn('daily_activity_entries', 'on_behalf_of_user_id')) {
            Schema::table('daily_activity_entries', function (Blueprint $t) {
                $t->foreignId('on_behalf_of_user_id')->nullable()
                    ->constrained('users', 'id', 'dae_obo_fk')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('assistant_assignments', function (Blueprint $t) {
            foreach (['can_manage_my_records', 'show_attribution', 'notify_on_action'] as $col) {
                if (Schema::hasColumn('assistant_assignments', $col)) {
                    $t->dropColumn($col);
                }
            }
        });

        if (Schema::hasTable('daily_activity_entries') && Schema::hasColumn('daily_activity_entries', 'on_behalf_of_user_id')) {
            Schema::table('daily_activity_entries', function (Blueprint $t) {
                $t->dropConstrainedForeignId('on_behalf_of_user_id');
            });
        }
    }
};
