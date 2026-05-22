<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Part A3 — agency-level delivery templates.
 *
 * Subject + body live as text columns on the agencies row. Placeholders
 * supported by PresentationDeliveryService::renderTemplate:
 *   {recipient_first_name}, {property_address}, {agent_name},
 *   {agency_name}, {presentation_url}
 *
 * Defaults seeded inline so existing agencies get sensible copy on migrate.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'email_default_subject_template')) {
                $table->string('email_default_subject_template', 300)->nullable()
                    ->after('teaser_default_show_holding_cost_summary');
            }
            if (!Schema::hasColumn('agencies', 'email_default_body_template')) {
                $table->text('email_default_body_template')->nullable()
                    ->after('email_default_subject_template');
            }
            if (!Schema::hasColumn('agencies', 'whatsapp_default_template')) {
                $table->text('whatsapp_default_template')->nullable()
                    ->after('email_default_body_template');
            }
        });

        // Seed sensible defaults for existing agencies.
        $emailSubject = 'Your property market analysis — {property_address}';
        $emailBody = <<<'BODY'
Hi {recipient_first_name},

I've prepared a complete market analysis for {property_address} based on recent sales, current competition, and current market trends.

You can view it here: {presentation_url}

Feel free to reach out with any questions.

Best regards,
{agent_name}
{agency_name}
BODY;
        $whatsappBody = "Hi {recipient_first_name}, I've prepared a property market analysis for {property_address}. View it here: {presentation_url}";

        \Illuminate\Support\Facades\DB::table('agencies')
            ->whereNull('email_default_subject_template')
            ->update([
                'email_default_subject_template' => $emailSubject,
                'email_default_body_template'    => $emailBody,
                'whatsapp_default_template'      => $whatsappBody,
            ]);
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach ([
                'whatsapp_default_template',
                'email_default_body_template',
                'email_default_subject_template',
            ] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
