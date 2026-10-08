<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 §11 — the assistant audit trail, for three audit tables that were added after the original
 * migration (2026_07_19_000006) and so were never covered: AuditActorCoverageTest named them.
 *
 * Each table already records a staff actor; it gains a nullable `on_behalf_of_user_id` beside it so an
 * assistant's action records BOTH the assistant and the Assigned Agent they acted for. Null for every normal
 * (non-assistant) action, so existing rows and behaviour are unchanged. The models stamp it through
 * `StampsOnBehalfOf` (a single `creating` chokepoint). Additive and idempotent; down() drops only what up() added.
 *
 * FK names are hand-shortened to stay under MySQL's 64-character identifier limit.
 */
return new class extends Migration
{
    /** table => short FK constraint name */
    private array $tables = [
        'template_transfer_log'        => 'ttl_obo_fk',
        'rental_inspection_audit_log'  => 'ria_obo_fk',
        'rental_setting_audit'         => 'rsa_obo_fk',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $fk) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'on_behalf_of_user_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($fk) {
                $t->foreignId('on_behalf_of_user_id')->nullable()
                    ->constrained('users', 'id', $fk)->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $fk) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'on_behalf_of_user_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('on_behalf_of_user_id');
            });
        }
    }
};
