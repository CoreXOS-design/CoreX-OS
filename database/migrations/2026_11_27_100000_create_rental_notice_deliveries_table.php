<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-495 - who a sent notice actually reached. One row per recipient per attempt (the first send and every resend), so the Notices sent tab and
 * the notice screen can say "emailed", "NOT sent - no email address on file" or "email failed" for each person instead of a bare "sent".
 * `rental_notices.delivery_status` is the summary of the LATEST attempt per recipient (sent | partial | not_sent); NULL = a notice that predates
 * this record (nothing was written down about its delivery).
 *
 * The deliveries are an append-only log (like lease_events) - never edited, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_notice_deliveries')) {
            Schema::create('rental_notice_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->foreignId('rental_notice_id')->constrained('rental_notices')->cascadeOnDelete();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->string('recipient_role', 20); // tenant | landlord
                $table->string('recipient_name', 191)->nullable();
                $table->string('recipient_email', 191)->nullable();
                $table->string('status', 30); // sent | not_sent_no_email | failed
                $table->text('error')->nullable();
                $table->dateTime('attempted_at');
                $table->unsignedBigInteger('attempted_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['rental_notice_id', 'recipient_role', 'contact_id'], 'rnd_notice_recipient_idx');
            });
        }

        if (! Schema::hasColumn('rental_notices', 'delivery_status')) {
            Schema::table('rental_notices', function (Blueprint $table) {
                $table->string('delivery_status', 20)->nullable()->after('sent_at');
                $table->index('delivery_status', 'rental_notices_delivery_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_notices', 'delivery_status')) {
            Schema::table('rental_notices', function (Blueprint $table) {
                $table->dropIndex('rental_notices_delivery_status_idx');
                $table->dropColumn('delivery_status');
            });
        }
        Schema::dropIfExists('rental_notice_deliveries');
    }
};
