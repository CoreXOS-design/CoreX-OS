<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Public API — Phase 1a.
 *
 * Master "website is live" switch (layer 1 of the three visibility gates)
 * and the public-facing website settings surfaced via GET /api/v1/website/agency
 * and edited on the Company Settings → Website tab.
 *
 * Spec: .ai/specs/agency-public-api.md §3.6, §3.7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            // Master kill-switch. Off by default — a new agency's website is
            // not live until explicitly turned on.
            if (!Schema::hasColumn('agencies', 'website_enabled')) {
                $table->boolean('website_enabled')->default(false);
            }

            $columns = [
                'website_url'               => fn () => $table->string('website_url')->nullable(),
                'website_tagline'           => fn () => $table->string('website_tagline')->nullable(),
                'website_about'             => fn () => $table->text('website_about')->nullable(),
                'website_social_facebook'   => fn () => $table->string('website_social_facebook')->nullable(),
                'website_social_instagram'  => fn () => $table->string('website_social_instagram')->nullable(),
                'website_social_linkedin'   => fn () => $table->string('website_social_linkedin')->nullable(),
                'website_social_youtube'    => fn () => $table->string('website_social_youtube')->nullable(),
                'website_contact_email'     => fn () => $table->string('website_contact_email')->nullable(),
                'website_contact_phone'     => fn () => $table->string('website_contact_phone')->nullable(),
                'website_show_agents'       => fn () => $table->boolean('website_show_agents')->default(true),
                'website_show_listings'     => fn () => $table->boolean('website_show_listings')->default(true),
            ];

            foreach ($columns as $name => $make) {
                if (!Schema::hasColumn('agencies', $name)) {
                    $make();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach ([
                'website_enabled',
                'website_url',
                'website_tagline',
                'website_about',
                'website_social_facebook',
                'website_social_instagram',
                'website_social_linkedin',
                'website_social_youtube',
                'website_contact_email',
                'website_contact_phone',
                'website_show_agents',
                'website_show_listings',
            ] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
