<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 §11 - the assistant audit trail for the Deal Document Distribution audit log (created 2026_12_29_120000, after the other audit
 * tables were covered by 2026_12_30_100000): AuditActorCoverageTest named it. Nullable `on_behalf_of_user_id` beside the actor, stamped by the
 * model through `StampsOnBehalfOf`; null for every normal action, so existing rows and behaviour are unchanged. Additive and idempotent.
 */
return new class extends Migration
{
    private string $table = 'document_distribution_audit_entries';

    public function up(): void
    {
        if (! Schema::hasTable($this->table) || Schema::hasColumn($this->table, 'on_behalf_of_user_id')) {
            return;
        }
        Schema::table($this->table, function (Blueprint $t) {
            $t->foreignId('on_behalf_of_user_id')->nullable()
                ->constrained('users', 'id', $this->table . '_obo_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table) || ! Schema::hasColumn($this->table, 'on_behalf_of_user_id')) {
            return;
        }
        Schema::table($this->table, function (Blueprint $t) {
            $t->dropConstrainedForeignId('on_behalf_of_user_id');
        });
    }
};
