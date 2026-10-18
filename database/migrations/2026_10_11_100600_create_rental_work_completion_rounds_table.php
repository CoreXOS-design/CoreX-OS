<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.10 (foundation F6 + F7) — one row per
 * "work reported done" event and the tenant check that follows it. Rows are
 * never deleted. Also: the photo links (a crew line / a completion round) and
 * the secure-token target for the tenant's response link. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_work_completion_rounds')) {
            Schema::create('rental_work_completion_rounds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->foreignId('rental_work_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('rental_job_card_id')->nullable()->constrained()->nullOnDelete();

                $table->unsignedInteger('round_no');
                $table->timestamp('opened_at');
                // crew or contractor name
                $table->string('reported_by_label', 191)->nullable();
                // crew_link | crew_page | signed_copy | office | contractor_captured
                $table->string('reported_via', 20);
                $table->text('reported_note')->nullable();
                $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();

                // sent | no_tenant | no_email | disabled | failed
                $table->string('tenant_notify_status', 20)->nullable();
                $table->timestamp('tenant_notified_at')->nullable();
                $table->timestamp('window_ends_at')->nullable();

                // awaiting_tenant | confirmed | disputed | accepted_by_silence | no_tenant
                $table->string('outcome', 24)->default('awaiting_tenant');
                $table->timestamp('responded_at')->nullable();
                // link | portal | office_on_behalf
                $table->string('responded_via', 20)->nullable();
                $table->unsignedBigInteger('responded_by_contact_id')->nullable();
                $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('response_note')->nullable();
                $table->timestamp('dispute_resolved_at')->nullable();
                // the worker + agent sign-off details a reopened card gives up (§17.10.6)
                $table->json('sign_off_snapshot')->nullable();

                $table->timestamps();

                $table->unique(['rental_work_order_id', 'round_no'], 'rwcr_wo_round_unique');
                $table->index(['agency_id', 'outcome', 'window_ends_at'], 'rwcr_settle_idx');
            });
        }

        Schema::table('rental_work_order_photos', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_work_order_photos', 'rental_job_card_line_id')) {
                $table->foreignId('rental_job_card_line_id')->nullable()
                    ->constrained('rental_job_card_lines', indexName: 'rwop_line_fk')->nullOnDelete();
            }
            if (! Schema::hasColumn('rental_work_order_photos', 'rental_completion_round_id')) {
                $table->foreignId('rental_completion_round_id')->nullable()
                    ->constrained('rental_work_completion_rounds', indexName: 'rwop_round_fk')->nullOnDelete();
            }
        });

        Schema::table('rental_secure_access_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_secure_access_tokens', 'rental_completion_round_id')) {
                $table->foreignId('rental_completion_round_id')->nullable()
                    ->constrained('rental_work_completion_rounds', indexName: 'rsat_round_fk')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_secure_access_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('rental_secure_access_tokens', 'rental_completion_round_id')) {
                $table->dropForeign('rsat_round_fk');
                $table->dropColumn('rental_completion_round_id');
            }
        });

        Schema::table('rental_work_order_photos', function (Blueprint $table) {
            if (Schema::hasColumn('rental_work_order_photos', 'rental_completion_round_id')) {
                $table->dropForeign('rwop_round_fk');
                $table->dropColumn('rental_completion_round_id');
            }
            if (Schema::hasColumn('rental_work_order_photos', 'rental_job_card_line_id')) {
                $table->dropForeign('rwop_line_fk');
                $table->dropColumn('rental_job_card_line_id');
            }
        });

        Schema::dropIfExists('rental_work_completion_rounds');
    }
};
