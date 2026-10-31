<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-509 — Compliance Switches (.ai/specs/property-compliance-gate-settings.md §4).
 *
 *  - agency_compliance_gate_settings: the on/off state of the gates that have no other home
 *    (master, photos, details_complete). Document gates keep living on
 *    agency_document_type_compliance.is_compliance_required — one source of truth per gate.
 *    A missing row means ON.
 *  - compliance_gate_changes: the permanent, append-only record of every switch (who, when, IP,
 *    and the exact disclaimer text accepted). No soft deletes — it is CoreX's evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_compliance_gate_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->string('gate_key', 64);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'gate_key']);
        });

        Schema::create('compliance_gate_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->string('gate_key', 64);
            $table->string('gate_label');
            $table->enum('action', ['switched_off', 'switched_on']);
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('disclaimer_version', 32)->nullable();
            $table->text('disclaimer_text')->nullable();
            $table->timestamp('disclaimer_accepted_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['agency_id', 'gate_key', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_gate_changes');
        Schema::dropIfExists('agency_compliance_gate_settings');
    }
};
