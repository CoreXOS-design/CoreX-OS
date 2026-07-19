<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 §11 (Prompt J) — the assistant audit trail.
 *
 * Every bespoke audit table that carries a staff actor column gains a nullable
 * `on_behalf_of_user_id` beside it, so an assistant's action records BOTH the assistant
 * (existing actor column) and the Assigned Agent they acted for. Null for every normal
 * (non-assistant) action, so existing rows and behaviour are unchanged.
 *
 * esign_consent_log and deal_document_access_log are excluded by design (§11) — their actor
 * is the external signer/recipient, never a staff user.
 *
 * FK names are hand-shortened to stay under MySQL's 64-char identifier limit.
 */
return new class extends Migration
{
    /** table => short FK constraint name */
    private array $tables = [
        'domain_event_log'         => 'del_obo_fk',
        'property_audit_log'       => 'pal_obo_fk',
        'deal_logs'                => 'dl_obo_fk',
        'deal_activity_log'        => 'dal_obo_fk',
        'signature_audit_log'      => 'sal_obo_fk',
        'calendar_event_audit_log' => 'ceal_obo_fk',
        'legal_block_audit_log'    => 'lbal_obo_fk',
        'comms_access_audit_log'   => 'caal_obo_fk',
        'marketing_share_log'      => 'msl_obo_fk',
        'contact_access_log'       => 'cal_obo_fk',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $fk) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'on_behalf_of_user_id')) {
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
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'on_behalf_of_user_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('on_behalf_of_user_id');
            });
        }
    }
};
