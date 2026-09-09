<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-URGENT-2026-09-09 (Johan, via conductor) — kill-switch, part 4. "Every
 * change records who changed it, when, and in which direction... Requiring
 * a reason is deliberate — it makes the person state what they are doing,
 * which is exactly what you want at 2am during an incident, and it tells
 * whoever finds it later why it is on."
 *
 * A dedicated table, matching this codebase's own established pattern for
 * sensitive-feature audit trails (SignatureAuditLog, rental_application_
 * status_history, whistleblow_audit_log) rather than bolting onto the
 * generic, un-audited DevSettingsController::update() path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_mail_guard_toggle_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 20); // 'intercept_on' | 'intercept_off'
            $table->text('reason');
            $table->string('environment', 40);
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_mail_guard_toggle_audit');
    }
};
