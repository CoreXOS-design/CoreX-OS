<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 §11 — the assistant audit trail, for eight more audit tables that were added after the earlier
 * migrations (2026_07_19_000006, 2026_10_20_100000) and so were never covered: AuditActorCoverageTest named them.
 *
 * Each table already records a staff actor; it gains a nullable `on_behalf_of_user_id` beside it so an assistant's
 * action records BOTH the assistant and the Assigned Agent they acted for. Null for every normal (non-assistant)
 * action, so existing rows and behaviour are unchanged. The models stamp it through `StampsOnBehalfOf` (a single
 * `creating` chokepoint). Additive and idempotent; down() drops only what up() added.
 *
 * FK names are `<table>_obo_fk` (unique in the schema - MySQL constraint names are schema-wide, a short code like 'cal_obo_fk' already existed - and
 * all under the 64-character limit).
 */
return new class extends Migration
{
    /** table => short FK constraint name */
    private array $tables = [
        'outbound_mail_guard_toggle_audit' => 'outbound_mail_guard_toggle_audit_obo_fk',
        'rental_application_audit_log'     => 'rental_application_audit_log_obo_fk',
        'title_deed_audit_log'             => 'title_deed_audit_log_obo_fk',
        'platform_esign_wording_audit'     => 'platform_esign_wording_audit_obo_fk',
        'rmcp_board_audit_log'             => 'rmcp_board_audit_log_obo_fk',
        'platform_company_audit'           => 'platform_company_audit_obo_fk',
        'commission_setting_audit_log'     => 'commission_setting_audit_log_obo_fk',
        'contact_audit_log'                => 'contact_audit_log_obo_fk',
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
