<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rentals front-half decisions (8 Oct 2026, built while Johan was away - each one a per-agency setting with the recommended
 * value as its default, each reversible from Settings and the Setup Wizard). All columns are nullable: NULL = "never set, use
 * the system default in code"; only an explicit override is stored.
 *
 *  rental_application_qualifying_settings
 *    allow_manual_link_share                   - the agent may hand the applicant's link over themselves (WhatsApp / in person)
 *    notify_agents_on_application_returned     - in-app note to the property's / lease's agents when a tenant submits
 *    notify_authoriser_on_hand_over            - the authoriser(s) are notified (in-app + email) when an application is handed over
 *    invite_policy_sentence                    - the agency's own sentence in the invite email / PDF intro ({agency} = its name)
 *    prefill_lease_from_application            - the lease screen suggests start date and term from the application
 *    allow_withdraw_after_approval             - an approved application that has no lease yet may be withdrawn (note required)
 *  lease_settings
 *    require_end_or_month_to_month_for_signing - "prepare for signing" needs an end date or an explicit month-to-month
 *    restore_end_date_on_leaving_month_to_month- reversing month-to-month puts the original end date back
 *    signed_copy_not_live_note                 - one sentence added to the signed-copy mail when the lease cannot go live yet
 *
 * Plus the audit trail for settings (who changed what from what to what) and the two notification event types.
 * No agency is named here. The one data step below preserves what every agency already received before this was a setting.
 */
return new class extends Migration
{
    private const EVENT_TYPES = [
        'rental_application.returned' => ['Tenant submitted a rental application', 'A tenant submitted their rental application for a property or lease you are the agent on.', 1, 0],
        'rental_application.handed_over' => ['Rental application handed to you to authorise', 'An agent handed a rental application to the authorisers for a decision.', 1, 1],
    ];

    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->boolean('allow_manual_link_share')->nullable();
            $table->boolean('notify_agents_on_application_returned')->nullable();
            $table->boolean('notify_authoriser_on_hand_over')->nullable();
            $table->text('invite_policy_sentence')->nullable();
            $table->boolean('prefill_lease_from_application')->nullable();
            $table->boolean('allow_withdraw_after_approval')->nullable();
        });

        Schema::table('lease_settings', function (Blueprint $table) {
            $table->boolean('require_end_or_month_to_month_for_signing')->nullable();
            $table->boolean('restore_end_date_on_leaving_month_to_month')->nullable();
            $table->text('signed_copy_not_live_note')->nullable();
        });

        Schema::create('rental_setting_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('setting_key', 100);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('source', 30)->default('settings'); // 'settings' | 'wizard'
            $table->timestamp('created_at')->nullable();
            $table->index(['agency_id', 'created_at']);
        });

        // Every agency that has already been sending applications has been sending the invite sentence that used to be
        // fixed text; keep it for them (as their own editable wording) so nothing changes until they choose. An agency
        // that has not sent any gets the neutral default: no policy sentence.
        $agencyIds = DB::table('rental_applications')->distinct()->pluck('agency_id')->filter();
        foreach ($agencyIds as $agencyId) {
            $exists = DB::table('rental_application_qualifying_settings')->where('agency_id', $agencyId)->exists();
            $sentence = '{agency} works on pre-approval of prospective tenants before any viewings take place.';
            if ($exists) {
                DB::table('rental_application_qualifying_settings')->where('agency_id', $agencyId)->whereNull('invite_policy_sentence')
                    ->update(['invite_policy_sentence' => $sentence]);
            } else {
                DB::table('rental_application_qualifying_settings')->insert([
                    'agency_id' => $agencyId, 'invite_policy_sentence' => $sentence, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('notification_event_types')) {
            foreach (self::EVENT_TYPES as $key => [$label, $description, $inApp, $email]) {
                if (DB::table('notification_event_types')->where('key', $key)->exists()) {
                    continue;
                }
                DB::table('notification_event_types')->insert([
                    'key' => $key, 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => $label, 'description' => $description,
                    'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                    'supports_in_app' => $inApp, 'supports_email' => $email, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                    'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_setting_audit');
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->dropColumn(['require_end_or_month_to_month_for_signing', 'restore_end_date_on_leaving_month_to_month', 'signed_copy_not_live_note']);
        });
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn(['allow_manual_link_share', 'notify_agents_on_application_returned', 'notify_authoriser_on_hand_over',
                'invite_policy_sentence', 'prefill_lease_from_application', 'allow_withdraw_after_approval']);
        });
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')->whereIn('key', array_keys(self::EVENT_TYPES))->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }
};
