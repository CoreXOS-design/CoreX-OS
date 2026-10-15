<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md section 17.40 (AT-465) - the crew's "Send to office" is ONE submission: what they found and
 * what is needed (this table), the lines they staged, their photos and receipts. One row per send, so a second batch has its
 * own findings. The lines of a send point back at it (rental_job_card_lines.rental_job_card_crew_report_id) so the office can
 * say "Crew reported ... - 3 lines, R 450.00 cost". Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_job_card_crew_reports')) {
            Schema::create('rental_job_card_crew_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->foreignId('rental_job_card_id')->constrained()->cascadeOnDelete();
                // required on the crew's first send, optional after - so nullable here
                $table->text('findings')->nullable();
                $table->unsignedSmallInteger('line_count')->default(0);
                // who/where the send came from (the same label the audit line carries)
                $table->string('sent_label', 191)->nullable();
                $table->string('sent_via', 20)->nullable();
                $table->string('sent_ip', 45)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['rental_job_card_id', 'id'], 'rjccr_card_idx');
            });
        }

        if (Schema::hasTable('rental_job_card_lines') && ! Schema::hasColumn('rental_job_card_lines', 'rental_job_card_crew_report_id')) {
            Schema::table('rental_job_card_lines', function (Blueprint $table) {
                $table->unsignedBigInteger('rental_job_card_crew_report_id')->nullable()->index('rjcl_crew_report_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_job_card_lines', 'rental_job_card_crew_report_id')) {
            Schema::table('rental_job_card_lines', function (Blueprint $table) {
                $table->dropIndex('rjcl_crew_report_idx');
                $table->dropColumn('rental_job_card_crew_report_id');
            });
        }
        Schema::dropIfExists('rental_job_card_crew_reports');
    }
};
