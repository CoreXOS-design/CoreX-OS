<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-notices.md §7b (AT-462, 15 Oct 2026):
 *  - each breach notice template carries its own remedy period in days (rental_notice_templates.remedy_days; NULL = the agency's own default);
 *  - the notice to vacate period is an agency setting (lease_settings.vacate_notice_days, NULL = 30) and so is the address a tenant sends proof
 *    of payment to (lease_settings.payment_proof_email, NULL = the agency's own email);
 *  - a breach whose remedy-by date has been reached tells the lease's agents once (lease_breaches.remedy_alert_sent_at) with its OWN alert type
 *    (a shared type would be swallowed by the per-person cooldown - see rental_work_order.quote_received).
 */
return new class extends Migration
{
    private const KEY = 'lease_breach.remedy_date_reached';

    public function up(): void
    {
        Schema::table('rental_notice_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_notice_templates', 'remedy_days')) {
                $table->unsignedSmallInteger('remedy_days')->nullable();
            }
        });
        Schema::table('lease_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('lease_settings', 'vacate_notice_days')) {
                $table->unsignedSmallInteger('vacate_notice_days')->nullable();   // NULL = 30
            }
            if (! Schema::hasColumn('lease_settings', 'payment_proof_email')) {
                $table->string('payment_proof_email', 191)->nullable();            // NULL = the agency's own email
            }
        });
        Schema::table('lease_breaches', function (Blueprint $table) {
            if (! Schema::hasColumn('lease_breaches', 'remedy_alert_sent_at')) {
                $table->timestamp('remedy_alert_sent_at')->nullable();
            }
        });

        if (Schema::hasTable('notification_event_types') && ! DB::table('notification_event_types')->where('key', self::KEY)->exists()) {
            DB::table('notification_event_types')->insert([
                'key' => self::KEY, 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => 'Breach remedy date reached',
                'description' => 'A breach notice\'s remedy-by date has been reached and the breach is not yet marked remedied - was it fixed?',
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
            DB::table('notification_event_types')->where('key', self::KEY)->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('lease_breaches', function (Blueprint $table) {
            if (Schema::hasColumn('lease_breaches', 'remedy_alert_sent_at')) {
                $table->dropColumn('remedy_alert_sent_at');
            }
        });
        Schema::table('lease_settings', function (Blueprint $table) {
            foreach (['vacate_notice_days', 'payment_proof_email'] as $c) {
                if (Schema::hasColumn('lease_settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('rental_notice_templates', function (Blueprint $table) {
            if (Schema::hasColumn('rental_notice_templates', 'remedy_days')) {
                $table->dropColumn('remedy_days');
            }
        });
    }
};
