<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA FFC renewal — Confirmation of Employment letter. .ai/specs/ppra-ffc-employment-letter.md
 *
 * Two agency-level settings (CLAUDE.md Non-negotiable #9 — never hardcoded,
 * never HFC-default):
 *
 * - ppra_employment_letter_address_block: the "RE:" line's addressee block
 *   (the Property Practitioners Regulatory Board's address). Defaults to
 *   the PPRA's own published Sandton address, which is correct for every
 *   agency in South Africa unless the agency's own records say otherwise —
 *   NOT an HFC-specific value, it is the regulator's address, editable per
 *   agency in case PPRA publishes a new address or an agency corresponds
 *   with a specific regional office.
 * - ppra_employment_letter_reminder_days: how often (in days) the system
 *   re-sends the "awaiting your signature" email to the resolved principal
 *   while a letter sits unsigned. 0 = reminders off. Default 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->text('ppra_employment_letter_address_block')
                ->nullable()
                ->after('ppra_number');
            $table->unsignedSmallInteger('ppra_employment_letter_reminder_days')
                ->default(2)
                ->after('ppra_employment_letter_address_block');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['ppra_employment_letter_address_block', 'ppra_employment_letter_reminder_days']);
        });
    }
};
