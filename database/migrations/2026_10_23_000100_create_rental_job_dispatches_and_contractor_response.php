<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md §19 (AT-452 round 2) - "the contractor / crew is told the moment they are appointed".
 *
 * rental_job_dispatches is the honest ledger of every attempt to tell a contractor or a crew about a job: which channel, to what
 * destination, who pressed it, and how far it got. A WhatsApp row is only ever 'sent' after the agent clicked "Open WhatsApp and send"
 * (opened_at) and then confirmed "Mark as sent" - never on the strength of the page alone. A recipient with no email and no mobile gets a
 * 'not_sent' row so the work order says so in plain words instead of silently doing nothing. idempotency_key is unique: a double click, a
 * retry or two agents can never send the same job twice.
 *
 * rental_work_orders gets the contractor's answer (spec §5.1): pending | accepted | declined | quote_uploaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_job_dispatches')) {
            Schema::create('rental_job_dispatches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->foreignId('rental_work_order_id')->constrained(indexName: 'rjd_wo_fk')->cascadeOnDelete();
                $table->unsignedBigInteger('rental_job_card_id')->nullable();
                $table->string('recipient_kind', 12);                       // contractor | crew
                $table->unsignedBigInteger('agency_service_provider_id')->nullable();
                $table->unsignedBigInteger('rental_crew_id')->nullable();
                $table->string('recipient_name', 191)->nullable();
                $table->string('channel', 12);                              // email | whatsapp | none
                $table->string('destination', 191)->nullable();             // the address / number it went (or would go) to
                $table->string('status', 16);                               // sent | failed | prepared | opened | marked_sent | not_sent
                $table->string('reason', 16)->default('appointed');         // appointed | reassigned | resend | crew_auto
                $table->unsignedBigInteger('rental_secure_access_token_id')->nullable();
                $table->string('idempotency_key', 120)->unique();
                $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('sent_at')->nullable();                   // email handed to the mailer, or WhatsApp confirmed "Mark as sent"
                $table->timestamp('opened_at')->nullable();                 // WhatsApp: the agent clicked "Open WhatsApp and send"
                $table->text('error')->nullable();
                $table->timestamps();

                $table->index(['rental_work_order_id', 'recipient_kind', 'id'], 'rjd_wo_kind_idx');
                $table->index(['agency_id', 'status'], 'rjd_agency_status_idx');
            });
        }

        if (Schema::hasTable('rental_work_orders')) {
            Schema::table('rental_work_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('rental_work_orders', 'contractor_response')) {
                    $table->string('contractor_response', 16)->nullable();
                }
                if (! Schema::hasColumn('rental_work_orders', 'contractor_responded_at')) {
                    $table->timestamp('contractor_responded_at')->nullable();
                }
                if (! Schema::hasColumn('rental_work_orders', 'contractor_decline_reason')) {
                    $table->text('contractor_decline_reason')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_job_dispatches');
        if (Schema::hasTable('rental_work_orders')) {
            Schema::table('rental_work_orders', function (Blueprint $table) {
                foreach (['contractor_response', 'contractor_responded_at', 'contractor_decline_reason'] as $c) {
                    if (Schema::hasColumn('rental_work_orders', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
