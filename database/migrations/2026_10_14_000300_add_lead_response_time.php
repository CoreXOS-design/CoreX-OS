<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead response time (Johan, 2026-10-07; .ai/specs/lead-response-time.md).
 *
 * portal_leads — the FIRST genuine response to an enquiry, recorded once at the moment it happens and
 * never overwritten (today's "last contacted" is overwritten, so history cannot be rebuilt from it):
 *   first_response_at / first_response_by_user_id / first_response_channel
 *     (contacted_action | message | shared_link | appointment_feedback)
 *   response_tracked — TRUE for every lead received from now on; existing leads are set FALSE because no
 *     first-contact history exists for them ("Not measured": left out of every average, percentage and
 *     count, never shown as a failure) unless the backfill command can PROVE a first response.
 *
 * agency_contact_settings — the agency's own target and counting hours (never hardcoded):
 *   lead_response_target_minutes  respond within N minutes (default 60)
 *   lead_response_hours           JSON, per weekday {counted,start,end}; NULL = code default (every day 08:00–20:00)
 * lead_response_setting_audit — append-only: who changed the setting, from what, to what, when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_leads', function (Blueprint $table) {
            $table->timestamp('first_response_at')->nullable()->after('received_at');
            $table->unsignedBigInteger('first_response_by_user_id')->nullable()->after('first_response_at');
            $table->string('first_response_channel', 30)->nullable()->after('first_response_by_user_id');
            $table->boolean('response_tracked')->default(true)->after('first_response_channel');
            $table->index(['agency_id', 'received_at'], 'portal_leads_agency_received_idx');
            $table->index('first_response_at', 'portal_leads_first_response_idx');
        });
        // Existing rows: no first-contact history was ever recorded.
        DB::table('portal_leads')->update(['response_tracked' => false]);

        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_response_target_minutes')->default(60)->after('buyer_kanban_column_limit');
            $table->json('lead_response_hours')->nullable()->after('lead_response_target_minutes');
        });

        Schema::create('lead_response_setting_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->json('old_values');
            $table->json('new_values');
            $table->timestamp('changed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_response_setting_audit');
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn(['lead_response_target_minutes', 'lead_response_hours']);
        });
        Schema::table('portal_leads', function (Blueprint $table) {
            $table->dropIndex('portal_leads_agency_received_idx');
            $table->dropIndex('portal_leads_first_response_idx');
            $table->dropColumn(['first_response_at', 'first_response_by_user_id', 'first_response_channel', 'response_tracked']);
        });
    }
};
