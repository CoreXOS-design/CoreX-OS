<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HD-5 / P2-1a — party GROUPS: the unit an agent checkpoint fires between.
 *
 * Today the ceremony checkpoints after EVERY external party: seller 1 signs → the agent must
 * approve → seller 2 signs → the agent must approve again. For joint sellers that is pure friction —
 * the agent is asked to authorise the gap between two people who are signing the same document for
 * the same reason. Doctrine (esign-ceremony-v3 §4) wants the checkpoint between GROUPS: for a
 * mandate, `sellers → agent`, with the sellers as ONE group.
 *
 * `signing_group` is deliberately NULLABLE, and NULL means "a group of one".
 *
 * The obvious column default (1) would have been a silent behaviour change on every ceremony that
 * already exists: every party would land in group 1, and every intermediate checkpoint in every
 * live lease flow (tenant → agent → landlord) would vanish on deploy. NULL preserves today exactly —
 * an ungrouped party checkpoints on its own, as it always has — and grouping becomes opt-in, stamped
 * only where a ceremony actually says two parties belong together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('signing_group')
                ->nullable()
                ->after('signing_order')
                ->comment('HD-5: parties sharing a group sign with no agent checkpoint between them. NULL = a group of one (today behaviour).');

            $table->index(['signature_template_id', 'signing_group', 'signing_order'], 'sig_req_group_order_idx');
        });

        Schema::table('signature_templates', function (Blueprint $table) {
            $table->json('group_order_json')
                ->nullable()
                ->comment('HD-5/HD-6: ordered group definitions for this ceremony (e.g. mandate = sellers, then agent).');
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropIndex('sig_req_group_order_idx');
            $table->dropColumn('signing_group');
        });

        Schema::table('signature_templates', function (Blueprint $table) {
            $table->dropColumn('group_order_json');
        });
    }
};
