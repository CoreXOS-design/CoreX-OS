<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-489 (21 Oct 2026) - the AGENCY'S EXPIRY NOTICE to a tenant, on Consumer Protection Act law (s14(2)(c); counted per s2(6)).
 *
 *  lease_settings.expiry_notice_send_business_days   "Send the lease expiry notice N business days before the lease ends": 40-80 only
 *                                                    (the CPA window), NULL = the default 60.
 *  lease_settings.expiry_notice_alert_business_days  when the notice has NOT gone out: alert the lease's agents at N business days left;
 *                                                    between 40 and the send day, NULL = the default 45.
 *  leases.expiry_notice_*                            whether / when / how the expiry notice went out (CoreX itself, an agent through Send
 *                                                    notice, or marked as given another way) and whether the agents were alerted.
 *
 * This REPLACES the old months-based renewal-reminder setting (lease_settings.default_earliest_notice_months, "months into the lease"):
 * an agency that had one gets its months converted to the nearest business days (21.67 a month), clamped into 40-80, written as its send
 * day, the old value cleared, and both steps audit-logged (source "migration") - nothing is converted silently. The column is left in place, unused.
 */
return new class extends Migration
{
    private const EVENT = 'lease.expiry_notice_due';

    public function up(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('lease_settings', 'expiry_notice_send_business_days')) {
                $table->unsignedSmallInteger('expiry_notice_send_business_days')->nullable();
            }
            if (! Schema::hasColumn('lease_settings', 'expiry_notice_alert_business_days')) {
                $table->unsignedSmallInteger('expiry_notice_alert_business_days')->nullable();
            }
        });
        Schema::table('leases', function (Blueprint $table) {
            if (! Schema::hasColumn('leases', 'expiry_notice_sent_at')) {
                $table->timestamp('expiry_notice_sent_at')->nullable();
                $table->string('expiry_notice_sent_via', 20)->nullable();            // corex | agent | manual
                $table->unsignedBigInteger('expiry_notice_sent_by_user_id')->nullable();
                $table->string('expiry_notice_note', 500)->nullable();
                $table->timestamp('expiry_notice_alerted_at')->nullable();
            }
        });

        // months -> business days, audit-logged
        $rows = DB::table('lease_settings')->whereNotNull('default_earliest_notice_months')->get(['id', 'agency_id', 'default_earliest_notice_months']);
        foreach ($rows as $row) {
            $months = (int) $row->default_earliest_notice_months;
            $days = max(40, min(80, (int) round($months * 21.67)));
            DB::table('lease_settings')->where('id', $row->id)->update([
                'expiry_notice_send_business_days' => $days, 'default_earliest_notice_months' => null,
            ]);
            if (Schema::hasTable('rental_setting_audit')) {
                DB::table('rental_setting_audit')->insert([
                    ['agency_id' => $row->agency_id, 'user_id' => null, 'setting_key' => 'expiry_notice_send_business_days', 'old_value' => null,
                        'new_value' => (string) $days, 'source' => 'migration', 'created_at' => now()],
                    ['agency_id' => $row->agency_id, 'user_id' => null, 'setting_key' => 'default_earliest_notice_months', 'old_value' => (string) $months,
                        'new_value' => null, 'source' => 'migration', 'created_at' => now()],
                ]);
            }
        }

        if (Schema::hasTable('notification_event_types') && ! DB::table('notification_event_types')->where('key', self::EVENT)->exists()) {
            DB::table('notification_event_types')->insert([
                'key' => self::EVENT, 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => 'Lease expiry notice not sent',
                'description' => 'A fixed-term lease is getting close to its end and the expiry notice to the tenant has not gone out (Consumer Protection Act: 40 to 80 business days before the lease ends).',
                'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                'supports_in_app' => 1, 'supports_email' => 1, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')->where('key', self::EVENT)->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('leases', function (Blueprint $table) {
            foreach (['expiry_notice_sent_at', 'expiry_notice_sent_via', 'expiry_notice_sent_by_user_id', 'expiry_notice_note', 'expiry_notice_alerted_at'] as $c) {
                if (Schema::hasColumn('leases', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('lease_settings', function (Blueprint $table) {
            foreach (['expiry_notice_send_business_days', 'expiry_notice_alert_business_days'] as $c) {
                if (Schema::hasColumn('lease_settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
