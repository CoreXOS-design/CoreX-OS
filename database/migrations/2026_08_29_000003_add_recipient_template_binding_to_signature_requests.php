<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The party-slot binding (Johan, 2026-08-24 — "the critical part... knowing
 * how to link them"). A recipient (Piet) whose party is being replaced picks
 * a RecipientTemplate and fills each of its slots with either a Contact
 * (a named-in-the-chain party, e.g. "Estate Pty Ltd" — typed or picked, not
 * a recipient) or another recipient's stable key (a signing link in the
 * chain, e.g. Koos). Stored here, on the recipient being replaced, not on a
 * separate join table — Piet's row already carries his own is_deceased,
 * his own party_clause_text snapshot, and now the template + bindings that
 * produced it.
 *
 * recipient_local_key: the stable identifier every recipient on the wizard's
 * recipient screen carries (assigned when added, never derived from name/
 * email/position) — this is what a signing-node binding in ANOTHER
 * recipient's slot_bindings points at, and what makes "the recipient was
 * removed/changed after binding" detectable: a dangling reference is a
 * lookup by this key that comes back empty, checked once at generation time.
 *
 * slot_bindings (json): {"deceased": {"type": "self"}, "entity": {"type":
 * "contact", "contact_id": 91}, "executor": {"type": "recipient",
 * "recipient_local_key": "..."}} — each of a template's declared party_slots
 * resolved to either a Contact (named-only, never a recipient) or another
 * recipient's local key (a signing link).
 *
 * Both recipient_template_id and slot_bindings are read ONLY at generation
 * time to compute party_clause_text — the same snapshot-once rule already
 * governing every other piece of this feature. Additive, nullable: a
 * recipient with neither is an ordinary party, exactly today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->string('recipient_local_key', 40)->nullable()->after('is_proxy');
            $table->foreignId('recipient_template_id')->nullable()
                ->after('recipient_local_key')
                ->constrained('recipient_templates')->nullOnDelete();
            $table->json('slot_bindings')->nullable()->after('recipient_template_id');

            $table->unique(['signature_template_id', 'recipient_local_key'], 'sig_req_template_local_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropForeign(['recipient_template_id']);
            $table->dropUnique('sig_req_template_local_key_unique');
            $table->dropColumn(['recipient_local_key', 'recipient_template_id', 'slot_bindings']);
        });
    }
};
