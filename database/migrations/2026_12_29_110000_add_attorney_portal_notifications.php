<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** .ai/specs/attorney-portal.md 15 - the "new documents" digest: the agency choice, and what each attorney was last told per deal. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('attorney_portal_settings', 'attorney_portal_notify')) {
            Schema::table('attorney_portal_settings', function (Blueprint $table) {
                // Null = the read-time default (daily). 'off' | 'daily'.
                $table->string('attorney_portal_notify', 10)->nullable()->after('link_valid_days');
            });
        }

        if (! Schema::hasTable('attorney_portal_notices')) {
            Schema::create('attorney_portal_notices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id');
                $table->unsignedBigInteger('access_id');
                $table->unsignedBigInteger('deal_id');
                // Documents filed up to this moment have been told about; the next digest only covers newer ones.
                $table->timestamp('notified_through')->nullable();
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamps();

                $table->unique(['access_id', 'deal_id'], 'atty_notice_access_deal_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attorney_portal_notices');
        if (Schema::hasColumn('attorney_portal_settings', 'attorney_portal_notify')) {
            Schema::table('attorney_portal_settings', fn (Blueprint $t) => $t->dropColumn('attorney_portal_notify'));
        }
    }
};
