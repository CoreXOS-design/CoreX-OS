<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-notices.md §3a / §5 (15 Oct 2026, AT-462) - the agency's own notice documents:
 *  - a template can carry a default lease-clause block (rental_notice_templates.default_clause) that the agent edits per notice;
 *  - the remedy window can count calendar days instead of business days (lease_settings.breach_remedy_day_basis, NULL = business) and the
 *    breach remembers which it was issued under (lease_breaches.remedy_day_basis);
 *  - the notice to vacate captures its vacate date and time (lease_breaches.vacate_date / vacate_time); the default time is an agency
 *    setting (lease_settings.vacate_time, NULL = 12:00).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('rental_notice_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_notice_templates', 'default_clause')) {
                $table->text('default_clause')->nullable();
            }
        });
        Schema::table('lease_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('lease_settings', 'breach_remedy_day_basis')) {
                $table->string('breach_remedy_day_basis', 10)->nullable();   // 'business' | 'calendar'; NULL = business
            }
            if (! Schema::hasColumn('lease_settings', 'vacate_time')) {
                $table->string('vacate_time', 5)->nullable();                // 'HH:MM'; NULL = 12:00
            }
        });
        Schema::table('lease_breaches', function (Blueprint $table) {
            if (! Schema::hasColumn('lease_breaches', 'remedy_day_basis')) {
                $table->string('remedy_day_basis', 10)->default('business');
            }
            if (! Schema::hasColumn('lease_breaches', 'vacate_date')) {
                $table->date('vacate_date')->nullable();
            }
            if (! Schema::hasColumn('lease_breaches', 'vacate_time')) {
                $table->string('vacate_time', 5)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('lease_breaches', function (Blueprint $table) {
            foreach (['remedy_day_basis', 'vacate_date', 'vacate_time'] as $c) {
                if (Schema::hasColumn('lease_breaches', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('lease_settings', function (Blueprint $table) {
            foreach (['breach_remedy_day_basis', 'vacate_time'] as $c) {
                if (Schema::hasColumn('lease_settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('rental_notice_templates', function (Blueprint $table) {
            if (Schema::hasColumn('rental_notice_templates', 'default_clause')) {
                $table->dropColumn('default_clause');
            }
        });
    }
};
