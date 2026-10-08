<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-portal-access.md §22 — the portal Home FAQ wording (tenant and owner, two questions each) and the
 * fault-photo limits, as agency settings. All nullable: a null column reads as the model's DEFAULT_* (read-time default
 * pattern, nothing written until an agency changes a value).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rental_portal_settings')) {
            return;
        }

        Schema::table('rental_portal_settings', function (Blueprint $table) {
            foreach ([
                'faq_tenant_notice_question', 'faq_tenant_early_question',
                'faq_landlord_notice_question', 'faq_landlord_early_question',
            ] as $col) {
                if (!Schema::hasColumn('rental_portal_settings', $col)) {
                    $table->string($col, 200)->nullable();
                }
            }
            foreach ([
                'faq_tenant_notice_answer', 'faq_tenant_early_answer',
                'faq_landlord_notice_answer', 'faq_landlord_early_answer',
            ] as $col) {
                if (!Schema::hasColumn('rental_portal_settings', $col)) {
                    $table->text($col)->nullable();
                }
            }
            if (!Schema::hasColumn('rental_portal_settings', 'fault_photo_max_count')) {
                $table->unsignedTinyInteger('fault_photo_max_count')->nullable();
            }
            if (!Schema::hasColumn('rental_portal_settings', 'fault_photo_max_mb')) {
                $table->unsignedTinyInteger('fault_photo_max_mb')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rental_portal_settings')) {
            return;
        }

        Schema::table('rental_portal_settings', function (Blueprint $table) {
            $cols = [
                'faq_tenant_notice_question', 'faq_tenant_early_question', 'faq_landlord_notice_question', 'faq_landlord_early_question',
                'faq_tenant_notice_answer', 'faq_tenant_early_answer', 'faq_landlord_notice_answer', 'faq_landlord_early_answer',
                'fault_photo_max_count', 'fault_photo_max_mb',
            ];
            $table->dropColumn(array_values(array_filter($cols, fn ($c) => Schema::hasColumn('rental_portal_settings', $c))));
        });
    }
};
