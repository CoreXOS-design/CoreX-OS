<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/lease-notices.md - a notice given by the tenant or the landlord is a CASE: received -> the agent reviews it ->
 * accepted / rejected -> (accepted) the other party is told. One row per notice given. Also (.ai/specs/rental-notices.md): the breach
 * register, the link from a sent notice to its breach, the Word document a notice template was made from, and the two business-day
 * figures (CPA early cancellation, breach remedy window) become agency settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lease_notices')) {
            Schema::create('lease_notices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->foreignId('lease_id')->constrained()->cascadeOnDelete();

                $table->string('given_by', 12);                       // tenant | landlord
                $table->string('via', 12)->default('portal');        // portal | agent
                $table->unsignedBigInteger('given_by_contact_id')->nullable();
                $table->unsignedBigInteger('recorded_by_user_id')->nullable();

                $table->date('notice_date');                          // the date notice was given
                $table->date('intended_end_date');                    // the move-out / end date as given
                $table->text('reason')->nullable();
                $table->string('attachment_path', 500)->nullable();
                $table->string('attachment_name', 255)->nullable();
                $table->string('attachment_mime', 100)->nullable();
                $table->unsignedInteger('attachment_size')->nullable();

                $table->string('status', 16)->default('received');   // received | accepted | rejected | withdrawn
                $table->date('accepted_end_date')->nullable();
                $table->string('decision_kind', 16)->nullable();     // as_given | corrected | rejected
                $table->text('decision_note')->nullable();
                $table->unsignedBigInteger('decided_by_user_id')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('property_outcome', 16)->nullable();
                $table->boolean('show_available_from_on_portals')->nullable();
                $table->json('flags')->nullable();                    // what the review showed when the agent decided
                $table->timestamp('other_party_told_at')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['agency_id', 'status']);
                $table->index(['lease_id', 'status']);
            });
        }

        if (! Schema::hasTable('lease_breaches')) {
            Schema::create('lease_breaches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
                $table->string('kind', 12);                          // payment | other
                $table->string('breach_party', 12)->default('tenant'); // tenant | landlord - who is in breach
                $table->decimal('amount', 12, 2)->nullable();        // outstanding amount (payment breaches)
                $table->text('clause')->nullable();                  // the lease clause quoted (other breaches)
                $table->text('reason')->nullable();
                $table->string('status', 16)->default('issued');     // issued | remedied | escalated
                $table->date('issued_on');
                $table->date('remedy_deadline')->nullable();
                $table->unsignedSmallInteger('remedy_business_days')->nullable(); // the window in force when it was issued
                $table->unsignedBigInteger('issued_by_user_id')->nullable();
                $table->unsignedBigInteger('rental_notice_id')->nullable();        // the breach notice that was sent
                $table->string('batch_ref', 40)->nullable()->index();             // a bulk send's reference
                $table->timestamp('remedied_at')->nullable();
                $table->unsignedBigInteger('remedied_by_user_id')->nullable();
                $table->text('remedied_note')->nullable();
                $table->timestamp('vacate_issued_at')->nullable();
                $table->unsignedBigInteger('vacate_issued_by_user_id')->nullable();
                $table->unsignedBigInteger('vacate_rental_notice_id')->nullable();   // the notice to vacate that was sent
                $table->timestamps();
                $table->softDeletes();
                $table->index(['agency_id', 'status']);
                $table->index(['lease_id', 'status']);
            });
        }

        if (Schema::hasTable('rental_notices') && ! Schema::hasColumn('rental_notices', 'lease_breach_id')) {
            Schema::table('rental_notices', function (Blueprint $table) {
                $table->unsignedBigInteger('lease_breach_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('rental_notice_templates') && ! Schema::hasColumn('rental_notice_templates', 'source_file_name')) {
            Schema::table('rental_notice_templates', function (Blueprint $table) {
                $table->string('source_file_name', 255)->nullable();   // the Word document the template was made from
                $table->string('source_file_path', 500)->nullable();
            });
        }

        if (Schema::hasTable('lease_settings')) {
            Schema::table('lease_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('lease_settings', 'cpa_early_cancel_business_days')) {
                    $table->unsignedSmallInteger('cpa_early_cancel_business_days')->nullable(); // NULL = the default (20)
                }
                if (! Schema::hasColumn('lease_settings', 'breach_remedy_business_days')) {
                    $table->unsignedSmallInteger('breach_remedy_business_days')->nullable();    // NULL = the default (20)
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lease_settings')) {
            foreach (['cpa_early_cancel_business_days', 'breach_remedy_business_days'] as $col) {
                if (Schema::hasColumn('lease_settings', $col)) {
                    Schema::table('lease_settings', fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
        if (Schema::hasTable('rental_notice_templates') && Schema::hasColumn('rental_notice_templates', 'source_file_name')) {
            Schema::table('rental_notice_templates', fn (Blueprint $t) => $t->dropColumn(['source_file_name', 'source_file_path']));
        }
        if (Schema::hasTable('rental_notices') && Schema::hasColumn('rental_notices', 'lease_breach_id')) {
            Schema::table('rental_notices', fn (Blueprint $t) => $t->dropColumn('lease_breach_id'));
        }
        Schema::dropIfExists('lease_breaches');
        Schema::dropIfExists('lease_notices');
    }
};
